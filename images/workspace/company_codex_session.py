#!/usr/bin/env python3
"""Keep the shared-company terminal constrained to the routed Codex UI."""

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
SESSION_ID_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)


def codex_command() -> list[str]:
    mode = os.environ.get("MOVIE_CODEX_SESSION_MODE", "new")
    session_id = os.environ.get("MOVIE_CODEX_SESSION_ID", "").lower()
    if mode == "new" and not session_id:
        return [CODEX, "--profile", "movie"]
    # Company credentials currently share one CODEX_HOME. Do not expose its
    # cross-user history until authentication and per-user session state split.
    if mode == "resume" and SESSION_ID_RE.fullmatch(session_id):
        raise RuntimeError("Company Codex history is unavailable")
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


def main() -> int:
    project = pathlib.Path("/workspace") / os.environ.get("MOVIE_PROJECT_DIRECTORY", "")
    if not project.is_dir():
        print("The selected Workspace project is unavailable.", file=sys.stderr)
        return 1

    try:
        router = start_router()
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    try:
        command = codex_command()
        print(
            "Starting Codex. Use /model to select Qwen, DeepSeek, or an OpenAI model.",
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
