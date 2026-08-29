#!/usr/bin/env python3
"""Fixed-template Workspace Manager for movie.example.com.

Only this service holds the Docker socket. Request fields can select an opaque
database-issued storage UUID, a reservation UUID, and strictly validated
email-root/project path components. They cannot select images, mount sources,
networks, devices, capabilities, commands, or container names.
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import http.client
import io
import json
import os
import pathlib
import re
import secrets
import socket
import struct
import tarfile
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
TOKEN_RE = re.compile(r"^[A-Za-z0-9._~-]{32,2048}$")
WORKSPACE_ROOT_RE = re.compile(r"^[a-z0-9._%+\-]+@[a-z0-9.-]+$")
PROJECT_DIRECTORY_RE = re.compile(r"^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$")
MEDIA_FILENAME_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:gif|jpg|png|webp|mp4|webm|mov|m4v)$"
)
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
MAX_IMAGE_BYTES = 20 * 1024 * 1024
MAX_MEDIA_BYTES = 32 * 1024 * 1024
MAX_MEDIA_REQUEST_BYTES = ((MAX_MEDIA_BYTES + 2) // 3 * 4) + (64 * 1024)
MEDIA_EXTENSIONS = {
    "image/gif": {"gif"},
    "image/jpeg": {"jpg"},
    "image/png": {"png"},
    "image/webp": {"webp"},
    "video/mp4": {"mp4", "m4v"},
    "video/x-m4v": {"m4v"},
    "video/webm": {"webm"},
    "video/quicktime": {"mov"},
}
ACTIVE_CONTAINER = "movie-active-workspace"
WORKSPACE_IMAGE = os.environ.get("MOVIE_WORKSPACE_IMAGE", "movie-portal-workspace:gate3")
WORKSPACE_SECURITY_REVISION = os.environ.get("MOVIE_WORKSPACE_SECURITY_REVISION", "1")
AUTH_MODES = {"personal", "company"}
SESSION_MODES = {"new", "resume"}
SESSION_INDEX_COMMAND = "/usr/local/bin/movie-codex-session-index"
CODEX_COMMAND = "/usr/local/bin/codex"
COMPANY_CODEX_VOLUME = os.environ.get("MOVIE_COMPANY_CODEX_VOLUME", "movie_company_codex_auth")
APPARMOR_PROFILE = os.environ.get("MOVIE_WORKSPACE_APPARMOR", "movie-workspace-bwrap")
SECCOMP_PROFILE_PATH = pathlib.Path(os.environ.get(
    "MOVIE_WORKSPACE_SECCOMP",
    "/usr/local/share/movie-workspace/seccomp.json",
))
STATE_PATH = pathlib.Path("/var/lib/movie-manager/state.json")
NETWORKS = ("movie_terminal", "movie_broker", "movie_egress_client")
TERMINAL_ROUTER_CONTAINER = os.environ.get("MOVIE_TERMINAL_ROUTER_CONTAINER", "movie-terminal-router")
EGRESS_CONTAINER = os.environ.get("MOVIE_EGRESS_CONTAINER", "movie-egress")
MAX_CONCURRENT_WORKSPACES = int(os.environ.get("MOVIE_MAX_CONCURRENT_WORKSPACES", "3"))
RUNTIME_MEMORY_BYTES = int(os.environ.get("MOVIE_WORKSPACE_MEMORY_BYTES", str(4 * 1024 * 1024 * 1024)))
RUNTIME_NANO_CPUS = int(os.environ.get("MOVIE_WORKSPACE_NANO_CPUS", "2000000000"))
RUNTIME_PIDS_LIMIT = int(os.environ.get("MOVIE_WORKSPACE_PIDS_LIMIT", "512"))
RUNTIME_NETWORK_PREFIX = "movie_ws_"
RUNTIME_CONTAINER_PREFIX = "movie-ws-"
RUNTIME_GRANT_PREFIX = "movie_grant_"
RUNTIME_DEADLINE_PREFIX = "movie_runtime_deadline_"
RUNTIME_LABEL = "com.linkprint.movie.runtime-id"
USER_LABEL = "com.linkprint.movie.user-id"
GENERATION_LABEL = "com.linkprint.movie.generation"
SECURITY_REVISION_LABEL = "com.linkprint.movie.security-revision"
OUTPUTS_VOLUME = os.environ.get("MOVIE_OUTPUTS_VOLUME", "movie_portal_outputs")
VIDEO_BASE_URL = os.environ.get(
    "MOVIE_VIDEO_BASE_URL",
    "https://movie.example.com/workspace/projects",
).rstrip("/")
SOCKET_PATH = "/var/run/docker.sock"
H3_CONTROL_SOCKET = os.environ.get("MOVIE_H3_CONTROL_SOCKET", "/run/movie-h3-control/control.sock")


def read_seccomp_profile() -> str:
    try:
        profile = json.loads(SECCOMP_PROFILE_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise SystemExit("MOVIE_WORKSPACE_SECCOMP must be a valid readable JSON profile") from exc
    if profile.get("defaultAction") != "SCMP_ACT_ERRNO" or not isinstance(profile.get("syscalls"), list):
        raise SystemExit("MOVIE_WORKSPACE_SECCOMP is not a fail-closed seccomp profile")
    return json.dumps(profile, separators=(",", ":"))


SECCOMP_PROFILE = read_seccomp_profile()
if not re.fullmatch(r"[1-9][0-9]{0,5}", WORKSPACE_SECURITY_REVISION):
    raise SystemExit("MOVIE_WORKSPACE_SECURITY_REVISION must be a positive integer")


def workspace_security_options() -> list[str]:
    options = ["no-new-privileges:true", f"apparmor={APPARMOR_PROFILE}"]
    options.append(f"seccomp={SECCOMP_PROFILE}")
    return options


def read_secret(name: str) -> bytes:
    path = pathlib.Path(os.environ.get(name, ""))
    value = path.read_bytes().strip()
    if len(value) < 32:
        raise SystemExit(f"{name} must contain at least 32 bytes")
    return value


HMAC_SECRET = read_secret("MOVIE_MANAGER_SECRET_FILE")
AI_HMAC_SECRET = read_secret("MOVIE_BROKER_MANAGER_SECRET_FILE")
H3_CONTROL_SECRET = read_secret("MOVIE_H3_CONTROL_SECRET_FILE")


class UnixHTTPConnection(http.client.HTTPConnection):
    def connect(self) -> None:
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.connect(SOCKET_PATH)


class DockerError(RuntimeError):
    def __init__(self, status: int, message: str) -> None:
        super().__init__(f"Docker API {status}: {message}")
        self.status = status


def docker_request(method: str, path: str, payload: Any = None, expected: tuple[int, ...] = (200, 201, 204)) -> Any:
    body = None if payload is None else json.dumps(payload, separators=(",", ":")).encode()
    connection = UnixHTTPConnection("localhost", timeout=20)
    headers = {"Host": "docker", "Content-Type": "application/json"}
    connection.request(method, path, body=body, headers=headers)
    response = connection.getresponse()
    raw = response.read()
    connection.close()
    if response.status not in expected:
        message = raw.decode("utf-8", errors="replace")[:500]
        raise DockerError(response.status, message)
    if not raw:
        return None
    if path == "/_ping":
        return raw.decode("ascii", errors="replace")
    return json.loads(raw)


def docker_request_bytes(
    method: str,
    path: str,
    body: bytes,
    content_type: str,
    expected: tuple[int, ...] = (200, 201, 204),
) -> bytes:
    connection = UnixHTTPConnection("localhost", timeout=30)
    headers = {
        "Host": "docker",
        "Content-Type": content_type,
        "Content-Length": str(len(body)),
    }
    connection.request(method, path, body=body, headers=headers)
    response = connection.getresponse()
    raw = response.read()
    connection.close()
    if response.status not in expected:
        message = raw.decode("utf-8", errors="replace")[:500]
        raise DockerError(response.status, message)
    return raw


def docker_request_stream(
    method: str,
    path: str,
    payload: Any,
    expected: tuple[int, ...] = (200,),
) -> bytes:
    body = json.dumps(payload, separators=(",", ":")).encode()
    connection = UnixHTTPConnection("localhost", timeout=30)
    headers = {
        "Host": "docker",
        "Content-Type": "application/json",
        "Content-Length": str(len(body)),
    }
    connection.request(method, path, body=body, headers=headers)
    response = connection.getresponse()
    raw = response.read()
    connection.close()
    if response.status not in expected:
        message = raw.decode("utf-8", errors="replace")[:500]
        raise DockerError(response.status, message)
    return raw


def decode_docker_stream(raw: bytes) -> tuple[bytes, bytes]:
    stdout = bytearray()
    stderr = bytearray()
    offset = 0
    while offset < len(raw):
        if len(raw) - offset < 8:
            raise RuntimeError("docker_exec_invalid_stream")
        stream_type = raw[offset]
        length = struct.unpack(">I", raw[offset + 4 : offset + 8])[0]
        offset += 8
        if length > len(raw) - offset:
            raise RuntimeError("docker_exec_invalid_stream")
        chunk = raw[offset : offset + length]
        offset += length
        if stream_type == 1:
            stdout.extend(chunk)
        elif stream_type == 2:
            stderr.extend(chunk)
    return bytes(stdout), bytes(stderr)


def exec_workspace_json(container_id: str, command: list[str]) -> dict[str, Any]:
    created = docker_request("POST", f"/containers/{container_id}/exec", {
        "AttachStdout": True,
        "AttachStderr": True,
        "Tty": False,
        "User": "10001:10001",
        "Cmd": command,
    })
    exec_id = str(created.get("Id", ""))
    if not re.fullmatch(r"[0-9a-f]{64}", exec_id):
        raise RuntimeError("docker_exec_invalid_id")
    raw = docker_request_stream("POST", f"/exec/{exec_id}/start", {"Detach": False, "Tty": False})
    stdout, _stderr = decode_docker_stream(raw)
    result = docker_request("GET", f"/exec/{exec_id}/json")
    if result.get("Running") or int(result.get("ExitCode", 1)) != 0:
        raise RuntimeError("session_index_failed")
    if len(stdout) > 1024 * 1024:
        raise RuntimeError("session_index_too_large")
    try:
        payload = json.loads(stdout)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("session_index_invalid") from exc
    if not isinstance(payload, dict):
        raise RuntimeError("session_index_invalid")
    return payload


def exec_workspace_command(
    container_id: str,
    command: list[str],
    error: str = "workspace_exec_failed",
) -> None:
    created = docker_request("POST", f"/containers/{container_id}/exec", {
        "AttachStdout": True,
        "AttachStderr": True,
        "Tty": False,
        "User": "10001:10001",
        "Cmd": command,
    })
    exec_id = str(created.get("Id", ""))
    if not re.fullmatch(r"[0-9a-f]{64}", exec_id):
        raise RuntimeError("docker_exec_invalid_id")
    docker_request_stream("POST", f"/exec/{exec_id}/start", {"Detach": False, "Tty": False})
    result = docker_request("GET", f"/exec/{exec_id}/json")
    if result.get("Running") or int(result.get("ExitCode", 1)) != 0:
        raise RuntimeError(error)


def host_control_request(action: str) -> dict[str, Any]:
    if action not in {"status", "prepare_h3", "prepare_image"}:
        raise AssertionError("host control action is not fixed")
    timestamp = int(time.time())
    nonce = secrets.token_urlsafe(32)
    signature = hmac.new(
        H3_CONTROL_SECRET,
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
        connection.connect(H3_CONTROL_SOCKET)
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
    if not isinstance(response, dict):
        raise RuntimeError("host_control_invalid_response")
    if not response.get("ok"):
        error = str(response.get("error", "host_control_failed"))
        if response.get("error_type") == "policy":
            raise RuntimeError(f"policy:{error}")
        raise RuntimeError(error)
    result = response.get("result")
    if not isinstance(result, dict):
        raise RuntimeError("host_control_invalid_response")
    return result


def inspect_container(name: str) -> dict[str, Any] | None:
    try:
        return docker_request("GET", f"/containers/{urllib.parse.quote(name, safe='')}/json")
    except DockerError as exc:
        if exc.status == 404:
            return None
        raise


def volume_names(storage_uuid: str) -> dict[str, str]:
    compact = storage_uuid.replace("-", "")
    return {
        "workspace": f"movie_user_{compact}_workspace",
        "outputs": f"movie_user_{compact}_outputs",
        "codex": f"movie_user_{compact}_codex",
    }


def validate_auth_mode(auth_mode: str) -> None:
    if auth_mode not in AUTH_MODES:
        raise ValueError("invalid_auth_mode")


def validate_session_selection(session_mode: str, session_id: str | None) -> None:
    if session_mode not in SESSION_MODES:
        raise ValueError("invalid_session_mode")
    if session_mode == "new" and session_id not in {None, ""}:
        raise ValueError("invalid_session_id")
    if session_mode == "resume" and (
        not isinstance(session_id, str) or not UUID_RE.fullmatch(session_id)
    ):
        raise ValueError("invalid_session_id")


def company_codex_volume() -> str:
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}", COMPANY_CODEX_VOLUME):
        raise RuntimeError("company_codex_volume_invalid")
    try:
        docker_request("GET", f"/volumes/{urllib.parse.quote(COMPANY_CODEX_VOLUME, safe='')}")
    except DockerError as exc:
        if exc.status == 404:
            raise RuntimeError("company_codex_auth_unavailable") from exc
        raise
    return COMPANY_CODEX_VOLUME


def deadline_volume(reservation_id: str) -> str:
    return f"movie_deadline_{reservation_id.replace('-', '')}"


def validate_workspace_path(workspace_root: str, project_directory: str) -> None:
    if len(workspace_root) > 254 or ".." in workspace_root or not WORKSPACE_ROOT_RE.fullmatch(workspace_root):
        raise ValueError("invalid_workspace_root")
    if not PROJECT_DIRECTORY_RE.fullmatch(project_directory) or ".." in project_directory:
        raise ValueError("invalid_project_directory")


def decode_project_media(
    filename: str,
    mime: str,
    expected_size: int,
    expected_sha256: str,
    content_base64: str,
) -> bytes:
    if not MEDIA_FILENAME_RE.fullmatch(filename):
        raise ValueError("invalid_media_filename")
    extensions = MEDIA_EXTENSIONS.get(mime)
    suffix = filename.rsplit(".", 1)[-1]
    if extensions is None or suffix not in extensions:
        raise ValueError("invalid_media_type")
    maximum = MAX_IMAGE_BYTES if mime.startswith("image/") else MAX_MEDIA_BYTES
    if expected_size < 1 or expected_size > maximum:
        raise ValueError("invalid_media_size")
    if not SHA256_RE.fullmatch(expected_sha256):
        raise ValueError("invalid_media_sha256")
    if not isinstance(content_base64, str) or len(content_base64) > ((maximum + 2) // 3 * 4):
        raise ValueError("invalid_media_content")
    try:
        contents = base64.b64decode(content_base64, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise ValueError("invalid_media_content") from exc
    if len(contents) != expected_size or hashlib.sha256(contents).hexdigest() != expected_sha256:
        raise ValueError("media_integrity_mismatch")

    signatures = {
        "image/gif": contents.startswith((b"GIF87a", b"GIF89a")),
        "image/jpeg": contents.startswith(b"\xff\xd8\xff"),
        "image/png": contents.startswith(b"\x89PNG\r\n\x1a\n"),
        "image/webp": len(contents) >= 12 and contents[:4] == b"RIFF" and contents[8:12] == b"WEBP",
        "video/mp4": len(contents) >= 12 and contents[4:8] == b"ftyp",
        "video/x-m4v": len(contents) >= 12 and contents[4:8] == b"ftyp",
        "video/webm": contents.startswith(b"\x1a\x45\xdf\xa3"),
        "video/quicktime": len(contents) >= 12 and contents[4:8] == b"ftyp",
    }
    if not signatures[mime]:
        raise ValueError("media_signature_mismatch")
    return contents


def write_project_media(
    reservation_id: str,
    storage_uuid: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    filename: str,
    mime: str,
    expected_size: int,
    expected_sha256: str,
    content_base64: str,
) -> dict[str, Any]:
    if not UUID_RE.fullmatch(reservation_id):
        raise ValueError("invalid_reservation")
    if not UUID_RE.fullmatch(storage_uuid):
        raise ValueError("invalid_storage")
    if not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_project")
    validate_workspace_path(workspace_root, project_directory)
    contents = decode_project_media(
        filename, mime, expected_size, expected_sha256, content_base64,
    )

    with STATE_LOCK:
        state = load_state()
    expected_state = {
        "reservation_id": reservation_id,
        "storage_uuid": storage_uuid,
        "workspace_root": workspace_root,
        "project_id": project_id,
        "project_directory": project_directory,
    }
    if any(str(state.get(key, "")) != value for key, value in expected_state.items()):
        raise RuntimeError("project_mismatch")
    if int(state.get("deadline_epoch", 0)) <= int(time.time()):
        raise RuntimeError("reservation_expired")

    current = inspect_container(ACTIVE_CONTAINER)
    if current is None or not current.get("State", {}).get("Running"):
        raise RuntimeError("workspace_not_running")
    labels = current.get("Config", {}).get("Labels", {})
    expected_labels = {
        "com.linkprint.movie.reservation": reservation_id,
        "com.linkprint.movie.storage": storage_uuid,
        "com.linkprint.movie.workspace-root": workspace_root,
        "com.linkprint.movie.project-id": project_id,
        "com.linkprint.movie.project-directory": project_directory,
    }
    if any(labels.get(key) != value for key, value in expected_labels.items()):
        raise RuntimeError("project_mismatch")

    now = int(time.time())
    archive = io.BytesIO()
    with tarfile.open(fileobj=archive, mode="w") as tar:
        directory = tarfile.TarInfo("uploads")
        directory.type = tarfile.DIRTYPE
        directory.mode = 0o700
        directory.uid = 10001
        directory.gid = 10001
        directory.mtime = now
        tar.addfile(directory)

        media = tarfile.TarInfo(f"uploads/{filename}")
        media.size = len(contents)
        media.mode = 0o600
        media.uid = 10001
        media.gid = 10001
        media.mtime = now
        tar.addfile(media, io.BytesIO(contents))

    target = f"/workspace/{project_directory}"
    query = urllib.parse.urlencode({"path": target})
    docker_request_bytes(
        "PUT",
        f"/containers/{urllib.parse.quote(ACTIVE_CONTAINER, safe='')}/archive?{query}",
        archive.getvalue(),
        "application/x-tar",
        expected=(200,),
    )
    relative_path = f"uploads/{filename}"
    return {
        "path": f"{target}/{relative_path}",
        "relative_path": relative_path,
        "filename": filename,
        "mime": mime,
        "size": len(contents),
        "sha256": expected_sha256,
    }


def workspace_volume_mount(volume: str, workspace_root: str) -> dict[str, Any]:
    validate_workspace_path(workspace_root, "project")
    return {
        "Type": "volume",
        "Source": volume,
        "Target": "/workspace",
        "ReadOnly": False,
        "VolumeOptions": {"NoCopy": True, "Subpath": workspace_root},
    }


def outputs_volume_mount(storage_uuid: str, project_id: str) -> dict[str, Any]:
    if not UUID_RE.fullmatch(storage_uuid) or not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_output_scope")
    return {
        "Type": "volume",
        "Source": OUTPUTS_VOLUME,
        "Target": "/outputs",
        "ReadOnly": False,
        "VolumeOptions": {
            "NoCopy": True,
            "Subpath": f"{storage_uuid}/{project_id}",
        },
    }


def ensure_volume(name: str, labels: dict[str, str]) -> None:
    try:
        docker_request("GET", f"/volumes/{urllib.parse.quote(name, safe='')}")
    except DockerError as exc:
        if exc.status != 404:
            raise
        docker_request("POST", "/volumes/create", {"Name": name, "Labels": labels})


def remove_container(name: str, force: bool = False) -> None:
    query = "?v=false&force=" + ("true" if force else "false")
    try:
        docker_request("DELETE", f"/containers/{urllib.parse.quote(name, safe='')}{query}")
    except DockerError as exc:
        if exc.status != 404:
            raise


def stop_container(name: str) -> None:
    try:
        docker_request("POST", f"/containers/{urllib.parse.quote(name, safe='')}/stop?t=60", expected=(204, 304))
    except DockerError as exc:
        if exc.status != 404:
            raise


def write_deadline(reservation_id: str, deadline: int) -> None:
    volume = deadline_volume(reservation_id)
    ensure_volume(volume, {
        "com.linkprint.movie.deadline": "true",
        "com.linkprint.movie.reservation": reservation_id,
    })
    helper = f"movie-deadline-init-{reservation_id.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/bin/sh", "-ec"],
        "Cmd": [
            "umask 077; temporary=/run/movie/deadline/.deadline.$$; "
            "trap 'rm -f \"$temporary\"' EXIT; "
            "printf '%s\\n' \"$DEADLINE_EPOCH\" > \"$temporary\"; "
            "chmod 0444 \"$temporary\"; "
            "mv -f \"$temporary\" /run/movie/deadline/deadline; "
            "trap - EXIT"
        ],
        "Env": [f"DEADLINE_EPOCH={deadline}"],
        "User": "10001:10001",
        "Labels": {"com.linkprint.movie.deadline-writer": "true"},
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 64 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [{"Type": "volume", "Source": volume, "Target": "/run/movie/deadline", "ReadOnly": False}],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("deadline writer failed")
    finally:
        remove_container(identifier, force=True)


def prepare_workspace_path(storage_uuid: str, workspace_root: str, project_directory: str) -> None:
    validate_workspace_path(workspace_root, project_directory)
    volume = volume_names(storage_uuid)["workspace"]
    helper = f"movie-workspace-init-{storage_uuid.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    script = """
