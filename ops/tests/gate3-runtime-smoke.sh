#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/../.."

manager='movie_portal-movie-workspace-manager-1'
broker='movie_portal-movie-ai-broker-1'

if [ -n "$(docker ps -a --filter name='^/movie-active-workspace$' --format '{{.ID}}')" ]; then
    echo 'an active workspace already exists; preserve it and stop' >&2
    exit 1
fi

new_uuid() {
    python3 -c 'import uuid; print(uuid.uuid4())'
}

new_uuid7() {
    python3 -c 'import os,uuid; b=bytearray(os.urandom(16)); b[6]=(b[6]&15)|112; b[8]=(b[8]&63)|128; print(uuid.UUID(bytes=bytes(b)))'
}

signed_post() {
    container="$1"
    secret="$2"
    path_value="$3"
    body_value="$4"
    expected="$5"
    docker exec \
        -e MOVIE_TEST_SECRET="$secret" \
        -e MOVIE_TEST_PATH="$path_value" \
        -e MOVIE_TEST_BODY="$body_value" \
        -e MOVIE_TEST_EXPECTED="$expected" \
        -i "$container" python3 - <<'PY'
import hashlib
import hmac
import os
import pathlib
import time
import urllib.error
import urllib.request

path = os.environ["MOVIE_TEST_PATH"]
body = os.environ["MOVIE_TEST_BODY"].encode()
stamp = str(int(time.time()))
secret = pathlib.Path(os.environ["MOVIE_TEST_SECRET"]).read_bytes().strip()
signature = hmac.new(
    secret,
    b"\n".join([stamp.encode(), b"POST", path.encode(), body]),
    hashlib.sha256,
).hexdigest()
request = urllib.request.Request(
    "http://127.0.0.1:8080" + path,
    data=body,
    method="POST",
    headers={
        "Content-Type": "application/json",
        "X-Movie-Timestamp": stamp,
        "X-Movie-Signature": signature,
    },
)
try:
    with urllib.request.urlopen(request, timeout=20) as response:
        status, payload = response.status, response.read().decode()
except urllib.error.HTTPError as error:
    status, payload = error.code, error.read().decode()
print(f"{path} status={status} body={payload}")
if status != int(os.environ["MOVIE_TEST_EXPECTED"]):
    raise SystemExit(1)
PY
}

wait_healthy() {
    for _ in $(seq 1 20); do
        status="$(docker inspect movie-active-workspace --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}')"
        [ "$status" = healthy ] && return 0
        sleep 1
    done
    docker inspect movie-active-workspace --format '{{json .State}}'
    return 1
}

broker_register() {
    reservation="$1" user="$2" token="$3" deadline="$4"
    body="{\"reservation_id\":\"$reservation\",\"user_id\":\"$user\",\"expires_at\":$deadline,\"token\":\"$token\"}"
    signed_post "$broker" /run/secrets/broker_hmac_secret /internal/register "$body" 200
}

broker_revoke() {
    reservation="$1"
    signed_post "$broker" /run/secrets/broker_hmac_secret /internal/revoke "{\"reservation_id\":\"$reservation\"}" 200
}

manager_start() {
    reservation="$1" storage="$2" token="$3" deadline="$4" expected="${5:-200}"
    project="$(new_uuid)"
    body="{\"reservation_id\":\"$reservation\",\"storage_uuid\":\"$storage\",\"workspace_root\":\"gate3.user@example.com\",\"project_id\":\"$project\",\"project_directory\":\"gate3-test\",\"deadline_epoch\":$deadline,\"broker_token\":\"$token\"}"
    signed_post "$manager" /run/secrets/manager_hmac_secret /v1/start "$body" "$expected"
}

manager_stop() {
    reservation="$1"
    signed_post "$manager" /run/secrets/manager_hmac_secret /v1/stop "{\"reservation_id\":\"$reservation\"}" 200
}

manager_deadline() {
    reservation="$1" deadline="$2"
    body="{\"reservation_id\":\"$reservation\",\"deadline_epoch\":$deadline}"
    signed_post "$manager" /run/secrets/manager_hmac_secret /v1/deadline "$body" 200
}

