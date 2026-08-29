#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
env_dir="$root/env"

command -v openssl >/dev/null 2>&1 || {
    echo "openssl is required" >&2
    exit 1
}

install_example() {
    source_file="$1"
    target_file="$2"
    if [ ! -e "$target_file" ]; then
        cp "$source_file" "$target_file"
        chmod 0600 "$target_file"
    fi
}

write_value() {
    target_file="$1"
    value="$2"
    if [ ! -e "$target_file" ]; then
        umask 077
        printf '%s\n' "$value" > "$target_file"
        chmod 0600 "$target_file"
    fi
}

write_shared() {
    existing_file=""
    for target_file in "$@"; do
        if [ -e "$target_file" ]; then
            test -f "$target_file"
            if [ -n "$existing_file" ] && ! cmp -s "$existing_file" "$target_file"; then
                echo "refusing mismatched shared secret files" >&2
                exit 1
            fi
            existing_file="$target_file"
        fi
    done

    if [ -n "$existing_file" ]; then
        for target_file in "$@"; do
            if [ ! -e "$target_file" ]; then
                umask 077
                cp "$existing_file" "$target_file"
                chmod 0600 "$target_file"
            fi
        done
        return
    fi

    value="$(openssl rand -hex 48)"
    for target_file in "$@"; do
        write_value "$target_file" "$value"
    done
}

mkdir -p "$env_dir"
install_example "$root/.env.example" "$root/.env"
install_example "$env_dir/laravel.env.example" "$env_dir/laravel.env"

if [ -S /var/run/docker.sock ]; then
    docker_gid="$(stat -c '%g' /var/run/docker.sock 2>/dev/null || stat -f '%g' /var/run/docker.sock)"
    temporary="$root/.env.bootstrap.$$"
    awk -v gid="$docker_gid" '
        /^MOVIE_DOCKER_GID=/ { print "MOVIE_DOCKER_GID=" gid; next }
        { print }
    ' "$root/.env" > "$temporary"
    chmod 0600 "$temporary"
    mv "$temporary" "$root/.env"
fi

if grep -q '^APP_KEY=$' "$env_dir/laravel.env"; then
    app_key="base64:$(openssl rand -base64 32 | tr -d '\r\n')"
    temporary="$env_dir/laravel.env.bootstrap.$$"
    awk -v key="$app_key" '
        /^APP_KEY=$/ { print "APP_KEY=" key; next }
        { print }
    ' "$env_dir/laravel.env" > "$temporary"
    chmod 0600 "$temporary"
    mv "$temporary" "$env_dir/laravel.env"
fi

write_shared "$env_dir/postgres_password.app" "$env_dir/postgres_password.db"
write_shared "$env_dir/redis_password.app" "$env_dir/redis_password.server"
write_shared "$env_dir/manager_hmac_secret" "$env_dir/manager_hmac_secret.app"
write_shared "$env_dir/broker_hmac_secret" "$env_dir/broker_hmac_secret.app"
write_shared "$env_dir/node_broker_hmac_secret.20"
write_shared "$env_dir/node_broker_hmac_secret.200"
write_shared \
    "$env_dir/broker_manager_hmac_secret" \
    "$env_dir/broker_manager_hmac_secret.manager" \
    "$env_dir/broker_manager_hmac_secret.broker"
write_shared "$env_dir/h3_control_hmac_secret" "$env_dir/h3_control_hmac_secret.manager"
write_shared \
    "$env_dir/router_hmac_secret" \
    "$env_dir/router_hmac_secret.router" \
    "$env_dir/router_hmac_secret.app"

if [ ! -e "$env_dir/movie_style_basic_credentials" ]; then
    write_value \
        "$env_dir/movie_style_basic_credentials" \
        "disabled:$(openssl rand -hex 32)"
fi

echo "Local configuration created without printing secrets."
echo "Next: edit .env and env/laravel.env, install host controls, migrate with a one-off movie-web container, then start Compose."
