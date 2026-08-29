#!/usr/bin/env python3
"""Reservation-token router for independently controlled Movie AI workers."""

from __future__ import annotations

import hashlib
import hmac
import http.client
import ipaddress
import json
import os
import pathlib
import re
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
TOKEN_RE = re.compile(r"^[A-Za-z0-9]{64,160}$")
MAX_BODY_BYTES = 40 * 1024 * 1024
STATE_PATH = pathlib.Path(os.environ.get("MOVIE_AI_ROUTER_STATE", "/var/lib/movie-ai-router/state.json"))
NODE_SECRET_DIR = pathlib.Path(os.environ.get("MOVIE_NODE_SECRET_DIR", "/run/secrets"))
NODE_PORT = int(os.environ.get("MOVIE_NODE_WORKER_PORT", "8080"))
NODE_HEALTH_TIMEOUT_SECONDS = max(1, min(int(os.environ.get("MOVIE_NODE_HEALTH_TIMEOUT_SECONDS", "5")), 15))
LOCAL_NODE_HOST = os.environ.get("MOVIE_LOCAL_NODE_HOST", "movie-ai-broker").strip()
ALLOWED_CIDRS = tuple(
    ipaddress.ip_network(value.strip(), strict=True)
    for value in os.environ.get("MOVIE_ALLOWED_NODE_CIDRS", "192.168.88.0/24").split(",")
    if value.strip()
)
STATE_LOCK = threading.RLock()


def read_secret(path: pathlib.Path) -> bytes:
    value = path.read_bytes().strip()
    if len(value) < 32:
        raise ValueError("invalid_secret")
    return value


CONTROL_SECRET = read_secret(pathlib.Path(os.environ.get("MOVIE_AI_ROUTER_SECRET_FILE", "")))


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


def default_state() -> dict[str, Any]:
    return {"claims": {}}


def load_state() -> dict[str, Any]:
    try:
        state = json.loads(STATE_PATH.read_text(encoding="utf-8"))
        if isinstance(state, dict) and isinstance(state.get("claims"), dict):
            return state
    except (OSError, ValueError):
        pass
    return default_state()


def save_state(state: dict[str, Any]) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    temporary = STATE_PATH.with_suffix(".tmp")
    temporary.write_text(json.dumps(state, separators=(",", ":")), encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(STATE_PATH)


def validated_node_url(value: Any) -> str:
    parsed = urllib.parse.urlsplit(str(value))
    if parsed.scheme != "http" or parsed.username or parsed.password or parsed.path not in {"", "/"} \
            or parsed.query or parsed.fragment or parsed.port != NODE_PORT:
        raise ValueError("invalid_node_url")
    hostname = (parsed.hostname or "").lower()
    if hostname != LOCAL_NODE_HOST:
        try:
            address = ipaddress.ip_address(hostname)
        except ValueError as exc:
            raise ValueError("invalid_node_host") from exc
        if address.version != 4 or not any(address in network for network in ALLOWED_CIDRS):
            raise ValueError("node_host_not_allowed")
    return f"http://{hostname}:{NODE_PORT}"


def node_secret(node_id: str) -> bytes:
    if not UUID_RE.fullmatch(node_id):
        raise ValueError("invalid_compute_node")
    return read_secret(NODE_SECRET_DIR / f"node_{node_id}")


def signed_node_request(node_id: str, node_url: str, path: str, payload: dict[str, Any]) -> tuple[int, bytes]:
    raw = json.dumps(payload, separators=(",", ":")).encode()
    timestamp = str(int(time.time()))
    signature = hmac.new(
        node_secret(node_id),
        b"\n".join([timestamp.encode(), b"POST", path.encode(), raw]),
        hashlib.sha256,
    ).hexdigest()
    parsed = urllib.parse.urlsplit(node_url)
    connection = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=15)
    try:
        connection.request("POST", path, body=raw, headers={
            "Content-Type": "application/json",
            "X-Movie-Timestamp": timestamp,
            "X-Movie-Signature": signature,
        })
        response = connection.getresponse()
        return response.status, response.read(64 * 1024)
    finally:
        connection.close()


