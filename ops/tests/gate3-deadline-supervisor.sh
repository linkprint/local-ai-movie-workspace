#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/../.."

manager='movie_portal-movie-workspace-manager-1'
broker='movie_portal-movie-ai-broker-1'
reservation=''
storage=''

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

wait_manager() {
    for _ in $(seq 1 30); do
        if [ "$(docker inspect "$manager" --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' 2>/dev/null || true)" = healthy ]; then
            return 0
        fi
        sleep 1
    done
    return 1
}

manager_deadline() {
    deadline_value="$1"
    signed_post "$manager" /run/secrets/manager_hmac_secret /v1/deadline \
        "{\"reservation_id\":\"$reservation\",\"deadline_epoch\":$deadline_value}" 200
}

cleanup() {
    docker start "$manager" >/dev/null 2>&1 || true
    wait_manager || true
    if [ -n "$reservation" ]; then
        signed_post "$broker" /run/secrets/broker_hmac_secret /internal/revoke \
            "{\"reservation_id\":\"$reservation\"}" 200 >/dev/null 2>&1 || true
        signed_post "$manager" /run/secrets/manager_hmac_secret /v1/stop \
            "{\"reservation_id\":\"$reservation\"}" 200 >/dev/null 2>&1 || true
    fi
    if [ -n "$storage" ]; then
        compact="${storage//-/}"
        for volume in \
            "movie_user_${compact}_workspace" \
            "movie_user_${compact}_outputs" \
            "movie_user_${compact}_codex"; do
            docker volume rm "$volume" >/dev/null 2>&1 || true
        done
    fi
}
trap cleanup EXIT

if [ -n "$(docker ps -a --filter name='^/movie-active-workspace$' --format '{{.ID}}')" ]; then
    echo 'an active workspace already exists; preserve it and stop' >&2
    exit 1
fi

reservation="$(new_uuid7)"
storage="$(new_uuid)"
project="$(new_uuid)"
user="$(new_uuid7)"
token="$(openssl rand -hex 48)"
deadline="$(( $(date +%s) + 300 ))"

printf 'synthetic_reservation=%s\n' "$reservation"
printf 'synthetic_storage=%s\n' "$storage"

signed_post "$broker" /run/secrets/broker_hmac_secret /internal/register \
    "{\"reservation_id\":\"$reservation\",\"user_id\":\"$user\",\"expires_at\":$deadline,\"token\":\"$token\"}" 200
signed_post "$manager" /run/secrets/manager_hmac_secret /v1/start \
    "{\"reservation_id\":\"$reservation\",\"storage_uuid\":\"$storage\",\"workspace_root\":\"deadline.user@example.com\",\"project_id\":\"$project\",\"project_directory\":\"deadline-test\",\"deadline_epoch\":$deadline,\"broker_token\":\"$token\"}" 200

for _ in $(seq 1 30); do
    if [ "$(docker inspect movie-active-workspace --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}')" = healthy ]; then
        break
    fi
    sleep 1
done
test "$(docker inspect movie-active-workspace --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}')" = healthy

short_deadline="$(( $(date +%s) + 20 ))"
manager_deadline "$short_deadline"
test "$(docker exec movie-active-workspace cat /run/movie/deadline/deadline)" = "$short_deadline"
printf 'deadline_shortening=PASS\n'

docker stop "$manager" >/dev/null
test "$(docker inspect movie-active-workspace --format '{{.State.Running}}')" = true
printf 'manager_off_workspace_running=PASS\n'

for _ in $(seq 1 70); do
    if [ "$(docker inspect movie-active-workspace --format '{{.State.Running}}')" = false ]; then
        break
    fi
    sleep 1
done

docker inspect movie-active-workspace | python3 -c '
import json
import sys

state = json.load(sys.stdin)[0]["State"]
assert state["Running"] is False
assert state["ExitCode"] == 0
print("independent_deadline_shutdown=PASS", {
    "exit_code": state["ExitCode"],
    "finished_at": state["FinishedAt"],
})
'

docker start "$manager" >/dev/null
wait_manager
signed_post "$broker" /run/secrets/broker_hmac_secret /internal/revoke \
    "{\"reservation_id\":\"$reservation\"}" 200
signed_post "$manager" /run/secrets/manager_hmac_secret /v1/stop \
    "{\"reservation_id\":\"$reservation\"}" 200

compact="${storage//-/}"
for volume in \
    "movie_user_${compact}_workspace" \
    "movie_user_${compact}_outputs" \
    "movie_user_${compact}_codex"; do
    docker volume rm "$volume" >/dev/null
done

reservation=''
storage=''
trap - EXIT
printf 'synthetic_volume_cleanup=PASS\n'
printf 'GATE3_DEADLINE_SUPERVISOR=PASS\n'
