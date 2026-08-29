#!/bin/sh
set -eu

umask 077

if [ -r /run/secrets/postgres_password ]; then
    DB_PASSWORD="$(tr -d '\r\n' < /run/secrets/postgres_password)"
    export DB_PASSWORD
fi

if [ -r /run/secrets/redis_password ]; then
    REDIS_PASSWORD="$(tr -d '\r\n' < /run/secrets/redis_password)"
    export REDIS_PASSWORD
fi

exec "$@"