reservation1="$(new_uuid7)"
reservation2="$(new_uuid7)"
reservation3="$(new_uuid7)"
storage1="$(new_uuid)"
storage2="$(new_uuid)"
user1="$(new_uuid7)"
user2="$(new_uuid7)"
token1="$(openssl rand -hex 48)"
token2="$(openssl rand -hex 48)"
token3="$(openssl rand -hex 48)"
deadline="$(( $(date +%s) + 900 ))"
compact1="${storage1//-/}"
compact2="${storage2//-/}"

printf 'synthetic_reservations=%s,%s,%s\n' "$reservation1" "$reservation2" "$reservation3"
printf 'synthetic_storages=%s,%s\n' "$storage1" "$storage2"

broker_register "$reservation1" "$user1" "$token1" "$deadline"
manager_start "$reservation1" "$storage1" "$token1" "$deadline"
wait_healthy

refreshed_deadline="$(( deadline - 60 ))"
manager_deadline "$reservation1" "$refreshed_deadline"
test "$(docker exec movie-active-workspace cat /run/movie/deadline/deadline)" = "$refreshed_deadline"
printf 'deadline_refresh=PASS\n'

docker inspect movie-active-workspace | python3 -c '
import json, sys
x = json.load(sys.stdin)[0]
h = x["HostConfig"]
opts = h["SecurityOpt"]
actual = {
    "user": x["Config"]["User"],
    "readonly": h["ReadonlyRootfs"],
    "privileged": h["Privileged"],
    "cap_drop": h["CapDrop"],
    "cap_add": h["CapAdd"],
    "pids": h["PidsLimit"],
    "memory": h["Memory"],
    "cpus": h["NanoCpus"],
    "devices": h["Devices"],
    "no_new_privileges": "no-new-privileges:true" in opts,
    "apparmor": any(v == "apparmor=movie-workspace-bwrap" for v in opts),
    "seccomp_custom": any(v.startswith("seccomp={") for v in opts),
    "mounts": [(m["Type"], m["Destination"], m["RW"]) for m in x["Mounts"]],
    "workspace_mount": next(m for m in h["Mounts"] if m["Target"] == "/workspace"),
    "outputs_mount": next(m for m in h["Mounts"] if m["Target"] == "/outputs"),
    "networks": sorted(x["NetworkSettings"]["Networks"]),
}
assert actual["user"] == "10001:10001"
assert actual["readonly"] is True and actual["privileged"] is False
assert actual["cap_drop"] == ["ALL"] and actual["cap_add"] is None
assert actual["devices"] in (None, [])
assert actual["no_new_privileges"] and actual["apparmor"] and actual["seccomp_custom"]
assert actual["networks"] == ["movie_broker", "movie_egress_client", "movie_terminal"]
assert all(mount[0] == "volume" for mount in actual["mounts"])
assert actual["workspace_mount"]["VolumeOptions"]["Subpath"] == "gate3.user@example.com"
assert actual["workspace_mount"]["VolumeOptions"]["NoCopy"] is True
labels = x["Config"]["Labels"]
assert actual["outputs_mount"]["Source"] == "movie_portal_outputs"
assert actual["outputs_mount"]["VolumeOptions"]["Subpath"] == (
    labels["movie.ai.workspace.storage"] + "/" + labels["movie.ai.workspace.project-id"]
)
assert actual["outputs_mount"]["VolumeOptions"]["NoCopy"] is True
assert any(value.startswith("MOVIE_VIDEO_BASE_URL=https://movie.example.com/workspace/projects") for value in x["Config"]["Env"])
assert x["Config"]["WorkingDir"] == "/workspace/gate3-test"
print("workspace_hardening=PASS", actual)
'

docker exec movie-active-workspace sh -lc '
set -eu
test ! -e "$CODEX_HOME/auth.json"
test ! -S /var/run/docker.sock
test ! -e /dev/nvidia0
test ! -e /srv/movie-portal
printf persistence-one > "$CODEX_HOME/gate3-owner-marker"
printf "no_shared_auth_or_host_mount=PASS\n"
'

docker exec movie-active-workspace sh -lc 'codex sandbox -C /workspace -P movie_workspace --include-managed-config -- /bin/sh -lc '\''
set -eu
printf sandbox-workspace > /workspace/gate3-runtime-sandbox
printf sandbox-output > /outputs/gate3-runtime-sandbox
if printf bad > /etc/gate3-bad 2>/tmp/e1; then exit 90; fi
if printf bad > "$CODEX_HOME/gate3-bad" 2>/tmp/e2; then exit 91; fi
printf "runtime_codex_sandbox=PASS\n"
'\'''

