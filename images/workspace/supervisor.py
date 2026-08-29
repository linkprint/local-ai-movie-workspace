#!/usr/bin/env python3
"""PID 1 for the disposable user workspace.

The deadline is read from a Manager-created, read-only named volume.  This
process deliberately has no control-plane or Docker access; expiration is
therefore independent of Laravel, Redis, and Workspace Manager availability.
"""

from __future__ import annotations

import hashlib
import json
import os
import pathlib
import re
import signal
import subprocess
import sys
import time
from typing import Callable, NamedTuple


DEADLINE_FILE = pathlib.Path("/run/movie/deadline/deadline")
GRANT_FILE = pathlib.Path("/run/movie/ai-grant/grant.json")
CODEX_HOME = pathlib.Path(os.environ.get("CODEX_HOME", "/home/codex/.codex"))
WORKSPACE = pathlib.Path("/workspace")
OUTPUTS = pathlib.Path("/outputs")
PROJECT_DIRECTORY_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
PROJECT_DIRECTORY = os.environ.get("MOVIE_PROJECT_DIRECTORY", "")
AUTH_MODE = os.environ.get("MOVIE_CODEX_AUTH_MODE", "personal")
PROJECT = WORKSPACE / PROJECT_DIRECTORY
TEMPLATE_ROOT = pathlib.Path("/usr/local/share/movie-workspace")
UNMANAGED_CODEX_GRACE_SECONDS = 15.0
UNMANAGED_CODEX_TERM_SECONDS = 5.0
MANAGED_CODEX_ANCESTORS = {
    "movie-workspace-supervisor",
    "movie-company-codex-session",
    "movie-personal-codex-session",
}
LEGACY_AGENTS_SHA256 = {
    "0edeb9f7d3ba9933c0015cf982df9e7e2caba5935a547bacd14e786675c67793",
    "1a7a0e27ce26c33a935e22d7fbe9330aeecb5d47717a8e7b40146d1844292188",
    "0747dbb3a55ecdc38c2ea8a186819a60b3dd2cdd5aea7b6144ba839655d50743",
    "1a229ab670c453bec242f84301bd5b6c1da7aeaf2d21483f62b26b8219ca1c5c",
    "7c73b519ca778324f448a455ae6d37614cc4496b5e495015b103f00aa76010c3",
    "3a3934f8245036b1d989a4ecceaf3e50b8151e060fdc2e467df3b57509afda44",
    "00607480741ebd3391b9c55195c574cede647f7788630eeacc62f56e6499d756",
    "6a4a0b8d30b99acb3b9a033fffc68ffea3280afb5225db312d963a311dfe5710",
    "64408b45ac5a47d5444b5d21ab2ad587ca4b4609d9a389488a9a8d88d6e5af34",
    "969d0f8307c40b0c5ff9ababa002ac0da8a0dd1c7825a74082b3464aaf968b02",
    "ad6dc14bdd0790fd24048bc3d5f3370a28df588580998419cb5729badd297332",
    "6f1b2879fd9042ed88bccb1df794aa132fa299c1c969fe79ad63f0b76e5f5d83",
    "ab8f3ce1c87a4430a77373e1d6f1f00ba9c3ecf6ae21419ae15b4e2ef025724f",
    "a1b1595c6aa8d4c571c8af6739db4b8761457359da6259bb9936e063ea65d1d4",
    "a249b319ca062d167da224bf5aa3116bec9958582eec7ec3647d69b73b234c6a",
    "1506441d1d07f9ad3bad54a976982a404741c8dc85e6c54949e6decadecccee9",
    "dbe9049e2c164ca5f5adbf092fe829dd8daa3f7789d15cc793312eeb65d3098c",
    "684f550bb833e3f83806151bdc79c2d94003440d726b19c69fe3c753a7ded3a1",
}
LEGACY_SERVER_CONTEXT_SHA256 = {
    "6a6ae1346c20b17b0824b949789e6fb1bfd1e50c665e4164115e7c12f409e3ee",
    "f078afa6a51ce66e0c2f9e99df6f555e5097c08f2eee3e51e478c434b0dc81c4",
    "a536f254b8b6219cd025c419cbf4dec60440159e2b2a23205fae116bff62cc34",
    "21605044ead0decf4744a551183f24f5fee5da87a61a6d8d8165af31326536bd",
    "3bc1263a57ae7fa40fc1da247ee185043f2a2df275d8e0bb64e41541ed825ef5",
    "fa07816b3c047200c76569f28cca11beb762361ca7f5665c412bcc6e7c817be2",
    "4e987107c645eaa40dbb82ed6a6f4fcf884d0e239bd2c010e5b9c3d06076f192",
    "9078868684c69321958b8196d47a22c15c731eceaf77d80773b6b4419d9a2cb4",
    "f46d76614a2c6cfe0ddba579b7b07c9a219e269a21686850bf7f47c24ac4327f",
    "2a5dd1fafdcc08838674888ef87a7343bfca9ce0da3e2cb4d53b782245cf1a0e",
    "38e19472d181c13a7f5376c253cd3787d52f075d71c887aec1f513fe2caaa9cc",
}


