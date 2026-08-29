#!/usr/bin/env python3
"""End-to-end Codex Responses smoke test against the Movie Qwen broker path."""

from __future__ import annotations

import argparse
import importlib.util
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import uuid
from http.server import ThreadingHTTPServer


def copy_stream(source: socket.socket, destination: socket.socket) -> None:
    try:
        while True:
            chunk = source.recv(65536)
            if not chunk:
                break
            destination.sendall(chunk)
    except OSError:
        pass
    finally:
        try:
            destination.shutdown(socket.SHUT_WR)
        except OSError:
            pass


def relay_connection(client: socket.socket, upstream_host: str, upstream_port: int) -> None:
    upstream = socket.create_connection((upstream_host, upstream_port), timeout=10)
    upstream.settimeout(None)
    client.settimeout(None)
    forward = threading.Thread(target=copy_stream, args=(client, upstream), daemon=True)
    reverse = threading.Thread(target=copy_stream, args=(upstream, client), daemon=True)
    forward.start()
    reverse.start()
    forward.join()
    reverse.join()
    client.close()
    upstream.close()


def run_relay(path: pathlib.Path, upstream_host: str, upstream_port: int, stop: threading.Event) -> None:
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    listener.bind(str(path))
    listener.listen(8)
    listener.settimeout(0.2)
    try:
        while not stop.is_set():
            try:
                client, _ = listener.accept()
            except TimeoutError:
                continue
            threading.Thread(
                target=relay_connection,
                args=(client, upstream_host, upstream_port),
                daemon=True,
            ).start()
    finally:
        listener.close()


def load_broker(root: pathlib.Path, secret_dir: pathlib.Path):
    broker_secret = secret_dir / "broker"
    manager_secret = secret_dir / "manager"
    broker_secret.write_text("b" * 64, encoding="ascii")
    manager_secret.write_text("m" * 64, encoding="ascii")
    os.environ["MOVIE_BROKER_SECRET_FILE"] = str(broker_secret)
    os.environ["MOVIE_BROKER_MANAGER_SECRET_FILE"] = str(manager_secret)
    spec = importlib.util.spec_from_file_location("movie_qwen_smoke_broker", root / "images/control/broker.py")
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def load_router(root: pathlib.Path, token: str, broker_port: int):
    os.environ["MOVIE_AI_TOKEN"] = token
    os.environ["MOVIE_AI_BROKER_URL"] = f"http://127.0.0.1:{broker_port}/v1"
    spec = importlib.util.spec_from_file_location(
        "movie_qwen_smoke_router", root / "images/workspace/codex_model_router.py"
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--upstream-host", default="192.168.88.105")
    parser.add_argument("--upstream-port", type=int, default=8000)
    parser.add_argument("--codex", default="codex")
    parser.add_argument(
        "--routed",
        action="store_true",
        help="use the current Codex login and the mixed OpenAI/Qwen loopback router",
    )
    args = parser.parse_args()

    root = pathlib.Path(__file__).resolve().parents[2]
    token = "movie-qwen-smoke-token-" + ("x" * 40)
    claims = {
        "reservation_id": str(uuid.uuid4()),
        "user_id": str(uuid.uuid4()),
        "expires_at": int(time.time()) + 900,
    }

    with tempfile.TemporaryDirectory(prefix="movie-qwen-smoke-") as directory:
        temp = pathlib.Path(directory)
        socket_path = temp / "qwen.sock"
        broker = load_broker(root, temp)
        broker.QWEN_SOCKET_PATH = str(socket_path)
        broker.active_claims_for_token = lambda value: claims if value == token else None

        stop = threading.Event()
        relay = threading.Thread(
            target=run_relay,
            args=(socket_path, args.upstream_host, args.upstream_port, stop),
            daemon=True,
        )
        relay.start()
        for _ in range(50):
            if socket_path.exists():
                break
            time.sleep(0.02)

        server = ThreadingHTTPServer(("127.0.0.1", 0), broker.Handler)
        port = server.server_address[1]
        serving = threading.Thread(target=server.serve_forever, daemon=True)
        serving.start()

        router_server = None
        if args.routed:
            router = load_router(root, token, port)
            router_server = ThreadingHTTPServer(("127.0.0.1", 0), router.RouterHandler)
            router_port = router_server.server_address[1]
            threading.Thread(target=router_server.serve_forever, daemon=True).start()

        codex_home = temp / "codex-home"
        skills = codex_home / "skills"
        skills.mkdir(parents=True)
        shutil.copytree(root / "images/workspace/admin-skills/h3-prompt-writing", skills / "h3-prompt-writing")
        (codex_home / "qwen.config.toml").write_text(
            "\n".join([
                f'model = "{broker.QWEN_MODEL}"',
                'model_provider = "movie_qwen"',
                'approval_policy = "never"',
                'sandbox_mode = "read-only"',
                'model_reasoning_effort = "xhigh"',
                '',
                '[model_providers.movie_qwen]',
                'name = "Movie Qwen smoke"',
                f'base_url = "http://127.0.0.1:{port}/v1"',
                'env_key = "MOVIE_AI_TOKEN"',
                'wire_api = "responses"',
                'requires_openai_auth = false',
                'request_max_retries = 0',
                'stream_max_retries = 0',
                '',
            ]),
            encoding="utf-8",
        )
        project = temp / "project"
        project.mkdir()
        if args.routed:
            project_skills = project / ".agents" / "skills"
            project_skills.mkdir(parents=True)
            shutil.copytree(
                root / "images/workspace/admin-skills/h3-prompt-writing",
                project_skills / "h3-prompt-writing",
            )
        environment = os.environ.copy()
        environment["MOVIE_AI_TOKEN"] = token
        if not args.routed:
            environment["CODEX_HOME"] = str(codex_home)
        prompt = (
            "Use $h3-prompt-writing and its base-mode reference. Write a 4-second T2VA prompt "
            "with one Chinese dialogue line. Return the required H3 fields and no commentary."
        )
        if args.routed:
            command = [
                args.codex,
                "exec",
                "--ephemeral",
                "--skip-git-repo-check",
                "--color",
                "never",
                "-m",
                router.QWEN_MODEL,
                "-c",
                'model_provider="movie_router"',
                "-c",
                (
                    'model_providers.movie_router={ name="Movie Router", '
                    f'base_url="http://127.0.0.1:{router_port}/v1", '
                    'wire_api="responses", requires_openai_auth=true, '
                    'supports_websockets=false, supports_standalone_web_search=true, '
                    'request_max_retries=0, stream_max_retries=0 }'
                ),
                "-c",
                'model_reasoning_effort="xhigh"',
                "-c",
                "features.enable_request_compression=false",
                prompt,
            ]
        else:
            command = [
                args.codex,
                "exec",
                "--profile",
                "qwen",
                "--skip-git-repo-check",
                "--color",
                "never",
                prompt,
            ]
        try:
            result = subprocess.run(
                command,
                cwd=project,
                env=environment,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                timeout=600,
                check=False,
            )
        finally:
            if router_server is not None:
                router_server.shutdown()
                router_server.server_close()
            server.shutdown()
            server.server_close()
            stop.set()

        print(result.stdout)
        if result.returncode != 0:
            return result.returncode or 1
        required = ("integrated_multimodal_description", "overall_soundscape", "non_diegetic_music")
        if not all(value in result.stdout for value in required):
            print("QWEN_H3_SKILL_SMOKE=FAIL")
            return 1
        print("QWEN_H3_SKILL_SMOKE=PASS")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
