#!/usr/bin/env python3
"""Loopback-only router that adds approved local models to Codex's picker."""

from __future__ import annotations

import copy
import http.client
import json
import os
import pathlib
import re
import select
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
from urllib.parse import urlsplit


LISTEN_HOST = "127.0.0.1"
LISTEN_PORT = int(os.environ.get("MOVIE_CODEX_ROUTER_PORT", "8765"))
BROKER_BASE_URL = os.environ.get(
    "MOVIE_AI_BROKER_URL", "http://movie-ai-broker:8080/v1"
).rstrip("/")
GRANT_FILE = pathlib.Path("/run/movie/ai-grant/grant.json")
RUNTIME_ID = os.environ.get("MOVIE_RUNTIME_ID", "")
RUNTIME_GENERATION = int(os.environ.get("MOVIE_RUNTIME_GENERATION", "0"))
UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
TOKEN_RE = re.compile(r"^[A-Za-z0-9._~-]{32,2048}$")
QWEN_MODEL = "qwen3.8-27b-uncensored"
QWEN_UPSTREAM_MODELS = {
    QWEN_MODEL,
    "qwen3.8-27b",
    "qwen3.8-27b-huihui-abliterated-nvfp4",
    "qwen3.8-27b-huihui-abliterated",
    "qwen3.8-27b-uncensored-nvfp4",
}
DEEPSEEK_MODEL = "deepseek-v4-flash-0731"
DEEPSEEK_UPSTREAM_MODELS = {
    DEEPSEEK_MODEL,
}
LOCAL_UPSTREAM_MODELS = QWEN_UPSTREAM_MODELS | DEEPSEEK_UPSTREAM_MODELS
CHATGPT_HOST = "chatgpt.com"
CHATGPT_BASE_PATH = "/backend-api/codex"
OPENAI_HOST = "api.openai.com"
OPENAI_BASE_PATH = "/v1"
MAX_REQUEST_BYTES = 32 * 1024 * 1024
HOP_BY_HOP_HEADERS = {
    "connection",
    "content-length",
    "host",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
}


def local_model_entry(
    models: list[dict[str, Any]],
    *,
    slug: str,
    display_name: str,
    description: str,
    comp_hash: str,
) -> dict[str, Any] | None:
    base = next((item for item in models if item.get("slug") == "gpt-5.5"), None)
    if base is None:
        base = next((item for item in models if item.get("visibility") == "list"), None)
    if base is None:
        return None

    model = copy.deepcopy(base)
    model.update(
        {
            "slug": slug,
            "display_name": display_name,
            "description": description,
            "default_reasoning_level": "xhigh",
            "supported_reasoning_levels": [
                {"effort": "low", "description": "Fast local reasoning"},
                {"effort": "medium", "description": "Balanced local reasoning"},
                {"effort": "xhigh", "description": "Deepest supported local reasoning"},
            ],
            "visibility": "list",
            "supported_in_api": True,
            "priority": 4,
            "availability_nux": None,
            "upgrade": None,
            "additional_speed_tiers": [],
            "service_tiers": [],
            "default_service_tier": None,
            "prefer_websockets": False,
            "support_verbosity": False,
            "default_verbosity": None,
            "input_modalities": ["text"],
            "supports_image_detail_original": False,
            "supports_search_tool": False,
            "include_skills_usage_instructions": True,
            "include_plugin_usage_instructions": False,
            "include_apps_usage_instructions": False,
            "context_window": 240000,
            "max_context_window": 240000,
            "auto_compact_token_limit": 200000,
            "comp_hash": comp_hash,
            "multi_agent_version": None,
        }
    )
    return model


def append_local_models_to_catalog(raw: bytes) -> bytes:
    payload = json.loads(raw)
    models = payload.get("models")
    if not isinstance(models, list):
        raise ValueError("invalid_models_catalog")
    source_models = [item for item in models if isinstance(item, dict)]
    configured = {item.get("slug") for item in source_models}
    entries = (
        (
            QWEN_MODEL,
            "Qwen 3.8 27B Uncensored (Local)",
            "Private Qwen endpoint for agent work and bounded Movie AI workflows.",
            "movie-qwen3.8-27b-v1",
        ),
        (
            DEEPSEEK_MODEL,
            "DeepSeek V4 Flash 0731 Uncensored (External)",
            "Private or external Responses-compatible DeepSeek endpoint routed through the Broker.",
            "movie-deepseek-v4-flash-0731-v1",
        ),
    )
    for slug, display_name, description, comp_hash in entries:
        if slug in configured:
            continue
        entry = local_model_entry(
            source_models,
            slug=slug,
            display_name=display_name,
            description=description,
            comp_hash=comp_hash,
        )
        if entry is None:
            raise ValueError("empty_models_catalog")
        if slug == DEEPSEEK_MODEL:
            entry.update({
                "priority": 5,
                "context_window": 500000,
                "max_context_window": 500000,
                "auto_compact_token_limit": 450000,
            })
        models.append(entry)
    return json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def append_qwen_to_catalog(raw: bytes) -> bytes:
    """Backward-compatible name for callers from the Qwen-only release."""

    return append_local_models_to_catalog(raw)


def active_grant() -> dict[str, Any] | None:
    try:
        grant = json.loads(GRANT_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError):
        return None
    if not isinstance(grant, dict) or grant.get("enabled") is not True:
        return None
    if grant.get("runtime_id") != RUNTIME_ID or int(grant.get("generation", 0)) != RUNTIME_GENERATION:
        return None
    if not UUID_RE.fullmatch(str(grant.get("reservation_id", ""))):
        return None
    if int(grant.get("expires_at", 0)) <= int(time.time()):
        return None
    if not TOKEN_RE.fullmatch(str(grant.get("token", ""))):
        return None
    return grant


def request_model(raw: bytes) -> str | None:
    try:
        payload = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError):
        return None
    model = payload.get("model") if isinstance(payload, dict) else None
    return model if isinstance(model, str) else None


def normalized_path(path: str) -> str:
    parsed = urlsplit(path)
    normalized = parsed.path
    if normalized == "/v1":
        normalized = ""
    elif normalized.startswith("/v1/"):
        normalized = normalized[3:]
    if not normalized.startswith("/"):
        normalized = "/" + normalized
    if parsed.query:
        normalized += "?" + parsed.query
    return normalized


def uses_api_key(headers: Any) -> bool:
    authorization = headers.get("Authorization", "")
    return authorization.startswith("Bearer sk-")


def openai_connection(host: str) -> http.client.HTTPSConnection:
    """Create an OpenAI TLS connection through the workspace egress proxy."""

    raw_proxy = (
        os.environ.get("MOVIE_CODEX_HTTPS_PROXY")
        or os.environ.get("HTTPS_PROXY")
        or os.environ.get("https_proxy")
    )
    if not raw_proxy:
        return http.client.HTTPSConnection(host, 443, timeout=310)

    proxy = urlsplit(raw_proxy)
    if proxy.scheme != "http" or not proxy.hostname or proxy.username or proxy.password:
        raise ValueError("movie_egress_proxy_invalid")

    connection = http.client.HTTPSConnection(
        proxy.hostname, proxy.port or 80, timeout=310
    )
    connection.set_tunnel(host, 443)
    return connection


def broker_upstream_path(base_path: str, request_path: str) -> str:
    """Map the shared broker origin to its OpenAI-compatible `/v1` surface."""

    prefix = base_path.rstrip("/")
    if not prefix:
        prefix = "/v1"
    return prefix + request_path