def node_health_request(node_url: str) -> dict[str, Any]:
    parsed = urllib.parse.urlsplit(node_url)
    connection = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=NODE_HEALTH_TIMEOUT_SECONDS)
    try:
        connection.request("GET", "/healthz", headers={"Accept": "application/json"})
        response = connection.getresponse()
        raw = response.read(64 * 1024)
        if response.status != 200:
            raise RuntimeError("selected_compute_node_unavailable")
        payload = json.loads(raw)
        if not isinstance(payload, dict):
            raise RuntimeError("invalid_worker_health")
        return payload
    finally:
        connection.close()


def active_claim(token: str) -> dict[str, Any] | None:
    if not TOKEN_RE.fullmatch(token):
        return None
    digest = token_hash(token)
    now = int(time.time())
    with STATE_LOCK:
        state = load_state()
        claims = state["claims"]
        expired = [key for key, claim in claims.items() if int(claim.get("expires_at", 0)) <= now]
        for key in expired:
            claims.pop(key, None)
        if expired:
            save_state(state)
        claim = claims.get(digest)
    return claim if isinstance(claim, dict) and hmac.compare_digest(str(claim.get("token_hash", "")), digest) else None


class Handler(BaseHTTPRequestHandler):
    server_version = "movie-ai-router/1"

    def log_message(self, fmt: str, *args: object) -> None:
        print(f"ai-router {self.command} {self.path.split('?', 1)[0]} {args[1] if len(args) > 1 else '-'}", flush=True)

    def body(self) -> bytes:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > MAX_BODY_BYTES:
            raise ValueError("invalid_body_size")
        return self.rfile.read(length)

    def respond(self, status: int, payload: dict[str, Any]) -> None:
        raw = json.dumps(payload, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def control_authorized(self, raw: bytes) -> bool:
        timestamp = self.headers.get("X-Movie-Timestamp", "")
        signature = self.headers.get("X-Movie-Signature", "")
        try:
            stamp = int(timestamp)
        except ValueError:
            return False
        if abs(time.time() - stamp) > 30:
            return False
        expected = hmac.new(
            CONTROL_SECRET,
            b"\n".join([timestamp.encode(), self.command.encode(), self.path.encode(), raw]),
            hashlib.sha256,
        ).hexdigest()
        return hmac.compare_digest(expected, signature)

    def register(self, data: dict[str, Any]) -> None:
        reservation_id = str(data.get("reservation_id", "")).lower()
        node_id = str(data.get("compute_node_id", "")).lower()
        token = str(data.get("token", ""))
        expires_at = int(data.get("expires_at", 0))
        if not UUID_RE.fullmatch(reservation_id) or not UUID_RE.fullmatch(node_id):
            raise ValueError("invalid_registration_identity")
        if not TOKEN_RE.fullmatch(token) or expires_at <= int(time.time()) or expires_at > int(time.time()) + 9 * 60 * 60:
            raise ValueError("invalid_registration_lease")
        node_url = validated_node_url(data.pop("node_url", None))
        status, response = signed_node_request(node_id, node_url, "/internal/register", data)
        if status != 200:
            try:
                error = str(json.loads(response).get("error", "selected_compute_node_unavailable"))
            except (ValueError, AttributeError):
                error = "selected_compute_node_unavailable"
            raise RuntimeError(error)
        digest = token_hash(token)
        with STATE_LOCK:
            state = load_state()
            state["claims"][digest] = {
                "token_hash": digest,
                "reservation_id": reservation_id,
                "compute_node_id": node_id,
                "node_url": node_url,
                "expires_at": expires_at,
            }
            save_state(state)

    def revoke(self, data: dict[str, Any]) -> None:
        reservation_id = str(data.get("reservation_id", "")).lower()
        node_id = str(data.get("compute_node_id", "")).lower()
        if not UUID_RE.fullmatch(reservation_id) or not UUID_RE.fullmatch(node_id):
            raise ValueError("invalid_revoke_identity")
        with STATE_LOCK:
            state = load_state()
            matches = [
                (key, claim) for key, claim in state["claims"].items()
                if claim.get("reservation_id") == reservation_id and claim.get("compute_node_id") == node_id
            ]
        if not matches:
            return
        key, claim = matches[0]
        status, response = signed_node_request(node_id, str(claim["node_url"]), "/internal/revoke", data)
        if status != 200:
            try:
                error = str(json.loads(response).get("error", "selected_compute_node_unavailable"))
            except (ValueError, AttributeError):
                error = "selected_compute_node_unavailable"
            raise RuntimeError(error)
        with STATE_LOCK:
            state = load_state()
            state["claims"].pop(key, None)
            save_state(state)

    def node_health(self, data: dict[str, Any]) -> dict[str, Any]:
        node_id = str(data.get("compute_node_id", "")).lower()
        if not UUID_RE.fullmatch(node_id):
            raise ValueError("invalid_compute_node")
        node_secret(node_id)
        node_url = validated_node_url(data.get("node_url"))
        return node_health_request(node_url)

    def control(self, raw: bytes) -> None:
        if not self.control_authorized(raw):
            self.respond(403, {"error": "forbidden"})
            return
        try:
            data = json.loads(raw or b"{}")
            if not isinstance(data, dict):
                raise ValueError("invalid_payload")
            if self.path == "/internal/register":
                self.register(data)
                self.respond(200, {"registered": True, "mode": "router"})
            elif self.path == "/internal/revoke":
                self.revoke(data)
                self.respond(200, {"revoked": True, "mode": "router"})
            elif self.path == "/internal/node-health":
                self.respond(200, self.node_health(data))
            else:
                self.respond(404, {"error": "not_found"})
        except ValueError as exc:
            self.respond(422, {"error": str(exc)})
        except (OSError, RuntimeError, http.client.HTTPException):
            self.respond(503, {"error": "selected_compute_node_unavailable"})

    def proxy(self, raw: bytes = b"") -> None:
        authorization = self.headers.get("Authorization", "")
        claim = active_claim(authorization[7:]) if authorization.startswith("Bearer ") else None
        if claim is None:
            self.respond(401, {"error": "invalid_or_expired_token"})
            return
        parsed = urllib.parse.urlsplit(str(claim["node_url"]))
        connection = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=310)
        response_started = False
        headers = {
            "Authorization": authorization,
            "Accept": self.headers.get("Accept", "application/json"),
        }
        for name in ("Content-Type", "X-Movie-Filename", "X-Movie-Sha256"):
            if self.headers.get(name):
                headers[name] = self.headers[name]
        try:
            connection.request(self.command, self.path, body=raw if self.command == "POST" else None, headers=headers)
            upstream = connection.getresponse()
            self.send_response(upstream.status)
            response_started = True
            for name in ("Content-Type", "Content-Length", "Content-Disposition", "Cache-Control"):
                value = upstream.getheader(name)
                if value:
                    self.send_header(name, value)
            self.send_header("Connection", "close")
            self.end_headers()
            while True:
                chunk = upstream.read(64 * 1024)
                if not chunk:
                    break
                self.wfile.write(chunk)
        except (OSError, http.client.HTTPException):
            if not response_started and not self.wfile.closed:
                self.respond(503, {"error": "selected_compute_node_unavailable"})
        finally:
            connection.close()
            self.close_connection = True

    def do_GET(self) -> None:
        if self.path == "/healthz":
            self.respond(200, {"ok": True, "mode": "router"})
            return
        self.proxy()

    def do_POST(self) -> None:
        try:
            raw = self.body()
        except (ValueError, TypeError):
            self.respond(413, {"error": "invalid_body_size"})
            return
        if self.path.startswith("/internal/"):
            self.control(raw)
            return
        self.proxy(raw)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
