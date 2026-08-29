#!/usr/bin/env python3
"""Narrow Worker-side bridge from a node Broker to fixed host GPU controls."""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import pathlib
import re
import secrets
import socket
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
SOCKET_PATH = os.environ.get("MOVIE_H3_CONTROL_SOCKET", "/run/movie-h3-control/control.sock")
MAX_BODY_BYTES = 8192


def read_secret(name: str) -> bytes:
    value = pathlib.Path(os.environ.get(name, "")).read_bytes().strip()
    if len(value) < 32:
        raise SystemExit(f"{name} must contain at least 32 bytes")
    return value


BROKER_SECRET = read_secret("MOVIE_NODE_CONTROL_SECRET_FILE")
HOST_SECRET = read_secret("MOVIE_H3_CONTROL_SECRET_FILE")


def host_control_request(action: str) -> dict[str, Any]:
    if action not in {"status", "prepare_h3", "prepare_image"}:
        raise AssertionError("host control action is not fixed")
    timestamp = int(time.time())
    nonce = secrets.token_urlsafe(32)
    signature = hmac.new(
        HOST_SECRET,
        f"{timestamp}\n{nonce}\n{action}".encode(),
        hashlib.sha256,
    ).hexdigest()
    request = json.dumps({
        "timestamp": timestamp,
        "nonce": nonce,
        "action": action,
        "signature": signature,
    }, separators=(",", ":")).encode() + b"\n"
    connection = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    connection.settimeout(240)
    try:
        connection.connect(SOCKET_PATH)
        connection.sendall(request)
        chunks = bytearray()
        while len(chunks) <= 1024 * 1024:
            chunk = connection.recv(65536)
            if not chunk:
                break
            chunks.extend(chunk)
            if b"\n" in chunk:
                break
    finally:
        connection.close()
    if len(chunks) > 1024 * 1024 or b"\n" not in chunks:
        raise RuntimeError("host_control_invalid_response")
    response = json.loads(bytes(chunks).split(b"\n", 1)[0])
    if not isinstance(response, dict) or not response.get("ok") or not isinstance(response.get("result"), dict):
        raise RuntimeError(str(response.get("error", "host_control_failed")) if isinstance(response, dict) else "host_control_invalid_response")
    return response["result"]


class Handler(BaseHTTPRequestHandler):
    server_version = "movie-node-control/1"

    def log_message(self, fmt: str, *args: object) -> None:
        print(f"node-control {self.command} {self.path.split('?', 1)[0]} {args[1] if len(args) > 1 else '-'}", flush=True)

    def respond(self, status: int, payload: dict[str, Any]) -> None:
        raw = json.dumps(payload, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def body(self) -> bytes:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > MAX_BODY_BYTES:
            raise ValueError("invalid_body_size")
        return self.rfile.read(length)

    def authorized(self, raw: bytes) -> bool:
        timestamp = self.headers.get("X-Movie-Timestamp", "")
        signature = self.headers.get("X-Movie-Signature", "")
        try:
            stamp = int(timestamp)
        except ValueError:
            return False
        if abs(time.time() - stamp) > 30:
            return False
        expected = hmac.new(
            BROKER_SECRET,
            b"\n".join([timestamp.encode(), self.command.encode(), self.path.encode(), raw]),
            hashlib.sha256,
        ).hexdigest()
        return hmac.compare_digest(expected, signature)

    def do_GET(self) -> None:
        if self.path == "/healthz":
            try:
                status = host_control_request("status")
                self.respond(200, {"ok": True, "gpu": status.get("gpu", {}), "services": status.get("services", {})})
            except Exception:
                self.respond(503, {"ok": False})
            return
        if not self.path.startswith("/v2/ai/status?") or not self.authorized(b""):
            self.respond(403, {"error": "forbidden"})
            return
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
        for field in ("reservation_id", "runtime_id", "user_id"):
            if not UUID_RE.fullmatch(str(query.get(field, [""])[0]).lower()):
                self.respond(422, {"error": f"invalid_{field}"})
                return
        try:
            self.respond(200, host_control_request("status"))
        except Exception:
            self.respond(503, {"error": "host_control_unavailable"})

    def do_POST(self) -> None:
        try:
            raw = self.body()
        except (ValueError, TypeError):
            self.respond(413, {"error": "invalid_body_size"})
            return
        if self.path != "/v2/ai/prepare" or not self.authorized(raw):
            self.respond(403, {"error": "forbidden"})
            return
        try:
            data = json.loads(raw or b"{}")
            for field in ("reservation_id", "runtime_id", "user_id"):
                if not UUID_RE.fullmatch(str(data.get(field, "")).lower()):
                    raise ValueError(f"invalid_{field}")
            if int(data.get("generation", 0)) < 1:
                raise ValueError("invalid_generation")
            action = {
                "h3.generate": "prepare_h3",
                "image.generate": "prepare_image",
            }.get(str(data.get("capability", "")))
            if action is None:
                raise ValueError("unsupported_capability")
            self.respond(200, host_control_request(action))
        except ValueError as exc:
            self.respond(422, {"error": str(exc)})
        except Exception:
            self.respond(503, {"error": "host_control_unavailable"})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
