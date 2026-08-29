#!/bin/sh
set -eu

CHAIN="${MOVIE_FIREWALL_CHAIN:-DOCKER-USER}"
SOURCE="${MOVIE_CADDY_IP:-192.168.88.30}"
DESTINATION="${MOVIE_PORTAL_BIND_IP:-192.168.88.20}"
PORT="${MOVIE_PORTAL_PORT:-8443}"

while iptables -C "$CHAIN" -p tcp -m conntrack --ctstate NEW --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP 2>/dev/null; do
    iptables -D "$CHAIN" -p tcp -m conntrack --ctstate NEW --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP
done

while iptables -C "$CHAIN" -p tcp -m conntrack --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP 2>/dev/null; do
    iptables -D "$CHAIN" -p tcp -m conntrack --ctorigdst "$DESTINATION" --ctorigdstport "$PORT" ! -s "$SOURCE" -j DROP
done
