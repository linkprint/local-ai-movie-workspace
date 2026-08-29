#!/usr/bin/env python3
"""Reservation-scoped CLI for the fixed Movie AI Broker capabilities."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pathlib
import re
import shutil
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


BASE_URL = os.environ.get("MOVIE_AI_BROKER_URL", "http://movie-ai-broker:8080").rstrip("/")
GRANT_FILE = pathlib.Path("/run/movie/ai-grant/grant.json")
RUNTIME_ID = os.environ.get("MOVIE_RUNTIME_ID", "")
RUNTIME_GENERATION = int(os.environ.get("MOVIE_RUNTIME_GENERATION", "0"))
PROJECT_ID = os.environ.get("MOVIE_PROJECT_ID", "")
VIDEO_BASE_URL = os.environ.get(
    "MOVIE_VIDEO_BASE_URL",
    "https://movie.example.com/workspace/projects",
).rstrip("/")
WRITE_ROOTS = (pathlib.Path("/workspace"), pathlib.Path("/outputs"))
OUTPUTS_ROOT = pathlib.Path("/outputs")
TERMINAL_STATES = {"completed", "failed", "cancelled", "expired"}
VIDEO_EXTENSIONS = {".mp4", ".webm", ".mov", ".m4v"}
IMAGE_EXTENSIONS = {".gif", ".jpeg", ".jpg", ".png", ".webp"}
H3_STYLE_SKILL_RE = re.compile(r"^h3-[a-z0-9]+(?:-[a-z0-9]+)*$")
H3_WORKFLOW_PRESETS = ("pdd-acc-8step", "standard")
H3_CONTENT_PROFILES = ("general", "adult")
ADMIN_SKILLS_ROOT = pathlib.Path(
    os.environ.get("MOVIE_ADMIN_SKILLS_ROOT", "/etc/codex/skills")
)
REQUIRED_ADMIN_SKILLS = {
    "h3-prompt-writing",
    "h3-video-generation",
    "z-image-turbo-generation",
    "hunyuan-image-generation",
}


def verify_admin_skills() -> dict:
    if not ADMIN_SKILLS_ROOT.is_dir() or ADMIN_SKILLS_ROOT.is_symlink():
        raise SystemExit("admin skill root is unavailable")
    installed: list[str] = []
    for directory in sorted(ADMIN_SKILLS_ROOT.iterdir(), key=lambda path: path.name):
        if not directory.is_dir() or directory.is_symlink():
            continue
        skill = directory / "SKILL.md"
        if not skill.is_file() or skill.is_symlink():
            raise SystemExit(f"invalid admin skill: {directory.name}")
        if skill.stat().st_mode & 0o022 or directory.stat().st_mode & 0o022:
            raise SystemExit(f"writable admin skill is not allowed: {directory.name}")
        header = skill.read_text(encoding="utf-8")[:8192]
        name = re.search(r"(?m)^name:\s*[\"']?([a-z0-9-]+)[\"']?\s*$", header)
        description = re.search(r"(?m)^description:\s*\S", header)
        if name is None or name.group(1) != directory.name or description is None:
            raise SystemExit(f"invalid admin skill metadata: {directory.name}")
        installed.append(directory.name)
    missing = sorted(REQUIRED_ADMIN_SKILLS.difference(installed))
    if missing:
        raise SystemExit("missing required admin skills: " + ", ".join(missing))
    return {
        "ok": True,
        "scope": "admin",
        "root": str(ADMIN_SKILLS_ROOT),
        "skills": installed,
    }


def auth_headers(extra: dict[str, str] | None = None) -> dict[str, str]:
    try:
        grant = json.loads(GRANT_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        raise SystemExit("local_ai_reservation_required") from exc
    valid = isinstance(grant, dict)
    valid = valid and grant.get("enabled") is True
    valid = valid and grant.get("runtime_id") == RUNTIME_ID
    valid = valid and int(grant.get("generation", 0)) == RUNTIME_GENERATION
    valid = valid and int(grant.get("expires_at", 0)) > int(time.time())
    token = str(grant.get("token", "")) if isinstance(grant, dict) else ""
    valid = valid and 32 <= len(token) <= 2048
    if not valid:
        raise SystemExit("local_ai_reservation_required")
    headers = {"Authorization": f"Bearer {token}"}
    if extra:
        headers.update(extra)
    return headers


def request(method: str, path: str, body: dict | None = None, timeout: int = 30) -> dict:
    data = None if body is None else json.dumps(body, separators=(",", ":")).encode()
    req = urllib.request.Request(
        BASE_URL + path,
        data=data,
        method=method,
        headers=auth_headers({"Content-Type": "application/json"}),
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return json.load(response)
    except urllib.error.HTTPError as exc:
        try:
            detail = json.load(exc)["error"]
        except Exception:
            detail = f"HTTP {exc.code}"
        raise SystemExit(detail) from exc


def readable_path(value: str, base: pathlib.Path | None = None) -> pathlib.Path:
    path = pathlib.Path(value)
    if not path.is_absolute() and base is not None:
        path = base / path
    resolved = path.resolve(strict=True)
    if not resolved.is_file() or not any(resolved.is_relative_to(root) for root in WRITE_ROOTS):
        raise SystemExit("input files must be regular files inside /workspace or /outputs")
    return resolved


def writable_path(value: str) -> pathlib.Path:
    path = pathlib.Path(value)
    if not path.is_absolute():
        path = pathlib.Path.cwd() / path
    parent = path.parent.resolve(strict=True)
    if not any(parent.is_relative_to(root) for root in WRITE_ROOTS):
        raise SystemExit("output must be inside /workspace or /outputs")
    return parent / path.name


def project_artifact_url(
    relative: pathlib.PurePath,
    kind: str,
    extensions: set[str],
) -> str:
    if relative.is_absolute() or not relative.parts or any(part in {"", ".", ".."} for part in relative.parts):
        raise SystemExit(f"invalid published {kind[:-1]} path")
    if relative.suffix.lower() not in extensions:
        raise SystemExit(f"unsupported published {kind[:-1]} extension")
    try:
        project_id = str(uuid.UUID(PROJECT_ID))
    except (ValueError, AttributeError) as exc:
        raise SystemExit("MOVIE_PROJECT_ID is unavailable") from exc
    encoded = "/".join(urllib.parse.quote(part, safe="") for part in relative.parts)
    return f"{VIDEO_BASE_URL}/{project_id}/{kind}/{encoded}"


def project_video_url(relative: pathlib.PurePath) -> str:
    return project_artifact_url(relative, "videos", VIDEO_EXTENSIONS)


def project_image_url(relative: pathlib.PurePath) -> str:
    return project_artifact_url(relative, "images", IMAGE_EXTENSIONS)


def video_url(value: str) -> str:
    path = readable_path(value)
    outputs = OUTPUTS_ROOT.resolve(strict=True)
    try:
        relative = path.relative_to(outputs)
    except ValueError as exc:
        raise SystemExit("published videos must be inside /outputs") from exc
    return project_video_url(relative)


def image_url(value: str) -> str:
    path = readable_path(value)
    outputs = OUTPUTS_ROOT.resolve(strict=True)
    try:
        relative = path.relative_to(outputs)
    except ValueError as exc:
        raise SystemExit("published images must be inside /outputs") from exc
    return project_image_url(relative)


def publish_image(value: str, link_source: bool = False) -> dict:
    source = readable_path(value)
    if source.suffix.lower() not in IMAGE_EXTENSIONS:
        raise SystemExit("published image must use gif, jpeg, jpg, png, or webp")
    outputs = OUTPUTS_ROOT.resolve(strict=True)
    source_is_output = source.is_relative_to(outputs)
    if source_is_output:
        destination = source
    else:
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        destination = outputs / source.name
        if destination.exists() or destination.is_symlink():
            if not destination.is_file() or destination.is_symlink():
                raise SystemExit("published image destination is unavailable")
            if hashlib.sha256(destination.read_bytes()).hexdigest() != digest:
                destination = outputs / f"{source.stem}-{digest[:12]}{source.suffix.lower()}"
        if destination.exists() or destination.is_symlink():
            if (not destination.is_file()
                    or destination.is_symlink()
                    or hashlib.sha256(destination.read_bytes()).hexdigest() != digest):
                raise SystemExit("published image destination collision")
        if not destination.exists():
            temporary = outputs / f".{uuid.uuid4()}.partial"
            try:
                shutil.copyfile(source, temporary)
                temporary.replace(destination)
                destination.chmod(0o660)
            finally:
                temporary.unlink(missing_ok=True)
        if link_source:
            source.unlink()
            source.symlink_to(destination)
    data = destination.read_bytes()
    return {
        "source": str(source),
        "output": str(destination),
        "source_linked": link_source and not source_is_output,
        "size": len(data),
        "sha256": hashlib.sha256(data).hexdigest(),
        "url": image_url(str(destination)),
    }


def load_spec(value: str) -> tuple[dict, pathlib.Path]:
    path = readable_path(value)
    try:
        spec = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise SystemExit(f"invalid spec: {exc}") from exc
    if not isinstance(spec, dict):
        raise SystemExit("spec must be a JSON object")
    return spec, path.parent


def upload_media(path: pathlib.Path) -> str:
    data = path.read_bytes()
    digest = hashlib.sha256(data).hexdigest()
    req = urllib.request.Request(
        BASE_URL + "/v1/uploads",
        data=data,
        method="POST",
        headers=auth_headers({
            "Content-Type": "application/octet-stream",
            "X-Movie-Filename": urllib.parse.quote(path.name, safe=""),
            "X-Movie-Sha256": digest,
        }),
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        try:
            detail = json.load(exc)["error"]
        except Exception:
            detail = f"HTTP {exc.code}"
        raise SystemExit(detail) from exc
    return str(result["upload"]["id"])


def prepare_h3_spec(spec: dict, base: pathlib.Path) -> dict:
    prepared = dict(spec)
    for source_key, upload_key in (
        ("first_frame", "first_frame_upload_id"),
        ("last_frame", "last_frame_upload_id"),
    ):
        value = prepared.pop(source_key, None)
        if value is not None:
            if not isinstance(value, str):
                raise SystemExit(f"{source_key} must be a path string")
            prepared[upload_key] = upload_media(readable_path(value, base))
    for source_key, upload_key, maximum in (
        ("reference_images", "reference_image_upload_ids", 9),
        ("reference_videos", "reference_video_upload_ids", 3),
    ):
        values = prepared.pop(source_key, None)
        if values is None:
            continue
        if not isinstance(values, list) or len(values) > maximum:
            raise SystemExit(f"{source_key} must be an array with at most {maximum} paths")
        upload_ids: list[str] = []
        for value in values:
            if not isinstance(value, str):
                raise SystemExit(f"{source_key} must contain path strings")
            upload_ids.append(upload_media(readable_path(value, base)))
        prepared[upload_key] = upload_ids
    return prepared


def prepare_h3_submission(
    spec: dict,
    base: pathlib.Path,
    style_skill: str | None,
    workflow_preset: str | None = "standard",
    content_profile: str | None = "general",
) -> dict:
    prepared = prepare_h3_spec(spec, base)
    embedded_style = prepared.pop("style_skill", None)
    if embedded_style is not None:
        raise SystemExit("style_skill must be supplied with --style-skill")
    embedded_workflow = prepared.pop("workflow_preset", None)
    if embedded_workflow is not None:
        raise SystemExit("workflow_preset must be supplied with --workflow-preset")
    if workflow_preset not in H3_WORKFLOW_PRESETS:
        raise SystemExit("H3 workflow preset selection is required")
    prepared["workflow_preset"] = workflow_preset
    embedded_content_profile = prepared.pop("content_profile", None)
    if embedded_content_profile is not None:
        raise SystemExit("content_profile must be supplied with --content-profile")
    if content_profile not in H3_CONTENT_PROFILES:
        raise SystemExit("H3 content profile selection is required")
    prepared["content_profile"] = content_profile
    if style_skill is not None:
        if not H3_STYLE_SKILL_RE.fullmatch(style_skill):
            raise SystemExit("invalid H3 style skill")
        prepared["style_skill"] = style_skill
    return prepared


def wait_for_job(job_id: str, interval: int = 3) -> dict:
    while True:
        result = request("GET", f"/v1/jobs/{job_id}")
        job = result["job"]
        print(json.dumps(job, ensure_ascii=False, sort_keys=True), flush=True)
        if job.get("status") in TERMINAL_STATES:
            return result
        time.sleep(interval)


def download_job(job_id: str, output_value: str) -> dict:
    output = writable_path(output_value)
    if output.suffix.lower() in VIDEO_EXTENSIONS | IMAGE_EXTENSIONS:
        outputs = pathlib.Path("/outputs").resolve(strict=True)
        if not output.parent.resolve(strict=True).is_relative_to(outputs):
            raise SystemExit("downloaded media must be saved inside /outputs")
    temporary = output.with_suffix(output.suffix + ".partial")
    req = urllib.request.Request(
        BASE_URL + f"/v1/jobs/{job_id}/artifact",
        headers=auth_headers(),
    )
    digest = hashlib.sha256()
    size = 0
    try:
        with urllib.request.urlopen(req, timeout=600) as response, temporary.open("wb") as destination:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                digest.update(chunk)
                destination.write(chunk)
        temporary.replace(output)
        output.chmod(0o660)
    except urllib.error.HTTPError as exc:
        temporary.unlink(missing_ok=True)
        try:
            detail = json.load(exc)["error"]
        except Exception:
            detail = f"HTTP {exc.code}"
        raise SystemExit(detail) from exc
    except Exception:
        temporary.unlink(missing_ok=True)
        raise
    result = {"output": str(output), "size": size, "sha256": digest.hexdigest()}
    if output.suffix.lower() in VIDEO_EXTENSIONS:
        result["url"] = video_url(str(output))
    elif output.suffix.lower() in IMAGE_EXTENSIONS:
        result["url"] = image_url(str(output))
    return result


def choose_style_model(models: list[dict]) -> str:
    if not models:
        raise SystemExit("no prompt-only image style models are available")
    print("支持的纯提示词生图模型：", flush=True)
    for index, model in enumerate(models, start=1):
        model_id = str(model.get("id", ""))
        display_name = str(model.get("display_name", model_id))
        print(f"  {index}. {model_id} — {display_name}", flush=True)
    selection = input("选择模型（输入编号或完整模型名）: ").strip()
    if selection.isdigit():
        selected_index = int(selection)
        if 1 <= selected_index <= len(models):
            return str(models[selected_index - 1]["id"])
    for model in models:
        if selection == model.get("id"):
            return selection
    raise SystemExit("invalid image style model selection")


def image_style(args: argparse.Namespace) -> dict:
    catalog = request("GET", "/v1/image/style/models", timeout=60)
    models = catalog.get("models", [])
    if not isinstance(models, list):
        raise SystemExit("invalid image style model catalog")
    if args.list:
        return catalog
    available = {
        str(model.get("id")): model
        for model in models
        if isinstance(model, dict) and isinstance(model.get("id"), str)
    }
    model_id = args.model or choose_style_model(models)
    if model_id not in available:
        raise SystemExit("unsupported image style model")
    prompt = args.prompt if args.prompt is not None else input("输入提示词并回车开始生成: ").strip()
    if not prompt.strip():
        raise SystemExit("prompt must not be empty")
    spec = {
        "model": model_id,
        "prompt": prompt,
        "width": args.width,
        "height": args.height,
    }
    if args.seed is not None:
        spec["seed"] = args.seed
    submitted = request("POST", "/v1/image/style/jobs", spec, timeout=120)
    job_id = str(submitted["job"]["id"])
    completed = wait_for_job(job_id)
    if completed.get("job", {}).get("status") != "completed":
        return completed
    if args.output:
        output_value = args.output
        if pathlib.Path(output_value).suffix.lower() not in {".jpg", ".jpeg"}:
            raise SystemExit("image style output must use .jpg or .jpeg")
    else:
        safe_model = re.sub(r"[^A-Za-z0-9._-]+", "-", pathlib.Path(model_id).stem).strip(".-")
        output_value = f"/outputs/image-style-{safe_model}-{job_id[:8]}.jpg"
    artifact = download_job(job_id, output_value)
    return {"job": completed["job"], "artifact": artifact}


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(prog="movie-ai")
    section = root.add_subparsers(dest="section", required=True)

    skills = section.add_parser("skills")
    skills.add_subparsers(dest="action", required=True).add_parser("verify")

    gpu = section.add_parser("gpu")
    gpu.add_subparsers(dest="action", required=True).add_parser("status")

    mock = section.add_parser("mock")
    mock_sub = mock.add_subparsers(dest="action", required=True)
    mock_submit = mock_sub.add_parser("submit")
    mock_submit.add_argument("--prompt", required=True)

    h3 = section.add_parser("h3")
    h3_sub = h3.add_subparsers(dest="action", required=True)
    h3_submit = h3_sub.add_parser("generate")
    h3_submit.add_argument("--spec", required=True)
    h3_submit.add_argument(
        "--workflow-preset",
        required=True,
        choices=H3_WORKFLOW_PRESETS,
        help=(
            "pdd-acc-8step for the locked high-speed 8-step workflow, or "
            "standard for the normal workflow (20 steps by default; 8-50 supported)"
        ),
    )
    h3_submit.add_argument(
        "--content-profile",
        required=True,
        choices=H3_CONTENT_PROFILES,
        help=(
            "general for the ordinary MiniMax H3 weight, or adult for the "
            "installed PinkCherry adult-content weight"
        ),
    )
    h3_submit.add_argument(
        "--style-skill",
        help="exact active registered h3-* style skill; omit for non-style video jobs",
    )
    h3_submit.add_argument("--wait", action="store_true")

    image = section.add_parser("image")
    image_sub = image.add_subparsers(dest="action", required=True)
    image_sub.add_parser("models")
    image_submit = image_sub.add_parser("generate")
    image_submit.add_argument("--spec", required=True)
    image_submit.add_argument(
        "--model",
        help=(
            "local renderer override: z-image-turbo (default) or "
            "HunyuanImage-3.0-Instruct"
        ),
    )
    image_submit.add_argument("--wait", action="store_true")
    image_style_command = image_sub.add_parser("style")
    image_style_command.add_argument("--list", action="store_true", help="list prompt-only style models")
    image_style_command.add_argument("--model", help="exact model filename from --list")
    image_style_command.add_argument("--prompt", help="text prompt; asks interactively when omitted")
    image_style_command.add_argument("--width", type=int, default=1024)
    image_style_command.add_argument("--height", type=int, default=1024)
    image_style_command.add_argument("--seed", type=int)
    image_style_command.add_argument("--output", help="destination .jpg/.jpeg path inside /outputs")
    image_url_command = image_sub.add_parser("url")
    image_url_command.add_argument("path")
    image_publish_command = image_sub.add_parser("publish")
    image_publish_command.add_argument("path")
    image_publish_command.add_argument("--link-source", action="store_true")

    jobs = section.add_parser("job")
    jobs_sub = jobs.add_subparsers(dest="action", required=True)
    jobs_sub.add_parser("list")
    status = jobs_sub.add_parser("status")
    status.add_argument("job_id")
    wait = jobs_sub.add_parser("wait")
    wait.add_argument("job_id")
    wait.add_argument("--interval", type=int, default=3, choices=range(1, 31))
    cancel = jobs_sub.add_parser("cancel")
    cancel.add_argument("job_id")
    download = jobs_sub.add_parser("download")
    download.add_argument("job_id")
    download.add_argument("--output", required=True)

    video = section.add_parser("video")
    video_sub = video.add_subparsers(dest="action", required=True)
    video_url_command = video_sub.add_parser("url")
    video_url_command.add_argument("path")
    return root


def main() -> int:
    args = parser().parse_args()
    if args.section == "skills" and args.action == "verify":
        result = verify_admin_skills()
    elif args.section == "gpu" and args.action == "status":
        result = request("GET", "/v1/gpu/status")
    elif args.section == "mock" and args.action == "submit":
        result = request("POST", "/v1/mock/jobs", {"prompt": args.prompt})
    elif args.section == "h3" and args.action == "generate":
        spec, base = load_spec(args.spec)
        result = request(
            "POST",
            "/v1/h3/jobs",
            prepare_h3_submission(
                spec,
                base,
                args.style_skill,
                args.workflow_preset,
                args.content_profile,
            ),
            timeout=120,
        )
        if args.wait:
            result = wait_for_job(result["job"]["id"])
    elif args.section == "image" and args.action == "models":
        result = request("GET", "/v1/image/models")
    elif args.section == "image" and args.action == "generate":
        spec, _ = load_spec(args.spec)
        if args.model:
            spec["model"] = args.model
        result = request("POST", "/v1/image/jobs", spec, timeout=120)
        if args.wait:
            result = wait_for_job(result["job"]["id"])
    elif args.section == "image" and args.action == "style":
        result = image_style(args)
    elif args.section == "image" and args.action == "url":
        result = {"path": str(readable_path(args.path)), "url": image_url(args.path)}
    elif args.section == "image" and args.action == "publish":
        result = publish_image(args.path, link_source=args.link_source)
    elif args.section == "job" and args.action == "list":
        result = request("GET", "/v1/jobs")
    elif args.section == "job" and args.action == "status":
        result = request("GET", f"/v1/jobs/{args.job_id}")
    elif args.section == "job" and args.action == "wait":
        result = wait_for_job(args.job_id, args.interval)
    elif args.section == "job" and args.action == "cancel":
        result = request("POST", f"/v1/jobs/{args.job_id}/cancel", {})
    elif args.section == "job" and args.action == "download":
        result = download_job(args.job_id, args.output)
    elif args.section == "video" and args.action == "url":
        result = {"path": str(readable_path(args.path)), "url": video_url(args.path)}
    else:
        raise SystemExit("unsupported command")
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
