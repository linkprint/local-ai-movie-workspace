#!/bin/sh
set -eu

portal_root="${MOVIE_PORTAL_ROOT:-/srv/movie-portal}"
host_ip="${MOVIE_PORTAL_HOST_IP:-192.168.88.20}"
gateway_name="${MOVIE_PORTAL_GATEWAY_CONTAINER:-movie_portal-movie-gateway-1}"
wait_seconds="${MOVIE_PORTAL_RECOVERY_WAIT_SECONDS:-180}"

log() {
    printf '%s\n' "movie-portal-gateway-recovery: $*"
}

elapsed=0
while ! ip -o -4 address show | grep -Fq "inet ${host_ip}/"; do
    if [ "$elapsed" -ge "$wait_seconds" ]; then
        log "timed out waiting for ${host_ip}"
        exit 1
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

elapsed=0
while ! docker info >/dev/null 2>&1; do
    if [ "$elapsed" -ge "$wait_seconds" ]; then
        log "timed out waiting for Docker"
        exit 1
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

cd "$portal_root"

state="missing"
movie_back_ip=""
movie_front_ip=""
movie_terminal_ip=""
if docker inspect "$gateway_name" >/dev/null 2>&1; then
    state="$(docker inspect --format '{{.State.Status}}' "$gateway_name")"
    movie_back_ip="$(docker inspect --format '{{with index .NetworkSettings.Networks "movie_back"}}{{.IPAddress}}{{end}}' "$gateway_name")"
    movie_front_ip="$(docker inspect --format '{{with index .NetworkSettings.Networks "movie_front"}}{{.IPAddress}}{{end}}' "$gateway_name")"
    movie_terminal_ip="$(docker inspect --format '{{with index .NetworkSettings.Networks "movie_terminal"}}{{.IPAddress}}{{end}}' "$gateway_name")"
fi

if [ "$state" != "running" ] || \
   [ "$movie_back_ip" != "172.30.10.2" ] || \
   [ -z "$movie_front_ip" ] || \
   [ "$movie_terminal_ip" != "172.30.20.2" ]; then
    log "recreating gateway after incomplete boot restore (state=${state}, back=${movie_back_ip:-none}, front=${movie_front_ip:-none}, terminal=${movie_terminal_ip:-none})"
    docker compose up -d --no-deps --force-recreate movie-gateway
else
    log "gateway container and networks are already present"
fi

elapsed=0
while :; do
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$gateway_name" 2>/dev/null || true)"
    if [ "$health" = "healthy" ]; then
        break
    fi
    if [ "$elapsed" -ge "$wait_seconds" ]; then
        log "gateway did not become healthy (last state=${health:-missing})"
        exit 1
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

curl --fail --silent --show-error --max-time 10 "http://${host_ip}:8443/up" >/dev/null
log "gateway is healthy on ${host_ip}:8443"
