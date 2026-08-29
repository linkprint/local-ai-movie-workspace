#!/usr/bin/env python3
"""Start a personal Codex session without exposing a pre-Codex shell."""

from __future__ import annotations

import os
import pathlib
import re
import socket
import subprocess
import sys
import time


CODEX = "/usr/local/bin/codex"
ROUTER = "/usr/local/bin/movie-codex-model-router"
ROUTER_PORT = 8765
WORKSPACE = pathlib.Path("/workspace")
SESSION_ID_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)


def codex_command() -> list[str]:
    mode = os.environ.get("MOVIE_CODEX_SESSION_MODE", "new")
    session_id = os.environ.get("MOVIE_CODEX_SESSION_ID", "").lower()
    if mode == "new" and not session_id:
        return [CODEX, "--profile", "movie"]
    if mode == "resume" and SESSION_ID_RE.fullmatch(session_id):
        return [CODEX, "resume", session_id, "--profile", "movie"]
    raise RuntimeError("Codex session selection is invalid")


def start_router() -> subprocess.Popen[bytes]:
    process = subprocess.Popen(
        [ROUTER], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
    )
    for _ in range(50):
        if process.poll() is not None:
            raise RuntimeError("Codex model router failed to start")
        try:
            with socket.create_connection(("127.0.0.1", ROUTER_PORT), timeout=0.1):
                return process
        except OSError:
            time.sleep(0.1)
    process.terminate()
    raise RuntimeError("Codex model router did not become ready")


def is_logged_in(project: pathlib.Path) -> bool:
    result = subprocess.run(
        [CODEX, "login", "status"],
        cwd=project,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    return result.returncode == 0


def main() -> int:
    project = WORKSPACE / os.environ.get("MOVIE_PROJECT_DIRECTORY", "")
    if not project.is_dir():
        print("The selected Workspace project is unavailable.", file=sys.stderr)
        return 1

    if not is_logged_in(project):
        print(
            "Personal Codex login is required. Starting the secure device-code flow...",
            flush=True,
        )
        login = subprocess.run(
            [CODEX, "login", "--device-auth"],
            cwd=project,
            check=False,
        )
        if login.returncode != 0 or not is_logged_in(project):
            print("Codex login did not complete. Reload to try again.", file=sys.stderr)
            return 1

    try:
        router = start_router()
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    try:
        command = codex_command()
        print(
            "Resuming Codex. Use /model to select Qwen, DeepSeek, or an OpenAI model."
            if command[1] == "resume"
            else "Starting Codex. Use /model to select Qwen, DeepSeek, or an OpenAI model.",
            flush=True,
        )
        return subprocess.run(
            command, cwd=project, check=False
        ).returncode
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    finally:
        router.terminate()
        try:
            router.wait(timeout=5)
        except subprocess.TimeoutExpired:
            router.kill()


if __name__ == "__main__":
    raise SystemExit(main())
