#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
env_dir="$root/env"

command -v openssl >/dev/null 2>&1 || {
    echo "openssl is required" >&2
    exit 1
}

mkdir -p "$env_dir"
if [ ! -e "$env_dir/worker.env" ]; then
    cp "$env_dir/worker.env.example" "$env_dir/worker.env"
    chmod 0600 "$env_dir/worker.env"
fi

write_secret() {
    target_file="$1"
    if [ ! -e "$target_file" ]; then
        umask 077
        openssl rand -hex 48 > "$target_file"
        chmod 0600 "$target_file"
    fi
}

write_secret "$env_dir/node_broker_hmac_secret"
write_secret "$env_dir/node_control_hmac_secret"
write_secret "$env_dir/h3_control_hmac_secret"

if [ ! -e "$env_dir/movie_style_basic_credentials" ]; then
    umask 077
    printf 'disabled:%s\n' "$(openssl rand -hex 32)" > "$env_dir/movie_style_basic_credentials"
    chmod 0600 "$env_dir/movie_style_basic_credentials"
fi

echo "Worker configuration created without printing secrets."
echo "Securely copy the exact node_broker_hmac_secret to the central Router mapping for this registered node UUID."
