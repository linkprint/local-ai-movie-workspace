#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root" >&2
    exit 1
fi

root="${MOVIE_PORTAL_ROOT:-$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)}"
secret="$root/env/h3_control_hmac_secret"
broker_secret="$root/env/broker_manager_hmac_secret"
comfy_unit="${MOVIE_COMFY_UNIT:-movie-comfyui.service}"
qwen_unit="${MOVIE_QWEN_UNIT:-movie-qwen.service}"
qwen_container="${MOVIE_QWEN_CONTAINER:-movie-qwen-runtime}"

case "$root" in
    /*) ;;
    *) echo "MOVIE_PORTAL_ROOT must be an absolute path" >&2; exit 1 ;;
esac
printf '%s\n' "$comfy_unit" | grep -Eq '^[a-z0-9][a-z0-9_.@-]{0,126}\.service$'
printf '%s\n' "$qwen_unit" | grep -Eq '^[a-z0-9][a-z0-9_.@-]{0,126}\.service$'
printf '%s\n' "$qwen_container" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$'

test -x /usr/bin/nvidia-smi
test -x /usr/bin/systemctl
test -x /usr/bin/docker
systemctl cat "$comfy_unit" >/dev/null
systemctl cat "$qwen_unit" >/dev/null
test -f "$root/ops/systemd/movie-qwen-manual-only.conf"

if getent group movie-h3-control >/dev/null; then
    test "$(getent group movie-h3-control | cut -d: -f3)" = 19002
else
    if getent group 19002 >/dev/null; then
        echo "GID 19002 is already in use" >&2
        exit 1
    fi
    groupadd --system --gid 19002 movie-h3-control
fi

if [ ! -e "$secret" ]; then
    umask 077
    openssl rand -hex 48 > "$secret"
fi
if [ ! -e "$broker_secret" ]; then
    umask 077
    openssl rand -hex 48 > "$broker_secret"
fi
chown root:root "$secret"
chmod 0400 "$secret"
chown root:root "$broker_secret"
chmod 0400 "$broker_secret"
install -o root -g 19002 -m 0440 "$secret" "$root/env/h3_control_hmac_secret.manager"
install -o root -g 19002 -m 0440 "$broker_secret" "$root/env/broker_manager_hmac_secret.manager"
install -o 10003 -g 65534 -m 0400 "$broker_secret" "$root/env/broker_manager_hmac_secret.broker"

install -o root -g root -m 0644 "$root/ops/systemd/movie-h3-control.socket" /etc/systemd/system/movie-h3-control.socket
temporary_unit="$(mktemp)"
trap 'rm -f "$temporary_unit"' EXIT HUP INT TERM
sed "s|/srv/movie-portal|$root|g" "$root/ops/systemd/movie-h3-control.service" > "$temporary_unit"
install -o root -g root -m 0644 "$temporary_unit" /etc/systemd/system/movie-h3-control.service
install -d -o root -g root -m 0755 /etc/movie-ai
{
    printf 'MOVIE_COMFY_UNIT=%s\n' "$comfy_unit"
    printf 'MOVIE_QWEN_UNIT=%s\n' "$qwen_unit"
    printf 'MOVIE_QWEN_CONTAINER=%s\n' "$qwen_container"
} > /etc/movie-ai/host-control.env
chmod 0644 /etc/movie-ai/host-control.env
install -d -o root -g root -m 0755 "/etc/systemd/system/$qwen_unit.d"
install -o root -g root -m 0644 \
    "$root/ops/systemd/movie-qwen-manual-only.conf" \
    "/etc/systemd/system/$qwen_unit.d/manual-only.conf"
systemctl daemon-reload
systemctl disable "$qwen_unit"
systemctl enable --now movie-h3-control.socket
systemctl is-active --quiet movie-h3-control.socket
test -S /run/movie-h3-control/control.sock

test "$(systemctl show "$qwen_unit" --property=Restart --value)" = "no"
test "$(systemctl is-enabled "$qwen_unit" 2>/dev/null || true)" = "disabled"

echo "movie-h3-control socket installed; $qwen_unit is manual-only"
