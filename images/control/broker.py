#!/usr/bin/env python3
"""Reservation-bound AI Broker for fixed local models and media workflows."""

from __future__ import annotations

import hashlib
import hmac
import http.client
import json
import math
import mimetypes
import os
import pathlib
import re
import secrets
import select
import shutil
import socket
import stat
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
TOKEN_RE = re.compile(r"^[A-Za-z0-9._~-]{32,2048}$")
SAFE_DOWNLOAD_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._ -]{0,254}$")
STATE_PATH = pathlib.Path("/var/lib/movie-broker/state.json")
UPLOAD_ROOT = pathlib.Path("/var/lib/movie-broker/uploads")
ARTIFACT_ROOT = pathlib.Path("/var/lib/movie-broker/artifacts")
STYLE_DEMO_ROOT = pathlib.Path(
    os.environ.get("MOVIE_STYLE_DEMO_ROOT", "/var/lib/movie-style-demos")
)
H3_STYLE_SKILLS_PATH = pathlib.Path(
    os.environ.get(
        "MOVIE_H3_STYLE_SKILLS_FILE",
        str(pathlib.Path(__file__).with_name("h3-style-skills.txt")),
    )
)
MANAGER_URL = os.environ.get("MOVIE_MANAGER_URL", "http://movie-workspace-manager:8080").rstrip("/")
ADAPTER_URL = os.environ.get("MOVIE_H3_ADAPTER_URL", "http://movie-h3-adapter:8080").rstrip("/")
MODEL_MANIFEST_SHA256 = os.environ.get("MOVIE_MODEL_MANIFEST_SHA256", "").strip().lower()
if MODEL_MANIFEST_SHA256 and not re.fullmatch(r"[0-9a-f]{64}", MODEL_MANIFEST_SHA256):
    raise SystemExit("MOVIE_MODEL_MANIFEST_SHA256 must be empty or a lowercase SHA-256 digest")