class ClientDisconnectCancellation:
    """Close an in-flight upstream as soon as the local Codex client disappears."""

    def __init__(self, client: socket.socket) -> None:
        self.client = client
        self.cancelled = threading.Event()
        self.stopped = threading.Event()
        self.lock = threading.Lock()
        self.connection: http.client.HTTPConnection | None = None
        self.response: http.client.HTTPResponse | None = None
        self.thread = threading.Thread(
            target=self._watch,
            name="movie-codex-client-watch",
            daemon=True,
        )

    def attach_connection(self, connection: http.client.HTTPConnection) -> None:
        with self.lock:
            self.connection = connection
            cancelled = self.cancelled.is_set()
        if cancelled:
            connection.close()

    def attach_response(self, response: http.client.HTTPResponse) -> None:
        with self.lock:
            self.response = response
            cancelled = self.cancelled.is_set()
        if cancelled:
            response.close()

    def start(self) -> None:
        self.thread.start()

    def stop(self) -> None:
        self.stopped.set()
        self.thread.join(timeout=1)

    def _cancel(self) -> None:
        if self.cancelled.is_set():
            return
        self.cancelled.set()
        with self.lock:
            response = self.response
            connection = self.connection
        if response is not None:
            response.close()
        if connection is not None:
            connection.close()

    def _watch(self) -> None:
        while not self.stopped.is_set():
            try:
                readable, _, exceptional = select.select(
                    [self.client], [], [self.client], 0.25
                )
                if exceptional:
                    self._cancel()
                    return
                if not readable:
                    continue
                # The request body has already been consumed and the response
                # forces Connection: close. Readable data is therefore either
                # peer EOF or an unsupported pipelined request.
                if self.client.recv(1, socket.MSG_PEEK) == b"":
                    self._cancel()
                return
            except OSError:
                if not self.stopped.is_set():
                    self._cancel()
                return


class RouterHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "movie-codex-router/1"

    def log_message(self, format: str, *args: object) -> None:
        # Never log request headers or bodies; either may contain Codex credentials.
        print(f"movie-codex-router: {format % args}", file=sys.stderr, flush=True)

    def send_json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def read_body(self) -> bytes:
        transfer_encoding = self.headers.get("Transfer-Encoding", "").lower()
        if "chunked" in transfer_encoding:
            body = bytearray()
            while True:
                line = self.rfile.readline(128)
                if not line:
                    raise ValueError("invalid_chunked_body")
                try:
                    size = int(line.split(b";", 1)[0].strip(), 16)
                except ValueError as exc:
                    raise ValueError("invalid_chunk_size") from exc
                if size == 0:
                    while trailer := self.rfile.readline(8192):
                        if trailer in {b"\r\n", b"\n"}:
                            break
                    break
                if len(body) + size > MAX_REQUEST_BYTES:
                    raise ValueError("request_too_large")
                chunk = self.rfile.read(size)
                if len(chunk) != size or self.rfile.read(2) != b"\r\n":
                    raise ValueError("invalid_chunked_body")
                body.extend(chunk)
            return bytes(body)

        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError as exc:
            raise ValueError("invalid_content_length") from exc
        if length < 0 or length > MAX_REQUEST_BYTES:
            raise ValueError("request_too_large")
        return self.rfile.read(length)

    def upstream_headers(self, *, local_model: bool, body: bytes, grant: dict[str, Any] | None) -> dict[str, str]:
        if local_model:
            if grant is None:
                raise ValueError("local_ai_reservation_required")
            return {
                "Authorization": f"Bearer {grant['token']}",
                "Accept": self.headers.get("Accept", "text/event-stream"),
                "Content-Type": self.headers.get("Content-Type", "application/json"),
                "Content-Length": str(len(body)),
            }

        headers: dict[str, str] = {}
        for name, value in self.headers.items():
            if name.lower() in HOP_BY_HOP_HEADERS or name.lower() == "accept-encoding":
                continue
            headers[name] = value
        if body:
            headers["Content-Length"] = str(len(body))
        return headers

    def proxy(self, method: str) -> None:
        response_started = False
        try:
            body = self.read_body() if method in {"POST", "PUT", "PATCH"} else b""
        except ValueError as exc:
            self.send_json(413, {"error": str(exc)})
            return

        model = request_model(body)
        normalized_model = model.rsplit("/", 1)[-1] if model else None
        routing_hint = self.headers.get("x-codex-routing-hint", "")
        if normalized_model is None and routing_hint.startswith("model="):
            normalized_model = routing_hint.split(";", 1)[0][6:]
        local_model = normalized_model in LOCAL_UPSTREAM_MODELS
        local_route = (
            "qwen" if normalized_model in QWEN_UPSTREAM_MODELS
            else "deepseek" if normalized_model in DEEPSEEK_UPSTREAM_MODELS
            else "openai"
        )
        grant = active_grant()
        print(
            (
                f"movie-codex-router: model={normalized_model or '-'} "
                f"route={local_route} bytes={len(body)} "
                f"transfer={self.headers.get('Transfer-Encoding', '-') }"
            ),
            file=sys.stderr,
            flush=True,
        )
        if local_model and grant is None:
            self.send_json(403, {"error": "local_ai_reservation_required"})
            return

        path = normalized_path(self.path)
        connection: http.client.HTTPConnection
        if local_model:
            broker = urlsplit(BROKER_BASE_URL)
            if broker.scheme != "http" or not broker.hostname:
                self.send_json(503, {"error": "movie_ai_broker_invalid"})
                return
            connection = http.client.HTTPConnection(
                broker.hostname, broker.port or 80, timeout=310
            )
            upstream_path = broker_upstream_path(broker.path, path)
        else:
            api_key = uses_api_key(self.headers)
            try:
                connection = openai_connection(
                    OPENAI_HOST if api_key else CHATGPT_HOST
                )
            except ValueError as exc:
                self.send_json(503, {"error": str(exc)})
                return
            upstream_path = (OPENAI_BASE_PATH if api_key else CHATGPT_BASE_PATH) + path

        cancellation = ClientDisconnectCancellation(self.connection)
        cancellation.attach_connection(connection)
        cancellation.start()
        try:
            connection.request(
                method,
                upstream_path,
                body=body or None,
                headers=self.upstream_headers(local_model=local_model, body=body, grant=grant),
            )
            response = connection.getresponse()
            cancellation.attach_response(response)
            response_body: bytes | None = None
            is_models = method == "GET" and path.split("?", 1)[0] == "/models"
            if is_models and response.status == 200:
                raw_models = response.read()
                response_body = append_local_models_to_catalog(raw_models) if grant is not None else raw_models

            self.send_response(response.status, response.reason)
            for name, value in response.getheaders():
                lowered = name.lower()
                if lowered in HOP_BY_HOP_HEADERS:
                    continue
                if response_body is not None and lowered in {
                    "content-encoding",
                    "content-length",
                    "etag",
                }:
                    continue
                self.send_header(name, value)
            if response_body is not None:
                self.send_header("Content-Length", str(len(response_body)))
            self.send_header("Connection", "close")
            self.end_headers()
            response_started = True

            if response_body is not None:
                self.wfile.write(response_body)
            else:
                reader = getattr(response, "read1", response.read)
                while chunk := reader(65536):
                    self.wfile.write(chunk)
                    self.wfile.flush()
        except (
            AttributeError,
            OSError,
            http.client.HTTPException,
            ValueError,
            json.JSONDecodeError,
        ) as exc:
            if (
                not response_started
                and not self.wfile.closed
                and not cancellation.cancelled.is_set()
            ):
                try:
                    self.send_json(502, {"error": str(exc)[:160] or "upstream_unavailable"})
                except OSError:
                    pass
        finally:
            cancellation.stop()
            connection.close()
            self.close_connection = True

    def do_GET(self) -> None:
        if self.path == "/healthz":
            self.send_json(200, {
                "ok": True,
                "local_ai_enabled": active_grant() is not None,
                "local_models": [QWEN_MODEL, DEEPSEEK_MODEL],
            })
            return
        self.proxy("GET")

    def do_POST(self) -> None:
        self.proxy("POST")


def main() -> int:
    if sys.argv[1:] == ["--build-catalog"]:
        try:
            raw = sys.stdin.buffer.read()
            sys.stdout.buffer.write(append_local_models_to_catalog(raw) if active_grant() is not None else raw)
        except (ValueError, json.JSONDecodeError) as exc:
            print(f"unable to build Movie model catalog: {exc}", file=sys.stderr)
            return 1
        return 0
    if len(sys.argv) > 1:
        print("usage: movie-codex-model-router [--build-catalog]", file=sys.stderr)
        return 2
    server = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), RouterHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
