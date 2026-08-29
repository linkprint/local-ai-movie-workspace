#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"

python3 -m py_compile \
    "$root/host-control/movie_h3_control.py" \
    "$root/images/control/manager.py" \
    "$root/images/control/broker.py" \
    "$root/images/control/ai_router.py" \
    "$root/images/control/node_control.py" \
    "$root/images/control/h3_adapter.py" \
    "$root/images/workspace/movie-ai.py" \
    "$root/images/workspace/codex_model_router.py" \
    "$root/images/workspace/company_codex_session.py" \
    "$root/images/workspace/personal_codex_session.py"
python3 "$root/ops/tests/test_gate4_host_control.py"
python3 "$root/ops/tests/test_h3_style_demo_binding.py"
python3 "$root/ops/tests/test_broker_malformed_tool_recovery.py"
python3 "$root/ops/tests/test_ai_router.py"

python3 - "$root" <<'PY'
import hashlib
import hmac
import importlib.util
import json
import os
import pathlib
import tempfile
import time
import uuid
import sys

root = pathlib.Path(sys.argv[1])

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module

with tempfile.TemporaryDirectory() as directory:
    temp = pathlib.Path(directory)
    control_secret = temp / "control"
    broker_secret = temp / "broker"
    manager_secret = temp / "manager"
    for path in (control_secret, broker_secret, manager_secret):
        path.write_text("a" * 64)

    os.environ["MOVIE_H3_CONTROL_SECRET_FILE"] = str(control_secret)
    host = load("movie_h3_control_test", root / "host-control/movie_h3_control.py")
    assert host.ALLOWED_ACTIONS == {"status", "prepare_h3", "prepare_image"}
    assert host.UNIT_RE.fullmatch(host.COMFY_UNIT)
    assert host.UNIT_RE.fullmatch(host.QWEN_UNIT)
    assert host.CONTAINER_RE.fullmatch(host.QWEN_CONTAINER)
    stamp = int(time.time())
    nonce = "n" * 40
    action = "status"
    signature = hmac.new(host.CONTROL_SECRET, f"{stamp}\n{nonce}\n{action}".encode(), hashlib.sha256).hexdigest()
    request = {"timestamp": stamp, "nonce": nonce, "action": action, "signature": signature}
    assert host.authenticate(request) == "status"
    try:
        host.authenticate(request)
        raise AssertionError("replay accepted")
    except host.RequestError as error:
        assert str(error) == "replayed_request"
    forged = dict(request, nonce="x" * 40, action="systemctl")
    try:
        host.authenticate(forged)
        raise AssertionError("arbitrary action accepted")
    except host.RequestError:
        pass

    os.environ["MOVIE_BROKER_SECRET_FILE"] = str(broker_secret)
    os.environ["MOVIE_BROKER_MANAGER_SECRET_FILE"] = str(manager_secret)
    broker = load("movie_broker_test", root / "images/control/broker.py")
    assert "z-image-turbo by default" in broker.QWEN_LOCAL_ONLY_INSTRUCTION
    assert (
        "only when the current user request explicitly names Hunyuan or 混元"
        in broker.QWEN_LOCAL_ONLY_INSTRUCTION
    )
    assert "Never invoke OpenAI image generation" in broker.QWEN_LOCAL_ONLY_INSTRUCTION
    reservation = str(uuid.uuid4())
    h3 = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "A fixed short test.",
        "resolution": "608x352", "duration_seconds": 4,
        "steps": 8, "seed": 7, "service": "attacker.service",
    }, reservation)
    workflow = broker.h3_workflow(h3, reservation, str(uuid.uuid4()))
    classes = {node["class_type"] for node in workflow["prompt"].values()}
    assert "MiniMaxH3ImageToVideo" in classes
    assert "LoadImage" not in classes
    assert h3["workflow_preset"] == broker.H3_STANDARD_WORKFLOW
    assert h3["content_profile"] == broker.H3_GENERAL_CONTENT_PROFILE
    assert h3["unet_name"] == broker.H3_GENERAL_UNET
    assert workflow["prompt"]["6"]["inputs"]["length"] == 98
    assert workflow["prompt"]["5"]["inputs"]["unet_name"] == broker.H3_GENERAL_UNET
    assert workflow["prompt"]["10"]["inputs"]["sampler_name"] == "res_multistep"
    adult = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "An explicitly adult-only test.",
        "content_profile": "adult", "duration_seconds": 4,
        "steps": 20, "seed": 12,
    }, reservation)
    adult_workflow = broker.h3_workflow(adult, reservation, str(uuid.uuid4()))
    assert adult["content_profile"] == broker.H3_ADULT_CONTENT_PROFILE
    assert adult["unet_name"] == broker.H3_ADULT_UNET
    assert adult_workflow["prompt"]["5"]["inputs"]["unet_name"] == broker.H3_ADULT_UNET
    adult_pdd = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "An explicitly adult-only PDD test.",
        "content_profile": "adult", "workflow_preset": "pdd-acc-8step",
        "duration_seconds": 4, "steps": 8, "seed": 13,
    }, reservation)
    adult_pdd_workflow = broker.h3_workflow(adult_pdd, reservation, str(uuid.uuid4()))
    assert adult_pdd_workflow["prompt"]["5"]["inputs"]["unet_name"] == broker.H3_ADULT_UNET
    assert adult_pdd_workflow["prompt"]["92"]["class_type"] == "MiniMaxH3PDDAccApply"
    try:
        broker.validate_h3_spec({
            "mode": "t2va", "prompt": "Invalid profile.",
            "content_profile": "guess-from-prompt",
        }, reservation)
        raise AssertionError("unsupported H3 content profile accepted")
    except ValueError as error:
        assert str(error) == "unsupported_h3_content_profile"
    standard_50 = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "A custom high-step test.",
        "workflow_preset": "standard", "duration_seconds": 4,
        "steps": 50, "seed": 10,
    }, reservation)
    standard_50_workflow = broker.h3_workflow(
        standard_50, reservation, str(uuid.uuid4())
    )
    assert standard_50_workflow["prompt"]["9"]["inputs"]["steps"] == 50
    pdd = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "A fixed PDD acceleration test.",
        "workflow_preset": "pdd-acc-8step", "duration_seconds": 4,
        "steps": 8, "seed": 11,
    }, reservation)
    pdd_workflow = broker.h3_workflow(pdd, reservation, str(uuid.uuid4()))
    assert pdd["steps"] == 8
    assert "9" not in pdd_workflow["prompt"]
    assert pdd_workflow["prompt"]["91"] == {
        "class_type": "MiniMaxH3SigmaShift",
        "inputs": {"model": ["90", 0], "shift_video": 12.0, "shift_audio": 3.0},
    }
    assert pdd_workflow["prompt"]["92"]["class_type"] == "MiniMaxH3PDDAccApply"
    assert pdd_workflow["prompt"]["92"]["inputs"] == {
        "model": ["91", 0],
        "pdd_file": "MiniMax-H3-FL2VA-Acc-8Step.safetensors",
        "nfe": "8", "lora_strength": 1.0, "head_strength": 1.0,
        "on_off_grid": "error",
    }
    assert pdd_workflow["prompt"]["10"]["inputs"]["sampler_name"] == "euler"
    assert pdd_workflow["prompt"]["11"]["inputs"]["sigmas"] == ["92", 1]
    try:
        broker.validate_h3_spec({
            "mode": "t2va", "prompt": "Invalid PDD steps.",
            "workflow_preset": "pdd-acc-8step", "steps": 20,
        }, reservation)
        raise AssertionError("PDD preset accepted non-8 step count")
    except ValueError as error:
        assert str(error) == "pdd_acc_8step_requires_steps_8"
    default_h3 = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "Default-resolution test.",
        "duration_seconds": 4, "seed": 8,
    }, reservation)
    assert default_h3["resolution"] == "864x480"
    assert default_h3["workflow_preset"] == "standard"
    assert default_h3["steps"] == 20
    explicit_768p = broker.validate_h3_spec({
        "mode": "t2va", "prompt": "Explicit 768p test.",
        "resolution": "768P", "duration_seconds": 4,
        "steps": 8, "seed": 9,
    }, reservation)
    assert explicit_768p["resolution"] == "1344x768"
    workflow_768p = broker.h3_workflow(explicit_768p, reservation, str(uuid.uuid4()))
    assert workflow_768p["prompt"]["6"]["inputs"]["width"] == 1344
    assert workflow_768p["prompt"]["6"]["inputs"]["height"] == 768
    image = broker.validate_image_spec({"prompt": "A production still.", "resolution": "1024x1024", "seed": 8})
    assert image["model"] == broker.Z_IMAGE_MODEL
    image_workflow = broker.image_workflow(image, reservation, str(uuid.uuid4()))
    assert image_workflow["prompt"]["1"]["inputs"]["unet_name"] == "z_image_turbo_nvfp4.safetensors"
    assert image_workflow["prompt"]["2"]["inputs"]["clip_name"] == "qwen_3_4b_fp8_mixed.safetensors"
    assert image_workflow["prompt"]["8"]["inputs"]["sampler_name"] == "res_multistep"
    image_output_node = image_workflow["prompt"]["10"]
    if image_output_node.get("class_type") != "SaveImage":
        raise AssertionError(json.dumps({
            "validated_model": image["model"],
            "node_10": image_output_node,
        }, sort_keys=True))
    z_widescreen = broker.validate_image_spec({
        "model": "z-image-turbo", "prompt": "A widescreen production still.",
        "resolution": "16:9", "seed": 10,
    })
    assert z_widescreen["resolution"] == "1344x768"
    hunyuan = broker.validate_image_spec({
        "model": "HunyuanImage-3.0-Instruct", "prompt": "A complex production still.",
        "resolution": "1024x1024", "seed": 9, "guidance_scale": 2.5, "flow_shift": 2.3,
    })
    assert hunyuan["model"] == broker.HUNYUAN_IMAGE_MODEL
    hunyuan_workflow = broker.image_workflow(hunyuan, reservation, str(uuid.uuid4()))
    assert hunyuan_workflow["prompt"]["1"]["class_type"] == "HunyuanInstructLoader"
    assert hunyuan_workflow["prompt"]["1"]["inputs"]["model_name"] == "HunyuanImage-3.0-Instruct-Distil-NF4-v2"
    assert hunyuan_workflow["prompt"]["2"]["class_type"] == "HunyuanInstructGenerate"
    assert hunyuan_workflow["prompt"]["3"]["class_type"] == "SaveImage"
    style = broker.validate_style_image_spec({
        "model": "svdq-fp4_r32-flux.1-krea-dev.safetensors",
        "prompt": "A bounded style image.", "width": 1024, "height": 768, "seed": 11,
    })
    assert style["width"] == 1024 and style["height"] == 768
    assert style["model"] in broker.STYLE_IMAGE_MODEL_SET
    try:
        broker.validate_style_image_spec({
            "model": "flux-2-klein-4b-fp8.safetensors", "prompt": "reference-only",
        })
        raise AssertionError("reference-only model accepted by prompt-only endpoint")
    except ValueError as error:
        assert str(error) == "unsupported_style_model"
    try:
        broker.validate_image_spec({"model": "gpt-image-2", "prompt": "not local"})
        raise AssertionError("hosted image model accepted by local Broker")
    except ValueError as error:
        assert str(error) == "unsupported_image_model"

    rewritten = broker.rewrite_qwen_responses_payload({
        "model": broker.QWEN_MODEL,
        "instructions": "base",
        "input": [
            {"role": "developer", "content": [{"type": "input_text", "text": "workspace"}]},
            {"role": "user", "content": [{"type": "input_text", "text": "hello"}]},
        ],
        "tools": [
            {"type": "function", "name": "exec_command", "description": "run", "parameters": {"type": "object"}, "strict": False, "defer_loading": None},
            {"type": "namespace", "name": "mcp", "description": "unsupported", "tools": []},
            {"type": "web_search"},
        ],
    })
    assert rewritten["instructions"] == (
        broker.QWEN_LOCAL_ONLY_INSTRUCTION + "\n\nbase\n\nworkspace"
    )
    assert [item.get("role") for item in rewritten["input"]] == ["user"]
    assert [tool["name"] for tool in rewritten["tools"]] == ["exec_command"]
    assert "defer_loading" not in rewritten["tools"][0]
    assert broker.rewrite_qwen_responses_payload({
        "model": "qwen3.8-27b-uncensored", "input": []
    })["model"] == broker.QWEN_MODEL
    deepseek = broker.rewrite_deepseek_responses_payload({
        "model": "deepseek-v4-flash-0731",
        "instructions": "base",
        "input": [{"role": "user", "content": [{"type": "input_text", "text": "hello"}]}],
        "tools": [{"type": "web_search"}],
    })
    assert deepseek["model"] == broker.DEEPSEEK_MODEL
    assert deepseek["instructions"] == broker.LOCAL_MODEL_ONLY_INSTRUCTION + "\n\nbase"
    assert "tools" not in deepseek
    image_upload_id = str(uuid.uuid4())
    video_upload_id = str(uuid.uuid4())
    records = {
        image_upload_id: {
            "id": image_upload_id, "reservation_id": reservation,
            "path": "/tmp/reference.png", "extension": "png", "media_type": "image",
        },
        video_upload_id: {
            "id": video_upload_id, "reservation_id": reservation,
            "path": "/tmp/reference.mp4", "extension": "mp4", "media_type": "video",
            "has_audio": True, "duration_seconds": 4.0,
        },
    }
    original_upload_record = broker.upload_record
    original_upload_to_comfy = broker.upload_to_comfy
    broker.upload_record = lambda actual_reservation, upload_id: records[upload_id]
    broker.upload_to_comfy = (
        lambda record, actual_reservation, upload_id:
        f"ref-{upload_id}.{record['extension']}"
    )
    try:
        ref2va = broker.validate_h3_spec({
            "mode": "ref2va", "prompt": "<Picture 1> follows <Video 1>.",
            "duration_seconds": 6, "steps": 20,
            "reference_image_upload_ids": [image_upload_id],
            "reference_video_upload_ids": [video_upload_id],
            "ref_image_size": "match",
        }, reservation)
        ref2va_workflow = broker.h3_workflow(
            ref2va, reservation, str(uuid.uuid4())
        )
        assert ref2va["unet_name"] == broker.H3_REF2VA_UNET
        assert ref2va_workflow["prompt"]["5"]["inputs"]["unet_name"] == broker.H3_REF2VA_UNET
        assert ref2va_workflow["prompt"]["6"]["class_type"] == "MiniMaxH3ReferenceToVideo"
        assert ref2va_workflow["prompt"]["6"]["inputs"]["ref_images.ref_image_0"] == ["100", 0]
        assert ref2va_workflow["prompt"]["200"]["class_type"] == "LoadVideo"
        assert ref2va_workflow["prompt"]["300"]["class_type"] == "GetVideoComponents"
        assert ref2va_workflow["prompt"]["6"]["inputs"]["ref_videos.ref_video_0"] == ["300", 0]
        assert ref2va_workflow["prompt"]["6"]["inputs"]["ref_video_audios.ref_video_audio_0"] == ["300", 1]
        try:
            broker.validate_h3_spec({
                "mode": "ref2va", "prompt": "Missing media.",
            }, reservation)
            raise AssertionError("Ref2VA accepted no reference media")
        except ValueError as error:
            assert str(error) == "ref2va_requires_reference_media"
        try:
            broker.validate_h3_spec({
                "mode": "ref2va", "prompt": "Wrong preset.",
                "workflow_preset": "pdd-acc-8step", "steps": 8,
                "reference_image_upload_ids": [image_upload_id],
            }, reservation)
            raise AssertionError("Ref2VA accepted FL2VA PDD preset")
        except ValueError as error:
            assert str(error) == "ref2va_requires_standard_workflow"
    finally:
        broker.upload_record = original_upload_record
        broker.upload_to_comfy = original_upload_to_comfy

    assert broker.classify_upload(b"GIF89a" + b"x", "reference.gif") == ("image", "gif")
    assert broker.classify_upload(b"\x00\x00\x00\x18ftypisom" + b"x", "reference.mov") == ("video", "mov")
    original_subprocess_run = broker.subprocess.run
    broker.subprocess.run = lambda *args, **kwargs: type("Probe", (), {
        "returncode": 0,
        "stdout": json.dumps({
            "format": {"duration": "4.250"},
            "streams": [{"codec_type": "video"}, {"codec_type": "audio"}],
        }),
    })()
    try:
        assert broker.probe_reference_video(pathlib.Path("/tmp/reference.mp4")) == {
            "duration_seconds": 4.25, "has_audio": True,
        }
    finally:
        broker.subprocess.run = original_subprocess_run

    for bad in ("shell", "../../service"):
        try:
            broker.validate_h3_spec({"mode": bad, "prompt": "x"}, reservation)
            raise AssertionError("unsupported H3 mode accepted")
        except ValueError:
            pass

    project = str(uuid.uuid4())
    os.environ["MOVIE_PROJECT_ID"] = project
    os.environ["MOVIE_VIDEO_BASE_URL"] = "https://movie.example.com/workspace/projects"
    movie_cli = load("movie_ai_test", root / "images/workspace/movie-ai.py")
    uploaded_paths = []
    original_readable_path = movie_cli.readable_path
    original_upload_media = movie_cli.upload_media
    movie_cli.readable_path = lambda value, base=None: pathlib.Path(value)
    movie_cli.upload_media = lambda path: uploaded_paths.append(str(path)) or str(uuid.uuid4())
    try:
        prepared_ref2va = movie_cli.prepare_h3_spec({
            "mode": "ref2va",
            "prompt": "<Picture 1> and <Video 1>",
            "reference_images": ["/workspace/ref.png"],
            "reference_videos": ["/workspace/ref.mp4"],
        }, pathlib.Path("/workspace"))
    finally:
        movie_cli.readable_path = original_readable_path
        movie_cli.upload_media = original_upload_media
    assert uploaded_paths == ["/workspace/ref.png", "/workspace/ref.mp4"]
    assert len(prepared_ref2va["reference_image_upload_ids"]) == 1
    assert len(prepared_ref2va["reference_video_upload_ids"]) == 1
    assert movie_cli.project_video_url(pathlib.PurePosixPath("cuts/片段 1.mp4")) == (
        f"https://movie.example.com/workspace/projects/{project}/videos/"
        "cuts/%E7%89%87%E6%AE%B5%201.mp4"
    )
    assert movie_cli.project_image_url(pathlib.PurePosixPath("stills/角色 1.png")) == (
        f"https://movie.example.com/workspace/projects/{project}/images/"
        "stills/%E8%A7%92%E8%89%B2%201.png"
    )
    try:
        movie_cli.project_video_url(pathlib.PurePosixPath("../other.mp4"))
        raise AssertionError("video URL traversal accepted")
    except SystemExit:
        pass
    parsed_h3 = movie_cli.parser().parse_args([
        "h3", "generate", "--spec", "/workspace/job.json",
        "--workflow-preset", "pdd-acc-8step",
        "--content-profile", "adult",
    ])
    assert parsed_h3.workflow_preset == "pdd-acc-8step"
    assert parsed_h3.content_profile == "adult"
    prepared_h3 = movie_cli.prepare_h3_submission(
        {"mode": "t2va", "prompt": "A CLI preset test.", "steps": 8},
        pathlib.Path("/workspace"), None, parsed_h3.workflow_preset,
        parsed_h3.content_profile,
    )
    assert prepared_h3["workflow_preset"] == "pdd-acc-8step"
    assert prepared_h3["content_profile"] == "adult"
    try:
        movie_cli.project_image_url(pathlib.PurePosixPath("../other.png"))
        raise AssertionError("image URL traversal accepted")
    except SystemExit:
        pass