import os
import pathlib
import stat

base = pathlib.Path('/workspace')
root_name = os.environ['WORKSPACE_ROOT']
project_name = os.environ['PROJECT_DIRECTORY']
root = base / root_name
root.mkdir(mode=0o700, exist_ok=True)
if root.is_symlink() or not root.is_dir():
    raise RuntimeError('workspace root is not a real directory')

legacy = [entry for entry in base.iterdir() if entry.name != root_name]
for entry in legacy:
    if (root / entry.name).exists() or (root / entry.name).is_symlink():
        raise RuntimeError('legacy workspace migration conflict')
for entry in legacy:
    entry.rename(root / entry.name)

project = root / project_name
if project.exists() or project.is_symlink():
    mode = project.lstat().st_mode
    if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
        raise RuntimeError('project path is not a real directory')
else:
    project.mkdir(mode=0o700)

os.chmod(root, 0o700)
os.chmod(project, 0o700)
"""
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/usr/bin/python3", "-c"],
        "Cmd": [script],
        "Env": [f"WORKSPACE_ROOT={workspace_root}", f"PROJECT_DIRECTORY={project_directory}"],
        "User": "10001:10001",
        "Labels": {
            "com.linkprint.movie.workspace-initializer": "true",
            "com.linkprint.movie.storage": storage_uuid,
        },
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 64 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [{
                "Type": "volume",
                "Source": volume,
                "Target": "/workspace",
                "ReadOnly": False,
            }],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("workspace path initializer failed")
    finally:
        remove_container(identifier, force=True)


def prepare_outputs_path(storage_uuid: str, project_id: str) -> None:
    """Create the stable user/project output scope and preserve legacy outputs.

    Older releases used one opaque output volume per user and did not record a
    project association.  Those files are copied, never moved, into an explicit
    `_legacy` scope so they are not silently attributed to the selected project.
    """
    if not UUID_RE.fullmatch(storage_uuid):
        raise ValueError("invalid_storage")
    if not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_project")

    legacy_volume = volume_names(storage_uuid)["outputs"]
    ensure_volume(legacy_volume, {
        "com.linkprint.movie.user-volume": "true",
        "com.linkprint.movie.storage": storage_uuid,
        "com.linkprint.movie.kind": "legacy-outputs",
    })
    ensure_volume(OUTPUTS_VOLUME, {
        "com.linkprint.movie.shared-output-store": "true",
    })

    helper = f"movie-outputs-init-{storage_uuid.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    script = r"""
