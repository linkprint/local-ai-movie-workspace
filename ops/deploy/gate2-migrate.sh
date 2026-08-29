#!/bin/sh
set -eu

movie_root="${1:-/srv/movie-portal}"
cd "$movie_root"

movie_table_count="$(
    docker compose exec -T movie-postgres sh -ec '
        export PGPASSWORD="$(tr -d "\r\n" < /run/secrets/postgres_password)"
        psql -U movie_portal -d movie_portal -Atc "select count(*) from pg_catalog.pg_tables where schemaname = '\''public'\'';"
    '
)"

if [ "$movie_table_count" -ne 0 ]; then
    echo "refusing first-deploy migration: public table count is $movie_table_count" >&2
    exit 1
fi

movie_backup_dir="$movie_root/data/backups"
movie_backup_file="$movie_backup_dir/pre-gate2-empty-schema.sql"
install -d -o root -g root -m 0700 "$movie_backup_dir"

docker compose exec -T movie-postgres sh -ec '
    export PGPASSWORD="$(tr -d "\r\n" < /run/secrets/postgres_password)"
    pg_dump -U movie_portal -d movie_portal --schema-only --no-owner --no-privileges
' > "$movie_backup_file"
chmod 0600 "$movie_backup_file"

docker compose run --rm --no-deps movie-web php -r '
    foreach (["DB_PASSWORD", "REDIS_PASSWORD"] as $name) {
        if (getenv($name) === false || getenv($name) === "") {
            fwrite(STDERR, "missing runtime secret env: {$name}\n");
            exit(1);
        }
    }
'

docker compose run --rm --no-deps movie-web php artisan migrate --force

echo "pre_migration_public_tables=$movie_table_count"
echo "pre_migration_backup_sha256=$(sha256sum "$movie_backup_file" | awk '{print $1}')"