adapter = (root / "images/control/h3_adapter.py").read_text()
assert '"MOVIE_COMFY_UPSTREAM", "http://192.168.88.20:8188"' in adapter
assert "host.docker.internal" not in adapter
assert "subprocess" not in adapter
host_source = (root / "host-control/movie_h3_control.py").read_text()
assert "shell=True" not in host_source
assert "os.system" not in host_source
assert 'configured_name("MOVIE_COMFY_UNIT"' in host_source
assert 'configured_name("MOVIE_QWEN_UNIT"' in host_source
broker_source = (root / "images/control/broker.py").read_text()
assert "class ClientDisconnectCancellation" in broker_source
assert "os.link(temporary, destination, follow_symlinks=False)" in broker_source
style_registry = [
    line.strip()
    for line in (root / "images/control/h3-style-skills.txt").read_text().splitlines()
    if line.strip()
]
configured_styles = set(__import__("re").findall(
    r"'skill' => '(h3-[a-z0-9-]+)'",
    (root / "app/config/movie.php").read_text(),
))
skill_directories = {
    path.name
    for path in (root / "images/workspace/admin-skills").glob("h3-*")
    if path.is_dir() and path.name not in {"h3-prompt-writing", "h3-video-generation"}
}
assert len(style_registry) == len(set(style_registry)) == 23
assert set(style_registry) == configured_styles == skill_directories
supervisor_source = (root / "images/workspace/supervisor.py").read_text()
assert "class UnmanagedCodexReaper" in supervisor_source
assert "install_model_catalog" in supervisor_source
manager_source = (root / "images/control/manager.py").read_text()
assert "list_workspace_sessions" in manager_source
assert "switch_workspace_session" in manager_source
assert "MOVIE_AI_BROKER_URL=http://movie-ai-router:8080" in manager_source
ai_router_source = (root / "images/control/ai_router.py").read_text()
assert "selected_compute_node_unavailable" in ai_router_source
assert "MOVIE_ALLOWED_NODE_CIDRS" in ai_router_source
assert 'f"node_{node_id}"' in ai_router_source
node_control_source = (root / "images/control/node_control.py").read_text()
assert 'action not in {"status", "prepare_h3", "prepare_image"}' in node_control_source
worker_compose = (root / "compose.worker.yaml").read_text()
for service in ("movie-node-broker", "movie-node-control", "movie-node-h3-adapter"):
    assert service in worker_compose
assert "movie-workspace" not in worker_compose
assert "/run/movie-h3-control/control.sock" in worker_compose
router_source = (root / "images/workspace/codex_model_router.py").read_text()
assert "class ClientDisconnectCancellation" in router_source
assert "openai_connection" in router_source

z_skill = " ".join(
    (root / "images/workspace/admin-skills/z-image-turbo-generation/SKILL.md")
    .read_text()
    .split()
)
assert "default for every local-language-model image request" in z_skill
assert "unless the current user request explicitly names Hunyuan/混元" in z_skill
assert "only when the current request explicitly names Z-Image-Turbo" in z_skill
hunyuan_skill = " ".join(
    (root / "images/workspace/admin-skills/hunyuan-image-generation/SKILL.md")
    .read_text()
    .split()
)
assert "only when the current user request explicitly names Hunyuan or 混元" in hunyuan_skill
assert "not merely because a prompt is complex" in hunyuan_skill
assert "Do not select Hunyuan based on prompt complexity" in hunyuan_skill
h3_generation_skill = " ".join(
    (root / "images/workspace/admin-skills/h3-video-generation/SKILL.md")
    .read_text()
    .split()
)
assert "--style-skill" in h3_generation_skill
assert "first completed render for a style with no demo atomically becomes" in h3_generation_skill
assert "An existing demo is never overwritten" in h3_generation_skill
assert "Mandatory User Confirmation" in h3_generation_skill
assert "高速 8-step（推荐）" in h3_generation_skill
assert "普通 20-step" in h3_generation_skill
assert "普通 50-step" in h3_generation_skill
assert "--workflow-preset pdd-acc-8step" in h3_generation_skill
assert "--workflow-preset standard" in h3_generation_skill
assert "--content-profile adult" in h3_generation_skill
assert "--content-profile general" in h3_generation_skill
assert "PinkCherry_fl2va_MiniMax_H3_int8_convrot-beta-0.6.safetensors" in h3_generation_skill
assert "under 18" in h3_generation_skill

socket_unit = (root / "ops/systemd/movie-h3-control.socket").read_text()
service_unit = (root / "ops/systemd/movie-h3-control.service").read_text()
assert "SocketUser=root" in socket_unit
assert "SocketGroup=movie-h3-control" in socket_unit and "SocketMode=0660" in socket_unit
for control in ("NoNewPrivileges=yes", "ProtectSystem=strict", "RestrictNamespaces=yes", "IPAddressDeny=any"):
    assert control in service_unit
PY

if grep -R -n --include='*.py' -E 'shell=True|os\.system\(' \
    "$root/host-control" "$root/images/control" >/dev/null; then
    echo "unsafe command construction found" >&2
    exit 1
fi

compose_gate_ran=false
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    "$root/ops/tests/gate3-static.sh"
    compose_gate_ran=true
else
    echo "GATE3_COMPOSE_STATIC=NOT_RUN (docker compose unavailable)"
fi

validator="${MOVIE_SKILL_VALIDATOR:-}"
if [ -n "$validator" ] && [ -f "$validator" ]; then
    validator_python="${MOVIE_SKILL_VALIDATOR_PYTHON:-python3}"
    if "$validator_python" -c 'import yaml' >/dev/null 2>&1; then
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-editorial-fashion-motion"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-surreal-miniature-absurdism"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-chibi-live-action-sticker"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-creature-motion-replacement"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-multiverse-portal-ensemble"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-deadpan-mockumentary-interview"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-soft-body-physics-comedy"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-retro-pixel-sprite-loop"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-japanese-craft-commercial"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-micro-fpv-impossible-one-take"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-occlusion-orbit-ensemble"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-character-intro-motion-card"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-ancient-title-sequence"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-interactive-creature-encyclopedia"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-anime-character-showcase-pv"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-material-carving-asmr"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-pop-art-split-screen-motion"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-dark-sci-fi-motion-poster"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-asymmetric-speed-duo-choreography"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-layered-windsurfing-fashion-mv"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-water-obstacle-variety-show"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-two-part-character-reveal"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-first-person-finger-controlled-dance"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/h3-video-generation"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/z-image-turbo-generation"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/hunyuan-image-generation"
        "$validator_python" "$validator" "$root/images/workspace/admin-skills/image-style"
    fi
fi

if [ "$compose_gate_ran" != true ]; then
    echo "GATE4_STATIC_AND_SKILLS=NOT_RUN"
    exit 2
fi

echo "GATE4_STATIC_AND_SKILLS=PASS"
