#!/usr/bin/env python3
"""Require public Portal URLs whenever a Codex response mentions local images."""

from __future__ import annotations

import json
import os
import re
import sys


IMAGE_EXTENSION = r"(?:gif|jpe?g|png|webp)"
LOCAL_IMAGE_RE = re.compile(
    rf"(?<![A-Za-z0-9])((?:/workspace|/outputs|assets|uploads|outputs)/[^\s`\"'<>\]\[(){{}}]+\.{IMAGE_EXTENSION})(?=$|[\s`\"'<>\]\[(){{}},;:!?])",
    re.IGNORECASE,
)
BACKTICK_IMAGE_RE = re.compile(
    rf"`((?:/workspace|/outputs|assets|uploads|outputs)/[^`\r\n]+\.{IMAGE_EXTENSION})`",
    re.IGNORECASE,
)
PORTAL_IMAGE_BASE = os.environ.get(
    "MOVIE_VIDEO_BASE_URL", "https://movie.example.com/workspace/projects"
).rstrip("/")
PORTAL_IMAGE_RE = re.compile(
    rf"{re.escape(PORTAL_IMAGE_BASE)}/[0-9a-f-]{{36}}/images/[^\s`\"'<>\]\[(){{}}]+\.{IMAGE_EXTENSION}",
    re.IGNORECASE,
)


def response(payload: dict) -> dict:
    if payload.get("stop_hook_active"):
        return {}

    message = payload.get("last_assistant_message")
    if not isinstance(message, str) or not message.strip():
        return {}

    local_images = sorted(
        {
            *LOCAL_IMAGE_RE.findall(message),
            *BACKTICK_IMAGE_RE.findall(message),
        }
    )
    portal_images = set(PORTAL_IMAGE_RE.findall(message))
    if not local_images or len(portal_images) >= len(local_images):
        return {}

    paths = "\n".join(f"- {path}" for path in local_images[:20])
    return {
        "decision": "block",
        "reason": (
            "Your response contains local image paths but does not include one absolute "
            "Movie AI Workspace Portal image URL for every image. Publish each existing "
            "image already under /outputs with `movie-ai image url PATH`; otherwise use "
            "`movie-ai image publish PATH --link-source`. Use the returned `url` values in "
            "the user-facing answer, and then finish the answer again. Do not claim that "
            "public image URLs are unavailable. Local image paths detected:\n"
            f"{paths}"
        ),
    }


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, OSError):
        payload = {}
    print(json.dumps(response(payload), ensure_ascii=False, separators=(",", ":")))


if __name__ == "__main__":
    main()
