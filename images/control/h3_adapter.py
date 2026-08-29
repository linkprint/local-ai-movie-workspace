#!/usr/bin/env python3
"""Fixed-path relay from the internal Broker network to host ComfyUI."""

from __future__ import annotations

import base64
import json
import os
import pathlib
import re
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


UPSTREAM = os.environ.get(
    "MOVIE_COMFY_UPSTREAM", "http://192.168.88.20:8188"
).rstrip("/")
STYLE_UPSTREAM = os.environ.get("MOVIE_STYLE_UPSTREAM", "https://render.example.com").rstrip("/")
STYLE_BASIC_AUTH_FILE = pathlib.Path(os.environ.get(
    "MOVIE_STYLE_BASIC_AUTH_FILE", "/run/secrets/movie_style_basic_credentials"
))
PROMPT_ID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
STYLE_TASK_RE = re.compile(r"^movie-style-[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
SAFE_FILE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._ -]{0,254}$")
SAFE_SUBFOLDER_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,511}$")
MAX_JSON_BYTES = 512 * 1024
MAX_UPLOAD_BYTES = (32 * 1024 * 1024) + (64 * 1024)
MAX_ARTIFACT_BYTES = 8 * 1024 * 1024 * 1024


class Handler(BaseHTTPRequestHandler):
    server_version = "movie-h3-adapter/1"

    def log_message(self, fmt: str, *args: object) -> None:
        print(f"h3-adapter {self.command} {self.path.split('?', 1)[0]} {args[1] if len(args) > 1 else '-'}", flush=True)

    def json_response(self, status: int, body: dict) -> None:
        raw = json.dumps(body, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def body(self, maximum: int) -> bytes:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > maximum:
            raise ValueError("invalid_body_size")
        return self.rfile.read(length)

    def upstream(self, method: str, path: str, body: bytes | None = None, content_type: str | None = None):
        headers = {}
        if content_type:
            headers["Content-Type"] = content_type
        request = urllib.request.Request(UPSTREAM + path, data=body, method=method, headers=headers)
        return urllib.request.urlopen(request, timeout=240)

    def style_upstream(self, method: str, path: str, body: bytes | None = None):
        credentials = STYLE_BASIC_AUTH_FILE.read_bytes().strip()
        if b":" not in credentials or len(credentials) > 4096 or b"\n" in credentials or b"\r" in credentials:
            raise ValueError("invalid_style_credentials")
        headers = {
            "Authorization": "Basic " + base64.b64encode(credentials).decode("ascii"),
            "Accept": "application/json, image/*",
            "User-Agent": "MovieAI-Style-Adapter/1.0",
        }
        if body is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(STYLE_UPSTREAM + path, data=body, method=method, headers=headers)
        return urllib.request.urlopen(request, timeout=600)

    def relay_json(self, method: str, path: str, body: bytes | None = None, content_type: str | None = None) -> None:
        try:
            with self.upstream(method, path, body, content_type) as response:
                raw = response.read(MAX_JSON_BYTES + 1)
                if len(raw) > MAX_JSON_BYTES:
                    raise ValueError("upstream_response_too_large")
                status = response.status
                upstream_type = response.headers.get("Content-Type", "application/json")
        except urllib.error.HTTPError as error:
            raw = error.read(MAX_JSON_BYTES + 1)
            status = error.code
            upstream_type = error.headers.get("Content-Type", "application/json")
        except (OSError, urllib.error.URLError):
            self.json_response(503, {"error": "comfyui_unavailable"})
            return
        self.send_response(status)
        self.send_header("Content-Type", upstream_type)
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def relay_style_json(self, method: str, path: str, body: bytes | None = None) -> None:
        try:
            with self.style_upstream(method, path, body) as response:
                raw = response.read(MAX_JSON_BYTES + 1)
                status = response.status
                upstream_type = response.headers.get("Content-Type", "application/json")
        except urllib.error.HTTPError as error:
            raw = error.read(MAX_JSON_BYTES + 1)
            status = error.code
            upstream_type = error.headers.get("Content-Type", "application/json")
        except (OSError, ValueError, urllib.error.URLError):
            self.json_response(503, {"error": "style_service_unavailable"})
            return
        if len(raw) > MAX_JSON_BYTES:
            self.json_response(502, {"error": "upstream_response_too_large"})
            return
        self.send_response(status)
        self.send_header("Content-Type", upstream_type)
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def relay_style_artifact(self, task_id: str) -> None:
        try:
            response = self.style_upstream("GET", f"/api/movie-style/jobs/{task_id}/artifact")
        except urllib.error.HTTPError as error:
            self.json_response(error.code, {"error": "style_artifact_unavailable"})
            return
        except (OSError, ValueError, urllib.error.URLError):
            self.json_response(503, {"error": "style_service_unavailable"})
            return
        length_header = response.headers.get("Content-Length")
        length = int(length_header) if length_header and length_header.isdigit() else 0
        if length > MAX_ARTIFACT_BYTES:
            response.close()
            self.json_response(502, {"error": "invalid_artifact_size"})
            return
        self.send_response(200)
        self.send_header("Content-Type", response.headers.get("Content-Type", "application/octet-stream"))
        if length:
            self.send_header("Content-Length", str(length))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        size = 0
        try:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                if size > MAX_ARTIFACT_BYTES:
                    break
                self.wfile.write(chunk)
        finally:
            response.close()

    def do_GET(self) -> None:
        parsed = urllib.parse.urlsplit(self.path)
        if parsed.path == "/healthz":
            self.json_response(200, {
                "ok": True,
                "upstream": UPSTREAM,
                "style_upstream": STYLE_UPSTREAM,
                "style_configured": STYLE_BASIC_AUTH_FILE.is_file(),
            })
            return
        if parsed.path == "/style/models" and not parsed.query:
            self.relay_style_json("GET", "/api/movie-style/models")
            return
        if parsed.path.startswith("/style/jobs/") and not parsed.query:
            suffix = parsed.path.removeprefix("/style/jobs/")
            artifact = suffix.endswith("/artifact")
            task_id = suffix.removesuffix("/artifact") if artifact else suffix
            if not STYLE_TASK_RE.fullmatch(task_id):
                self.json_response(422, {"error": "invalid_style_task_id"})
                return
            if artifact:
                self.relay_style_artifact(task_id)
            else:
                self.relay_style_json("GET", f"/api/movie-style/jobs/{task_id}")
            return
        if parsed.path == "/comfy/system_stats" and not parsed.query:
            self.relay_json("GET", "/system_stats")
            return
        if parsed.path == "/comfy/queue" and not parsed.query:
            self.relay_json("GET", "/queue")
            return
        if parsed.path.startswith("/comfy/history/") and not parsed.query:
            prompt_id = parsed.path.removeprefix("/comfy/history/").lower()
            if not PROMPT_ID_RE.fullmatch(prompt_id):
                self.json_response(422, {"error": "invalid_prompt_id"})
                return
            self.relay_json("GET", f"/history/{prompt_id}")
            return
        if parsed.path == "/comfy/view":
            query = urllib.parse.parse_qs(parsed.query, keep_blank_values=True)
            if set(query) != {"filename", "subfolder", "type"}:
                self.json_response(422, {"error": "invalid_artifact_query"})
                return
            filename = query["filename"][0]
            subfolder = query["subfolder"][0]
            file_type = query["type"][0]
            if not SAFE_FILE_RE.fullmatch(filename):
                self.json_response(422, {"error": "invalid_filename"})
                return
            if subfolder and (not SAFE_SUBFOLDER_RE.fullmatch(subfolder) or ".." in subfolder.split("/")):
                self.json_response(422, {"error": "invalid_subfolder"})
                return
            if file_type != "output":
                self.json_response(422, {"error": "invalid_artifact_type"})
                return
            upstream_query = urllib.parse.urlencode({
                "filename": filename,
                "subfolder": subfolder,
                "type": file_type,
            })
            try:
                response = self.upstream("GET", "/view?" + upstream_query)
            except urllib.error.HTTPError as error:
                self.json_response(error.code, {"error": "artifact_unavailable"})
                return
            except (OSError, urllib.error.URLError):
                self.json_response(503, {"error": "comfyui_unavailable"})
                return
            length = int(response.headers.get("Content-Length", "0"))
            if length <= 0 or length > MAX_ARTIFACT_BYTES:
                response.close()
                self.json_response(502, {"error": "invalid_artifact_size"})
                return
            self.send_response(200)
            self.send_header("Content-Type", response.headers.get("Content-Type", "application/octet-stream"))
            self.send_header("Content-Length", str(length))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            try:
                while True:
                    chunk = response.read(1024 * 1024)
                    if not chunk:
                        break
                    self.wfile.write(chunk)
            finally:
                response.close()
            return
        self.json_response(404, {"error": "not_found"})

    def do_POST(self) -> None:
        parsed = urllib.parse.urlsplit(self.path)
        if parsed.query:
            self.json_response(404, {"error": "not_found"})
            return
        try:
            if parsed.path == "/comfy/prompt":
                body = self.body(MAX_JSON_BYTES)
                json.loads(body)
                self.relay_json("POST", "/prompt", body, "application/json")
                return
            if parsed.path == "/comfy/interrupt":
                body = self.body(1024)
                self.relay_json("POST", "/interrupt", body or b"{}", "application/json")
                return
            if parsed.path == "/comfy/upload/image":
                body = self.body(MAX_UPLOAD_BYTES)
                content_type = self.headers.get("Content-Type", "")
                if not content_type.startswith("multipart/form-data; boundary="):
                    raise ValueError("invalid_upload_content_type")
                self.relay_json("POST", "/upload/image", body, content_type)
                return
            if parsed.path == "/style/jobs":
                body = self.body(MAX_JSON_BYTES)
                parsed_body = json.loads(body)
                if not isinstance(parsed_body, dict):
                    raise ValueError("invalid_style_spec")
                self.relay_style_json("POST", "/api/movie-style/jobs", body)
                return
        except (ValueError, json.JSONDecodeError) as exc:
            self.json_response(422, {"error": str(exc)})
            return
        self.json_response(404, {"error": "not_found"})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