QWEN_SOCKET_PATH = os.environ.get("MOVIE_QWEN_SOCKET", "/run/movie-qwen/qwen.sock")
QWEN_MODEL = "qwen3.8-27b-huihui-abliterated-nvfp4"
QWEN_MODEL_ALIASES = {
    QWEN_MODEL,
    "qwen3.8-27b",
    "qwen3.8-27b-uncensored",
    "qwen3.8-27b-huihui-abliterated",
    "qwen3.8-27b-uncensored-nvfp4",
}
DEEPSEEK_SOCKET_PATH = os.environ.get(
    "MOVIE_DEEPSEEK_SOCKET", "/run/movie-qwen/deepseek.sock"
)
DEEPSEEK_MODEL = "deepseek-v4-flash-0731"
DEEPSEEK_MODEL_ALIASES = {
    DEEPSEEK_MODEL,
}
MAX_SPEC_BYTES = 64 * 1024
MAX_QWEN_REQUEST_BYTES = 16 * 1024 * 1024
MAX_UPLOAD_BYTES = 32 * 1024 * 1024
MAX_ARTIFACT_BYTES = 8 * 1024 * 1024 * 1024
H3_RESOLUTIONS = {
    "608x352": (608, 352), "736x416": (736, 416),
    "864x480": (864, 480), "960x544": (960, 544),
    "1344x768": (1344, 768),
    "768x768": (768, 768), "480x864": (480, 864),
    "416x736": (416, 736), "352x608": (352, 608),
}
H3_RESOLUTION_ALIASES = {"768p": "1344x768"}
H3_STYLE_SKILL_RE = re.compile(r"^h3-[a-z0-9]+(?:-[a-z0-9]+)*$")
H3_STANDARD_WORKFLOW = "standard"
H3_PDD_ACC_8STEP_WORKFLOW = "pdd-acc-8step"
H3_WORKFLOW_PRESETS = frozenset({H3_STANDARD_WORKFLOW, H3_PDD_ACC_8STEP_WORKFLOW})
H3_PDD_ACC_FILE = "MiniMax-H3-FL2VA-Acc-8Step.safetensors"
H3_GENERAL_CONTENT_PROFILE = "general"
H3_ADULT_CONTENT_PROFILE = "adult"
H3_CONTENT_PROFILES = frozenset({H3_GENERAL_CONTENT_PROFILE, H3_ADULT_CONTENT_PROFILE})
H3_GENERAL_UNET = "minimax_h3_fl2va_int8_convrot.safetensors"
H3_ADULT_UNET = "PinkCherry_fl2va_MiniMax_H3_int8_convrot-beta-0.6.safetensors"
H3_REF2VA_UNET = "minimax_h3_ref2va_pruned_int8_convrot.safetensors"
H3_REF2VA_MAX_IMAGES = 9
H3_REF2VA_MAX_VIDEOS = 3
Z_IMAGE_RESOLUTIONS = {
    "1024x1024": (1024, 1024),
    "1344x768": (1344, 768),
}
Z_IMAGE_RESOLUTION_ALIASES = {
    "768p": "1344x768",
    "16:9": "1344x768",
    "16：9": "1344x768",
}
HUNYUAN_IMAGE_RESOLUTIONS = {"1024x1024": "1024x1024 (1:1 Square)"}
Z_IMAGE_MODEL = "z-image-turbo"
HUNYUAN_IMAGE_MODEL = "HunyuanImage-3.0-Instruct"
DEFAULT_LOCAL_IMAGE_MODEL = Z_IMAGE_MODEL
IN_FLIGHT_JOB_STATUSES = {"queued", "preparing", "running", "postprocessing"}
ACTIVE_JOB_STATUSES = IN_FLIGHT_JOB_STATUSES | {"cancel_requested"}
LOCAL_IMAGE_MODEL_ALIASES = {
    Z_IMAGE_MODEL: Z_IMAGE_MODEL,
    "z-image": Z_IMAGE_MODEL,
    HUNYUAN_IMAGE_MODEL.casefold(): HUNYUAN_IMAGE_MODEL,
    "hunyuan-image-3.0-instruct": HUNYUAN_IMAGE_MODEL,
    "hunyuan-image-3": HUNYUAN_IMAGE_MODEL,
}
LOCAL_IMAGE_MODELS = (
    {
        "id": Z_IMAGE_MODEL,
        "local": True,
        "default": True,
        "scope": "fast square or 16:9 768p text-to-image drafts and storyboard stills",
        "resolutions": list(Z_IMAGE_RESOLUTIONS),
        "presets": {
            "768p": {"aspect_ratio": "16:9", "resolution": "1344x768"},
        },
    },
    {
        "id": HUNYUAN_IMAGE_MODEL,
        "local": True,
        "default": False,
        "scope": "instruction-focused 1024x1024 text-to-image stills",
        "resolutions": list(HUNYUAN_IMAGE_RESOLUTIONS),
        "presets": {},
    },
)
STYLE_IMAGE_MODELS = (
    "svdq-fp4_r32-flux.1-krea-dev.safetensors",
    "svdq-fp4_r32-flux.1-dev.safetensors",
    "vixonsNoobIllust_v14.safetensors",
    "autismmixSDXL_autismmixPony.safetensors",
    "animagine-xl-4.0-opt.safetensors",
    "bubbliCartoonIL_v10.safetensors",
    "aMixIllustrious_aMix.safetensors",
    "obsessionIllustrious_vPredV11.safetensors",
    "illustriousnxtXLBy_v10.safetensors",
)
STYLE_IMAGE_MODEL_SET = set(STYLE_IMAGE_MODELS)
STYLE_TASK_RE = re.compile(
    r"^movie-style-[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)
LOCAL_MODEL_ONLY_INSTRUCTION = (
    "Movie Workspace model boundary: you may use only local AI model capabilities. "
    "For every image request use movie-ai image generate with z-image-turbo by default; "
    "use HunyuanImage-3.0-Instruct only when the current user request explicitly names "
    "Hunyuan or 混元. For audiovisual video use movie-ai h3 generate. "
    "An explicit still-image 768p or 16:9 request means z-image-turbo at 1344x768; "
    "do not map that exact preset to HunyuanImage. "
    "Never invoke OpenAI image generation, web search, plugins, apps, or any hosted AI API."
)
QWEN_LOCAL_ONLY_INSTRUCTION = LOCAL_MODEL_ONLY_INSTRUCTION
STATE_LOCK = threading.RLock()
MALFORMED_TOOL_CALL_LOCK = threading.Lock()
MALFORMED_TOOL_CALLS_QUARANTINED = 0
MALFORMED_TOOL_CALL_RECOVERY_INSTRUCTION = (
    "Local tool-call recovery: one or more prior function calls contained invalid "
    "JSON object arguments and were quarantined together with their matching "
    "outputs before conversation replay. Those calls did not complete successfully; "
    "do not assume that they produced any side effects. Re-evaluate the latest user "
    "request and reissue any still-needed tool calls with complete valid JSON arguments."
)


class JobCancelled(RuntimeError):
    pass


def load_h3_style_skills(path: pathlib.Path) -> frozenset[str]:
    try:
        names = [line.strip() for line in path.read_text(encoding="utf-8").splitlines()]
    except OSError as exc:
        raise SystemExit(f"unable to read H3 style registry: {path}") from exc
    if not names or any(not name or not H3_STYLE_SKILL_RE.fullmatch(name) for name in names):
        raise SystemExit("invalid H3 style registry")
    if len(names) != len(set(names)):
        raise SystemExit("duplicate H3 style registry entry")
    return frozenset(names)


H3_STYLE_SKILLS = load_h3_style_skills(H3_STYLE_SKILLS_PATH)


def read_secret(name: str) -> bytes:
    path = pathlib.Path(os.environ.get(name, ""))
    value = path.read_bytes().strip()
    if len(value) < 32:
        raise SystemExit(f"{name} must contain at least 32 bytes")
    return value


CONTROL_SECRET = read_secret("MOVIE_BROKER_SECRET_FILE")
MANAGER_SECRET = read_secret("MOVIE_BROKER_MANAGER_SECRET_FILE")
COMPUTE_NODE_ID = os.environ.get(
    "MOVIE_COMPUTE_NODE_ID", "20000000-0000-4000-8000-000000000020"
).strip().lower()
if not UUID_RE.fullmatch(COMPUTE_NODE_ID):
    raise SystemExit("MOVIE_COMPUTE_NODE_ID must be a UUID")
WORKER_REVISION = os.environ.get("MOVIE_WORKER_REVISION", "").strip()[:128]
WORKFLOW_REVISION = os.environ.get("MOVIE_WORKFLOW_REVISION", "").strip()[:128]


class QwenUnixHTTPConnection(http.client.HTTPConnection):
    """HTTP connection whose transport is the administrator-owned tunnel socket."""

    def connect(self) -> None:
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(QWEN_SOCKET_PATH)


class DeepSeekUnixHTTPConnection(http.client.HTTPConnection):
    """HTTP connection through the administrator-owned DeepSeek tunnel socket."""

    def connect(self) -> None:
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(DEEPSEEK_SOCKET_PATH)


class ClientDisconnectCancellation:
    """Close the Qwen Unix request when the Workspace proxy disconnects."""

    def __init__(self, client: socket.socket) -> None:
        self.client = client
        self.cancelled = threading.Event()
        self.stopped = threading.Event()
        self.lock = threading.Lock()
        self.connection: http.client.HTTPConnection | None = None
        self.response: http.client.HTTPResponse | None = None
        self.thread = threading.Thread(
            target=self._watch,
            name="movie-broker-client-watch",
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
                if self.client.recv(1, socket.MSG_PEEK) == b"":
                    self._cancel()
                return
            except OSError:
                if not self.stopped.is_set():
                    self._cancel()
                return


def qwen_ready() -> bool:
    try:
        return pathlib.Path(QWEN_SOCKET_PATH).is_socket()
    except OSError:
        return False


def deepseek_ready() -> bool:
    try:
        return pathlib.Path(DEEPSEEK_SOCKET_PATH).is_socket()
    except OSError:
        return False


def dependency_ready(base_url: str) -> bool:
    try:
        with urllib.request.urlopen(base_url + "/healthz", timeout=3) as response:
            raw = response.read(64 * 1024)
            payload = json.loads(raw)
            return response.status == 200 and isinstance(payload, dict) and payload.get("ok") is True
    except (OSError, ValueError, urllib.error.URLError):
        return False


def _response_content_text(content: Any) -> str:
    if isinstance(content, str):
        return content
    if not isinstance(content, list):
        return ""
    parts: list[str] = []
    for part in content:
        if not isinstance(part, dict):
            continue
        if part.get("type") in {"input_text", "text"} and isinstance(part.get("text"), str):
            parts.append(part["text"])
    return "\n".join(parts)


def _valid_function_arguments(arguments: Any) -> bool:
    if isinstance(arguments, dict):
        return True
    if not isinstance(arguments, str) or not arguments.strip():
        return False
    try:
        return isinstance(json.loads(arguments), dict)
    except (TypeError, ValueError, json.JSONDecodeError):
        return False


def quarantine_malformed_function_call_history(
    raw_input: Any,
) -> tuple[Any, int]:
    """Drop malformed historical function calls and their paired outputs."""
    if not isinstance(raw_input, list):
        return raw_input, 0

    bad_indexes: set[int] = set()
    bad_call_ids: set[str] = set()
    for index, item in enumerate(raw_input):
        if not isinstance(item, dict) or item.get("type") != "function_call":
            continue
        if _valid_function_arguments(item.get("arguments")):
            continue
        bad_indexes.add(index)
        call_id = item.get("call_id")
        if isinstance(call_id, str) and call_id:
            bad_call_ids.add(call_id)

    if not bad_indexes:
        return list(raw_input), 0

    filtered: list[Any] = []
    for index, item in enumerate(raw_input):
        if index in bad_indexes:
            continue
        if (
            isinstance(item, dict)
            and item.get("type") == "function_call_output"
            and item.get("call_id") in bad_call_ids
        ):
            continue
        filtered.append(item)
    return filtered, len(bad_indexes)


def record_malformed_tool_call_quarantine(count: int) -> int:
    global MALFORMED_TOOL_CALLS_QUARANTINED
    if count < 1:
        return malformed_tool_call_quarantine_count()
    with MALFORMED_TOOL_CALL_LOCK:
        MALFORMED_TOOL_CALLS_QUARANTINED += count
        total = MALFORMED_TOOL_CALLS_QUARANTINED
    print(
        f"broker malformed-tool-call quarantine count={count} total={total}",
        flush=True,
    )
    return total


def malformed_tool_call_quarantine_count() -> int:
    with MALFORMED_TOOL_CALL_LOCK:
        return MALFORMED_TOOL_CALLS_QUARANTINED


def rewrite_local_responses_payload(
    payload: dict[str, Any],
    *,
    aliases: set[str],
    upstream_model: str,
    unsupported_error: str,
) -> dict[str, Any]:
    """Normalize Codex Responses requests to the approved local-model subset."""
    selected_model = str(payload.get("model", ""))
    if selected_model not in aliases:
        raise ValueError(unsupported_error)

    rewritten = dict(payload)
    rewritten["model"] = upstream_model

    instructions: list[str] = []
    instructions.append(LOCAL_MODEL_ONLY_INSTRUCTION)
    existing_instructions = rewritten.get("instructions")
    if isinstance(existing_instructions, str) and existing_instructions.strip():
        instructions.append(existing_instructions.strip())

    raw_input = rewritten.get("input")
    if isinstance(raw_input, list):
        normalized_input: list[Any] = []
        for item in raw_input:
            if isinstance(item, dict) and item.get("role") in {"developer", "system"}:
                text = _response_content_text(item.get("content"))
                if text.strip():
                    instructions.append(text.strip())
                continue
            normalized_input.append(item)
        normalized_input, quarantined_count = quarantine_malformed_function_call_history(
            normalized_input
        )
        rewritten["input"] = normalized_input
        if quarantined_count:
            instructions.append(MALFORMED_TOOL_CALL_RECOVERY_INSTRUCTION)
            record_malformed_tool_call_quarantine(quarantined_count)
    if instructions:
        rewritten["instructions"] = "\n\n".join(instructions)

    raw_tools = rewritten.get("tools")
    if isinstance(raw_tools, list):
        function_tools: list[dict[str, Any]] = []
        for tool in raw_tools:
            if not isinstance(tool, dict) or tool.get("type") != "function":
                continue
            normalized = {
                key: tool[key]
                for key in ("type", "name", "description", "parameters", "strict")
                if key in tool
            }
            if isinstance(normalized.get("name"), str) and isinstance(normalized.get("parameters"), dict):
                function_tools.append(normalized)
        if function_tools:
            rewritten["tools"] = function_tools
        else:
            rewritten.pop("tools", None)
            rewritten.pop("tool_choice", None)
            rewritten.pop("parallel_tool_calls", None)
    return rewritten


def rewrite_qwen_responses_payload(payload: dict[str, Any]) -> dict[str, Any]:
    return rewrite_local_responses_payload(
        payload,
        aliases=QWEN_MODEL_ALIASES,
        upstream_model=QWEN_MODEL,
        unsupported_error="unsupported_qwen_model",
    )


def rewrite_deepseek_responses_payload(payload: dict[str, Any]) -> dict[str, Any]:
    return rewrite_local_responses_payload(
        payload,
        aliases=DEEPSEEK_MODEL_ALIASES,
        upstream_model=DEEPSEEK_MODEL,
        unsupported_error="unsupported_deepseek_model",
    )


def qwen_user_key(claims: dict[str, Any]) -> str:
    identity = f"movie-qwen:{claims['user_id']}".encode()
    return hmac.new(MANAGER_SECRET, identity, hashlib.sha256).hexdigest()


def default_state() -> dict[str, Any]:
    return {"jobs": {}, "uploads": {}}


def load_state() -> dict[str, Any]:
    try:
        state = json.loads(STATE_PATH.read_text(encoding="utf-8"))
        if isinstance(state, dict):
            state.setdefault("jobs", {})
            state.setdefault("uploads", {})
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


def reservation_has_active_jobs(state: dict[str, Any], reservation_id: str) -> bool:
    return any(
        job.get("reservation_id") == reservation_id
        and job.get("status") in ACTIVE_JOB_STATUSES
        for job in state.get("jobs", {}).values()
        if isinstance(job, dict)
    )


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


def active_claims_for_token(token: str) -> dict[str, Any] | None:
    if not TOKEN_RE.fullmatch(token):
        return None
    with STATE_LOCK:
        state = load_state()
    active = state.get("active")
    if not isinstance(active, dict) or int(active.get("expires_at", 0)) <= int(time.time()):
        return None
    if not hmac.compare_digest(str(active.get("token_hash", "")), token_hash(token)):
        return None
    return active


def active_reservation(reservation_id: str) -> bool:
    with STATE_LOCK:
        active = load_state().get("active")
    return bool(
        isinstance(active, dict)
        and active.get("reservation_id") == reservation_id
        and int(active.get("expires_at", 0)) > int(time.time())
    )


def register_active_claim(
    reservation_id: str,
    user_id: str,
    runtime_id: str,
    generation: int,
    token: str,
    expires_at: int,
    compute_node_id: str = COMPUTE_NODE_ID,
    *,
    now: int | None = None,
) -> None:
    current_time = int(time.time()) if now is None else now
    with STATE_LOCK:
        state = load_state()
        active = state.get("active", {})
        preserved_reservation = str(state.get("preserved_reservation_id", ""))
        same = active.get("reservation_id") == reservation_id and hmac.compare_digest(
            str(active.get("token_hash", "")), token_hash(token)
        )
        if runtime_id:
            same = same and active.get("runtime_id") == runtime_id \
                and int(active.get("generation", 0)) == generation
        if not same:
            previous = str(active.get("reservation_id", ""))
            if UUID_RE.fullmatch(previous) and int(active.get("expires_at", 0)) > current_time:
                raise ValueError("broker_occupied")
            if preserved_reservation != reservation_id:
                stale_reservation = previous if UUID_RE.fullmatch(previous) else preserved_reservation
                state = default_state()
                if UUID_RE.fullmatch(stale_reservation):
                    cleanup_reservation_files(stale_reservation)
        state.pop("preserved_reservation_id", None)
        state["active"] = {
            "reservation_id": reservation_id,
            "compute_node_id": compute_node_id,
            "user_id": user_id,
            "expires_at": expires_at,
            "token_hash": token_hash(token),
            "runtime_id": runtime_id or None,
            "generation": generation,
        }
        save_state(state)


def signed_manager_request(method: str, path: str, body: dict | None = None) -> dict[str, Any]:
    raw = b"" if body is None else json.dumps(body, separators=(",", ":")).encode()
    timestamp = str(int(time.time()))
    signature = hmac.new(
        MANAGER_SECRET,
        b"\n".join([timestamp.encode(), method.encode(), path.encode(), raw]),
        hashlib.sha256,
    ).hexdigest()
    request = urllib.request.Request(
        MANAGER_URL + path,
        data=raw if method != "GET" else None,
        method=method,
        headers={
            "Content-Type": "application/json",
            "X-Movie-Timestamp": timestamp,
            "X-Movie-Signature": signature,
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=250) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        try:
            message = json.load(error).get("error", f"manager_http_{error.code}")
        except Exception:
            message = f"manager_http_{error.code}"
        raise RuntimeError(str(message)) from error


def adapter_json(method: str, path: str, body: bytes | None = None, content_type: str = "application/json", timeout: int = 250) -> dict:
    request = urllib.request.Request(
        ADAPTER_URL + path,
        data=body,
        method=method,
        headers={"Content-Type": content_type},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            result = json.load(response)
            return result if isinstance(result, dict) else {}
    except urllib.error.HTTPError as error:
        try:
            message = json.load(error).get("error", f"comfy_http_{error.code}")
        except Exception:
            message = f"comfy_http_{error.code}"
        raise RuntimeError(str(message)) from error


def manager_status(claims: dict[str, Any]) -> dict[str, Any]:
    if claims.get("runtime_id") and int(claims.get("generation", 0)) > 0:
        path = "/v2/ai/status?" + urllib.parse.urlencode({
            "reservation_id": claims["reservation_id"],
            "runtime_id": claims["runtime_id"],
            "user_id": claims["user_id"],
            "generation": claims["generation"],
        })
    else:
        path = "/v1/ai/status?" + urllib.parse.urlencode({"reservation_id": claims["reservation_id"]})
    return signed_manager_request("GET", path)


def manager_prepare(claims: dict[str, Any], capability: str) -> dict[str, Any]:
    if claims.get("runtime_id") and int(claims.get("generation", 0)) > 0:
        return signed_manager_request("POST", "/v2/ai/prepare", {
            "reservation_id": claims["reservation_id"],
            "runtime_id": claims["runtime_id"],
            "user_id": claims["user_id"],
            "generation": claims["generation"],
            "capability": capability,
        })
    return signed_manager_request("POST", "/v1/ai/prepare", {
        "reservation_id": claims["reservation_id"], "capability": capability,
    })


def public_gpu_status(status: dict[str, Any]) -> dict[str, Any]:
    gpu = status.get("gpu", {})
    processes = gpu.get("processes", []) if isinstance(gpu, dict) else []
    owner_usage: dict[str, int] = {}
    for process in processes:
        owner = str(process.get("owner", "unknown"))
        owner_usage[owner] = owner_usage.get(owner, 0) + int(process.get("used_memory_mib", 0))
    services = status.get("services", {})
    return {
        "mode": "real",
        "real_gpu_available": True,
        "h3_submission_allowed": bool(status.get("h3_submission_allowed")),
        "active_profile": status.get("active_profile"),
        "gpu": {
            "name": gpu.get("name"),
            "memory_used_mib": gpu.get("memory_used_mib"),
            "memory_total_mib": gpu.get("memory_total_mib"),
            "power_draw_w": gpu.get("power_draw_w"),
            "power_limit_w": gpu.get("power_limit_w"),
            "temperature_c": gpu.get("temperature_c"),
            "utilization_percent": gpu.get("utilization_percent"),
            "process_owner_memory_mib": owner_usage,
            "unknown_process_count": len(status.get("unknown_processes", [])),
        },
        "services": {
            "comfyui": {
                "active": services.get("comfyui", {}).get("active"),
                "healthy": services.get("comfyui", {}).get("healthy"),
            },
            "qwen": {"active": services.get("qwen", {}).get("active")},
        },
        "message": "Each real submission performs the fixed service-aware preflight.",
    }


def require_string(spec: dict, key: str, maximum: int) -> str:
    value = spec.get(key)
    if not isinstance(value, str) or not value.strip() or len(value) > maximum:
        raise ValueError(f"{key}_must_be_1_to_{maximum}_characters")
    return value.strip()


def integer_between(spec: dict, key: str, minimum: int, maximum: int, default: int) -> int:
    value = spec.get(key, default)
    if isinstance(value, bool) or not isinstance(value, int) or value < minimum or value > maximum:
        raise ValueError(f"{key}_must_be_{minimum}_to_{maximum}")
    return value


def upload_record(reservation_id: str, upload_id: str) -> dict[str, Any]:
    if not UUID_RE.fullmatch(upload_id):
        raise ValueError("invalid_upload_id")
    with STATE_LOCK:
        record = load_state().get("uploads", {}).get(upload_id)
    if not isinstance(record, dict) or record.get("reservation_id") != reservation_id:
        raise ValueError("upload_not_found")
    path = pathlib.Path(str(record.get("path", "")))
    try:
        path.relative_to(UPLOAD_ROOT / reservation_id)
    except ValueError as exc:
        raise ValueError("invalid_upload_path") from exc
    if not path.is_file():
        raise ValueError("upload_not_found")
    return record


def upload_media_type(record: dict[str, Any]) -> str:
    media_type = record.get("media_type")
    if media_type in {"image", "video"}:
        return str(media_type)
    return "video" if str(record.get("extension", "")) in {"mp4", "webm", "mov", "m4v"} else "image"


def classify_upload(contents: bytes, filename: str) -> tuple[str, str]:
    if contents.startswith((b"GIF87a", b"GIF89a")):
        return "image", "gif"
    if contents.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image", "png"
    if contents.startswith(b"\xff\xd8\xff"):
        return "image", "jpg"
    if len(contents) >= 12 and contents[:4] == b"RIFF" and contents[8:12] == b"WEBP":
        return "image", "webp"
    if contents.startswith(b"\x1a\x45\xdf\xa3"):
        return "video", "webm"
    if len(contents) >= 12 and contents[4:8] == b"ftyp":
        suffix = pathlib.Path(filename).suffix.lower().removeprefix(".")
        return "video", suffix if suffix in {"mp4", "mov", "m4v"} else "mp4"
    raise ValueError("unsupported_media_format")


def probe_reference_video(path: pathlib.Path) -> dict[str, Any]:
    try:
        completed = subprocess.run(
            [
                "ffprobe", "-v", "error", "-show_entries",
                "format=duration:stream=codec_type", "-of", "json", str(path),
            ],
            check=False,
            capture_output=True,
            text=True,
            timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError("reference_video_probe_unavailable") from exc
    if completed.returncode != 0:
        raise ValueError("invalid_reference_video")
    try:
        payload = json.loads(completed.stdout)
        duration = float(payload.get("format", {}).get("duration"))
        stream_types = {
            str(stream.get("codec_type"))
            for stream in payload.get("streams", [])
            if isinstance(stream, dict)
        }
    except (TypeError, ValueError, json.JSONDecodeError) as exc:
        raise ValueError("invalid_reference_video") from exc
    if "video" not in stream_types:
        raise ValueError("reference_video_stream_required")
    if not math.isfinite(duration) or duration < 2.0 or duration > 15.0:
        raise ValueError("reference_video_duration_must_be_2_to_15_seconds")
    return {"duration_seconds": round(duration, 3), "has_audio": "audio" in stream_types}


def reference_uploads(
    spec: dict[str, Any], reservation_id: str, key: str, media_type: str, maximum: int,
) -> list[str]:
    value = spec.get(key, [])
    if not isinstance(value, list) or len(value) > maximum:
        raise ValueError(f"{key}_must_be_an_array_with_at_most_{maximum}_items")
    if any(not isinstance(upload_id, str) for upload_id in value):
        raise ValueError(f"{key}_must_contain_upload_ids")
    if len(value) != len(set(value)):
        raise ValueError(f"{key}_must_not_contain_duplicates")
    for upload_id in value:
        record = upload_record(reservation_id, upload_id)
        if upload_media_type(record) != media_type:
            raise ValueError(f"{key}_contains_wrong_media_type")
    return value


def validate_h3_spec(spec: dict, reservation_id: str) -> dict[str, Any]:
    prompt = require_string(spec, "prompt", 12000)
    mode = str(spec.get("mode", "t2va")).lower()
    if mode not in {"t2va", "i2va", "fl2va", "l2va", "ref2va"}:
        raise ValueError("mode_must_be_t2va_i2va_fl2va_l2va_or_ref2va")
    resolution = str(spec.get("resolution", "864x480")).strip().lower()
    resolution = H3_RESOLUTION_ALIASES.get(resolution, resolution)
    if resolution not in H3_RESOLUTIONS:
        raise ValueError("unsupported_h3_resolution")
    duration = integer_between(spec, "duration_seconds", 4, 15, 5)
    workflow_preset = str(spec.get("workflow_preset", H3_STANDARD_WORKFLOW)).strip().lower()
    if workflow_preset not in H3_WORKFLOW_PRESETS:
        raise ValueError("unsupported_h3_workflow_preset")
    content_profile = str(spec.get("content_profile", H3_GENERAL_CONTENT_PROFILE)).strip().lower()
    if content_profile not in H3_CONTENT_PROFILES:
        raise ValueError("unsupported_h3_content_profile")
    if mode == "ref2va" and content_profile != H3_GENERAL_CONTENT_PROFILE:
        raise ValueError("ref2va_requires_general_content_profile")
    if mode == "ref2va" and workflow_preset != H3_STANDARD_WORKFLOW:
        raise ValueError("ref2va_requires_standard_workflow")
    unet_name = H3_REF2VA_UNET if mode == "ref2va" else (
        H3_ADULT_UNET if content_profile == H3_ADULT_CONTENT_PROFILE else H3_GENERAL_UNET
    )
    if workflow_preset == H3_PDD_ACC_8STEP_WORKFLOW:
        steps = integer_between(spec, "steps", 8, 50, 8)
        if steps != 8:
            raise ValueError("pdd_acc_8step_requires_steps_8")
    else:
        steps = integer_between(spec, "steps", 8, 50, 20)
    seed = integer_between(spec, "seed", 0, (1 << 63) - 1, secrets.randbits(63))
    first = spec.get("first_frame_upload_id")
    last = spec.get("last_frame_upload_id")
    if mode in {"i2va", "fl2va"} and not isinstance(first, str):
        raise ValueError("first_frame_is_required")
    if mode in {"l2va", "fl2va"} and not isinstance(last, str):
        raise ValueError("last_frame_is_required")
    if mode == "t2va" and (first is not None or last is not None):
        raise ValueError("t2va_does_not_accept_frames")
    if isinstance(first, str):
        if upload_media_type(upload_record(reservation_id, first)) != "image":
            raise ValueError("first_frame_must_be_an_image")
    if isinstance(last, str):
        if upload_media_type(upload_record(reservation_id, last)) != "image":
            raise ValueError("last_frame_must_be_an_image")
    reference_images = reference_uploads(
        spec, reservation_id, "reference_image_upload_ids", "image", H3_REF2VA_MAX_IMAGES,
    )
    reference_videos = reference_uploads(
        spec, reservation_id, "reference_video_upload_ids", "video", H3_REF2VA_MAX_VIDEOS,
    )
    if mode == "ref2va":
        if first is not None or last is not None:
            raise ValueError("ref2va_does_not_accept_first_or_last_frame")
        if not reference_images and not reference_videos:
            raise ValueError("ref2va_requires_reference_media")
    elif reference_images or reference_videos:
        raise ValueError("reference_media_requires_ref2va_mode")
    ref_image_size = str(spec.get("ref_image_size", "match")).strip().lower()
    if ref_image_size not in {"match", "max"}:
        raise ValueError("ref_image_size_must_be_match_or_max")
    if mode != "ref2va" and "ref_image_size" in spec:
        raise ValueError("ref_image_size_requires_ref2va_mode")
    style_skill = spec.get("style_skill")
    if style_skill is not None:
        if not isinstance(style_skill, str) or style_skill not in H3_STYLE_SKILLS:
            raise ValueError("unsupported_h3_style_skill")
    return {
        "prompt": prompt, "mode": mode, "resolution": resolution,
        "duration_seconds": duration, "steps": steps, "seed": seed,
        "workflow_preset": workflow_preset,
        "content_profile": content_profile, "unet_name": unet_name,
        "first_frame_upload_id": first, "last_frame_upload_id": last,
        "reference_image_upload_ids": reference_images,
        "reference_video_upload_ids": reference_videos,
        "ref_image_size": ref_image_size,
        "style_skill": style_skill,
    }


def validate_image_spec(spec: dict) -> dict[str, Any]:
    prompt = require_string(spec, "prompt", 8000)
    requested_model = str(spec.get("model", DEFAULT_LOCAL_IMAGE_MODEL)).strip()
    model = LOCAL_IMAGE_MODEL_ALIASES.get(requested_model.casefold())
    if model is None:
        raise ValueError("unsupported_image_model")
    resolution = str(spec.get("resolution", "1024x1024")).strip().lower()
    if model == Z_IMAGE_MODEL:
        resolution = Z_IMAGE_RESOLUTION_ALIASES.get(resolution, resolution)
    resolutions = Z_IMAGE_RESOLUTIONS if model == Z_IMAGE_MODEL else HUNYUAN_IMAGE_RESOLUTIONS
    if resolution not in resolutions:
        raise ValueError("unsupported_image_resolution")
    validated = {
        "model": model,
        "prompt": prompt,
        "resolution": resolution,
        "seed": integer_between(spec, "seed", 0, (1 << 63) - 1, secrets.randbits(63)),
        "steps": integer_between(spec, "steps", 4, 12, 8),
    }
    if model == HUNYUAN_IMAGE_MODEL:
        guidance = float(spec.get("guidance_scale", 2.5))
        flow_shift = float(spec.get("flow_shift", 2.3))
        if not 1.0 <= guidance <= 5.0:
            raise ValueError("guidance_scale_must_be_1_to_5")
        if not 1.0 <= flow_shift <= 5.0:
            raise ValueError("flow_shift_must_be_1_to_5")
        validated["guidance_scale"] = guidance
        validated["flow_shift"] = flow_shift
    return validated


def validate_style_image_spec(spec: dict) -> dict[str, Any]:
    model = require_string(spec, "model", 255)
    if model not in STYLE_IMAGE_MODEL_SET:
        raise ValueError("unsupported_style_model")
    width = integer_between(spec, "width", 512, 1536, 1024)
    height = integer_between(spec, "height", 512, 1536, 1024)
    if width % 64:
        raise ValueError("width_must_be_divisible_by_64")
    if height % 64:
        raise ValueError("height_must_be_divisible_by_64")
    return {
        "model": model,
        "prompt": require_string(spec, "prompt", 8000),
        "width": width,
        "height": height,
        "seed": integer_between(spec, "seed", 0, (1 << 63) - 1, secrets.randbits(63)),
    }


def style_image_models() -> dict[str, Any]:
    upstream = adapter_json("GET", "/style/models", timeout=30)
    models_by_id = {
        str(item.get("id")): item
        for item in upstream.get("models", [])
        if isinstance(item, dict) and str(item.get("id")) in STYLE_IMAGE_MODEL_SET
    }
    models = [models_by_id[model_id] for model_id in STYLE_IMAGE_MODELS if model_id in models_by_id]
    if len(models) != len(STYLE_IMAGE_MODELS):
        raise RuntimeError("style_model_catalog_mismatch")
    reference_only = [
        item for item in upstream.get("reference_only_models", [])
        if isinstance(item, dict)
    ]
    return {"models": models, "reference_only_models": reference_only}


def upload_to_comfy(record: dict[str, Any], reservation_id: str, upload_id: str) -> str:
    path = pathlib.Path(record["path"])
    extension = str(record["extension"])
    remote_name = f"movie_portal_{reservation_id.replace('-', '')}_{upload_id.replace('-', '')}.{extension}"
    boundary = "----movie-portal-" + uuid.uuid4().hex
    prefix = (
        f"--{boundary}\r\n"
        f"Content-Disposition: form-data; name=\"image\"; filename=\"{remote_name}\"\r\n"
        f"Content-Type: {mimetypes.guess_type(remote_name)[0] or 'application/octet-stream'}\r\n\r\n"
    ).encode()
    suffix = (
        f"\r\n--{boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\ninput"
        f"\r\n--{boundary}\r\nContent-Disposition: form-data; name=\"overwrite\"\r\n\r\ntrue"
        f"\r\n--{boundary}--\r\n"
    ).encode()
    body = prefix + path.read_bytes() + suffix
    result = adapter_json("POST", "/comfy/upload/image", body, f"multipart/form-data; boundary={boundary}")
    return str(result.get("name") or remote_name)


def h3_workflow(spec: dict[str, Any], reservation_id: str, job_id: str) -> dict[str, Any]:
    width, height = H3_RESOLUTIONS[spec["resolution"]]
    h3_node_type = "MiniMaxH3ReferenceToVideo" if spec["mode"] == "ref2va" else "MiniMaxH3ImageToVideo"
    h3_inputs: dict[str, Any] = {
        "clip": ["2", 0], "vae": ["3", 0], "prompt": spec["prompt"],
        "width": width, "height": height, "length": spec["duration_seconds"] * 24 + 2,
    }
    if spec["mode"] == "ref2va":
        h3_inputs.update({"audio_vae": ["4", 0], "ref_image_size": spec["ref_image_size"]})
    prompt: dict[str, Any] = {
        "2": {"class_type": "CLIPLoader", "inputs": {
            "clip_name": "qwen3vl_32b_minimax_h3_int8_convrot.safetensors", "type": "minimax", "device": "default",
        }},
        "3": {"class_type": "VAELoader", "inputs": {"vae_name": "minimax_h3_video_vae_fp16.safetensors"}},
        "4": {"class_type": "VAELoader", "inputs": {"vae_name": "minimax_h3_audio_vae_fp32.safetensors"}},
        "5": {"class_type": "UNETLoader", "inputs": {
            "unet_name": spec["unet_name"], "weight_dtype": "default",
        }},
        "90": {"class_type": "ModelAttentionBackend", "inputs": {"model": ["5", 0], "attention": "comfy kitchen attention"}},
        "6": {"class_type": h3_node_type, "inputs": h3_inputs},
        "7": {"class_type": "RandomNoise", "inputs": {"noise_seed": spec["seed"]}},
        "12": {"class_type": "VAEDecode", "inputs": {"samples": ["11", 0], "vae": ["3", 0]}},
        "13": {"class_type": "VAEDecodeAudio", "inputs": {"samples": ["11", 0], "vae": ["4", 0]}},
        "14": {"class_type": "CreateVideo", "inputs": {
            "images": ["12", 0], "audio": ["13", 0], "fps": 24.0, "bit_depth": 8,
        }},
        "15": {"class_type": "SaveVideo", "inputs": {
            "video": ["14", 0], "filename_prefix": f"movie_portal/{reservation_id}/{job_id}",
            "format": "mp4", "codec": "auto",
        }},
    }
    if spec["workflow_preset"] == H3_PDD_ACC_8STEP_WORKFLOW:
        prompt.update({
            "91": {"class_type": "MiniMaxH3SigmaShift", "inputs": {
                "model": ["90", 0], "shift_video": 12.0, "shift_audio": 3.0,
            }},
            "92": {"class_type": "MiniMaxH3PDDAccApply", "inputs": {
                "model": ["91", 0], "pdd_file": H3_PDD_ACC_FILE, "nfe": "8",
                "lora_strength": 1.0, "head_strength": 1.0, "on_off_grid": "error",
            }},
            "8": {"class_type": "BasicGuider", "inputs": {
                "model": ["92", 0], "conditioning": ["6", 0],
            }},
            "10": {"class_type": "KSamplerSelect", "inputs": {"sampler_name": "euler"}},
            "11": {"class_type": "SamplerCustomAdvanced", "inputs": {
                "noise": ["7", 0], "guider": ["8", 0], "sampler": ["10", 0],
                "sigmas": ["92", 1], "latent_image": ["6", 1],
            }},
        })
    else:
        prompt.update({
            "8": {"class_type": "BasicGuider", "inputs": {
                "model": ["90", 0], "conditioning": ["6", 0],
            }},
            "9": {"class_type": "BasicScheduler", "inputs": {
                "model": ["90", 0], "scheduler": "simple",
                "steps": spec["steps"], "denoise": 1.0,
            }},
            "10": {"class_type": "KSamplerSelect", "inputs": {"sampler_name": "res_multistep"}},
            "11": {"class_type": "SamplerCustomAdvanced", "inputs": {
                "noise": ["7", 0], "guider": ["8", 0], "sampler": ["10", 0],
                "sigmas": ["9", 0], "latent_image": ["6", 1],
            }},
        })
    for key, node_id, input_name in (
        ("first_frame_upload_id", "1", "first_frame"),
        ("last_frame_upload_id", "16", "last_frame"),
    ):
        upload_id = spec.get(key)
        if isinstance(upload_id, str):
            record = upload_record(reservation_id, upload_id)
            remote_name = upload_to_comfy(record, reservation_id, upload_id)
            prompt[node_id] = {"class_type": "LoadImage", "inputs": {"image": remote_name}}
            prompt["6"]["inputs"][input_name] = [node_id, 0]
    for index, upload_id in enumerate(spec["reference_image_upload_ids"]):
        record = upload_record(reservation_id, upload_id)
        remote_name = upload_to_comfy(record, reservation_id, upload_id)
        node_id = str(100 + index)
        prompt[node_id] = {"class_type": "LoadImage", "inputs": {"image": remote_name}}
        prompt["6"]["inputs"][f"ref_images.ref_image_{index}"] = [node_id, 0]
    for index, upload_id in enumerate(spec["reference_video_upload_ids"]):
        record = upload_record(reservation_id, upload_id)
        remote_name = upload_to_comfy(record, reservation_id, upload_id)
        load_node_id = str(200 + index)
        components_node_id = str(300 + index)
        prompt[load_node_id] = {"class_type": "LoadVideo", "inputs": {"file": remote_name}}
        prompt[components_node_id] = {
            "class_type": "GetVideoComponents", "inputs": {"video": [load_node_id, 0]},
        }
        prompt["6"]["inputs"][f"ref_videos.ref_video_{index}"] = [components_node_id, 0]
        if record.get("has_audio") is True:
            prompt["6"]["inputs"][f"ref_video_audios.ref_video_audio_{index}"] = [components_node_id, 1]
    return {"prompt": prompt, "client_id": f"movie-portal-{job_id}"}


def z_image_workflow(spec: dict[str, Any], reservation_id: str, job_id: str) -> dict[str, Any]:
    width, height = Z_IMAGE_RESOLUTIONS[spec["resolution"]]
    return {"prompt": {
        "1": {"class_type": "UNETLoader", "inputs": {
            "unet_name": "z_image_turbo_nvfp4.safetensors", "weight_dtype": "default",
        }},
        "2": {"class_type": "CLIPLoader", "inputs": {
            "clip_name": "qwen_3_4b_fp8_mixed.safetensors", "type": "lumina2", "device": "default",
        }},
        "3": {"class_type": "VAELoader", "inputs": {"vae_name": "ae.safetensors"}},
        "4": {"class_type": "CLIPTextEncode", "inputs": {
            "clip": ["2", 0], "text": spec["prompt"],
        }},
        "5": {"class_type": "ConditioningZeroOut", "inputs": {"conditioning": ["4", 0]}},
        "6": {"class_type": "EmptySD3LatentImage", "inputs": {
            "width": width, "height": height, "batch_size": 1,
        }},
        "7": {"class_type": "ModelSamplingAuraFlow", "inputs": {"model": ["1", 0], "shift": 3.0}},
        "8": {"class_type": "KSampler", "inputs": {
            "model": ["7", 0], "positive": ["4", 0], "negative": ["5", 0],
            "latent_image": ["6", 0], "seed": spec["seed"], "steps": spec["steps"],
            "cfg": 1.0, "sampler_name": "res_multistep", "scheduler": "simple", "denoise": 1.0,
        }},
        "9": {"class_type": "VAEDecode", "inputs": {"samples": ["8", 0], "vae": ["3", 0]}},
        "10": {"class_type": "SaveImage", "inputs": {
            "images": ["9", 0], "filename_prefix": f"movie_portal/{reservation_id}/{job_id}",
        }},
    }, "client_id": f"movie-portal-{job_id}"}


def hunyuan_image_workflow(spec: dict[str, Any], reservation_id: str, job_id: str) -> dict[str, Any]:
    return {"prompt": {
        "1": {"class_type": "HunyuanInstructLoader", "inputs": {
            "model_name": "HunyuanImage-3.0-Instruct-Distil-NF4-v2", "force_reload": False,
            "attention_impl": "sdpa", "moe_impl": "eager", "vram_reserve_gb": 30.0,
            "blocks_to_swap": 0, "moe_drop_tokens": False, "vae_dtype": "bfloat16",
        }},
        "2": {"class_type": "HunyuanInstructGenerate", "inputs": {
            "model": ["1", 0], "prompt": spec["prompt"], "bot_task": "image",
            "system_prompt": "dynamic", "resolution": HUNYUAN_IMAGE_RESOLUTIONS[spec["resolution"]],
            "seed": spec["seed"], "steps": spec["steps"],
            "guidance_scale": spec["guidance_scale"], "flow_shift": spec["flow_shift"],
            "max_new_tokens": 2048, "verbose": 0, "vae_tiling": "auto", "vae_offload": "auto",
        }},
        "3": {"class_type": "SaveImage", "inputs": {
            "images": ["2", 0], "filename_prefix": f"movie_portal/{reservation_id}/{job_id}",
        }},
    }, "client_id": f"movie-portal-{job_id}"}


def image_workflow(spec: dict[str, Any], reservation_id: str, job_id: str) -> dict[str, Any]:
    if spec["model"] == Z_IMAGE_MODEL:
        return z_image_workflow(spec, reservation_id, job_id)
    if spec["model"] == HUNYUAN_IMAGE_MODEL:
        return hunyuan_image_workflow(spec, reservation_id, job_id)
    raise RuntimeError("unsupported_image_model")


def sanitized_job(job: dict[str, Any]) -> dict[str, Any]:
    return {
        key: value for key, value in job.items()
        if key not in {"artifact_path", "prompt_id", "remote_job_id"}
    }


def update_job(job_id: str, **updates: Any) -> dict[str, Any] | None:
    with STATE_LOCK:
        state = load_state()
        job = state.get("jobs", {}).get(job_id)
        if not isinstance(job, dict):
            return None
        job.update(updates)
        save_state(state)
        return dict(job)


def job_state(job_id: str) -> dict[str, Any] | None:
    with STATE_LOCK:
        job = load_state().get("jobs", {}).get(job_id)
    return dict(job) if isinstance(job, dict) else None


def release_lease(job_id: str) -> None:
    with STATE_LOCK:
        state = load_state()
        if state.get("lease", {}).get("job_id") == job_id:
            state.pop("lease", None)
            save_state(state)


def interrupt_comfy() -> None:
    try:
        adapter_json("POST", "/comfy/interrupt", b"{}")
    except Exception:
        pass


def ensure_job_active(job_id: str, reservation_id: str) -> None:
    job = job_state(job_id)
    if job is None or job.get("status") == "cancel_requested":
        raise JobCancelled("cancelled")
    if not active_reservation(reservation_id):
        raise JobCancelled("reservation_expired")


def extract_artifact(history: dict[str, Any], prompt_id: str) -> tuple[dict[str, str] | None, bool, str | None]:
    entry = history.get(prompt_id)
    if not isinstance(entry, dict):
        return None, False, None
    status = entry.get("status", {})
    completed = bool(status.get("completed")) if isinstance(status, dict) else False
    status_text = str(status.get("status_str", "")) if isinstance(status, dict) else ""
    outputs = entry.get("outputs", {})
    if isinstance(outputs, dict):
        candidates: list[dict[str, str]] = []
        for output in outputs.values():
            if not isinstance(output, dict):
                continue
            for value in output.values():
                if isinstance(value, list):
                    for item in value:
                        if isinstance(item, dict) and all(key in item for key in ("filename", "subfolder", "type")):
                            candidates.append({key: str(item[key]) for key in ("filename", "subfolder", "type")})
        allowed = {".mp4", ".mov", ".webm", ".png", ".jpg", ".jpeg", ".webp"}
        for item in candidates:
            if pathlib.Path(item["filename"]).suffix.lower() in allowed and item["type"] == "output":
                return item, completed, status_text
    return None, completed, status_text


def download_artifact(job_id: str, reservation_id: str, artifact: dict[str, str]) -> tuple[pathlib.Path, int, str]:
    filename = pathlib.Path(artifact["filename"]).name
    if not SAFE_DOWNLOAD_RE.fullmatch(filename):
        raise RuntimeError("invalid_artifact_filename")
    request = urllib.request.Request(ADAPTER_URL + "/comfy/view?" + urllib.parse.urlencode(artifact))
    destination_dir = ARTIFACT_ROOT / reservation_id
    destination_dir.mkdir(parents=True, exist_ok=True)
    destination = destination_dir / f"{job_id}{pathlib.Path(filename).suffix.lower()}"
    temporary = destination.with_suffix(destination.suffix + ".tmp")
    size = 0
    digest = hashlib.sha256()
    try:
        with urllib.request.urlopen(request, timeout=600) as response, temporary.open("wb") as output:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                if size > MAX_ARTIFACT_BYTES:
                    raise RuntimeError("artifact_too_large")
                digest.update(chunk)
                output.write(chunk)
        os.chmod(temporary, 0o600)
        temporary.replace(destination)
    finally:
        temporary.unlink(missing_ok=True)
    return destination, size, digest.hexdigest()


def existing_style_demo(destination: pathlib.Path, style_skill: str) -> dict[str, Any] | None:
    try:
        metadata = destination.lstat()
    except FileNotFoundError:
        return None
    if not stat.S_ISREG(metadata.st_mode):
        raise RuntimeError("style_demo_destination_unavailable")
    return {
        "status": "existing",
        "style_skill": style_skill,
        "filename": destination.name,
        "size": metadata.st_size,
    }


def claim_style_demo(
    source: pathlib.Path,
    style_skill: str,
    expected_sha256: str,
) -> dict[str, Any]:
    """Atomically bind the first complete MP4 to an empty registered style slot."""
    if style_skill not in H3_STYLE_SKILLS:
        raise RuntimeError("unsupported_h3_style_skill")
    if source.suffix.lower() != ".mp4":
        raise RuntimeError("style_demo_requires_mp4")
    try:
        source_metadata = source.lstat()
    except FileNotFoundError as exc:
        raise RuntimeError("style_demo_source_unavailable") from exc
    if not stat.S_ISREG(source_metadata.st_mode):
        raise RuntimeError("style_demo_source_unavailable")

    STYLE_DEMO_ROOT.mkdir(mode=0o755, parents=True, exist_ok=True)
    root_metadata = STYLE_DEMO_ROOT.lstat()
    if not stat.S_ISDIR(root_metadata.st_mode):
        raise RuntimeError("style_demo_root_unavailable")
    destination = STYLE_DEMO_ROOT / f"{style_skill}.mp4"
    existing = existing_style_demo(destination, style_skill)
    if existing is not None:
        return existing

    temporary = STYLE_DEMO_ROOT / f".{style_skill}.{uuid.uuid4().hex}.partial"
    digest = hashlib.sha256()
    size = 0
    try:
        with source.open("rb") as source_file, temporary.open("xb") as target_file:
            while True:
                chunk = source_file.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                digest.update(chunk)
                target_file.write(chunk)
            target_file.flush()
            os.fsync(target_file.fileno())
        actual_sha256 = digest.hexdigest()
        if size == 0 or actual_sha256 != expected_sha256:
            raise RuntimeError("style_demo_source_digest_mismatch")
        os.chmod(temporary, 0o644)
        try:
            os.link(temporary, destination, follow_symlinks=False)
        except FileExistsError:
            existing = existing_style_demo(destination, style_skill)
            if existing is None:
                raise RuntimeError("style_demo_claim_race")
            return existing
        directory_fd = os.open(STYLE_DEMO_ROOT, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        return {
            "status": "bound",
            "style_skill": style_skill,
            "filename": destination.name,
            "size": size,
            "sha256": actual_sha256,
        }
    finally:
        temporary.unlink(missing_ok=True)


def download_style_artifact(job_id: str, reservation_id: str, remote_job_id: str) -> tuple[pathlib.Path, int, str]:
    if not STYLE_TASK_RE.fullmatch(remote_job_id):
        raise RuntimeError("invalid_style_task_id")
    request = urllib.request.Request(ADAPTER_URL + f"/style/jobs/{remote_job_id}/artifact")
    destination_dir = ARTIFACT_ROOT / reservation_id
    destination_dir.mkdir(parents=True, exist_ok=True)
    destination = destination_dir / f"{job_id}.jpg"
    temporary = destination.with_suffix(".jpg.tmp")
    size = 0
    digest = hashlib.sha256()
    try:
        with urllib.request.urlopen(request, timeout=600) as response, temporary.open("wb") as output:
            content_type = response.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
            if content_type not in {"image/jpeg", "image/jpg", "application/octet-stream"}:
                raise RuntimeError("invalid_style_artifact_type")
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                if size > MAX_ARTIFACT_BYTES:
                    raise RuntimeError("artifact_too_large")
                digest.update(chunk)
                output.write(chunk)
        if size == 0:
            raise RuntimeError("empty_style_artifact")
        os.chmod(temporary, 0o600)
        temporary.replace(destination)
    finally:
        temporary.unlink(missing_ok=True)
    return destination, size, digest.hexdigest()


def execute_job(
    job_id: str,
    capability: str,
    spec: dict[str, Any],
    reservation_id: str,
    claims: dict[str, Any],
) -> None:
    try:
        update_job(job_id, status="preparing", progress=5, started_at=int(time.time()))
        ensure_job_active(job_id, reservation_id)
        preflight = manager_prepare(claims, capability)
        update_job(job_id, progress=15, preflight={
            "reused_comfyui": preflight.get("reused_comfyui"),
            "restarted_comfyui": preflight.get("restarted_comfyui", False),
            "idle_readings_mib": preflight.get("idle_readings_mib"),
            "power_limit_w": preflight.get("gpu", {}).get("power_limit_w"),
        })
        ensure_job_active(job_id, reservation_id)
        workflow = h3_workflow(spec, reservation_id, job_id) if capability == "h3.generate" else image_workflow(spec, reservation_id, job_id)
        submitted = adapter_json("POST", "/comfy/prompt", json.dumps(workflow, separators=(",", ":")).encode())
        prompt_id = str(submitted.get("prompt_id", "")).lower()
        if not UUID_RE.fullmatch(prompt_id):
            raise RuntimeError("comfyui_rejected_workflow" if submitted.get("node_errors") else "missing_prompt_id")
        update_job(job_id, status="running", progress=25, prompt_id=prompt_id)
        deadline = time.monotonic() + 2 * 60 * 60
        while time.monotonic() < deadline:
            ensure_job_active(job_id, reservation_id)
            history = adapter_json("GET", f"/comfy/history/{prompt_id}", timeout=30)
            artifact, completed, status_text = extract_artifact(history, prompt_id)
            if artifact is not None:
                update_job(job_id, status="postprocessing", progress=90)
                path, size, sha256 = download_artifact(job_id, reservation_id, artifact)
                completion: dict[str, Any] = {
                    "status": "completed",
                    "progress": 100,
                    "completed_at": int(time.time()),
                    "artifact_name": path.name,
                    "artifact_path": str(path),
                    "artifact_size": size,
                    "artifact_sha256": sha256,
                }
                style_skill = spec.get("style_skill") if capability == "h3.generate" else None
                if isinstance(style_skill, str):
                    try:
                        completion["style_demo"] = claim_style_demo(path, style_skill, sha256)
                    except Exception as exc:
                        completion["style_demo"] = {
                            "status": "error",
                            "style_skill": style_skill,
                            "error": str(exc)[:200],
                        }
                update_job(job_id, **completion)
                return
            if completed or status_text in {"error", "failed"}:
                raise RuntimeError("comfyui_completed_without_artifact")
            update_job(job_id, progress=50)
            time.sleep(3)
        interrupt_comfy()
        raise RuntimeError("job_timeout")
    except JobCancelled as exc:
        interrupt_comfy()
        update_job(job_id, status="expired" if str(exc) == "reservation_expired" else "cancelled",
                   progress=0, completed_at=int(time.time()))
    except Exception as exc:
        update_job(job_id, status="failed", progress=0, completed_at=int(time.time()), error=str(exc)[:200])
    finally:
        release_lease(job_id)


def execute_style_job(job_id: str, spec: dict[str, Any], reservation_id: str) -> None:
    try:
        update_job(job_id, status="preparing", progress=5, started_at=int(time.time()))
        ensure_job_active(job_id, reservation_id)
        submitted = adapter_json(
            "POST", "/style/jobs", json.dumps(spec, separators=(",", ":")).encode(), timeout=120,
        )
        remote_job = submitted.get("job", {})
        remote_job_id = str(remote_job.get("id", "")).lower() if isinstance(remote_job, dict) else ""
        if not STYLE_TASK_RE.fullmatch(remote_job_id):
            raise RuntimeError("style_service_rejected_workflow")
        update_job(job_id, status="running", progress=20, remote_job_id=remote_job_id)
        deadline = time.monotonic() + 2 * 60 * 60
        while time.monotonic() < deadline:
            ensure_job_active(job_id, reservation_id)
            remote = adapter_json("GET", f"/style/jobs/{remote_job_id}", timeout=30).get("job", {})
            if not isinstance(remote, dict):
                raise RuntimeError("invalid_style_job_status")
            status = str(remote.get("status", "")).lower()
            if status == "completed" and bool(remote.get("artifact_ready")):
                update_job(job_id, status="postprocessing", progress=90)
                path, size, sha256 = download_style_artifact(job_id, reservation_id, remote_job_id)
                update_job(
                    job_id, status="completed", progress=100, completed_at=int(time.time()),
                    artifact_name=path.name, artifact_path=str(path), artifact_size=size,
                    artifact_sha256=sha256,
                )
                return
            if status in {"failed", "error", "cancelled"}:
                reason = str(remote.get("reason") or remote.get("error") or f"style_job_{status}")
                raise RuntimeError(reason[:200])
            update_job(job_id, progress=50 if status in {"running", "submitted"} else 30)
            time.sleep(3)
        raise RuntimeError("job_timeout")
    except JobCancelled as exc:
        update_job(
            job_id,
            status="expired" if str(exc) == "reservation_expired" else "cancelled",
            progress=0,
            completed_at=int(time.time()),
        )
    except Exception as exc:
        update_job(job_id, status="failed", progress=0, completed_at=int(time.time()), error=str(exc)[:200])
    finally:
        release_lease(job_id)


def create_job(claims: dict[str, Any], capability: str, spec: dict[str, Any]) -> dict[str, Any]:
    job_id = str(uuid.uuid4())
    reservation_id = str(claims["reservation_id"])
    summary = {
        key: spec.get(key)
        for key in (
            "model", "style_skill", "workflow_preset", "content_profile", "unet_name",
            "mode", "resolution", "width", "height",
            "duration_seconds", "steps", "seed",
        )
        if spec.get(key) is not None
    }
    job = {
        "id": job_id, "reservation_id": reservation_id, "capability": capability,
        "runtime_id": claims.get("runtime_id"), "generation": int(claims.get("generation", 0)),
        "status": "queued", "progress": 0, "submitted_at": int(time.time()),
        "real_gpu_used": True, "prompt_sha256": hashlib.sha256(spec["prompt"].encode()).hexdigest(),
        "spec": summary,
    }
    with STATE_LOCK:
        state = load_state()
        lease = state.get("lease")
        if capability != "image.style" and isinstance(lease, dict) and lease.get("job_id"):
            raise RuntimeError("gpu_lease_busy")
        state.setdefault("jobs", {})[job_id] = job
        if capability != "image.style":
            state["lease"] = {
                "job_id": job_id, "reservation_id": reservation_id,
                "capability": capability, "expires_at": int(claims["expires_at"]),
            }
        save_state(state)
    target = execute_style_job if capability == "image.style" else execute_job
    args = (job_id, spec, reservation_id) if capability == "image.style" else (
        job_id, capability, spec, reservation_id, dict(claims)
    )
    threading.Thread(target=target, args=args, name=f"job-{job_id[:8]}", daemon=True).start()
    return job


def cleanup_reservation_files(reservation_id: str) -> None:
    if not UUID_RE.fullmatch(reservation_id):
        return
    for root in (UPLOAD_ROOT, ARTIFACT_ROOT):
        directory = root / reservation_id
        try:
            directory.relative_to(root)
        except ValueError:
            continue
        shutil.rmtree(directory, ignore_errors=True)


class Handler(BaseHTTPRequestHandler):
    server_version = "movie-ai-broker/3"

    def log_message(self, fmt: str, *args: object) -> None:
        print(f"broker {self.command} {self.path.split('?', 1)[0]} {args[1] if len(args) > 1 else '-'}", flush=True)

    def respond(self, status: int, body: dict[str, Any]) -> None:
        raw = json.dumps(body, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def body(self, maximum: int = MAX_SPEC_BYTES) -> bytes:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > maximum:
            raise ValueError("invalid_body_size")
        return self.rfile.read(length)

    def control_authorized(self, raw: bytes) -> bool:
        timestamp = self.headers.get("X-Movie-Timestamp", "")
        signature = self.headers.get("X-Movie-Signature", "")
        try:
            stamp = int(timestamp)
        except ValueError:
            return False
        if abs(time.time() - stamp) > 30:
            return False
        message = b"\n".join([timestamp.encode(), self.command.encode(), self.path.encode(), raw])
        expected = hmac.new(CONTROL_SECRET, message, hashlib.sha256).hexdigest()
        return hmac.compare_digest(expected, signature)

    def active_claims(self) -> dict[str, Any] | None:
        authorization = self.headers.get("Authorization", "")
        return active_claims_for_token(authorization[7:]) if authorization.startswith("Bearer ") else None

    def proxy_local_model(self, claims: dict[str, Any], raw: bytes = b"") -> None:
        try:
            body = raw
            selected_model = ""
            if self.command == "POST":
                payload = json.loads(raw or b"{}")
                if not isinstance(payload, dict):
                    raise ValueError("invalid_local_model_request")
                selected_model = str(payload.get("model", ""))
                if selected_model in QWEN_MODEL_ALIASES:
                    rewritten = rewrite_qwen_responses_payload(payload)
                elif selected_model in DEEPSEEK_MODEL_ALIASES:
                    rewritten = rewrite_deepseek_responses_payload(payload)
                else:
                    raise ValueError("unsupported_local_model")
                body = json.dumps(
                    rewritten,
                    ensure_ascii=False,
                    separators=(",", ":"),
                ).encode()
        except (ValueError, json.JSONDecodeError) as exc:
            self.respond(422, {"error": str(exc)[:200] or "invalid_local_model_request"})
            return

        is_qwen = selected_model in QWEN_MODEL_ALIASES
        connection: http.client.HTTPConnection
        if is_qwen:
            connection = QwenUnixHTTPConnection("localhost", timeout=600)
        else:
            connection = DeepSeekUnixHTTPConnection("localhost", timeout=600)
        cancellation = ClientDisconnectCancellation(self.connection)
        cancellation.attach_connection(connection)
        cancellation.start()
        try:
            headers: dict[str, str] = {
                "Accept": self.headers.get("Accept", "application/json"),
                "Content-Type": "application/json",
                "Content-Length": str(len(body)),
            }
            if is_qwen:
                headers["X-Qwen-User"] = qwen_user_key(claims)
            connection.request(self.command, self.path, body=body or None, headers=headers)
            upstream = connection.getresponse()
            cancellation.attach_response(upstream)
        except (OSError, http.client.HTTPException) as exc:
            cancellation.stop()
            connection.close()
            if not cancellation.cancelled.is_set():
                self.respond(503, {"error": str(exc)[:200] or "local_model_unavailable"})
            return

        try:
            self.send_response(upstream.status)
            content_type = upstream.getheader("Content-Type") or "application/json"
            self.send_header("Content-Type", content_type)
            self.send_header("Cache-Control", "no-store")
            self.send_header("Connection", "close")
            self.end_headers()
            while True:
                chunk = upstream.read1(65536)
                if not chunk:
                    break
                self.wfile.write(chunk)
                self.wfile.flush()
        except (AttributeError, OSError, ValueError, http.client.HTTPException):
            pass
        finally:
            cancellation.stop()
            upstream.close()
            connection.close()
            self.close_connection = True

    def stream_artifact(self, claims: dict[str, Any], job_id: str) -> None:
        with STATE_LOCK:
            job = load_state().get("jobs", {}).get(job_id)
        if not isinstance(job, dict) or job.get("reservation_id") != claims["reservation_id"]:
            self.respond(404, {"error": "job_not_found"})
            return
        if job.get("status") != "completed" or not job.get("artifact_path"):
            self.respond(409, {"error": "artifact_not_ready"})
            return
        path = pathlib.Path(str(job["artifact_path"]))
        try:
            path.relative_to(ARTIFACT_ROOT / str(claims["reservation_id"]))
        except ValueError:
            self.respond(500, {"error": "invalid_artifact_path"})
            return
        if not path.is_file():
            self.respond(404, {"error": "artifact_not_found"})
            return
        self.send_response(200)
        self.send_header("Content-Type", mimetypes.guess_type(path.name)[0] or "application/octet-stream")
        self.send_header("Content-Length", str(path.stat().st_size))
        self.send_header("Content-Disposition", f'attachment; filename="{path.name}"')
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        with path.open("rb") as artifact:
            while True:
                chunk = artifact.read(1024 * 1024)
                if not chunk:
                    break
                self.wfile.write(chunk)

    def do_GET(self) -> None:
        if self.path == "/healthz":
            capabilities = ["h3.generate", "image.generate", "image.style"]
            manager_is_ready = dependency_ready(MANAGER_URL)
            adapter_is_ready = dependency_ready(ADAPTER_URL)
            qwen_is_ready = qwen_ready()
            deepseek_is_ready = deepseek_ready()
            if qwen_is_ready:
                capabilities.append("qwen.responses")
            if deepseek_is_ready:
                capabilities.append("deepseek.responses")
            self.respond(200 if manager_is_ready and adapter_is_ready else 503, {
                "ok": manager_is_ready and adapter_is_ready,
                "mode": "real",
                "compute_node_id": COMPUTE_NODE_ID,
                "worker_revision": WORKER_REVISION,
                "workflow_revision": WORKFLOW_REVISION,
                "model_manifest_sha256": MODEL_MANIFEST_SHA256 or None,
                "manager_ready": manager_is_ready,
                "adapter_ready": adapter_is_ready,
                "malformed_tool_calls_quarantined": malformed_tool_call_quarantine_count(),
                "qwen_ready": qwen_is_ready,
                "deepseek_ready": deepseek_is_ready,
                "capabilities": capabilities,
                "image_models": list(LOCAL_IMAGE_MODELS),
            })
            return
        claims = self.active_claims()
        if claims is None:
            self.respond(401, {"error": "invalid_or_expired_token"})
            return
        if self.path == "/v1/models":
            models = []
            if qwen_ready():
                models.append({"id": QWEN_MODEL, "object": "model", "owned_by": "movie"})
            if deepseek_ready():
                models.append({"id": DEEPSEEK_MODEL, "object": "model", "owned_by": "movie"})
            self.respond(200, {"object": "list", "data": models})
            return
        if self.path == "/v1/image/models":
            self.respond(200, {"models": list(LOCAL_IMAGE_MODELS)})
            return
        if self.path == "/v1/image/style/models":
            try:
                self.respond(200, style_image_models())
            except Exception as exc:
                self.respond(503, {"error": str(exc)[:200]})
            return
        if self.path == "/v1/gpu/status":
            try:
                self.respond(200, public_gpu_status(manager_status(claims)))
            except Exception as exc:
                self.respond(503, {"error": str(exc)[:200]})
            return
        if self.path == "/v1/jobs":
            with STATE_LOCK:
                jobs = load_state().get("jobs", {})
                own = [sanitized_job(job) for job in jobs.values() if job.get("reservation_id") == claims["reservation_id"]]
            self.respond(200, {"jobs": own})
            return
        if self.path.startswith("/v1/jobs/") and self.path.endswith("/artifact"):
            job_id = self.path.removeprefix("/v1/jobs/").removesuffix("/artifact")
            if not UUID_RE.fullmatch(job_id):
                self.respond(404, {"error": "job_not_found"})
                return
            self.stream_artifact(claims, job_id)
            return
        if self.path.startswith("/v1/jobs/"):
            job_id = self.path.removeprefix("/v1/jobs/")
            with STATE_LOCK:
                job = load_state().get("jobs", {}).get(job_id)
            if not isinstance(job, dict) or job.get("reservation_id") != claims["reservation_id"]:
                self.respond(404, {"error": "job_not_found"})
                return
            self.respond(200, {"job": sanitized_job(job)})
            return
        self.respond(404, {"error": "not_found"})

    def do_POST(self) -> None:
        if self.path == "/v1/responses":
            maximum = MAX_QWEN_REQUEST_BYTES
        else:
            maximum = MAX_UPLOAD_BYTES if self.path == "/v1/uploads" else MAX_SPEC_BYTES
        try:
            raw = self.body(maximum)
        except ValueError as exc:
            self.respond(413, {"error": str(exc)})
            return
        if self.path.startswith("/internal/"):
            if not self.control_authorized(raw):
                self.respond(403, {"error": "forbidden"})
                return
            try:
                data = json.loads(raw or b"{}")
                reservation_id = str(data.get("reservation_id", "")).lower()
                if not UUID_RE.fullmatch(reservation_id):
                    raise ValueError("invalid_reservation")
                if self.path == "/internal/register":
                    compute_node_id = str(data.get("compute_node_id", "")).lower()
                    user_id = str(data.get("user_id", "")).lower()
                    runtime_id = str(data.get("runtime_id", "")).lower()
                    generation = int(data.get("generation", 0))
                    token = str(data.get("token", ""))
                    expires_at = int(data.get("expires_at", 0))
                    now = int(time.time())
                    if not UUID_RE.fullmatch(user_id):
                        raise ValueError("invalid_user")
                    if not UUID_RE.fullmatch(compute_node_id) or not hmac.compare_digest(compute_node_id, COMPUTE_NODE_ID):
                        raise ValueError("compute_node_mismatch")
                    if runtime_id and (not UUID_RE.fullmatch(runtime_id) or generation < 1):
                        raise ValueError("invalid_runtime_binding")
                    if not TOKEN_RE.fullmatch(token):
                        raise ValueError("invalid_token")
                    if expires_at <= now or expires_at > now + 9 * 60 * 60:
                        raise ValueError("invalid_expiry")
                    register_active_claim(
                        reservation_id, user_id, runtime_id, generation, token, expires_at,
                        compute_node_id, now=now,
                    )
                    self.respond(200, {"registered": True, "mode": "real"})
                    return
                if self.path == "/internal/revoke":
                    with STATE_LOCK:
                        state = load_state()
                        active = state.get("active", {})
                        requested_runtime = str(data.get("runtime_id", "")).lower()
                        requested_generation = int(data.get("generation", 0))
                        require_idle = data.get("require_idle") is True
                        preserve_files = data.get("preserve_files") is True
                        if preserve_files and not require_idle:
                            raise ValueError("invalid_preserve_files")
                        if active.get("reservation_id") == reservation_id and requested_runtime and (
                            active.get("runtime_id") != requested_runtime
                            or int(active.get("generation", 0)) != requested_generation
                        ):
                            raise ValueError("runtime_binding_mismatch")
                        if active.get("reservation_id") == reservation_id:
                            if require_idle and reservation_has_active_jobs(state, reservation_id):
                                raise ValueError("reservation_has_active_jobs")
                            for job in state.get("jobs", {}).values():
                                if job.get("reservation_id") == reservation_id \
                                    and job.get("status") in IN_FLIGHT_JOB_STATUSES:
                                    job["status"] = "cancel_requested"
                            state.pop("active", None)
                            if preserve_files:
                                state["preserved_reservation_id"] = reservation_id
                            else:
                                state.pop("preserved_reservation_id", None)
                            save_state(state)
                    if any(
                        job.get("capability") != "image.style"
                        and job.get("status") == "cancel_requested"
                        for job in state.get("jobs", {}).values()
                    ):
                        interrupt_comfy()
                    if not preserve_files:
                        cleanup_reservation_files(reservation_id)
                    self.respond(200, {"revoked": True})
                    return
                raise ValueError("unsupported_operation")
            except (ValueError, TypeError) as exc:
                self.respond(422, {"error": str(exc)})
            return
        claims = self.active_claims()
        if claims is None:
            self.respond(401, {"error": "invalid_or_expired_token"})
            return
        reservation_id = str(claims["reservation_id"])
        if self.path == "/v1/responses":
            self.proxy_local_model(claims, raw)
            return
        if self.path == "/v1/uploads":
            filename = urllib.parse.unquote(self.headers.get("X-Movie-Filename", ""))
            provided_hash = self.headers.get("X-Movie-Sha256", "").lower()
            if len(raw) == 0 or len(raw) > MAX_UPLOAD_BYTES:
                self.respond(422, {"error": "invalid_upload_size"})
                return
            if not SAFE_DOWNLOAD_RE.fullmatch(pathlib.Path(filename).name) or pathlib.Path(filename).name != filename:
                self.respond(422, {"error": "invalid_upload_filename"})
                return
            try:
                media_type, extension = classify_upload(raw, filename)
            except ValueError as exc:
                self.respond(422, {"error": str(exc)})
                return
            digest = hashlib.sha256(raw).hexdigest()
            if provided_hash and not hmac.compare_digest(provided_hash, digest):
                self.respond(422, {"error": "upload_sha256_mismatch"})
                return
            upload_id = str(uuid.uuid4())
            directory = UPLOAD_ROOT / reservation_id
            directory.mkdir(parents=True, exist_ok=True)
            path = directory / f"{upload_id}.{extension}"
            path.write_bytes(raw)
            os.chmod(path, 0o600)
            record = {"id": upload_id, "reservation_id": reservation_id, "path": str(path),
                      "extension": extension, "media_type": media_type,
                      "size": len(raw), "sha256": digest}
            if media_type == "video":
                try:
                    record.update(probe_reference_video(path))
                except ValueError as exc:
                    path.unlink(missing_ok=True)
                    self.respond(422, {"error": str(exc)})
                    return
                except RuntimeError as exc:
                    path.unlink(missing_ok=True)
                    self.respond(503, {"error": str(exc)})
                    return
            with STATE_LOCK:
                state = load_state()
                state.setdefault("uploads", {})[upload_id] = record
                save_state(state)
            response_upload = {
                key: record[key]
                for key in ("id", "media_type", "size", "sha256", "duration_seconds", "has_audio")
                if key in record
            }
            self.respond(201, {"upload": response_upload})
            return
        if self.path in {"/v1/h3/jobs", "/v1/image/jobs", "/v1/image/style/jobs"}:
            try:
                data = json.loads(raw or b"{}")
                if not isinstance(data, dict):
                    raise ValueError("invalid_spec")
                if self.path == "/v1/h3/jobs":
                    capability, spec = "h3.generate", validate_h3_spec(data, reservation_id)
                elif self.path == "/v1/image/style/jobs":
                    capability, spec = "image.style", validate_style_image_spec(data)
                else:
                    capability, spec = "image.generate", validate_image_spec(data)
                self.respond(202, {"job": sanitized_job(create_job(claims, capability, spec))})
            except (ValueError, TypeError, json.JSONDecodeError) as exc:
                self.respond(422, {"error": str(exc)})
            except RuntimeError as exc:
                self.respond(409, {"error": str(exc)})
            return
        if self.path == "/v1/mock/jobs":
            try:
                data = json.loads(raw or b"{}")
                prompt = str(data.get("prompt", ""))
                if not prompt or len(prompt) > 500:
                    raise ValueError("prompt_must_be_1_to_500_characters")
            except (ValueError, TypeError) as exc:
                self.respond(422, {"error": str(exc)})
                return
            job_id = str(uuid.uuid4())
            job = {"id": job_id, "reservation_id": reservation_id, "capability": "mock.echo",
                   "status": "completed", "progress": 100, "submitted_at": int(time.time()),
                   "real_gpu_used": False, "prompt_sha256": hashlib.sha256(prompt.encode()).hexdigest()}
            with STATE_LOCK:
                state = load_state()
                state.setdefault("jobs", {})[job_id] = job
                save_state(state)
            self.respond(201, {"job": job})
            return
        if self.path.startswith("/v1/jobs/") and self.path.endswith("/cancel"):
            job_id = self.path.removeprefix("/v1/jobs/").removesuffix("/cancel")
            with STATE_LOCK:
                state = load_state()
                job = state.get("jobs", {}).get(job_id)
                if not isinstance(job, dict) or job.get("reservation_id") != reservation_id:
                    self.respond(404, {"error": "job_not_found"})
                    return
                if job.get("status") not in {"completed", "failed", "cancelled", "expired"}:
                    job["status"] = "cancel_requested"
                    save_state(state)
            if job.get("capability") != "image.style":
                interrupt_comfy()
            self.respond(200, {"job": sanitized_job(job)})
            return
        self.respond(404, {"error": "not_found"})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
