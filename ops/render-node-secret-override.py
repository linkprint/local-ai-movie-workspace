#!/usr/bin/env python3
"""Render a local Compose override that mounts Router secrets by node UUID."""

from __future__ import annotations

import argparse
import pathlib
import re


UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
SAFE_FILE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")


def parse_mapping(value: str) -> tuple[str, str]:
    node_id, separator, filename = value.partition("=")
    if not separator or not UUID_RE.fullmatch(node_id):
        raise argparse.ArgumentTypeError("mapping must be NODE_UUID=ENV_FILENAME")
    if not SAFE_FILE_RE.fullmatch(filename):
        raise argparse.ArgumentTypeError("ENV_FILENAME must be a plain filename under env/")
    return node_id, filename


def render(mappings: list[tuple[str, str]]) -> str:
    if len({node_id for node_id, _ in mappings}) != len(mappings):
        raise ValueError("duplicate node UUID")
    lines = ["services:", "  movie-ai-router:", "    secrets:"]
    secret_names: list[tuple[str, str]] = []
    for node_id, filename in mappings:
        source = "node_broker_hmac_" + node_id.replace("-", "")
        secret_names.append((source, filename))
        lines.extend([
            f"      - source: {source}",
            f"        target: node_{node_id}",
        ])
    lines.append("secrets:")
    for source, filename in secret_names:
        lines.extend([
            f"  {source}:",
            f"    file: ./env/{filename}",
        ])
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--node",
        action="append",
        required=True,
        type=parse_mapping,
        help="registered NODE_UUID=secret filename under env/; repeat for every added node",
    )
    args = parser.parse_args()
    print(render(args.node), end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
