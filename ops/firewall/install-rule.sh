#!/bin/sh
set -eu

CHAIN="${MOVIE_FIREWALL_CHAIN:-DOCKER-USER}"
SOURCE="${MOVIE_CADDY_IP:-192.168.88.30}"
DESTINATION="${MOVIE_PORTAL_BIND_IP:-192.168.88.20}"
PORT="${MOVIE_PORTAL_PORT:-8443}"
BACKUP_DIR="${MOVIE_FIREWALL_BACKUP_DIR:-/srv/movie-portal/data/firewall-backups}"

docker_backend="$(docker info --format '{{.FirewallBackend.Driver}}' 2>/dev/null || true)"
if [ "$docker_backend" != iptables ]; then
    echo "unsupported Docker firewall backend: $docker_backend" >&2
    exit 1
fi

iptables -nL "$CHAIN" >/dev/null

if iptables -C "$CHAIN" -p tcp -m conntrack --ctstate NEW --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP 2>/dev/null; then
    exit 0
fi

install -d -m 0700 "$BACKUP_DIR"
backup="$BACKUP_DIR/iptables-before-movie-$(date -u +%Y%m%dT%H%M%SZ).rules"
iptables-save > "$backup"
chmod 0600 "$backup"

iptables -I "$CHAIN" -p tcp -m conntrack --ctstate NEW --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP

while iptables -C "$CHAIN" -p tcp -m conntrack --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP 2>/dev/null; do
    iptables -D "$CHAIN" -p tcp -m conntrack --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP
done

iptables -C "$CHAIN" -p tcp -m conntrack --ctstate NEW --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP
