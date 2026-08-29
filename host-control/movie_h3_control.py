#!/usr/bin/env python3
"""Narrow host control plane for the Movie Portal GPU runtime.

The protocol is a single authenticated JSON line over a systemd-owned Unix
socket.  Callers can choose only a capability-level action.  Unit names,
commands, paths, ports, and process targets are constants in this file.
"""

from __future__ import annotations

import fcntl
import hashlib
import hmac
import json
import os
import pathlib
import re
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.request
from typing import Any


UNIT_RE = re.compile(r"^[a-z0-9][a-z0-9_.@-]{0,126}\.service$")
CONTAINER_RE = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$")


def configured_name(environment_name: str, default: str, pattern: re.Pattern[str]) -> str:
    value = os.environ.get(environment_name, default).strip()
    if not pattern.fullmatch(value):
        raise SystemExit(f"invalid {environment_name}")
    return value


COMFY_UNIT = configured_name("MOVIE_COMFY_UNIT", "movie-comfyui.service", UNIT_RE)
QWEN_UNIT = configured_name("MOVIE_QWEN_UNIT", "movie-qwen.service", UNIT_RE)
QWEN_CONTAINER = configured_name("MOVIE_QWEN_CONTAINER", "movie-qwen-runtime", CONTAINER_RE)
COMFY_HEALTH_URL = "http://127.0.0.1:8188/system_stats"
SECRET_PATH = pathlib.Path(os.environ.get(
    "MOVIE_H3_CONTROL_SECRET_FILE",
    "/srv/movie-portal/env/h3_control_hmac_secret",
))
LOCK_PATH = pathlib.Path("/run/movie-h3-control/operation.lock")
PROFILE_PATH = pathlib.Path("/run/movie-h3-control/active-profile")
MAX_REQUEST_BYTES = 8192
MAX_POWER_LIMIT_W = 550.0
IDLE_VRAM_LIMIT_MIB = 2048
MODEL_UNLOAD_TIMEOUT_SECONDS = 30
NONCE_RE = re.compile(r"^[A-Za-z0-9._~-]{32,128}$")
ALLOWED_ACTIONS = frozenset({"status", "prepare_h3", "prepare_image"})
OPERATION_LOCK = threading.Lock()
REPLAY_LOCK = threading.Lock()
SEEN_NONCES: dict[str, int] = {}


class RequestError(RuntimeError):
    pass


class PolicyError(RuntimeError):
    pass


def read_secret() -> bytes:
    value = SECRET_PATH.read_bytes().strip()
    if len(value) < 32:
        raise SystemExit("host control secret must contain at least 32 bytes")
    return value


CONTROL_SECRET = read_secret()