class ProcessInfo(NamedTuple):
    pid: int
    ppid: int
    pgrp: int
    start_ticks: int
    argv: tuple[str, ...]


def process_programs(argv: tuple[str, ...]) -> set[str]:
    """Return executable/script basenames without retaining command arguments."""

    return {pathlib.PurePath(value).name for value in argv[:2] if value}


def is_codex_process(process: ProcessInfo) -> bool:
    return "codex" in process_programs(process.argv)


def is_managed_codex_ancestor(process: ProcessInfo) -> bool:
    return bool(process_programs(process.argv) & MANAGED_CODEX_ANCESTORS)


def read_process_table(proc_root: pathlib.Path = pathlib.Path("/proc")) -> dict[int, ProcessInfo]:
    processes: dict[int, ProcessInfo] = {}
    try:
        entries = list(proc_root.iterdir())
    except OSError:
        return processes

    for entry in entries:
        if not entry.name.isdigit():
            continue
        try:
            raw_stat = (entry / "stat").read_text(encoding="ascii")
            fields = raw_stat[raw_stat.rfind(")") + 2 :].split()
            raw_argv = (entry / "cmdline").read_bytes().split(b"\0")
            argv = tuple(
                value.decode("utf-8", errors="replace")
                for value in raw_argv
                if value
            )
            process = ProcessInfo(
                pid=int(entry.name),
                ppid=int(fields[1]),
                pgrp=int(fields[2]),
                start_ticks=int(fields[19]),
                argv=argv,
            )
        except (IndexError, OSError, ValueError):
            # Processes can exit while /proc is being sampled. An incomplete
            # ancestry is never considered safe to kill.
            continue
        processes[process.pid] = process
    return processes


def unmanaged_codex_groups(
    processes: dict[int, ProcessInfo], *, protected_pgrps: set[int]
) -> dict[tuple[int, int], int]:
    """Return non-terminal Codex process groups keyed by a reuse-safe identity."""

    groups: dict[tuple[int, int], int] = {}
    for process in processes.values():
        if not is_codex_process(process):
            continue

        current = process
        visited: set[int] = set()
        managed = False
        complete_ancestry = False
        while current.pid not in visited:
            visited.add(current.pid)
            if is_managed_codex_ancestor(current):
                managed = True
                complete_ancestry = True
                break
            if current.ppid == 0:
                complete_ancestry = True
                break
            parent = processes.get(current.ppid)
            if parent is None:
                break
            current = parent

        if managed or not complete_ancestry:
            continue
        if process.pgrp <= 1 or process.pgrp in protected_pgrps:
            continue
        leader = processes.get(process.pgrp)
        generation = leader.start_ticks if leader is not None else process.start_ticks
        groups[(process.pgrp, generation)] = process.pgrp
    return groups


class ReapState:
    def __init__(self, first_seen: float) -> None:
        self.first_seen = first_seen
        self.term_sent: float | None = None
        self.kill_sent = False