import os
import pathlib
import shutil
import stat

base = pathlib.Path('/outputs')
legacy = pathlib.Path('/legacy')
storage_uuid = os.environ['STORAGE_UUID']
project_id = os.environ['PROJECT_ID']
user_root = base / storage_uuid
project_root = user_root / project_id
legacy_root = user_root / '_legacy'

for directory in (user_root, project_root, legacy_root):
    if directory.exists() or directory.is_symlink():
        mode = directory.lstat().st_mode
        if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
            raise RuntimeError('output scope is not a real directory')
    else:
        directory.mkdir(mode=0o770)
    directory.chmod(0o770)

def preserve(source: pathlib.Path, destination: pathlib.Path) -> None:
    mode = source.lstat().st_mode
    if stat.S_ISLNK(mode):
        return
    if stat.S_ISDIR(mode):
        if destination.exists() or destination.is_symlink():
            target_mode = destination.lstat().st_mode
            if stat.S_ISLNK(target_mode) or not stat.S_ISDIR(target_mode):
                return
        else:
            destination.mkdir(mode=0o770)
        destination.chmod(0o770)
        for child in source.iterdir():
            preserve(child, destination / child.name)
        return
    if not stat.S_ISREG(mode):
        return
    if not destination.exists() and not destination.is_symlink():
        shutil.copyfile(source, destination)
    if destination.is_file() and not destination.is_symlink():
        destination.chmod(0o660)

if legacy.is_dir() and not legacy.is_symlink():
    for entry in legacy.iterdir():
        preserve(entry, legacy_root / entry.name)
"""
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/usr/bin/python3", "-c"],
        "Cmd": [script],
        "Env": [f"STORAGE_UUID={storage_uuid}", f"PROJECT_ID={project_id}"],
        "User": "10001:10001",
        "Labels": {
            "com.linkprint.movie.outputs-initializer": "true",
            "com.linkprint.movie.storage": storage_uuid,
        },
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 128 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [
                {"Type": "volume", "Source": OUTPUTS_VOLUME, "Target": "/outputs", "ReadOnly": False},
                {"Type": "volume", "Source": legacy_volume, "Target": "/legacy", "ReadOnly": True},
            ],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("output scope initializer failed")
    finally:
        remove_container(identifier, force=True)


def rename_workspace_path(
    storage_uuid: str,
    workspace_root: str,
    old_directory: str,
    new_directory: str,
) -> None:
    validate_workspace_path(workspace_root, old_directory)
    validate_workspace_path(workspace_root, new_directory)
    if old_directory == new_directory:
        return
    if inspect_container(ACTIVE_CONTAINER) is not None:
        raise RuntimeError("workspace_active")

    volume = volume_names(storage_uuid)["workspace"]
    ensure_volume(volume, {
        "com.linkprint.movie.user-volume": "true",
        "com.linkprint.movie.storage": storage_uuid,
        "com.linkprint.movie.kind": "workspace",
    })
    helper = f"movie-workspace-rename-{storage_uuid.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    script = """
import os
import pathlib
import stat

base = pathlib.Path('/workspace')
root = base / os.environ['WORKSPACE_ROOT']
old = root / os.environ['OLD_DIRECTORY']
new = root / os.environ['NEW_DIRECTORY']
root.mkdir(mode=0o700, exist_ok=True)
if root.is_symlink() or not root.is_dir():
    raise RuntimeError('workspace root is not a real directory')
if old.exists() or old.is_symlink():
    mode = old.lstat().st_mode
    if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
        raise RuntimeError('old project path is not a real directory')
    if new.exists() or new.is_symlink():
        raise RuntimeError('new project path already exists')
    old.rename(new)
else:
    if new.exists() or new.is_symlink():
        raise RuntimeError('new project path already exists')
    new.mkdir(mode=0o700)
os.chmod(root, 0o700)
os.chmod(new, 0o700)
"""
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/usr/bin/python3", "-c"],
        "Cmd": [script],
        "Env": [
            f"WORKSPACE_ROOT={workspace_root}",
            f"OLD_DIRECTORY={old_directory}",
            f"NEW_DIRECTORY={new_directory}",
        ],
        "User": "10001:10001",
        "Labels": {
            "com.linkprint.movie.workspace-renamer": "true",
            "com.linkprint.movie.storage": storage_uuid,
        },
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 64 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [{
                "Type": "volume",
                "Source": volume,
                "Target": "/workspace",
                "ReadOnly": False,
            }],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("workspace project rename failed")
    finally:
        remove_container(identifier, force=True)


def move_workspace_path_to_trash(
    storage_uuid: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    direction: str,
) -> dict[str, Any]:
    validate_workspace_path(workspace_root, project_directory)
    if not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_project")
    if direction not in {"trash", "restore"}:
        raise ValueError("invalid_trash_direction")
    if inspect_container(ACTIVE_CONTAINER) is not None:
        raise RuntimeError("workspace_active")

    volume = volume_names(storage_uuid)["workspace"]
    ensure_volume(volume, {
        "com.linkprint.movie.user-volume": "true",
        "com.linkprint.movie.storage": storage_uuid,
        "com.linkprint.movie.kind": "workspace",
    })
    helper = f"movie-workspace-trash-{storage_uuid.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    script = """
import os
import pathlib
import stat

base = pathlib.Path('/workspace')
root = base / os.environ['WORKSPACE_ROOT']
project_name = os.environ['PROJECT_DIRECTORY']
project_id = os.environ['PROJECT_ID']
direction = os.environ['DIRECTION']
if direction not in {'trash', 'restore'}:
    raise RuntimeError('invalid trash direction')

root.mkdir(mode=0o700, exist_ok=True)
if root.is_symlink() or not root.is_dir():
    raise RuntimeError('workspace root is not a real directory')
trash_root = root / '.trash'
if trash_root.exists() or trash_root.is_symlink():
    mode = trash_root.lstat().st_mode
    if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
        raise RuntimeError('trash path is not a real directory')
else:
    trash_root.mkdir(mode=0o700)

project = root / project_name
trashed = trash_root / (project_id + '-' + project_name)

def require_real_directory(path, label):
    mode = path.lstat().st_mode
    if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):
        raise RuntimeError(label + ' is not a real directory')

if direction == 'trash':
    if project.exists() or project.is_symlink():
        require_real_directory(project, 'project path')
        if trashed.exists() or trashed.is_symlink():
            raise RuntimeError('trash destination already exists')
        project.rename(trashed)
    elif trashed.exists() or trashed.is_symlink():
        require_real_directory(trashed, 'trashed project path')
else:
    if trashed.exists() or trashed.is_symlink():
        require_real_directory(trashed, 'trashed project path')
        if project.exists() or project.is_symlink():
            raise RuntimeError('project restore destination already exists')
        trashed.rename(project)
    elif project.exists() or project.is_symlink():
        require_real_directory(project, 'project path')

