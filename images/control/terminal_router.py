#!/usr/bin/env python3
"""Authenticated per-runtime HTTP/WebSocket router for Workspace ttyd."""

from __future__ import annotations

import hashlib
import hmac
import http.client
import json
import os
import pathlib
import re
import select
import socket
import sys
import time
from base64 import urlsafe_b64decode
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
LISTEN_HOST = os.environ.get("MOVIE_TERMINAL_ROUTER_LISTEN", "172.30.20.10")
LISTEN_PORT = int(os.environ.get("MOVIE_TERMINAL_ROUTER_PORT", "8080"))
SECRET_FILE = pathlib.Path(os.environ.get("MOVIE_TERMINAL_ROUTER_SECRET_FILE", "/run/secrets/router_hmac_secret"))
ROUTE_HEADER = "X-Movie-Route"
MAX_BODY_BYTES = 64 * 1024 * 1024
HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
}
PRIVATE_HEADERS = {"authorization", "cookie", "proxy-authorization", ROUTE_HEADER.lower()}


def read_secret() -> bytes:
    value = SECRET_FILE.read_bytes().strip()
    if len(value) < 32:
        raise SystemExit("terminal router secret must contain at least 32 bytes")
    return value


HMAC_SECRET = read_secret()


def decode_claim(value: str) -> dict[str, Any]:
    try:
        encoded, supplied = value.split(".", 1)
        expected = hmac.new(HMAC_SECRET, encoded.encode("ascii"), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, supplied):
            raise ValueError("route_signature_invalid")
        padded = encoded + "=" * (-len(encoded) % 4)
        payload = json.loads(urlsafe_b64decode(padded.encode("ascii")))
    except (UnicodeError, ValueError, json.JSONDecodeError) as exc:
        raise ValueError("route_claim_invalid") from exc
    if not isinstance(payload, dict):
        raise ValueError("route_claim_invalid")
    now = int(time.time())
    runtime_id = str(payload.get("runtime_id", "")).lower()
    user_id = str(payload.get("sub", "")).lower()
    generation = payload.get("generation")
    expires_at = payload.get("exp")
    nonce = str(payload.get("nonce", ""))
    if payload.get("aud") != "movie-terminal-router":
        raise ValueError("route_audience_invalid")
    if not UUID_RE.fullmatch(runtime_id) or not UUID_RE.fullmatch(user_id):
        raise ValueError("route_identity_invalid")
    if not isinstance(generation, int) or generation < 1:
        raise ValueError("route_generation_invalid")
    if not isinstance(expires_at, int) or expires_at < now or expires_at > now + 30:
        raise ValueError("route_expired")
    if len(nonce) < 24 or len(nonce) > 128:
        raise ValueError("route_nonce_invalid")
    return payload


def upstream_host(claim: dict[str, Any]) -> str:
    return f"movie-ws-{claim['runtime_id']}"


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "movie-terminal-router/1"

    def log_message(self, fmt: str, *args: object) -> None:
        status = args[1] if len(args) > 1 else "-"
        print(f"terminal-router {self.command} {self.path.split('?', 1)[0]} {status}", file=sys.stderr, flush=True)

    def respond(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def claim(self) -> dict[str, Any]:
        value = self.headers.get(ROUTE_HEADER, "")
        if not value or len(value) > 4096:
            raise ValueError("route_claim_missing")
        return decode_claim(value)

    def request_headers(self, *, websocket: bool = False) -> dict[str, str]:
        headers: dict[str, str] = {}
        for name, value in self.headers.items():
            lowered = name.lower()
            if lowered in PRIVATE_HEADERS or lowered == "host":
                continue
            if not websocket and lowered in HOP_BY_HOP:
                continue
            headers[name] = value
        headers["Host"] = "movie-workspace"
        headers["X-Forwarded-Proto"] = "https"
        return headers

    def proxy_http(self, claim: dict[str, Any]) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > MAX_BODY_BYTES:
            self.respond(413, {"error": "request_too_large"})
            return
        body = self.rfile.read(length) if length else None
        connection = http.client.HTTPConnection(upstream_host(claim), 7681, timeout=310)
        try:
            connection.request(self.command, self.path, body=body, headers=self.request_headers())
            response = connection.getresponse()
            payload = response.read()
            self.send_response(response.status, response.reason)
            for name, value in response.getheaders():
                lowered = name.lower()
                if lowered in HOP_BY_HOP or lowered == "content-length":
                    continue
                self.send_header(name, value)
            self.send_header("Content-Length", str(len(payload)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(payload)
        finally:
            connection.close()
        self.close_connection = True

    def proxy_websocket(self, claim: dict[str, Any]) -> None:
        upstream = socket.create_connection((upstream_host(claim), 7681), timeout=15)
        try:
            request = [f"{self.command} {self.path} HTTP/1.1\r\n"]
            for name, value in self.request_headers(websocket=True).items():
                request.append(f"{name}: {value}\r\n")
            request.append("\r\n")
            upstream.sendall("".join(request).encode("latin-1"))
            handshake = bytearray()
            while b"\r\n\r\n" not in handshake:
                chunk = upstream.recv(4096)
                if not chunk:
                    raise ConnectionError("upstream_closed_during_handshake")
                handshake.extend(chunk)
                if len(handshake) > 65536:
                    raise ConnectionError("upstream_handshake_too_large")
            self.connection.sendall(handshake)
            self.connection.setblocking(False)
            upstream.setblocking(False)
            sockets = [self.connection, upstream]
            while True:
                readable, _, exceptional = select.select(sockets, [], sockets, 60)
                if exceptional:
                    return
                if not readable:
                    continue
                for source in readable:
                    try:
                        data = source.recv(65536)
                    except BlockingIOError:
                        continue
                    if not data:
                        return
                    target = upstream if source is self.connection else self.connection
                    target.sendall(data)
        finally:
            upstream.close()
            self.close_connection = True

    def handle_request(self) -> None:
        if self.path == "/healthz":
            self.respond(200, {"ok": True})
            return
        if not (self.path == "/terminal" or self.path.startswith("/terminal/")):
            self.respond(404, {"error": "not_found"})
            return
        try:
            claim = self.claim()
            websocket = self.headers.get("Upgrade", "").lower() == "websocket"
            if websocket:
                self.proxy_websocket(claim)
            else:
                self.proxy_http(claim)
        except ValueError as exc:
            self.respond(403, {"error": str(exc)})
        except (ConnectionError, OSError, http.client.HTTPException) as exc:
            print(f"terminal-router upstream error: {type(exc).__name__}", file=sys.stderr, flush=True)
            if not self.wfile.closed:
                try:
                    self.respond(502, {"error": "workspace_unavailable"})
                except OSError:
                    pass

    do_GET = handle_request
    do_POST = handle_request
    do_PUT = handle_request
    do_PATCH = handle_request
    do_DELETE = handle_request
    do_OPTIONS = handle_request


if __name__ == "__main__":
    ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), Handler).serve_forever()
