#!/bin/sh
set -eu

if [ "$#" -ne 2 ]; then
    echo "usage: $0 DESTINATION COMMIT_OR_TAG" >&2
    exit 2
fi

root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
destination="$1"
revision="$2"
private_identifiers_file="${MOVIE_PRIVATE_IDENTIFIERS_FILE:-}"

scan_snapshot() {
    if [ -n "$private_identifiers_file" ]; then
        python3 "$destination/ops/tests/public_release_scan.py" \
            "$@" --deny-identifiers-file "$private_identifiers_file"
    else
        python3 "$destination/ops/tests/public_release_scan.py" "$@"
    fi
}

git -C "$root" rev-parse --verify "$revision^{commit}" >/dev/null

if [ -e "$destination" ]; then
    if [ ! -d "$destination" ] || [ -n "$(ls -A "$destination")" ]; then
        echo "destination must not exist or must be an empty directory" >&2
        exit 1
    fi
else
    mkdir -p "$destination"
fi

git -C "$root" archive --format=tar "$revision" | tar -xf - -C "$destination"
git -C "$destination" init -b main
scan_snapshot --tree
git -C "$destination" ls-files --others --exclude-standard -z \
    | xargs -0 git -C "$destination" add --
GIT_AUTHOR_NAME="Movie AI Workspace Contributors" \
GIT_AUTHOR_EMAIL="contributors@example.com" \
GIT_COMMITTER_NAME="Movie AI Workspace Contributors" \
GIT_COMMITTER_EMAIL="contributors@example.com" \
    git -C "$destination" commit -m "Initial open-source release"
scan_snapshot --tree --history

echo "Public snapshot created at $destination"
echo "No remote was added and nothing was pushed. Review before publication."