docker exec movie-active-workspace movie-ai gpu status |
    python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["mode"]=="real" and d["real_gpu_available"] is True and float(d["gpu"]["power_limit_w"]) <= 550; print("bounded_gpu_status=PASS")'
docker exec movie-active-workspace movie-ai mock submit --prompt 'Gate 3 synthetic mock only' |
    python3 -c 'import json,sys; d=json.load(sys.stdin)["job"]; assert d["status"]=="completed" and d["real_gpu_used"] is False and d["capability"]=="mock.echo"; print("mock_job=PASS")'

docker exec movie-active-workspace sh -lc '
set -eu
for command in \
    "sudo -n true" \
    "docker ps" \
    "systemctl status caddy" \
    "ssh root@192.168.88.30 true" \
    "nvidia-smi" \
    "mount -t tmpfs tmpfs /tmp/gate3-mount" \
    "nsenter --target 1 --mount true"; do
    if sh -lc "$command" >/dev/null 2>&1; then
        echo "unexpected_success=$command"
        exit 80
    fi
done
if curl --noproxy "*" --max-time 3 --silent --show-error http://192.168.88.1 >/dev/null 2>&1; then exit 81; fi
if curl --noproxy "*" --max-time 3 --silent --show-error https://api.openai.com >/dev/null 2>&1; then exit 82; fi
if curl --fail --max-time 5 --silent --show-error https://example.com >/dev/null 2>&1; then exit 83; fi
code="$(curl --noproxy "*" --max-time 5 --silent --output /dev/null --write-out "%{http_code}" http://movie-gateway:8443/login)"
test "$code" = 403
printf "workspace_escape_and_egress=PASS gateway_http=%s\n" "$code"
'

manager_start "$reservation2" "$storage2" "$token2" "$deadline" 409
broker_revoke "$reservation1"
manager_stop "$reservation1"

[ -z "$(docker ps -a --filter name='^/movie-active-workspace$' --format '{{.ID}}')" ]
for volume in "movie_user_${compact1}_workspace" "movie_user_${compact1}_outputs" "movie_user_${compact1}_codex"; do
    [ -z "$(docker ps -a --filter volume="$volume" --format '{{.ID}}')" ]
done

docker exec -e MOVIE_TEST_TOKEN="$token1" -i "$broker" python3 - <<'PY'
import os
import urllib.error
import urllib.request

request = urllib.request.Request(
    "http://127.0.0.1:8080/v1/gpu/status",
    headers={"Authorization": "Bearer " + os.environ["MOVIE_TEST_TOKEN"]},
)
try:
    urllib.request.urlopen(request, timeout=5)
    raise SystemExit(1)
except urllib.error.HTTPError as error:
    print(f"old_broker_token_status={error.code}")
    assert error.code == 401
PY

broker_register "$reservation2" "$user1" "$token2" "$deadline"
manager_start "$reservation2" "$storage1" "$token2" "$deadline"
wait_healthy
docker exec movie-active-workspace sh -lc 'test "$(cat "$CODEX_HOME/gate3-owner-marker")" = persistence-one; printf "same_user_codex_home_persistence=PASS\n"'
broker_revoke "$reservation2"
manager_stop "$reservation2"

broker_register "$reservation3" "$user2" "$token3" "$deadline"
manager_start "$reservation3" "$storage2" "$token3" "$deadline"
wait_healthy
docker exec movie-active-workspace sh -lc 'test ! -e "$CODEX_HOME/gate3-owner-marker"; printf "second_user_codex_home_isolation=PASS\n"'
broker_revoke "$reservation3"
manager_stop "$reservation3"

for volume in \
    "movie_user_${compact1}_workspace" "movie_user_${compact1}_outputs" "movie_user_${compact1}_codex" \
    "movie_user_${compact2}_workspace" "movie_user_${compact2}_outputs" "movie_user_${compact2}_codex"; do
    docker volume rm "$volume" >/dev/null
done

printf 'synthetic_volume_cleanup=PASS\n'
printf 'GATE3_MOCK_CONTROL_SMOKE=PASS\n'