class UnmanagedCodexReaper:
    """Reap Codex instances launched outside the single managed tmux session."""

    def __init__(
        self,
        *,
        grace_seconds: float = UNMANAGED_CODEX_GRACE_SECONDS,
        term_seconds: float = UNMANAGED_CODEX_TERM_SECONDS,
        signal_group: Callable[[int, int], None] = os.killpg,
    ) -> None:
        self.grace_seconds = grace_seconds
        self.term_seconds = term_seconds
        self.signal_group = signal_group
        self.states: dict[tuple[int, int], ReapState] = {}

    def tick(
        self,
        *,
        now: float | None = None,
        processes: dict[int, ProcessInfo] | None = None,
    ) -> None:
        observed_at = time.monotonic() if now is None else now
        snapshot = read_process_table() if processes is None else processes
        groups = unmanaged_codex_groups(snapshot, protected_pgrps={os.getpgrp()})
        active = set(groups)
        for key in tuple(self.states):
            if key not in active:
                self.states.pop(key, None)

        for key, process_group in groups.items():
            state = self.states.setdefault(key, ReapState(first_seen=observed_at))
            if state.term_sent is None:
                if observed_at - state.first_seen < self.grace_seconds:
                    continue
                try:
                    self.signal_group(process_group, signal.SIGTERM)
                    print(
                        f"workspace-supervisor: reaping unmanaged Codex process group {process_group}",
                        file=sys.stderr,
                        flush=True,
                    )
                except ProcessLookupError:
                    self.states.pop(key, None)
                    continue
                except PermissionError as exc:
                    print(
                        f"workspace-supervisor: unable to reap process group {process_group}: {exc}",
                        file=sys.stderr,
                        flush=True,
                    )
                    state.kill_sent = True
                    continue
                state.term_sent = observed_at
                continue

            if state.kill_sent or observed_at - state.term_sent < self.term_seconds:
                continue
            try:
                self.signal_group(process_group, signal.SIGKILL)
            except (ProcessLookupError, PermissionError):
                pass
            state.kill_sent = True


def read_deadline(*, require_future: bool = False) -> int:
    try:
        value = DEADLINE_FILE.read_text(encoding="ascii").strip()
        deadline = int(value)
    except (OSError, ValueError) as exc:
        raise RuntimeError(f"workspace deadline is unavailable: {exc}") from exc

    now = int(time.time())
    if deadline > now + (9 * 60 * 60) or (require_future and deadline <= now):
        raise RuntimeError("workspace deadline is outside the allowed window")
    return deadline