os.chmod(root, 0o700)
os.chmod(trash_root, 0o700)
"""
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/usr/bin/python3", "-c"],
        "Cmd": [script],
        "Env": [
            f"WORKSPACE_ROOT={workspace_root}",
            f"PROJECT_ID={project_id}",
            f"PROJECT_DIRECTORY={project_directory}",
            f"DIRECTION={direction}",
        ],
        "User": "10001:10001",
        "Labels": {
            "com.linkprint.movie.workspace-trash-helper": "true",
            "com.linkprint.movie.storage": storage_uuid,
        },
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 64 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [{
                "Type": "volume",
                "Source": volume,
                "Target": "/workspace",
                "ReadOnly": False,
            }],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("workspace project trash operation failed")
    finally:
        remove_container(identifier, force=True)

    return {
        "completed": True,
        "disposition": "private_trash" if direction == "trash" else "restored",
        "trash_name": f"{project_id}-{project_directory}",
    }


def trash_workspace_path(
    storage_uuid: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
) -> dict[str, Any]:
    return move_workspace_path_to_trash(
        storage_uuid, workspace_root, project_id, project_directory, "trash",
    )


def restore_workspace_path(
    storage_uuid: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
) -> dict[str, Any]:
    return move_workspace_path_to_trash(
        storage_uuid, workspace_root, project_id, project_directory, "restore",
    )


def connect_network(network: str, container_id: str, alias: str) -> None:
    docker_request(
        "POST",
        f"/networks/{urllib.parse.quote(network, safe='')}/connect",
        {"Container": container_id, "EndpointConfig": {"Aliases": [alias]}},
    )


def runtime_container_name(runtime_id: str) -> str:
    if not UUID_RE.fullmatch(runtime_id):
        raise ValueError("invalid_runtime")
    return f"{RUNTIME_CONTAINER_PREFIX}{runtime_id}"


def runtime_network_name(runtime_id: str) -> str:
    if not UUID_RE.fullmatch(runtime_id):
        raise ValueError("invalid_runtime")
    return f"{RUNTIME_NETWORK_PREFIX}{runtime_id.replace('-', '')}"


def runtime_grant_volume(runtime_id: str) -> str:
    if not UUID_RE.fullmatch(runtime_id):
        raise ValueError("invalid_runtime")
    return f"{RUNTIME_GRANT_PREFIX}{runtime_id.replace('-', '')}"


def runtime_deadline_volume(runtime_id: str) -> str:
    if not UUID_RE.fullmatch(runtime_id):
        raise ValueError("invalid_runtime")
    return f"{RUNTIME_DEADLINE_PREFIX}{runtime_id.replace('-', '')}"


def list_runtime_containers(*, all_containers: bool = True) -> list[dict[str, Any]]:
    filters = urllib.parse.quote(json.dumps({"label": ["com.linkprint.movie.workspace-runtime=true"]}, separators=(",", ":")))
    return docker_request("GET", f"/containers/json?all={'true' if all_containers else 'false'}&filters={filters}")


def expected_workspace_image_id() -> str:
    image = docker_request("GET", f"/images/{urllib.parse.quote(WORKSPACE_IMAGE, safe='')}/json")
    identifier = str(image.get("Id", ""))
    if not identifier.startswith("sha256:"):
        raise RuntimeError("workspace_image_unavailable")
    return identifier


def inspect_runtime(
    runtime_id: str,
    *,
    require_current_security_revision: bool = True,
) -> dict[str, Any] | None:
    current = inspect_container(runtime_container_name(runtime_id))
    if current is None:
        return None
    labels = current.get("Config", {}).get("Labels", {})
    if labels.get("com.linkprint.movie.workspace-runtime") != "true" or labels.get(RUNTIME_LABEL) != runtime_id:
        raise RuntimeError("runtime_identity_mismatch")
    if (require_current_security_revision
            and labels.get(SECURITY_REVISION_LABEL) != WORKSPACE_SECURITY_REVISION):
        raise RuntimeError("runtime_security_revision_mismatch")
    return current


def ensure_runtime_network(runtime_id: str) -> str:
    name = runtime_network_name(runtime_id)
    try:
        network = docker_request("GET", f"/networks/{urllib.parse.quote(name, safe='')}")
        labels = network.get("Labels", {}) or {}
        if labels.get("com.linkprint.movie.runtime-network") != "true" or labels.get(RUNTIME_LABEL) != runtime_id:
            raise RuntimeError("runtime_network_identity_mismatch")
        if not network.get("Internal"):
            raise RuntimeError("runtime_network_not_internal")
        return name
    except DockerError as exc:
        if exc.status != 404:
            raise
    docker_request("POST", "/networks/create", {
        "Name": name,
        "CheckDuplicate": True,
        "Internal": True,
        "Attachable": False,
        "Labels": {
            "com.linkprint.movie.runtime-network": "true",
            RUNTIME_LABEL: runtime_id,
        },
    })
    return name


def disconnect_network(network: str, container: str, *, force: bool = False) -> None:
    try:
        docker_request(
            "POST",
            f"/networks/{urllib.parse.quote(network, safe='')}/disconnect",
            {"Container": container, "Force": force},
        )
    except DockerError as exc:
        if exc.status not in (403, 404):
            raise


def remove_runtime_network(runtime_id: str) -> None:
    network = runtime_network_name(runtime_id)
    disconnect_network(network, TERMINAL_ROUTER_CONTAINER, force=True)
    disconnect_network(network, EGRESS_CONTAINER, force=True)
    try:
        docker_request("DELETE", f"/networks/{urllib.parse.quote(network, safe='')}")
    except DockerError as exc:
        if exc.status != 404:
            raise


def runtime_networks(container: dict[str, Any]) -> set[str]:
    networks = container.get("NetworkSettings", {}).get("Networks", {})
    return set(networks) if isinstance(networks, dict) else set()


def write_runtime_deadline(runtime_id: str, deadline: int) -> None:
    volume = runtime_deadline_volume(runtime_id)
    ensure_volume(volume, {
        "com.linkprint.movie.runtime-deadline": "true",
        RUNTIME_LABEL: runtime_id,
    })
    helper = f"movie-runtime-deadline-{runtime_id.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/bin/sh", "-ec"],
        "Cmd": [
            "umask 077; temporary=/run/movie/deadline/.deadline.$$; "
            "trap 'rm -f \"$temporary\"' EXIT; "
            "printf '%s\\n' \"$DEADLINE_EPOCH\" > \"$temporary\"; "
            "chmod 0444 \"$temporary\"; mv -f \"$temporary\" /run/movie/deadline/deadline; trap - EXIT"
        ],
        "Env": [f"DEADLINE_EPOCH={deadline}"],
        "User": "10001:10001",
        "Labels": {"com.linkprint.movie.runtime-deadline-writer": "true", RUNTIME_LABEL: runtime_id},
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 64 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [{"Type": "volume", "Source": volume, "Target": "/run/movie/deadline", "ReadOnly": False}],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("runtime_deadline_writer_failed")
    finally:
        remove_container(identifier, force=True)


def write_runtime_grant(runtime_id: str, grant: dict[str, Any]) -> None:
    volume = runtime_grant_volume(runtime_id)
    ensure_volume(volume, {
        "com.linkprint.movie.ai-grant": "true",
        RUNTIME_LABEL: runtime_id,
    })
    encoded = base64.b64encode(json.dumps(grant, separators=(",", ":")).encode()).decode("ascii")
    helper = f"movie-runtime-grant-{runtime_id.replace('-', '')[:12]}"
    remove_container(helper, force=True)
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Entrypoint": ["/bin/sh", "-ec"],
        "Cmd": [
            "umask 077; temporary=/run/movie/ai-grant/.grant.$$; "
            "trap 'rm -f \"$temporary\"' EXIT; "
            "printf '%s' \"$GRANT_BASE64\" | base64 -d > \"$temporary\"; "
            "chmod 0400 \"$temporary\"; mv -f \"$temporary\" /run/movie/ai-grant/grant.json; trap - EXIT"
        ],
        "Env": [f"GRANT_BASE64={encoded}"],
        "User": "10001:10001",
        "Labels": {"com.linkprint.movie.ai-grant-writer": "true", RUNTIME_LABEL: runtime_id},
        "HostConfig": {
            "NetworkMode": "none",
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 16,
            "Memory": 64 * 1024 * 1024,
            "NanoCpus": 250_000_000,
            "Mounts": [{"Type": "volume", "Source": volume, "Target": "/run/movie/ai-grant", "ReadOnly": False}],
        },
    }
    created = docker_request("POST", f"/containers/create?name={helper}", payload)
    identifier = created["Id"]
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
        result = docker_request("POST", f"/containers/{identifier}/wait?condition=not-running")
        if int(result.get("StatusCode", 1)) != 0:
            raise RuntimeError("runtime_grant_writer_failed")
    finally:
        remove_container(identifier, force=True)


def company_volume_mounts() -> list[dict[str, str]]:
    mounts: list[dict[str, str]] = []
    for summary in list_runtime_containers():
        identifier = str(summary.get("Id", ""))
        if not identifier:
            continue
        current = inspect_container(identifier)
        if current is None:
            continue
        for mount in current.get("Mounts", []):
            if mount.get("Type") == "volume" and mount.get("Name") == COMPANY_CODEX_VOLUME:
                labels = current.get("Config", {}).get("Labels", {})
                mounts.append({
                    "runtime_id": str(labels.get(RUNTIME_LABEL, "")),
                    "container_id": identifier[:12],
                    "state": str(current.get("State", {}).get("Status", "unknown")),
                })
                break
    return mounts


def ensure_company_volume_available(runtime_id: str) -> None:
    company_codex_volume()
    mounts = company_volume_mounts()
    if any(item.get("runtime_id") != runtime_id for item in mounts):
        raise RuntimeError("company_codex_occupied")
    if len(mounts) > 1:
        raise RuntimeError("company_codex_resource_locked")


def runtime_status_payload(runtime_id: str) -> dict[str, Any]:
    current = inspect_runtime(runtime_id)
    if current is None:
        return {"running": False, "runtime_id": runtime_id}
    labels = current.get("Config", {}).get("Labels", {})
    return {
        "running": bool(current.get("State", {}).get("Running")),
        "healthy": current.get("State", {}).get("Health", {}).get("Status") == "healthy",
        "runtime_id": runtime_id,
        "user_id": labels.get(USER_LABEL),
        "generation": int(labels.get(GENERATION_LABEL, "0")),
        "container_id": str(current.get("Id", ""))[:12],
        "container_name": runtime_container_name(runtime_id),
        "network_name": runtime_network_name(runtime_id),
        "workspace_root": labels.get("com.linkprint.movie.workspace-root"),
        "project_id": labels.get("com.linkprint.movie.project-id"),
        "project_directory": labels.get("com.linkprint.movie.project-directory"),
        "auth_mode": labels.get("com.linkprint.movie.auth-mode", "personal"),
        "session_mode": labels.get("com.linkprint.movie.session-mode", "new"),
        "session_id": labels.get("com.linkprint.movie.session-id") or None,
        "security_revision": labels.get(SECURITY_REVISION_LABEL),
        "image_current": str(current.get("Image", "")) == expected_workspace_image_id(),
        "ai_network_connected": "movie_broker" in runtime_networks(current),
    }


def create_runtime_workspace(
    runtime_id: str,
    user_id: str,
    storage_uuid: str,
    generation: int,
    idle_deadline: int,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str,
    session_mode: str = "new",
    session_id: str | None = None,
) -> dict[str, Any]:
    for value, error in ((runtime_id, "invalid_runtime"), (user_id, "invalid_user"), (storage_uuid, "invalid_storage"), (project_id, "invalid_project")):
        if not UUID_RE.fullmatch(value):
            raise ValueError(error)
    if generation < 1:
        raise ValueError("invalid_generation")
    validate_workspace_path(workspace_root, project_directory)
    validate_auth_mode(auth_mode)
    validate_session_selection(session_mode, session_id)
    if auth_mode == "company" and session_mode == "resume":
        raise RuntimeError("session_history_unavailable")
    now = int(time.time())
    if idle_deadline <= now or idle_deadline > now + (9 * 60 * 60):
        raise ValueError("invalid_idle_deadline")

    current = inspect_runtime(runtime_id, require_current_security_revision=False)
    desired_labels = {
        USER_LABEL: user_id,
        "com.linkprint.movie.storage": storage_uuid,
        "com.linkprint.movie.workspace-root": workspace_root,
        "com.linkprint.movie.project-id": project_id,
        "com.linkprint.movie.project-directory": project_directory,
        "com.linkprint.movie.auth-mode": auth_mode,
        "com.linkprint.movie.session-mode": session_mode,
        "com.linkprint.movie.session-id": session_id or "",
        GENERATION_LABEL: str(generation),
        SECURITY_REVISION_LABEL: WORKSPACE_SECURITY_REVISION,
    }
    if current is not None:
        labels = current.get("Config", {}).get("Labels", {})
        if labels.get(USER_LABEL) != user_id or labels.get("com.linkprint.movie.storage") != storage_uuid:
            raise RuntimeError("runtime_identity_mismatch")
        if (labels.get(SECURITY_REVISION_LABEL) == WORKSPACE_SECURITY_REVISION
                and all(labels.get(key) == value for key, value in desired_labels.items())):
            write_runtime_deadline(runtime_id, idle_deadline)
            if not current.get("State", {}).get("Running"):
                docker_request("POST", f"/containers/{current['Id']}/start", expected=(204, 304))
            return inspect_runtime(runtime_id) or current
        if "movie_broker" in runtime_networks(current):
            raise RuntimeError("ai_grant_active")
        stop_runtime_workspace(runtime_id, preserve_volumes=True)

    running = sum(1 for item in list_runtime_containers(all_containers=False) if item.get("State") == "running")
    legacy = inspect_container(ACTIVE_CONTAINER)
    if legacy is not None and legacy.get("State", {}).get("Running"):
        running += 1
    if running >= MAX_CONCURRENT_WORKSPACES:
        raise RuntimeError("workspace_capacity_full")
    if auth_mode == "company":
        ensure_company_volume_available(runtime_id)

    names = volume_names(storage_uuid)
    for kind, name in names.items():
        ensure_volume(name, {
            "com.linkprint.movie.user-volume": "true",
            "com.linkprint.movie.storage": storage_uuid,
            "com.linkprint.movie.kind": kind,
        })
    prepare_workspace_path(storage_uuid, workspace_root, project_directory)
    prepare_outputs_path(storage_uuid, project_id)
    write_runtime_deadline(runtime_id, idle_deadline)
    write_runtime_grant(runtime_id, {
        "version": 1,
        "enabled": False,
        "runtime_id": runtime_id,
        "generation": generation,
    })

    codex_volume = names["codex"] if auth_mode == "personal" else company_codex_volume()
    mounts = [
        workspace_volume_mount(names["workspace"], workspace_root),
        outputs_volume_mount(storage_uuid, project_id),
        {"Type": "volume", "Source": codex_volume, "Target": "/home/codex/.codex", "ReadOnly": False},
        {"Type": "volume", "Source": runtime_deadline_volume(runtime_id), "Target": "/run/movie/deadline", "ReadOnly": True},
        {"Type": "volume", "Source": runtime_grant_volume(runtime_id), "Target": "/run/movie/ai-grant", "ReadOnly": True},
    ]
    network = ensure_runtime_network(runtime_id)
    router = inspect_container(TERMINAL_ROUTER_CONTAINER)
    if router is None or not router.get("State", {}).get("Running"):
        raise RuntimeError("terminal_router_unavailable")
    egress = inspect_container(EGRESS_CONTAINER)
    if egress is None or not egress.get("State", {}).get("Running"):
        raise RuntimeError("workspace_egress_unavailable")
    try:
        if network not in runtime_networks(router):
            connect_network(network, str(router["Id"]), TERMINAL_ROUTER_CONTAINER)
        if network not in runtime_networks(egress):
            connect_network(network, str(egress["Id"]), "movie-egress")
    except DockerError as exc:
        if exc.status != 403:
            raise

    name = runtime_container_name(runtime_id)
    labels = {
        "com.linkprint.movie.workspace": "true",
        "com.linkprint.movie.workspace-runtime": "true",
        RUNTIME_LABEL: runtime_id,
        **desired_labels,
    }
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Hostname": "movie-workspace",
        "User": "10001:10001",
        "WorkingDir": f"/workspace/{project_directory}",
        "Env": [
            "CODEX_HOME=/home/codex/.codex",
            "HOME=/home/codex",
            "HTTP_PROXY=http://movie-egress:3128",
            "HTTPS_PROXY=http://movie-egress:3128",
            "http_proxy=http://movie-egress:3128",
            "https_proxy=http://movie-egress:3128",
            "NO_PROXY=127.0.0.1,localhost,movie-ai-router",
            "no_proxy=127.0.0.1,localhost,movie-ai-router",
            "MOVIE_AI_BROKER_URL=http://movie-ai-router:8080",
            f"MOVIE_RUNTIME_ID={runtime_id}",
            f"MOVIE_RUNTIME_GENERATION={generation}",
            f"MOVIE_PROJECT_ID={project_id}",
            f"MOVIE_PROJECT_DIRECTORY={project_directory}",
            f"MOVIE_VIDEO_BASE_URL={VIDEO_BASE_URL}",
            f"MOVIE_CODEX_AUTH_MODE={auth_mode}",
            f"MOVIE_CODEX_SESSION_MODE={session_mode}",
            f"MOVIE_CODEX_SESSION_ID={session_id or ''}",
        ],
        "Labels": labels,
        "ExposedPorts": {"7681/tcp": {}},
        "Healthcheck": {
            "Test": ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:7681/terminal/"],
            "Interval": 10_000_000_000,
            "Timeout": 3_000_000_000,
            "Retries": 6,
            "StartPeriod": 10_000_000_000,
        },
        "StopTimeout": 60,
        "HostConfig": {
            "NetworkMode": network,
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": RUNTIME_PIDS_LIMIT,
            "Memory": RUNTIME_MEMORY_BYTES,
            "MemorySwap": RUNTIME_MEMORY_BYTES,
            "NanoCpus": RUNTIME_NANO_CPUS,
            "OomKillDisable": False,
            "Init": True,
            "Tmpfs": {
                "/tmp": "rw,noexec,nosuid,nodev,size=536870912,uid=10001,gid=10001,mode=1777",
                "/run/user/10001": "rw,noexec,nosuid,nodev,size=16777216,uid=10001,gid=10001,mode=0700",
            },
            "Mounts": mounts,
            "LogConfig": {"Type": "json-file", "Config": {"max-size": "10m", "max-file": "3"}},
        },
        "NetworkingConfig": {"EndpointsConfig": {network: {"Aliases": [name]}}},
    }
    created = docker_request("POST", f"/containers/create?name={name}", payload)
    identifier = str(created["Id"])
    try:
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
    except Exception:
        remove_container(identifier, force=True)
        remove_runtime_network(runtime_id)
        raise
    return inspect_runtime(runtime_id) or {"Id": identifier, "State": {"Running": True}}


def stop_runtime_workspace(runtime_id: str, *, preserve_volumes: bool = True) -> dict[str, Any]:
    # Cleanup must remain possible after an intentional security revision bump.
    # Identity labels are still checked; only reuse is denied for an old revision.
    current = inspect_runtime(runtime_id, require_current_security_revision=False)
    was_company = False
    if current is not None:
        was_company = current.get("Config", {}).get("Labels", {}).get("com.linkprint.movie.auth-mode") == "company"
        stop_container(runtime_container_name(runtime_id))
        remove_container(runtime_container_name(runtime_id), force=True)
    remove_runtime_network(runtime_id)
    if was_company and company_volume_mounts():
        raise RuntimeError("company_codex_resource_locked")
    if not preserve_volumes:
        for volume in (runtime_deadline_volume(runtime_id), runtime_grant_volume(runtime_id)):
            try:
                docker_request("DELETE", f"/volumes/{urllib.parse.quote(volume, safe='')}")
            except DockerError as exc:
                if exc.status not in (404, 409):
                    raise
    return {"stopped": True, "company_volume_mount_count": len(company_volume_mounts())}


def runtime_context(
    runtime_id: str,
    user_id: str,
    generation: int,
    project_id: str | None = None,
) -> dict[str, Any]:
    current = inspect_runtime(runtime_id)
    if current is None or not current.get("State", {}).get("Running"):
        raise RuntimeError("workspace_not_running")
    labels = current.get("Config", {}).get("Labels", {})
    if labels.get(USER_LABEL) != user_id or int(labels.get(GENERATION_LABEL, "0")) != generation:
        raise RuntimeError("runtime_identity_mismatch")
    if project_id is not None and labels.get("com.linkprint.movie.project-id") != project_id:
        raise RuntimeError("project_mismatch")
    return current


def list_runtime_sessions(data: dict[str, Any]) -> dict[str, Any]:
    runtime_id = str(data.get("runtime_id", "")).lower()
    user_id = str(data.get("user_id", "")).lower()
    project_id = str(data.get("project_id", "")).lower()
    generation = int(data.get("generation", 0))
    auth_mode = str(data.get("auth_mode", "personal")).lower()
    current = runtime_context(runtime_id, user_id, generation, project_id)
    labels = current.get("Config", {}).get("Labels", {})
    if labels.get("com.linkprint.movie.auth-mode") != auth_mode:
        raise RuntimeError("runtime_identity_mismatch")
    current_mode = str(labels.get("com.linkprint.movie.session-mode", "new"))
    current_id = str(labels.get("com.linkprint.movie.session-id", "")) or None
    validate_session_selection(current_mode, current_id)
    if auth_mode == "company":
        return {
            "available": False,
            "reason": "personal_only",
            "sessions": [],
            "current_session_mode": current_mode,
            "current_session_id": current_id,
        }
    project_directory = str(labels.get("com.linkprint.movie.project-directory", ""))
    validate_workspace_path(str(labels.get("com.linkprint.movie.workspace-root", "")), project_directory)
    payload = exec_workspace_json(str(current.get("Id", "")), [
        SESSION_INDEX_COMMAND, "--project", project_directory, "--limit", "50",
    ])
    raw_sessions = payload.get("sessions")
    if not isinstance(raw_sessions, list):
        raise RuntimeError("session_index_invalid")
    sessions: list[dict[str, str]] = []
    for raw_session in raw_sessions[:50]:
        if not isinstance(raw_session, dict):
            raise RuntimeError("session_index_invalid")
        session_id = str(raw_session.get("id", "")).lower()
        title = str(raw_session.get("title", ""))
        started_at = str(raw_session.get("started_at", ""))
        updated_at = str(raw_session.get("updated_at", ""))
        if (not UUID_RE.fullmatch(session_id) or not title or len(title) > 120
                or len(started_at) > 64 or len(updated_at) > 64):
            raise RuntimeError("session_index_invalid")
        sessions.append({
            "id": session_id,
            "title": title,
            "started_at": started_at,
            "updated_at": updated_at,
        })
    return {
        "available": True,
        "sessions": sessions,
        "current_session_mode": current_mode,
        "current_session_id": current_id,
    }


def delete_runtime_session(data: dict[str, Any]) -> dict[str, Any]:
    session_id = str(data.get("session_id", "")).lower()
    if not UUID_RE.fullmatch(session_id):
        raise ValueError("invalid_session_id")
    listing = list_runtime_sessions(data)
    if not listing.get("available"):
        raise RuntimeError("session_history_unavailable")
    if listing.get("current_session_id") == session_id:
        raise RuntimeError("session_active")
    if session_id not in {session["id"] for session in listing["sessions"]}:
        raise RuntimeError("session_not_found")

    runtime_id = str(data.get("runtime_id", "")).lower()
    user_id = str(data.get("user_id", "")).lower()
    project_id = str(data.get("project_id", "")).lower()
    generation = int(data.get("generation", 0))
    current = runtime_context(runtime_id, user_id, generation, project_id)
    exec_workspace_command(
        str(current.get("Id", "")),
        [CODEX_COMMAND, "delete", session_id, "--force"],
        "session_delete_failed",
    )

    refreshed = list_runtime_sessions(data)
    if session_id in {session["id"] for session in refreshed["sessions"]}:
        raise RuntimeError("session_delete_failed")
    return {"deleted": True, "session_id": session_id}


def write_runtime_project_media(data: dict[str, Any]) -> dict[str, Any]:
    runtime_id = str(data.get("runtime_id", "")).lower()
    user_id = str(data.get("user_id", "")).lower()
    project_id = str(data.get("project_id", "")).lower()
    generation = int(data.get("generation", 0))
    filename = str(data.get("filename", "")).lower()
    mime = str(data.get("mime", "")).lower()
    expected_size = int(data.get("size", 0))
    expected_sha256 = str(data.get("sha256", "")).lower()
    contents = decode_project_media(
        filename, mime, expected_size, expected_sha256, data.get("content_base64", ""),
    )
    current = runtime_context(runtime_id, user_id, generation, project_id)
    labels = current.get("Config", {}).get("Labels", {})
    project_directory = str(labels.get("com.linkprint.movie.project-directory", ""))
    validate_workspace_path(str(labels.get("com.linkprint.movie.workspace-root", "")), project_directory)
    now = int(time.time())
    archive = io.BytesIO()
    with tarfile.open(fileobj=archive, mode="w") as tar:
        directory = tarfile.TarInfo("uploads")
        directory.type = tarfile.DIRTYPE
        directory.mode = 0o700
        directory.uid = 10001
        directory.gid = 10001
        directory.mtime = now
        tar.addfile(directory)
        media = tarfile.TarInfo(f"uploads/{filename}")
        media.size = len(contents)
        media.mode = 0o600
        media.uid = 10001
        media.gid = 10001
        media.mtime = now
        tar.addfile(media, io.BytesIO(contents))
    target = f"/workspace/{project_directory}"
    query = urllib.parse.urlencode({"path": target})
    docker_request_bytes(
        "PUT",
        f"/containers/{urllib.parse.quote(str(current['Id']), safe='')}/archive?{query}",
        archive.getvalue(),
        "application/x-tar",
        expected=(200,),
    )
    relative_path = f"uploads/{filename}"
    return {
        "path": f"{target}/{relative_path}",
        "relative_path": relative_path,
        "filename": filename,
        "mime": mime,
        "size": len(contents),
        "sha256": expected_sha256,
    }


def set_runtime_ai_grant(data: dict[str, Any]) -> dict[str, Any]:
    runtime_id = str(data.get("runtime_id", "")).lower()
    user_id = str(data.get("user_id", "")).lower()
    reservation_id = str(data.get("reservation_id", "")).lower()
    compute_node_id = str(data.get("compute_node_id", "")).lower()
    generation = int(data.get("generation", 0))
    expires_at = int(data.get("expires_at", 0))
    token = str(data.get("token", ""))
    capabilities = data.get("capabilities", [])
    if not UUID_RE.fullmatch(reservation_id):
        raise ValueError("invalid_reservation")
    if not UUID_RE.fullmatch(compute_node_id):
        raise ValueError("invalid_compute_node")
    if not TOKEN_RE.fullmatch(token):
        raise ValueError("invalid_broker_token")
    if expires_at <= int(time.time()) or expires_at > int(time.time()) + (9 * 60 * 60):
        raise ValueError("invalid_grant_expiry")
    if not isinstance(capabilities, list) or any(not isinstance(item, str) or len(item) > 64 for item in capabilities):
        raise ValueError("invalid_capabilities")
    current = runtime_context(runtime_id, user_id, generation)
    write_runtime_grant(runtime_id, {
        "version": 1,
        "enabled": True,
        "reservation_id": reservation_id,
        "compute_node_id": compute_node_id,
        "runtime_id": runtime_id,
        "generation": generation,
        "user_id": user_id,
        "expires_at": expires_at,
        "token": token,
        "capabilities": capabilities,
    })
    if "movie_broker" not in runtime_networks(current):
        connect_network("movie_broker", str(current["Id"]), runtime_container_name(runtime_id))
    try:
        exec_workspace_command(str(current["Id"]), [
            "tmux", "display-message", "-d", "10000",
            "本地 AI 已可用，可在 /model 中选择本地 Qwen，并可使用本地生图/视频命令。",
        ])
    except Exception as exc:
        print(f"manager grant notification error: {type(exc).__name__}", flush=True)
    return {"granted": True, "runtime_id": runtime_id, "generation": generation, "ai_network_connected": True}


def revoke_runtime_ai_grant(data: dict[str, Any]) -> dict[str, Any]:
    runtime_id = str(data.get("runtime_id", "")).lower()
    user_id = str(data.get("user_id", "")).lower()
    generation = int(data.get("generation", 0))
    current = runtime_context(runtime_id, user_id, generation)
    write_runtime_grant(runtime_id, {
        "version": 1,
        "enabled": False,
        "runtime_id": runtime_id,
        "generation": generation,
    })
    if "movie_broker" in runtime_networks(current):
        disconnect_network("movie_broker", str(current["Id"]), force=True)
    refreshed = inspect_runtime(runtime_id)
    if refreshed is not None and "movie_broker" in runtime_networks(refreshed):
        raise RuntimeError("ai_network_disconnect_failed")
    try:
        exec_workspace_command(str(current["Id"]), [
            "tmux", "display-message", "-d", "10000",
            "本地 AI 预约已结束；工作区和 OpenAI Codex 仍可继续使用。",
        ])
    except Exception as exc:
        print(f"manager revoke notification error: {type(exc).__name__}", flush=True)
    return {"revoked": True, "runtime_id": runtime_id, "generation": generation, "ai_network_connected": False}


def create_workspace(
    reservation_id: str,
    storage_uuid: str,
    deadline: int,
    broker_token: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str,
    session_mode: str = "new",
    session_id: str | None = None,
) -> dict[str, Any]:
    validate_workspace_path(workspace_root, project_directory)
    validate_auth_mode(auth_mode)
    validate_session_selection(session_mode, session_id)
    if auth_mode == "company" and session_mode == "resume":
        raise RuntimeError("session_history_unavailable")
    if not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_project")
    current = inspect_container(ACTIVE_CONTAINER)
    if current is not None:
        labels = current.get("Config", {}).get("Labels", {})
        if labels.get("com.linkprint.movie.reservation") == reservation_id:
            if (labels.get("com.linkprint.movie.workspace-root") != workspace_root
                    or labels.get("com.linkprint.movie.project-id") != project_id
                    or labels.get("com.linkprint.movie.project-directory") != project_directory
                    or labels.get("com.linkprint.movie.auth-mode", "personal") != auth_mode):
                raise RuntimeError("project_mismatch")
            if not current.get("State", {}).get("Running"):
                docker_request("POST", f"/containers/{current['Id']}/start", expected=(204, 304))
            return inspect_container(ACTIVE_CONTAINER) or current
        if current.get("State", {}).get("Running"):
            raise RuntimeError("resource_locked")
        remove_container(ACTIVE_CONTAINER, force=True)

    names = volume_names(storage_uuid)
    for kind, name in names.items():
        ensure_volume(name, {
            "com.linkprint.movie.user-volume": "true",
            "com.linkprint.movie.storage": storage_uuid,
            "com.linkprint.movie.kind": kind,
        })
    prepare_workspace_path(storage_uuid, workspace_root, project_directory)
    prepare_outputs_path(storage_uuid, project_id)
    write_deadline(reservation_id, deadline)

    codex_volume = names["codex"] if auth_mode == "personal" else company_codex_volume()
    mounts = [
        workspace_volume_mount(names["workspace"], workspace_root),
        outputs_volume_mount(storage_uuid, project_id),
        {"Type": "volume", "Source": codex_volume, "Target": "/home/codex/.codex", "ReadOnly": False},
        {"Type": "volume", "Source": deadline_volume(reservation_id), "Target": "/run/movie/deadline", "ReadOnly": True},
    ]
    payload = {
        "Image": WORKSPACE_IMAGE,
        "Hostname": "movie-workspace",
        "User": "10001:10001",
        "WorkingDir": f"/workspace/{project_directory}",
        "Env": [
            "CODEX_HOME=/home/codex/.codex",
            "HOME=/home/codex",
            "HTTP_PROXY=http://movie-egress:3128",
            "HTTPS_PROXY=http://movie-egress:3128",
            "http_proxy=http://movie-egress:3128",
            "https_proxy=http://movie-egress:3128",
            "NO_PROXY=127.0.0.1,localhost,movie-ai-router",
            "no_proxy=127.0.0.1,localhost,movie-ai-router",
            "MOVIE_AI_BROKER_URL=http://movie-ai-router:8080",
            f"MOVIE_AI_TOKEN={broker_token}",
            f"MOVIE_RESERVATION_ID={reservation_id}",
            f"MOVIE_PROJECT_ID={project_id}",
            f"MOVIE_PROJECT_DIRECTORY={project_directory}",
            f"MOVIE_VIDEO_BASE_URL={VIDEO_BASE_URL}",
            f"MOVIE_CODEX_AUTH_MODE={auth_mode}",
            f"MOVIE_CODEX_SESSION_MODE={session_mode}",
            f"MOVIE_CODEX_SESSION_ID={session_id or ''}",
        ],
        "Labels": {
            "com.linkprint.movie.workspace": "true",
            "com.linkprint.movie.reservation": reservation_id,
            "com.linkprint.movie.storage": storage_uuid,
            "com.linkprint.movie.workspace-root": workspace_root,
            "com.linkprint.movie.project-id": project_id,
            "com.linkprint.movie.project-directory": project_directory,
            "com.linkprint.movie.auth-mode": auth_mode,
            "com.linkprint.movie.session-mode": session_mode,
            "com.linkprint.movie.session-id": session_id or "",
        },
        "ExposedPorts": {"7681/tcp": {}},
        "Healthcheck": {
            "Test": ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:7681/terminal/"],
            "Interval": 10_000_000_000,
            "Timeout": 3_000_000_000,
            "Retries": 6,
            "StartPeriod": 10_000_000_000,
        },
        "StopTimeout": 60,
        "HostConfig": {
            "NetworkMode": NETWORKS[0],
            "ReadonlyRootfs": True,
            "CapDrop": ["ALL"],
            "SecurityOpt": workspace_security_options(),
            "PidsLimit": 512,
            "Memory": 8 * 1024 * 1024 * 1024,
            "MemorySwap": 8 * 1024 * 1024 * 1024,
            "NanoCpus": 4_000_000_000,
            "OomKillDisable": False,
            "Init": True,
            "Tmpfs": {
                "/tmp": "rw,noexec,nosuid,nodev,size=536870912,uid=10001,gid=10001,mode=1777",
                "/run/user/10001": "rw,noexec,nosuid,nodev,size=16777216,uid=10001,gid=10001,mode=0700",
            },
            "Mounts": mounts,
            "LogConfig": {"Type": "json-file", "Config": {"max-size": "10m", "max-file": "3"}},
        },
        "NetworkingConfig": {"EndpointsConfig": {NETWORKS[0]: {"Aliases": [ACTIVE_CONTAINER]}}},
    }
    created = docker_request("POST", f"/containers/create?name={ACTIVE_CONTAINER}", payload)
    identifier = created["Id"]
    try:
        for network in NETWORKS[1:]:
            connect_network(network, identifier, ACTIVE_CONTAINER)
        docker_request("POST", f"/containers/{identifier}/start", expected=(204, 304))
    except Exception:
        remove_container(identifier, force=True)
        raise
    return inspect_container(ACTIVE_CONTAINER) or {"Id": identifier, "State": {"Running": True}}


def load_state() -> dict[str, Any]:
    try:
        return json.loads(STATE_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def save_state(state: dict[str, Any]) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    temporary = STATE_PATH.with_suffix(".tmp")
    temporary.write_text(json.dumps(state, separators=(",", ":")), encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(STATE_PATH)


STATE_LOCK = threading.Lock()


def state_set(
    reservation_id: str,
    storage_uuid: str,
    deadline: int,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str,
    session_mode: str = "new",
    session_id: str | None = None,
) -> None:
    validate_auth_mode(auth_mode)
    validate_session_selection(session_mode, session_id)
    with STATE_LOCK:
        save_state({
            "reservation_id": reservation_id,
            "storage_uuid": storage_uuid,
            "deadline_epoch": deadline,
            "workspace_root": workspace_root,
            "project_id": project_id,
            "project_directory": project_directory,
            "auth_mode": auth_mode,
            "session_mode": session_mode,
            "session_id": session_id or "",
        })


def state_clear(reservation_id: str) -> None:
    with STATE_LOCK:
        state = load_state()
        if state.get("reservation_id") == reservation_id:
            save_state({})


def stop_workspace(reservation_id: str) -> None:
    current = inspect_container(ACTIVE_CONTAINER)
    if current is not None:
        labels = current.get("Config", {}).get("Labels", {})
        if labels.get("com.linkprint.movie.reservation") != reservation_id:
            raise RuntimeError("reservation_mismatch")
        stop_container(ACTIVE_CONTAINER)
        remove_container(ACTIVE_CONTAINER, force=True)
    try:
        docker_request("DELETE", f"/volumes/{deadline_volume(reservation_id)}")
    except DockerError as exc:
        if exc.status not in (404, 409):
            raise
    state_clear(reservation_id)


def refresh_workspace(
    reservation_id: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str | None = None,
    session_mode: str = "new",
    session_id: str | None = None,
) -> dict[str, Any]:
    validate_workspace_path(workspace_root, project_directory)
    if not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_project")
    current = inspect_container(ACTIVE_CONTAINER)
    if current is None:
        raise RuntimeError("workspace_not_running")
    labels = current.get("Config", {}).get("Labels", {})
    if labels.get("com.linkprint.movie.reservation") != reservation_id:
        raise RuntimeError("reservation_mismatch")
    with STATE_LOCK:
        state = load_state()
    if state.get("reservation_id") != reservation_id:
        raise RuntimeError("reservation_mismatch")
    deadline = int(state.get("deadline_epoch", 0))
    if deadline <= int(time.time()):
        raise RuntimeError("reservation_expired")
    token = ""
    for value in current.get("Config", {}).get("Env", []):
        if value.startswith("MOVIE_AI_TOKEN="):
            token = value.split("=", 1)[1]
            break
    if not TOKEN_RE.fullmatch(token):
        raise RuntimeError("broker_token_unavailable")
    storage_uuid = str(state.get("storage_uuid", ""))
    if not UUID_RE.fullmatch(storage_uuid):
        raise RuntimeError("invalid_storage")
    selected_auth_mode = auth_mode or str(state.get("auth_mode", "personal"))
    validate_auth_mode(selected_auth_mode)
    validate_session_selection(session_mode, session_id)
    if selected_auth_mode == "company":
        company_codex_volume()
        if session_mode == "resume":
            raise RuntimeError("session_history_unavailable")
    previous_auth_mode = str(state.get("auth_mode", "personal"))
    validate_auth_mode(previous_auth_mode)
    previous_session_mode = str(state.get("session_mode", "new"))
    previous_session_id = str(state.get("session_id", "")) or None
    validate_session_selection(previous_session_mode, previous_session_id)
    previous_workspace_root = str(state.get("workspace_root", ""))
    previous_project_id = str(state.get("project_id", ""))
    previous_project_directory = str(state.get("project_directory", ""))
    prepare_workspace_path(storage_uuid, workspace_root, project_directory)
    stop_container(ACTIVE_CONTAINER)
    remove_container(ACTIVE_CONTAINER, force=True)
    try:
        refreshed = create_workspace(
            reservation_id, storage_uuid, deadline, token, workspace_root, project_id, project_directory,
            selected_auth_mode, session_mode, session_id,
        )
    except Exception as switch_error:
        try:
            create_workspace(
                reservation_id, storage_uuid, deadline, token,
                previous_workspace_root, previous_project_id, previous_project_directory,
                previous_auth_mode, previous_session_mode, previous_session_id,
            )
            state_set(
                reservation_id, storage_uuid, deadline,
                previous_workspace_root, previous_project_id, previous_project_directory,
                previous_auth_mode, previous_session_mode, previous_session_id,
            )
        except Exception as rollback_error:
            raise RuntimeError("resource_locked") from rollback_error
        raise switch_error
    state_set(
        reservation_id, storage_uuid, deadline, workspace_root, project_id, project_directory,
        selected_auth_mode, session_mode, session_id,
    )
    return {
        "running": bool(refreshed.get("State", {}).get("Running")),
        "container_id": str(refreshed.get("Id", ""))[:12],
        "refreshed": True,
        "workspace_root": workspace_root,
        "project_id": project_id,
        "project_directory": project_directory,
        "auth_mode": selected_auth_mode,
        "session_mode": session_mode,
        "session_id": session_id or None,
    }


def active_workspace_context(
    reservation_id: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str,
) -> tuple[dict[str, Any], dict[str, Any]]:
    validate_workspace_path(workspace_root, project_directory)
    validate_auth_mode(auth_mode)
    if not UUID_RE.fullmatch(project_id):
        raise ValueError("invalid_project")
    current = inspect_container(ACTIVE_CONTAINER)
    if current is None or not current.get("State", {}).get("Running"):
        raise RuntimeError("workspace_not_running")
    labels = current.get("Config", {}).get("Labels", {})
    expected_labels = {
        "com.linkprint.movie.reservation": reservation_id,
        "com.linkprint.movie.workspace-root": workspace_root,
        "com.linkprint.movie.project-id": project_id,
        "com.linkprint.movie.project-directory": project_directory,
        "com.linkprint.movie.auth-mode": auth_mode,
    }
    if any(labels.get(key) != value for key, value in expected_labels.items()):
        raise RuntimeError("project_mismatch")
    with STATE_LOCK:
        state = load_state()
    expected_state = {
        "reservation_id": reservation_id,
        "workspace_root": workspace_root,
        "project_id": project_id,
        "project_directory": project_directory,
        "auth_mode": auth_mode,
    }
    if any(state.get(key) != value for key, value in expected_state.items()):
        raise RuntimeError("project_mismatch")
    if int(state.get("deadline_epoch", 0)) <= int(time.time()):
        raise RuntimeError("reservation_expired")
    return current, state


def list_workspace_sessions(
    reservation_id: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str,
) -> dict[str, Any]:
    current, _state = active_workspace_context(
        reservation_id, workspace_root, project_id, project_directory, auth_mode,
    )
    labels = current.get("Config", {}).get("Labels", {})
    current_mode = labels.get("com.linkprint.movie.session-mode", "new")
    current_id = labels.get("com.linkprint.movie.session-id") or None
    validate_session_selection(current_mode, current_id)
    if auth_mode == "company":
        return {
            "available": False,
            "reason": "personal_only",
            "sessions": [],
            "current_session_mode": current_mode,
            "current_session_id": current_id,
        }
    payload = exec_workspace_json(str(current.get("Id", "")), [
        SESSION_INDEX_COMMAND,
        "--project",
        project_directory,
        "--limit",
        "50",
    ])
    raw_sessions = payload.get("sessions")
    if not isinstance(raw_sessions, list):
        raise RuntimeError("session_index_invalid")
    sessions: list[dict[str, str]] = []
    for raw_session in raw_sessions[:50]:
        if not isinstance(raw_session, dict):
            raise RuntimeError("session_index_invalid")
        session_id = str(raw_session.get("id", "")).lower()
        title = str(raw_session.get("title", ""))
        started_at = str(raw_session.get("started_at", ""))
        updated_at = str(raw_session.get("updated_at", ""))
        if (not UUID_RE.fullmatch(session_id) or not title or len(title) > 120
                or len(started_at) > 64 or len(updated_at) > 64):
            raise RuntimeError("session_index_invalid")
        sessions.append({
            "id": session_id,
            "title": title,
            "started_at": started_at,
            "updated_at": updated_at,
        })
    return {
        "available": True,
        "sessions": sessions,
        "current_session_mode": current_mode,
        "current_session_id": current_id,
    }


def switch_workspace_session(
    reservation_id: str,
    workspace_root: str,
    project_id: str,
    project_directory: str,
    auth_mode: str,
    session_mode: str,
    session_id: str | None,
) -> dict[str, Any]:
    validate_session_selection(session_mode, session_id)
    listing = list_workspace_sessions(
        reservation_id, workspace_root, project_id, project_directory, auth_mode,
    )
    if session_mode == "resume":
        if not listing.get("available"):
            raise RuntimeError("session_history_unavailable")
        known_ids = {session["id"] for session in listing["sessions"]}
        if session_id not in known_ids:
            raise RuntimeError("session_not_found")
    return refresh_workspace(
        reservation_id,
        workspace_root,
        project_id,
        project_directory,
        auth_mode,
        session_mode,
        session_id,
    )


def switch_workspace_auth_mode(reservation_id: str, auth_mode: str) -> dict[str, Any]:
    validate_auth_mode(auth_mode)
    with STATE_LOCK:
        state = load_state()
    if state.get("reservation_id") != reservation_id:
        raise RuntimeError("reservation_mismatch")
    return refresh_workspace(
        reservation_id,
        str(state.get("workspace_root", "")),
        str(state.get("project_id", "")),
        str(state.get("project_directory", "")),
        auth_mode,
    )


def status_payload(reservation_id: str | None = None) -> dict[str, Any]:
    current = inspect_container(ACTIVE_CONTAINER)
    if current is None:
        return {"running": False}
    labels = current.get("Config", {}).get("Labels", {})
    active_reservation = labels.get("com.linkprint.movie.reservation")
    if reservation_id and reservation_id != active_reservation:
        return {"running": False, "resource_locked": bool(current.get("State", {}).get("Running"))}
    return {
        "running": bool(current.get("State", {}).get("Running")),
        "healthy": current.get("State", {}).get("Health", {}).get("Status") == "healthy",
        "reservation_id": active_reservation,
        "container_id": str(current.get("Id", ""))[:12],
        "workspace_root": labels.get("com.linkprint.movie.workspace-root"),
        "project_id": labels.get("com.linkprint.movie.project-id"),
        "project_directory": labels.get("com.linkprint.movie.project-directory"),
        "auth_mode": labels.get("com.linkprint.movie.auth-mode", "personal"),
        "session_mode": labels.get("com.linkprint.movie.session-mode", "new"),
        "session_id": labels.get("com.linkprint.movie.session-id") or None,
    }


def watchdog() -> None:
    while True:
        time.sleep(5)
        try:
            with STATE_LOCK:
                state = load_state()
            reservation_id = state.get("reservation_id")
            deadline = int(state.get("deadline_epoch", 0))
            if reservation_id and deadline and time.time() >= deadline:
                stop_workspace(reservation_id)
        except Exception as exc:
            print(f"manager watchdog error: {type(exc).__name__}", flush=True)


class Handler(BaseHTTPRequestHandler):
    server_version = "movie-workspace-manager/1"

    def log_message(self, fmt: str, *args: object) -> None:
        print(f"manager {self.command} {self.path.split('?', 1)[0]} {args[1] if len(args) > 1 else '-'}", flush=True)

    def respond(self, status: int, body: dict[str, Any]) -> None:
        raw = json.dumps(body, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def read_body(self, max_length: int = 8192) -> bytes:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > max_length:
            raise ValueError("invalid_body_size")
        return self.rfile.read(length)

    def authorized_with(self, raw: bytes, secret: bytes) -> bool:
        timestamp = self.headers.get("X-Movie-Timestamp", "")
        signature = self.headers.get("X-Movie-Signature", "")
        try:
            stamp = int(timestamp)
        except ValueError:
            return False
        if abs(time.time() - stamp) > 30:
            return False
        message = b"\n".join([timestamp.encode(), self.command.encode(), self.path.encode(), raw])
        expected = hmac.new(secret, message, hashlib.sha256).hexdigest()
        return hmac.compare_digest(expected, signature)

    def authorized(self, raw: bytes) -> bool:
        return self.authorized_with(raw, HMAC_SECRET)

    def ai_authorized(self, raw: bytes) -> bool:
        return self.authorized_with(raw, AI_HMAC_SECRET)

    def active_ai_reservation(self, reservation_id: str) -> None:
        with STATE_LOCK:
            state = load_state()
        if state.get("reservation_id") != reservation_id:
            raise RuntimeError("reservation_mismatch")
        if int(state.get("deadline_epoch", 0)) <= int(time.time()):
            raise RuntimeError("reservation_expired")
        current = inspect_container(ACTIVE_CONTAINER)
        if current is None or not current.get("State", {}).get("Running"):
            raise RuntimeError("workspace_not_running")
        labels = current.get("Config", {}).get("Labels", {})
        if labels.get("com.linkprint.movie.reservation") != reservation_id:
            raise RuntimeError("reservation_mismatch")

    def active_ai_runtime(
        self,
        reservation_id: str,
        runtime_id: str,
        user_id: str,
        generation: int,
    ) -> dict[str, Any]:
        if not UUID_RE.fullmatch(reservation_id):
            raise ValueError("invalid_reservation")
        current = runtime_context(runtime_id, user_id, generation)
        if "movie_broker" not in runtime_networks(current):
            raise RuntimeError("ai_grant_inactive")
        return current

    def do_GET(self) -> None:
        if self.path == "/healthz":
            try:
                docker_request("GET", "/_ping", expected=(200,))
                self.respond(200, {"ok": True})
            except Exception:
                self.respond(503, {"ok": False})
            return
        if self.path.startswith("/v2/ai/status"):
            if not self.ai_authorized(b""):
                self.respond(403, {"error": "forbidden"})
                return
            try:
                query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
                reservation_id = str(query.get("reservation_id", [""])[0]).lower()
                runtime_id = str(query.get("runtime_id", [""])[0]).lower()
                user_id = str(query.get("user_id", [""])[0]).lower()
                generation = int(query.get("generation", ["0"])[0])
                self.active_ai_runtime(reservation_id, runtime_id, user_id, generation)
                self.respond(200, host_control_request("status"))
            except ValueError as exc:
                self.respond(422, {"error": str(exc)})
            except RuntimeError as exc:
                self.respond(409, {"error": str(exc)})
            except Exception as exc:
                print(f"manager v2 ai status error: {type(exc).__name__}", flush=True)
                self.respond(503, {"error": "host_control_unavailable"})
            return
        if self.path.startswith("/v2/"):
            if not self.authorized(b""):
                self.respond(403, {"error": "forbidden"})
                return
            try:
                parsed = urllib.parse.urlsplit(self.path)
                query = urllib.parse.parse_qs(parsed.query)
                if parsed.path == "/v2/runtime/status":
                    runtime_id = str(query.get("runtime_id", [""])[0]).lower()
                    self.respond(200, runtime_status_payload(runtime_id))
                    return
                if parsed.path == "/v2/company/status":
                    mounts = company_volume_mounts()
                    self.respond(200, {
                        "available": len(mounts) == 0,
                        "mount_count": len(mounts),
                        "runtime_ids": [item["runtime_id"] for item in mounts],
                    })
                    return
                if parsed.path == "/v2/capacity":
                    running = sum(1 for item in list_runtime_containers(all_containers=False) if item.get("State") == "running")
                    legacy = inspect_container(ACTIVE_CONTAINER)
                    if legacy is not None and legacy.get("State", {}).get("Running"):
                        running += 1
                    self.respond(200, {
                        "running": running,
                        "maximum": MAX_CONCURRENT_WORKSPACES,
                        "available": max(0, MAX_CONCURRENT_WORKSPACES - running),
                    })
                    return
                self.respond(404, {"error": "not_found"})
            except ValueError as exc:
                self.respond(422, {"error": str(exc)})
            except RuntimeError as exc:
                self.respond(409, {"error": str(exc)})
            except Exception as exc:
                print(f"manager v2 status error: {type(exc).__name__}", flush=True)
                self.respond(503, {"error": "manager_unavailable"})
            return
        if self.path.startswith("/v1/ai/status"):
            if not self.ai_authorized(b""):
                self.respond(403, {"error": "forbidden"})
                return
            reservation_id = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query).get("reservation_id", [None])[0]
            if reservation_id is None or not UUID_RE.fullmatch(reservation_id):
                self.respond(422, {"error": "invalid_reservation"})
                return
            try:
                self.active_ai_reservation(reservation_id)
                self.respond(200, host_control_request("status"))
            except RuntimeError as exc:
                status = 409 if str(exc) in {"reservation_mismatch", "reservation_expired", "workspace_not_running"} else 503
                self.respond(status, {"error": str(exc)})
            except Exception as exc:
                print(f"manager ai status error: {type(exc).__name__}", flush=True)
                self.respond(503, {"error": "host_control_unavailable"})
            return
        if not self.path.startswith("/v1/status") or not self.authorized(b""):
            self.respond(403, {"error": "forbidden"})
            return
        reservation_id = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query).get("reservation_id", [None])[0]
        if reservation_id is not None and not UUID_RE.fullmatch(reservation_id):
            self.respond(422, {"error": "invalid_reservation"})
            return
        self.respond(200, status_payload(reservation_id))

    def do_POST(self) -> None:
        try:
            maximum = MAX_MEDIA_REQUEST_BYTES if self.path in {
                "/v1/project-image", "/v1/project-media",
                "/v2/runtime/project-image", "/v2/runtime/project-media",
            } else 8192
            raw = self.read_body(maximum)
        except ValueError as exc:
            self.respond(413, {"error": str(exc)})
            return
        if self.path in {"/v1/ai/prepare", "/v2/ai/prepare"}:
            if not self.ai_authorized(raw):
                self.respond(403, {"error": "forbidden"})
                return
            try:
                data = json.loads(raw or b"{}")
                reservation_id = str(data.get("reservation_id", "")).lower()
                capability = str(data.get("capability", ""))
                if not UUID_RE.fullmatch(reservation_id):
                    raise ValueError("invalid_reservation")
                action = {
                    "h3.generate": "prepare_h3",
                    "image.generate": "prepare_image",
                }.get(capability)
                if action is None:
                    raise ValueError("unsupported_capability")
                if self.path == "/v2/ai/prepare":
                    runtime_id = str(data.get("runtime_id", "")).lower()
                    user_id = str(data.get("user_id", "")).lower()
                    generation = int(data.get("generation", 0))
                    self.active_ai_runtime(reservation_id, runtime_id, user_id, generation)
                else:
                    self.active_ai_reservation(reservation_id)
                self.respond(200, host_control_request(action))
            except ValueError as exc:
                self.respond(422, {"error": str(exc)})
            except RuntimeError as exc:
                message = str(exc)
                status = 409 if message.startswith("policy:") or message in {
                    "reservation_mismatch", "reservation_expired", "workspace_not_running",
                } else 503
                self.respond(status, {"error": message})
            except Exception as exc:
                print(f"manager ai prepare error: {type(exc).__name__}", flush=True)
                self.respond(503, {"error": "host_control_unavailable"})
            return
        if not self.authorized(raw):
            self.respond(403, {"error": "forbidden"})
            return
        try:
            data = json.loads(raw or b"{}")
            if self.path == "/v2/runtime/sessions":
                self.respond(200, list_runtime_sessions(data))
                return

            if self.path == "/v2/runtime/session/delete":
                self.respond(200, delete_runtime_session(data))
                return

            if self.path in {"/v2/runtime/project-image", "/v2/runtime/project-media"}:
                self.respond(201, write_runtime_project_media(data))
                return

            if self.path == "/v2/runtime/ensure":
                runtime_id = str(data.get("runtime_id", "")).lower()
                user_id = str(data.get("user_id", "")).lower()
                storage_uuid = str(data.get("storage_uuid", "")).lower()
                workspace_root = str(data.get("workspace_root", "")).lower()
                project_id = str(data.get("project_id", "")).lower()
                project_directory = str(data.get("project_directory", "")).lower()
                generation = int(data.get("generation", 0))
                idle_deadline = int(data.get("idle_deadline_epoch", 0))
                auth_mode = str(data.get("auth_mode", "personal")).lower()
                session_mode = str(data.get("session_mode", "new")).lower()
                session_id_value = data.get("session_id")
                session_id = str(session_id_value).lower() if session_id_value not in {None, ""} else None
                create_runtime_workspace(
                    runtime_id,
                    user_id,
                    storage_uuid,
                    generation,
                    idle_deadline,
                    workspace_root,
                    project_id,
                    project_directory,
                    auth_mode,
                    session_mode,
                    session_id,
                )
                self.respond(200, runtime_status_payload(runtime_id))
                return

            if self.path == "/v2/runtime/stop":
                runtime_id = str(data.get("runtime_id", "")).lower()
                user_id = str(data.get("user_id", "")).lower()
                generation = int(data.get("generation", 0))
                current = runtime_context(runtime_id, user_id, generation)
                if "movie_broker" in runtime_networks(current):
                    raise RuntimeError("ai_grant_active")
                self.respond(200, stop_runtime_workspace(runtime_id, preserve_volumes=True))
                return

            if self.path == "/v2/runtime/deadline":
                runtime_id = str(data.get("runtime_id", "")).lower()
                user_id = str(data.get("user_id", "")).lower()
                generation = int(data.get("generation", 0))
                deadline = int(data.get("idle_deadline_epoch", 0))
                runtime_context(runtime_id, user_id, generation)
                now = int(time.time())
                if deadline <= now or deadline > now + (9 * 60 * 60):
                    raise ValueError("invalid_idle_deadline")
                write_runtime_deadline(runtime_id, deadline)
                self.respond(200, {"updated": True})
                return

            if self.path == "/v2/runtime/grant":
                self.respond(200, set_runtime_ai_grant(data))
                return

            if self.path == "/v2/runtime/revoke":
                self.respond(200, revoke_runtime_ai_grant(data))
                return

            if self.path == "/v2/company/assert-available":
                runtime_id = str(data.get("runtime_id", "")).lower()
                ensure_company_volume_available(runtime_id)
                self.respond(200, {"available": True, "mount_count": len(company_volume_mounts())})
                return

            if self.path in {"/v1/project-image", "/v1/project-media"}:
                reservation_id = str(data.get("reservation_id", "")).lower()
                storage_uuid = str(data.get("storage_uuid", "")).lower()
                workspace_root = str(data.get("workspace_root", "")).lower()
                project_id = str(data.get("project_id", "")).lower()
                project_directory = str(data.get("project_directory", "")).lower()
                filename = str(data.get("filename", "")).lower()
                mime = str(data.get("mime", "")).lower()
                size = int(data.get("size", 0))
                sha256 = str(data.get("sha256", "")).lower()
                content_base64 = data.get("content_base64", "")
                self.respond(201, write_project_media(
                    reservation_id,
                    storage_uuid,
                    workspace_root,
                    project_id,
                    project_directory,
                    filename,
                    mime,
                    size,
                    sha256,
                    content_base64,
                ))
                return

            if self.path == "/v1/project-directory":
                storage_uuid = str(data.get("storage_uuid", "")).lower()
                workspace_root = str(data.get("workspace_root", "")).lower()
                old_directory = str(data.get("old_directory", "")).lower()
                new_directory = str(data.get("new_directory", "")).lower()
                if not UUID_RE.fullmatch(storage_uuid):
                    raise ValueError("invalid_storage")
                validate_workspace_path(workspace_root, old_directory)
                validate_workspace_path(workspace_root, new_directory)
                rename_workspace_path(storage_uuid, workspace_root, old_directory, new_directory)
                self.respond(200, {"renamed": True, "directory_name": new_directory})
                return

            if self.path in {"/v1/project-directory/trash", "/v1/project-directory/restore"}:
                storage_uuid = str(data.get("storage_uuid", "")).lower()
                workspace_root = str(data.get("workspace_root", "")).lower()
                project_id = str(data.get("project_id", "")).lower()
                project_directory = str(data.get("project_directory", "")).lower()
                if not UUID_RE.fullmatch(storage_uuid):
                    raise ValueError("invalid_storage")
                if not UUID_RE.fullmatch(project_id):
                    raise ValueError("invalid_project")
                validate_workspace_path(workspace_root, project_directory)
                operation = trash_workspace_path if self.path.endswith("/trash") else restore_workspace_path
                self.respond(200, operation(
                    storage_uuid, workspace_root, project_id, project_directory,
                ))
                return

            reservation_id = str(data.get("reservation_id", "")).lower()
            if not UUID_RE.fullmatch(reservation_id):
                raise ValueError("invalid_reservation")

            if self.path in {"/v1/sessions", "/v1/session"}:
                workspace_root = str(data.get("workspace_root", "")).lower()
                project_id = str(data.get("project_id", "")).lower()
                project_directory = str(data.get("project_directory", "")).lower()
                auth_mode = str(data.get("auth_mode", "personal")).lower()
                validate_workspace_path(workspace_root, project_directory)
                validate_auth_mode(auth_mode)
                if not UUID_RE.fullmatch(project_id):
                    raise ValueError("invalid_project")
                if self.path == "/v1/sessions":
                    self.respond(200, list_workspace_sessions(
                        reservation_id,
                        workspace_root,
                        project_id,
                        project_directory,
                        auth_mode,
                    ))
                    return
                action = str(data.get("action", "")).lower()
                if action not in SESSION_MODES:
                    raise ValueError("invalid_session_mode")
                session_id_value = data.get("session_id")
                session_id = (
                    str(session_id_value).lower()
                    if session_id_value not in {None, ""}
                    else None
                )
                self.respond(200, switch_workspace_session(
                    reservation_id,
                    workspace_root,
                    project_id,
                    project_directory,
                    auth_mode,
                    action,
                    session_id,
                ))
                return

            if self.path == "/v1/start":
                storage_uuid = str(data.get("storage_uuid", "")).lower()
                workspace_root = str(data.get("workspace_root", "")).lower()
                project_id = str(data.get("project_id", "")).lower()
                project_directory = str(data.get("project_directory", "")).lower()
                broker_token = str(data.get("broker_token", ""))
                deadline = int(data.get("deadline_epoch", 0))
                auth_mode = str(data.get("auth_mode", "personal")).lower()
                now = int(time.time())
                if not UUID_RE.fullmatch(storage_uuid):
                    raise ValueError("invalid_storage")
                if not UUID_RE.fullmatch(project_id):
                    raise ValueError("invalid_project")
                if not TOKEN_RE.fullmatch(broker_token):
                    raise ValueError("invalid_broker_token")
                validate_workspace_path(workspace_root, project_directory)
                validate_auth_mode(auth_mode)
                if deadline <= now or deadline > now + (9 * 60 * 60):
                    raise ValueError("invalid_deadline")
                current = create_workspace(
                    reservation_id, storage_uuid, deadline, broker_token,
                    workspace_root, project_id, project_directory, auth_mode,
                )
                state_set(
                    reservation_id, storage_uuid, deadline,
                    workspace_root, project_id, project_directory, auth_mode,
                )
                self.respond(200, {
                    "running": bool(current.get("State", {}).get("Running")),
                    "container_id": str(current.get("Id", ""))[:12],
                    "auth_mode": auth_mode,
                    "session_mode": "new",
                    "session_id": None,
                })
                return

            if self.path == "/v1/auth-mode":
                auth_mode = str(data.get("auth_mode", "")).lower()
                self.respond(200, switch_workspace_auth_mode(reservation_id, auth_mode))
                return

            if self.path == "/v1/deadline":
                deadline = int(data.get("deadline_epoch", 0))
                now = int(time.time())
                if deadline <= now or deadline > now + (9 * 60 * 60):
                    raise ValueError("invalid_deadline")
                state = load_state()
                if state.get("reservation_id") != reservation_id:
                    raise RuntimeError("reservation_mismatch")
                write_deadline(reservation_id, deadline)
                state_set(
                    reservation_id,
                    str(state["storage_uuid"]),
                    deadline,
                    str(state.get("workspace_root", "")),
                    str(state.get("project_id", "")),
                    str(state.get("project_directory", "")),
                    str(state.get("auth_mode", "personal")),
                    str(state.get("session_mode", "new")),
                    str(state.get("session_id", "")) or None,
                )
                self.respond(200, {"updated": True})
                return

            if self.path == "/v1/stop":
                stop_workspace(reservation_id)
                self.respond(200, {"stopped": True})
                return

            if self.path == "/v1/refresh":
                workspace_root = str(data.get("workspace_root", "")).lower()
                project_id = str(data.get("project_id", "")).lower()
                project_directory = str(data.get("project_directory", "")).lower()
                validate_workspace_path(workspace_root, project_directory)
                if not UUID_RE.fullmatch(project_id):
                    raise ValueError("invalid_project")
                self.respond(200, refresh_workspace(
                    reservation_id, workspace_root, project_id, project_directory,
                ))
                return

            raise ValueError("unsupported_operation")
        except ValueError as exc:
            self.respond(422, {"error": str(exc)})
        except RuntimeError as exc:
            status = 409 if str(exc) in (
                "resource_locked", "reservation_mismatch", "project_mismatch",
                "reservation_expired", "workspace_not_running", "workspace_active",
                "session_history_unavailable", "session_not_found", "runtime_identity_mismatch",
                "session_active",
                "runtime_image_mismatch", "runtime_security_revision_mismatch",
                "runtime_network_identity_mismatch", "workspace_capacity_full",
                "company_codex_occupied", "company_codex_resource_locked", "ai_grant_active",
                "ai_network_disconnect_failed", "ai_lease_occupied",
            ) else 500
            self.respond(status, {"error": str(exc)})
        except Exception as exc:
            print(f"manager request error: {type(exc).__name__}", flush=True)
            self.respond(500, {"error": "manager_error"})


if __name__ == "__main__":
    threading.Thread(target=watchdog, name="deadline-watchdog", daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
