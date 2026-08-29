#!/usr/bin/env python3
"""Atomically update only the fixed Movie Portal image pins in an env file."""

from __future__ import annotations

import os
import pathlib
import re
import sys
import tempfile


ALLOWED_KEYS = frozenset({
    "MOVIE_APP_IMAGE",
    "MOVIE_GATEWAY_IMAGE",
    "MOVIE_MANAGER_IMAGE",
    "MOVIE_BROKER_IMAGE",
    "MOVIE_H3_ADAPTER_IMAGE",
    "MOVIE_WORKSPACE_IMAGE",
})
IMAGE_RE = re.compile(r"^sha256:[0-9a-f]{64}$")


def main() -> None:
    if len(sys.argv) < 3:
        raise SystemExit("usage: pin-images.py ENV KEY=sha256:... [KEY=sha256:...]")
    target = pathlib.Path(sys.argv[1]).resolve(strict=True)
    replacements: dict[str, str] = {}
    for argument in sys.argv[2:]:
        key, separator, value = argument.partition("=")
        if not separator or key not in ALLOWED_KEYS or not IMAGE_RE.fullmatch(value):
            raise SystemExit(f"invalid fixed image pin: {key}")
        if key in replacements:
            raise SystemExit(f"duplicate fixed image pin: {key}")
        replacements[key] = value

    original = target.read_text(encoding="utf-8")
    lines = original.splitlines()
    seen: set[str] = set()
    updated: list[str] = []
    for line in lines:
        key, separator, _ = line.partition("=")
        if separator and key in replacements:
            if key in seen:
                raise SystemExit(f"duplicate existing image pin: {key}")
            updated.append(f"{key}={replacements[key]}")
            seen.add(key)
        else:
            updated.append(line)
    for key in sorted(replacements):
        if key not in seen:
            updated.append(f"{key}={replacements[key]}")

    stat = target.stat()
    descriptor, temporary_name = tempfile.mkstemp(prefix=".movie-env-", dir=target.parent)
    temporary = pathlib.Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            stream.write("\n".join(updated) + "\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, stat.st_mode & 0o777)
        os.chown(temporary, stat.st_uid, stat.st_gid)
        temporary.replace(target)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


if __name__ == "__main__":
    main()
