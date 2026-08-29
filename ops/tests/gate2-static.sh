#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
rendered="$(mktemp)"
trap 'rm -f "$rendered"' EXIT

cd "$root"
MOVIE_DOCKER_GID="${MOVIE_DOCKER_GID:-999}" docker compose config --format json > "$rendered"

jq -e '
  (.services | keys | sort) == [
    "movie-ai-broker",
    "movie-auth",
    "movie-egress",
    "movie-gateway",
    "movie-h3-adapter",
    "movie-postgres",
    "movie-queue",
    "movie-redis",
    "movie-scheduler",
    "movie-terminal-router",
    "movie-web",
    "movie-workspace-manager"
  ] and
  ([.services[] | select(.privileged == true)] | length) == 0 and
  ([.services[] | .cap_add[]? | select(. == "SYS_ADMIN")] | length) == 0 and
  ([.services[] | .security_opt[]? | select(. == "seccomp=unconfined")] | length) == 0 and
  ([.services[] | .volumes[]? | .source | select(. == "/" or . == "/etc" or . == "/srv" or . == "/home")] | length) == 0 and
  ([.services[] | .ports[]?] | length) == 1 and
  .services["movie-gateway"].ports[0].host_ip == "192.168.88.20" and
  .services["movie-gateway"].ports[0].published == "8443"
' "$rendered" >/dev/null

echo "gate2 static compose policy: PASS"
