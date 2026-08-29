#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root" >&2
    exit 1
fi

systemctl disable --now movie-h3-control.socket 2>/dev/null || true
systemctl stop movie-h3-control.service 2>/dev/null || true
rm -f /etc/systemd/system/movie-h3-control.socket /etc/systemd/system/movie-h3-control.service
systemctl daemon-reload
if getent group movie-h3-control >/dev/null && [ "$(getent group movie-h3-control | cut -d: -f3)" = 19002 ]; then
    groupdel movie-h3-control
fi

echo "movie-h3-control units removed; project secret retained for rollback"