def file_sha256(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def install_managed_file(
    source: pathlib.Path,
    target: pathlib.Path,
    *,
    replace_hashes: set[str] | None = None,
) -> bool:
    """Install atomically, preserving an existing user-authored file."""
    if target.exists():
        if replace_hashes is None or file_sha256(target) not in replace_hashes:
            return False

    temporary = target.with_name(f".{target.name}.managed-{os.getpid()}")
    temporary.write_bytes(source.read_bytes())
    temporary.chmod(0o444)
    os.replace(temporary, target)
    return True


def install_model_catalog() -> None:
    try:
        bundled = subprocess.run(
            ["/usr/local/bin/codex", "debug", "models", "--bundled"],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        generated = subprocess.run(
            ["/usr/local/bin/movie-codex-model-router", "--build-catalog"],
            check=True,
            input=bundled.stdout,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        payload = json.loads(generated.stdout)
    except (OSError, subprocess.CalledProcessError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Movie model catalog is unavailable: {exc}") from exc

    models = payload.get("models") if isinstance(payload, dict) else None
    if not isinstance(models, list) or not models:
        raise RuntimeError("Movie model catalog is empty")

    target = CODEX_HOME / "movie-models.json"
    temporary = target.with_name(f".{target.name}.managed-{os.getpid()}")
    temporary.write_bytes(generated.stdout)
    temporary.chmod(0o600)
    os.replace(temporary, target)


def initialize_user_volumes() -> None:
    # The Portal is added only to the output-volume group so it can manage the
    # current user's videos.  Workspace and CODEX_HOME volumes are not mounted
    # into the Portal and remain independently isolated.
    os.umask(0o007)
    for directory in (CODEX_HOME, WORKSPACE, OUTPUTS):
        directory.mkdir(parents=True, exist_ok=True)
    OUTPUTS.chmod(0o770)

    if not PROJECT_DIRECTORY_RE.fullmatch(PROJECT_DIRECTORY) or ".." in PROJECT_DIRECTORY:
        raise RuntimeError("project directory is invalid")
    if AUTH_MODE not in {"personal", "company"}:
        raise RuntimeError("Codex authentication mode is invalid")
    if PROJECT.is_symlink() or not PROJECT.is_dir() or PROJECT.resolve().parent != WORKSPACE.resolve():
        raise RuntimeError("project directory is outside the workspace root")

    try:
        subprocess.run(
            ["/usr/local/bin/movie-ai", "skills", "verify"],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
    except (OSError, subprocess.CalledProcessError) as exc:
        detail = getattr(exc, "stderr", b"").decode("utf-8", errors="replace").strip()
        raise RuntimeError(f"Workspace admin skills are invalid: {detail or exc}") from exc

    config = CODEX_HOME / "config.toml"
    if not config.exists():
        source = TEMPLATE_ROOT / "config.toml"
        config.write_bytes(source.read_bytes())
        config.chmod(0o600)

    movie_profile = CODEX_HOME / "movie.config.toml"
    movie_temporary = CODEX_HOME / f".movie.config.toml.managed-{os.getpid()}"
    movie_temporary.write_bytes((TEMPLATE_ROOT / "movie.config.toml").read_bytes())
    movie_temporary.chmod(0o600)
    os.replace(movie_temporary, movie_profile)

    # Codex 0.149+ reads custom picker entries from model_catalog_json rather
    # than discovering them through the provider's /models endpoint.
    install_model_catalog()
    (CODEX_HOME / "models_cache.json").unlink(missing_ok=True)

    install_managed_file(
        TEMPLATE_ROOT / "AGENTS.md",
        WORKSPACE / "AGENTS.md",
        replace_hashes=LEGACY_AGENTS_SHA256,
    )
    install_managed_file(
        TEMPLATE_ROOT / "SERVER_CONTEXT.md",
        WORKSPACE / "SERVER_CONTEXT.md",
        replace_hashes=LEGACY_SERVER_CONTEXT_SHA256,
    )


def terminate(process: subprocess.Popen[bytes]) -> None:
    if process.poll() is not None:
        return
    process.send_signal(signal.SIGTERM)
    try:
        process.wait(timeout=60)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)


def grant_fingerprint() -> tuple[bool, str, int]:
    try:
        grant = json.loads(GRANT_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError):
        return False, "", 0
    if not isinstance(grant, dict):
        return False, "", 0
    enabled = grant.get("enabled") is True and int(grant.get("expires_at", 0)) > int(time.time())
    return enabled, str(grant.get("reservation_id", "")), int(grant.get("generation", 0))


def notify_grant_change(enabled: bool) -> None:
    message = (
        "本地 AI 已可用，可在 /model 中选择本地 Qwen，并可使用本地生图/视频命令。"
        if enabled
        else "本地 AI 当前不可用；OpenAI Codex、项目文件和历史会话仍可继续使用。"
    )
    subprocess.run(
        ["tmux", "display-message", "-d", "10000", message],
        check=False,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def main() -> int:
    try:
        deadline = read_deadline(require_future=True)
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    try:
        initialize_user_volumes()
    except (OSError, RuntimeError) as exc:
        print(str(exc), file=sys.stderr)
        return 1

    command = [
        "/usr/local/bin/ttyd",
        "--port", "7681",
        "--interface", "0.0.0.0",
        "--base-path", "/terminal",
        "--writable",
        "--signal", "SIGHUP",
        "-t", "disableLeaveAlert=true",
        "-t", "fontSize=15",
        "tmux", "-f", "/usr/local/share/movie-workspace/tmux.conf",
        "new-session", "-A", "-s", "movie", "-c", str(PROJECT),
    ]
    if AUTH_MODE == "company":
        command.append("/usr/local/bin/movie-company-codex-session")
    else:
        command.append("/usr/local/bin/movie-personal-codex-session")
    child = subprocess.Popen(command, start_new_session=True)
    codex_reaper = UnmanagedCodexReaper()
    observed_grant = grant_fingerprint()

    stopping = False

    def request_stop(_signum: int, _frame: object) -> None:
        nonlocal stopping
        stopping = True

    signal.signal(signal.SIGTERM, request_stop)
    signal.signal(signal.SIGINT, request_stop)

    while child.poll() is None:
        codex_reaper.tick()
        current_grant = grant_fingerprint()
        if current_grant != observed_grant:
            observed_grant = current_grant
            try:
                install_model_catalog()
                (CODEX_HOME / "models_cache.json").unlink(missing_ok=True)
            except (OSError, RuntimeError) as exc:
                print(f"workspace-supervisor: unable to refresh model catalog: {exc}", file=sys.stderr, flush=True)
            notify_grant_change(current_grant[0])
        try:
            deadline = read_deadline()
        except RuntimeError as exc:
            print(str(exc), file=sys.stderr)
            terminate(child)
            return 1
        if stopping or time.time() >= deadline:
            terminate(child)
            return 0
        time.sleep(1)

    return child.returncode or 0


if __name__ == "__main__":
    sys.exit(main())
