#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root" >&2
    exit 1
fi

root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"

test -x /usr/bin/systemctl
test -x /usr/bin/docker
test -x /usr/bin/nvidia-smi
test -x "$root/ops/systemd/movie-portal-gateway-recovery.sh"
test -x "$root/ops/systemd/nvidia-gpu-recovery.sh"

systemd-analyze verify \
    "$root/ops/systemd/movie-portal-gateway-recovery.service" \
    "$root/ops/systemd/nvidia-gpu-recovery.service"

install -o root -g root -m 0644 \
    "$root/ops/systemd/movie-portal-gateway-recovery.service" \
    /etc/systemd/system/movie-portal-gateway-recovery.service
install -o root -g root -m 0644 \
    "$root/ops/systemd/nvidia-gpu-recovery.service" \
    /etc/systemd/system/nvidia-gpu-recovery.service

systemctl daemon-reload
systemctl enable movie-portal-gateway-recovery.service nvidia-gpu-recovery.service
systemctl restart movie-portal-gateway-recovery.service
systemctl is-active --quiet movie-portal-gateway-recovery.service

if ! systemctl restart nvidia-gpu-recovery.service; then
    echo "NVIDIA recovery could not complete; check Secure Boot/MOK state" >&2
    exit 1
fi
systemctl is-active --quiet nvidia-gpu-recovery.service

echo "Movie Portal gateway and NVIDIA boot recovery installed"
