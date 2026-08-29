#!/usr/bin/env python3
"""Return a bounded, project-scoped index of saved Codex sessions."""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import pathlib
import re
import sys
from typing import Any


SESSION_ID_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)
PROJECT_DIRECTORY_RE = re.compile(r"^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$")
MAX_CANDIDATES = 200
MAX_SCAN_BYTES = 2 * 1024 * 1024
MAX_LINE_BYTES = 1024 * 1024
MAX_TITLE_CHARS = 120
HIDDEN_USER_PREFIXES = (
    "<environment_context>",
    "<permissions instructions>",
    "<recommended_plugins>",
    "<app-context>",
    "<skills_instructions>",
)


def normalize_title(value: str) -> str:
    cleaned = "".join(
        " " if ord(character) < 32 or ord(character) == 127 else character
        for character in value
    ).strip()
    cleaned = " ".join(cleaned.split())
    if len(cleaned) > MAX_TITLE_CHARS:
        return cleaned[: MAX_TITLE_CHARS - 1].rstrip() + "…"
    return cleaned


def message_text(payload: dict[str, Any]) -> str:
    content = payload.get("content")
    if not isinstance(content, list):
        return ""
    parts: list[str] = []
    for block in content:
        if not isinstance(block, dict) or block.get("type") not in {"input_text", "text"}:
            continue
        text = block.get("text")
        if isinstance(text, str):
            parts.append(text)
    return normalize_title(" ".join(parts))


def visible_user_title(payload: dict[str, Any]) -> str:
    if payload.get("type") != "message" or payload.get("role") != "user":
        return ""
    title = message_text(payload)
    if not title or title.startswith(HIDDEN_USER_PREFIXES):
        return ""
    return title


def iso_timestamp(timestamp: float) -> str:
    return dt.datetime.fromtimestamp(timestamp, tz=dt.timezone.utc).isoformat().replace("+00:00", "Z")


def read_session(path: pathlib.Path, expected_cwd: str) -> dict[str, str] | None:
    try:
        stat = path.stat()
        if path.is_symlink() or not path.is_file():
            return None
        scanned = 0
        session_id = ""
        started_at = ""
        title = ""
        with path.open("rb") as stream:
            while True:
                raw_line = stream.readline(MAX_LINE_BYTES + 1)
                if not raw_line:
                    break
                scanned += len(raw_line)
                if len(raw_line) > MAX_LINE_BYTES or scanned > MAX_SCAN_BYTES:
                    break
                try:
                    item = json.loads(raw_line)
                except (UnicodeDecodeError, json.JSONDecodeError):
                    continue
                if not isinstance(item, dict):
                    continue
                payload = item.get("payload")
                if not isinstance(payload, dict):
                    continue
                if item.get("type") == "session_meta":
                    if payload.get("cwd") != expected_cwd:
                        return None
                    candidate = str(payload.get("id") or payload.get("session_id") or "").lower()
                    if not SESSION_ID_RE.fullmatch(candidate):
                        return None
                    session_id = candidate
                    timestamp = payload.get("timestamp")
                    started_at = timestamp if isinstance(timestamp, str) else ""
                    continue
                if session_id and not title:
                    title = visible_user_title(payload)
                if session_id and title:
                    break
        if not session_id:
            return None
        return {
            "id": session_id,
            "title": title or f"Session {session_id[:8]}",
            "started_at": started_at or iso_timestamp(stat.st_ctime),
            "updated_at": iso_timestamp(stat.st_mtime),
        }
    except OSError:
        return None


def list_sessions(codex_home: pathlib.Path, project_directory: str, limit: int) -> list[dict[str, str]]:
    if not PROJECT_DIRECTORY_RE.fullmatch(project_directory) or ".." in project_directory:
        raise ValueError("invalid_project_directory")
    if limit < 1 or limit > 50:
        raise ValueError("invalid_limit")
    root = codex_home / "sessions"
    if root.is_symlink() or not root.is_dir():
        return []
    try:
        candidates = [
            path for path in root.rglob("*.jsonl")
            if not path.is_symlink() and path.is_file()
        ]
        candidates.sort(key=lambda path: path.stat().st_mtime, reverse=True)
    except OSError:
        return []
    expected_cwd = f"/workspace/{project_directory}"
    sessions: list[dict[str, str]] = []
    for path in candidates[:MAX_CANDIDATES]:
        session = read_session(path, expected_cwd)
        if session is not None:
            sessions.append(session)
        if len(sessions) >= limit:
            break
    sessions.sort(key=lambda session: session["updated_at"], reverse=True)
    return sessions


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--project", required=True)
    parser.add_argument("--limit", type=int, default=50)
    args = parser.parse_args()
    codex_home = pathlib.Path(os.environ.get("CODEX_HOME", "/home/codex/.codex"))
    try:
        sessions = list_sessions(codex_home, args.project, args.limit)
    except ValueError as exc:
        print(json.dumps({"error": str(exc)}, separators=(",", ":")))
        return 2
    print(json.dumps({"sessions": sessions}, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