def run_fixed(command: tuple[str, ...], timeout: int = 30) -> str:
    completed = subprocess.run(
        command,
        check=True,
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    return completed.stdout.strip()


def unit_state(unit: str) -> dict[str, Any]:
    if unit not in (COMFY_UNIT, QWEN_UNIT):
        raise AssertionError("unit target is not fixed")
    output = run_fixed((
        "/usr/bin/systemctl", "show", unit,
        "--property=LoadState", "--property=ActiveState",
        "--property=SubState", "--property=UnitFileState",
        "--property=ControlGroup", "--no-pager",
    ))
    values: dict[str, str] = {}
    for line in output.splitlines():
        key, _, value = line.partition("=")
        values[key] = value
    return {
        "load": values.get("LoadState", "unknown"),
        "active": values.get("ActiveState", "unknown"),
        "sub": values.get("SubState", "unknown"),
        "enabled": values.get("UnitFileState", "unknown"),
        "control_group": values.get("ControlGroup", ""),
    }


def qwen_container_id() -> str | None:
    try:
        value = run_fixed((
            "/usr/bin/docker", "inspect", "--format", "{{.Id}}", QWEN_CONTAINER,
        ))
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
        return None
    return value if re.fullmatch(r"[0-9a-f]{64}", value) else None


def parse_number(value: str) -> float:
    cleaned = value.strip()
    if cleaned in {"", "[N/A]", "N/A"}:
        return 0.0
    return float(cleaned)


def gpu_snapshot() -> dict[str, Any]:
    raw_gpu = run_fixed((
        "/usr/bin/nvidia-smi",
        "--query-gpu=index,uuid,name,memory.used,memory.total,power.draw,power.limit,temperature.gpu,utilization.gpu",
        "--format=csv,noheader,nounits",
    ))
    rows = [line for line in raw_gpu.splitlines() if line.strip()]
    if len(rows) != 1:
        raise PolicyError("expected_exactly_one_gpu")
    columns = [value.strip() for value in rows[0].split(",")]
    if len(columns) != 9:
        raise PolicyError("unexpected_gpu_telemetry")

    processes: list[dict[str, Any]] = []
    try:
        raw_processes = run_fixed((
            "/usr/bin/nvidia-smi",
            "--query-compute-apps=pid,used_memory",
            "--format=csv,noheader,nounits",
        ))
    except subprocess.CalledProcessError:
        raw_processes = ""

    qwen_id = qwen_container_id()
    for line in raw_processes.splitlines():
        if not line.strip():
            continue
        pid_value, _, memory_value = line.partition(",")
        pid = int(pid_value.strip())
        cgroup_path = pathlib.Path(f"/proc/{pid}/cgroup")
        try:
            cgroup = cgroup_path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            cgroup = ""
        if f"/system.slice/{COMFY_UNIT}" in cgroup:
            owner = "comfyui"
        elif qwen_id and qwen_id in cgroup:
            owner = "qwen"
        elif f"/system.slice/{QWEN_UNIT}" in cgroup:
            owner = "qwen"
        else:
            owner = "unknown"
        processes.append({
            "pid": pid,
            "used_memory_mib": int(parse_number(memory_value)),
            "owner": owner,
        })

    return {
        "index": int(columns[0]),
        "uuid": columns[1],
        "name": columns[2],
        "memory_used_mib": int(parse_number(columns[3])),
        "memory_total_mib": int(parse_number(columns[4])),
        "power_draw_w": parse_number(columns[5]),
        "power_limit_w": parse_number(columns[6]),
        "temperature_c": int(parse_number(columns[7])),
        "utilization_percent": int(parse_number(columns[8])),
        "processes": processes,
    }


def comfy_healthy() -> bool:
    try:
        with urllib.request.urlopen(COMFY_HEALTH_URL, timeout=3) as response:
            return response.status == 200
    except (OSError, urllib.error.URLError):
        return False


def active_profile(comfy_active: bool) -> str | None:
    if not comfy_active:
        return None
    try:
        value = PROFILE_PATH.read_text(encoding="ascii").strip()
    except OSError:
        return None
    return value if value in {"h3", "image"} else None


def set_active_profile(profile: str) -> None:
    if profile not in {"h3", "image"}:
        raise AssertionError("profile is not fixed")
    temporary = PROFILE_PATH.with_suffix(".tmp")
    temporary.write_text(profile + "\n", encoding="ascii")
    os.chmod(temporary, 0o600)
    temporary.replace(PROFILE_PATH)


def free_comfy_models() -> None:
    body = json.dumps({"unload_models": True, "free_memory": True}, separators=(",", ":")).encode()
    request = urllib.request.Request(
        "http://127.0.0.1:8188/free",
        data=body,
        method="POST",
        headers={"Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            if response.status not in (200, 204):
                raise PolicyError("comfyui_free_failed")
            response.read(1024)
    except (OSError, urllib.error.URLError) as exc:
        raise PolicyError("comfyui_free_failed") from exc
    deadline = time.monotonic() + MODEL_UNLOAD_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        snapshot = status_payload()
        require_power_and_known(snapshot)
        if (snapshot["gpu"]["memory_used_mib"] < IDLE_VRAM_LIMIT_MIB
                and all(p["owner"] == "comfyui" for p in snapshot["gpu"]["processes"])):
            return
        time.sleep(2)
    raise PolicyError("comfyui_model_unload_timeout")


def status_payload() -> dict[str, Any]:
    comfy = unit_state(COMFY_UNIT)
    qwen = unit_state(QWEN_UNIT)
    gpu = gpu_snapshot()
    unknown = [p for p in gpu["processes"] if p["owner"] == "unknown"]
    profile = active_profile(comfy["active"] == "active")
    return {
        "mode": "real",
        "services": {
            "comfyui": {**comfy, "healthy": comfy_healthy()},
            "qwen": qwen,
        },
        "gpu": gpu,
        "unknown_processes": unknown,
        "active_profile": profile,
        "h3_submission_allowed": (
            gpu["power_limit_w"] <= MAX_POWER_LIMIT_W
            and not unknown
            and qwen["active"] != "active"
            and comfy["active"] == "active"
            and comfy_healthy()
            and profile == "h3"
            and all(p["owner"] == "comfyui" for p in gpu["processes"])
        ),
    }


def stop_qwen() -> None:
    run_fixed(("/usr/bin/systemctl", "stop", QWEN_UNIT), timeout=180)
    deadline = time.monotonic() + 180
    while time.monotonic() < deadline:
        state = unit_state(QWEN_UNIT)
        gpu = gpu_snapshot()
        if state["active"] != "active" and not any(p["owner"] == "qwen" for p in gpu["processes"]):
            return
        time.sleep(2)
    raise PolicyError("qwen_stop_timeout")


def stop_comfy() -> None:
    run_fixed(("/usr/bin/systemctl", "stop", COMFY_UNIT), timeout=180)
    deadline = time.monotonic() + 180
    while time.monotonic() < deadline:
        snapshot = status_payload()
        require_power_and_known(snapshot)
        if snapshot["services"]["qwen"]["active"] == "active":
            raise PolicyError("qwen_restarted_during_preflight")
        if (snapshot["services"]["comfyui"]["active"] != "active"
                and not any(p["owner"] == "comfyui" for p in snapshot["gpu"]["processes"])):
            return
        time.sleep(2)
    raise PolicyError("comfyui_stop_timeout")


def require_power_and_known(snapshot: dict[str, Any]) -> None:
    gpu = snapshot["gpu"]
    if gpu["power_limit_w"] > MAX_POWER_LIMIT_W:
        raise PolicyError("gpu_power_limit_exceeds_550w")
    if snapshot["unknown_processes"]:
        raise PolicyError("unknown_gpu_process")


def wait_for_comfy() -> dict[str, Any]:
    deadline = time.monotonic() + 180
    while time.monotonic() < deadline:
        snapshot = status_payload()
        require_power_and_known(snapshot)
        if snapshot["services"]["comfyui"]["active"] == "active" and snapshot["services"]["comfyui"]["healthy"]:
            if all(p["owner"] == "comfyui" for p in snapshot["gpu"]["processes"]):
                return snapshot
        time.sleep(2)
    raise PolicyError("comfyui_start_timeout")


def collect_idle_readings() -> list[int]:
    readings: list[int] = []
    for index in range(2):
        sample = status_payload()
        require_power_and_known(sample)
        if sample["services"]["qwen"]["active"] == "active":
            raise PolicyError("qwen_restarted_during_preflight")
        if sample["services"]["comfyui"]["active"] == "active":
            raise PolicyError("comfyui_active_during_idle_check")
        if sample["gpu"]["processes"]:
            raise PolicyError("gpu_process_remains_before_comfyui_start")
        used = int(sample["gpu"]["memory_used_mib"])
        readings.append(used)
        if used >= IDLE_VRAM_LIMIT_MIB:
            raise PolicyError("idle_vram_not_below_2gb")
        if index == 0:
            time.sleep(3)
    return readings


def start_comfy(profile: str) -> dict[str, Any]:
    run_fixed(("/usr/bin/systemctl", "start", COMFY_UNIT), timeout=60)
    wait_for_comfy()
    set_active_profile(profile)
    return status_payload()


def recycle_comfy(profile: str) -> tuple[dict[str, Any], list[int]]:
    """Clear a non-H3 model that the fixed ComfyUI /free API retained."""
    stop_comfy()
    readings = collect_idle_readings()
    return start_comfy(profile), readings


def prepare_comfy(profile: str) -> dict[str, Any]:
    if profile not in {"h3", "image"}:
        raise AssertionError("profile is not fixed")
    LOCK_PATH.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with OPERATION_LOCK, LOCK_PATH.open("a+", encoding="utf-8") as lock_file:
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        before = status_payload()
        require_power_and_known(before)

        if before["services"]["qwen"]["active"] == "active" or any(
            p["owner"] == "qwen" for p in before["gpu"]["processes"]
        ):
            stop_qwen()

        cleared = status_payload()
        require_power_and_known(cleared)
        if any(p["owner"] == "qwen" for p in cleared["gpu"]["processes"]):
            raise PolicyError("non_h3_gpu_service_remains")

        comfy = cleared["services"]["comfyui"]
        if comfy["active"] == "active":
            if not comfy["healthy"]:
                raise PolicyError("comfyui_active_but_unhealthy")
            if not all(p["owner"] == "comfyui" for p in cleared["gpu"]["processes"]):
                raise PolicyError("unattributed_gpu_process")
            current_profile = cleared.get("active_profile")
            if current_profile is None:
                raise PolicyError("comfyui_profile_unknown")
            if current_profile != profile:
                try:
                    free_comfy_models()
                except PolicyError as exc:
                    if str(exc) != "comfyui_model_unload_timeout":
                        raise
                    switched, readings = recycle_comfy(profile)
                    return {
                        "prepared": True,
                        "profile": profile,
                        "reused_comfyui": False,
                        "restarted_comfyui": True,
                        "idle_readings_mib": readings,
                        "unloaded_previous_profile": current_profile,
                        **switched,
                    }
                set_active_profile(profile)
                switched = status_payload()
                return {"prepared": True, "profile": profile, "reused_comfyui": True,
                        "restarted_comfyui": False,
                        "unloaded_previous_profile": current_profile, **switched}
            return {"prepared": True, "profile": profile, "reused_comfyui": True, **cleared}

        readings = collect_idle_readings()
        ready = start_comfy(profile)
        return {
            "prepared": True,
            "profile": profile,
            "reused_comfyui": False,
            "restarted_comfyui": False,
            "idle_readings_mib": readings,
            **ready,
        }


def authenticate(request: dict[str, Any]) -> str:
    timestamp = int(request.get("timestamp", 0))
    nonce = str(request.get("nonce", ""))
    action = str(request.get("action", ""))
    signature = str(request.get("signature", ""))
    if abs(time.time() - timestamp) > 30:
        raise RequestError("stale_request")
    if not NONCE_RE.fullmatch(nonce):
        raise RequestError("invalid_nonce")
    if action not in ALLOWED_ACTIONS:
        raise RequestError("unsupported_action")
    message = f"{timestamp}\n{nonce}\n{action}".encode()
    expected = hmac.new(CONTROL_SECRET, message, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise RequestError("forbidden")
    with REPLAY_LOCK:
        cutoff = int(time.time()) - 60
        for key, seen_at in list(SEEN_NONCES.items()):
            if seen_at < cutoff:
                del SEEN_NONCES[key]
        if nonce in SEEN_NONCES:
            raise RequestError("replayed_request")
        SEEN_NONCES[nonce] = int(time.time())
    return action


def dispatch(request: dict[str, Any]) -> dict[str, Any]:
    action = authenticate(request)
    if action == "status":
        return status_payload()
    if action == "prepare_h3":
        return prepare_comfy("h3")
    if action == "prepare_image":
        return prepare_comfy("image")
    raise AssertionError("unreachable action")


def handle_connection(connection: socket.socket) -> None:
    try:
        connection.settimeout(240)
        data = bytearray()
        while len(data) <= MAX_REQUEST_BYTES:
            chunk = connection.recv(min(4096, MAX_REQUEST_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if b"\n" in chunk:
                break
        if len(data) > MAX_REQUEST_BYTES or b"\n" not in data:
            raise RequestError("invalid_request_size")
        request = json.loads(bytes(data).split(b"\n", 1)[0])
        if not isinstance(request, dict):
            raise RequestError("invalid_request")
        result = dispatch(request)
        response = {"ok": True, "result": result}
        status = "ok"
    except RequestError as exc:
        response = {"ok": False, "error": str(exc), "error_type": "request"}
        status = "request_error"
    except PolicyError as exc:
        response = {"ok": False, "error": str(exc), "error_type": "policy"}
        status = "policy_error"
    except (json.JSONDecodeError, TypeError, ValueError):
        response = {"ok": False, "error": "invalid_request", "error_type": "request"}
        status = "request_error"
    except Exception as exc:
        print(f"movie-h3-control internal_error={type(exc).__name__}", flush=True)
        response = {"ok": False, "error": "host_control_error", "error_type": "runtime"}
        status = "runtime_error"
    raw = json.dumps(response, separators=(",", ":")).encode() + b"\n"
    try:
        connection.sendall(raw)
    finally:
        connection.close()
    print(f"movie-h3-control result={status}", flush=True)


def inherited_listener() -> socket.socket:
    if int(os.environ.get("LISTEN_FDS", "0")) != 1:
        raise SystemExit("exactly one systemd socket is required")
    listener = socket.fromfd(3, socket.AF_UNIX, socket.SOCK_STREAM)
    listener.setblocking(True)
    return listener


def main() -> None:
    listener = inherited_listener()
    while True:
        connection, _ = listener.accept()
        threading.Thread(target=handle_connection, args=(connection,), daemon=True).start()


if __name__ == "__main__":
    main()
