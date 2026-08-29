#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
rendered="$(mktemp)"
worker_rendered="$(mktemp)"
trap 'rm -f "$rendered" "$worker_rendered"' EXIT

python3 "$root/ops/tests/workspace-project-isolation.py"
python3 "$root/ops/tests/test_enforce_image_urls.py"

cd "$root"
MOVIE_DOCKER_GID="${MOVIE_DOCKER_GID:-999}" docker compose config --format json > "$rendered"
docker compose --env-file env/worker.env.example -f compose.worker.yaml config --format json > "$worker_rendered"

jq -e '
  (.services | keys | sort) == [
    "movie-ai-broker",
    "movie-ai-router",
    "movie-auth",
    "movie-egress",
    "movie-gateway",
    "movie-h3-adapter",
    "movie-postgres",
    "movie-queue",
    "movie-redis",
    "movie-scheduler",
    "movie-terminal-router",
    "movie-web",
    "movie-workspace-manager"
  ] and
  ([.services[] | select(.privileged == true)] | length) == 0 and
  ([.services[] | .cap_add[]? | select(. == "SYS_ADMIN")] | length) == 0 and
  ([.services[] | .security_opt[]? | select(. == "seccomp=unconfined")] | length) == 0 and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.type == "bind" and .source == "/var/run/docker.sock") |
      $service.key] == ["movie-workspace-manager"]) and
  (.services["movie-workspace-manager"].volumes[] |
      select(.source == "/var/run/docker.sock") | .read_only) == true and
  (.services["movie-workspace-manager"].volumes[] |
      select(.source == "/run/movie-h3-control/control.sock") | .read_only) == true and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.type == "bind" and .source == "/run/movie-h3-control/control.sock") |
      $service.key] == ["movie-workspace-manager"]) and
  .services["movie-workspace-manager"].environment.MOVIE_WORKSPACE_APPARMOR == "movie-workspace-bwrap" and
  .services["movie-workspace-manager"].environment.MOVIE_WORKSPACE_SECCOMP == "/usr/local/share/movie-workspace/seccomp.json" and
  .services["movie-workspace-manager"].environment.MOVIE_OUTPUTS_VOLUME == "movie_portal_outputs" and
  .services["movie-workspace-manager"].environment.MOVIE_COMPANY_CODEX_VOLUME == "movie_company_codex_auth" and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.source == "movie_company_codex_auth") | $service.key] | length) == 0 and
  (.services["movie-workspace-manager"].group_add | index("19002")) != null and
  (.services["movie-web"].group_add | index("10001")) != null and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.source == "movie_outputs") | $service.key] == ["movie-web"]) and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.source == "movie_style_demos") | $service.key] | sort) == ["movie-ai-broker", "movie-web"] and
  ([.services["movie-web"].volumes[] |
      select(.source == "movie_style_demos" and .target == "/srv/app/storage/app/style-demos" and .read_only == true)] | length) == 1 and
  ([.services["movie-ai-broker"].volumes[] |
      select(.source == "movie_style_demos" and .target == "/var/lib/movie-style-demos" and (.read_only // false) == false)] | length) == 1 and
  ([.services["movie-ai-broker"].volumes[] | select(.source == "movie_storage")] | length) == 0 and
  ([.services["movie-auth"].secrets[].target] | sort) == [
    "broker_hmac_secret",
    "manager_hmac_secret",
    "postgres_password",
    "redis_password",
    "router_hmac_secret"
  ] and
  (.services["movie-auth"].networks | has("movie_control")) and
  .networks.movie_terminal.internal == true and
  .networks.movie_control.internal == true and
  .networks.movie_broker.internal == true and
  .networks.movie_h3_control.internal == true and
  (.networks.movie_worker_upstream.internal // false) == false and
  .networks.movie_egress_client.internal == true and
  (.services["movie-h3-adapter"].networks | keys | sort) == ["movie_h3_control", "movie_h3_upstream"] and
  (.services["movie-ai-broker"].networks | has("movie_h3_control")) and
  (.services["movie-ai-router"].networks | keys | sort) == ["movie_broker", "movie_control", "movie_worker_upstream"] and
  (.services["movie-ai-broker"].group_add | index("19003")) != null and
  ([.services["movie-ai-broker"].volumes[] | select(.source == "/run/movie-qwen" and .target == "/run/movie-qwen" and .read_only == true)] | length) == 1 and
  (.services["movie-h3-adapter"].privileged // false) == false and
  (.services["movie-h3-adapter"].cap_drop == ["ALL"]) and
  .services["movie-gateway"].ports[0].host_ip == "192.168.88.20" and
  .services["movie-gateway"].ports[0].published == "8443"
' "$rendered" >/dev/null

jq -e '
  (.services | keys | sort) == [
    "movie-node-broker",
    "movie-node-control",
    "movie-node-h3-adapter"
  ] and
  ([.services[] | select(.privileged == true)] | length) == 0 and
  ([.services[] | .cap_add[]? | select(. == "SYS_ADMIN")] | length) == 0 and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.type == "bind" and .source == "/var/run/docker.sock") |
      $service.key] | length) == 0 and
  ([.services | to_entries[] | . as $service | .value.volumes[]? |
      select(.type == "bind" and .source == "/run/movie-h3-control/control.sock") |
      $service.key] == ["movie-node-control"]) and
  (.services["movie-node-control"].volumes[] |
      select(.source == "/run/movie-h3-control/control.sock") | .read_only) == true and
  (.services["movie-node-control"].group_add | index("19002")) != null and
  (.services["movie-node-broker"].group_add | index("19003")) != null and
  .services["movie-node-broker"].ports[0].host_ip == "192.168.88.200" and
  .services["movie-node-broker"].ports[0].published == "8080" and
  .networks.node_control.internal == true and
  (.networks.comfy_upstream.internal // false) == false
' "$worker_rendered" >/dev/null

python3 - "$root" <<'PY'
import json
import hashlib
import importlib.util
import pathlib
import os
import subprocess
import sys
import tempfile
import tomllib
from unittest import mock

root = pathlib.Path(sys.argv[1])
requirements = tomllib.loads((root / "images/workspace/requirements.toml").read_text())
managed = tomllib.loads((root / "images/workspace/managed_config.toml").read_text())
user_config = tomllib.loads((root / "images/workspace/config.toml").read_text())
seccomp = json.loads((root / "security/seccomp/workspace.json").read_text())
agents = (root / "images/workspace/AGENTS.md").read_text()
agents_flat = " ".join(agents.split())
server_context = (root / "images/workspace/SERVER_CONTEXT.md").read_text()
server_context_flat = " ".join(server_context.split())
workspace_dockerfile = (root / "images/workspace/Dockerfile").read_text()
portal_dockerfile = (root / "images/portal/Dockerfile").read_text()
tmux_config = (root / "images/workspace/tmux.conf").read_text()
skill_root = root / "images/workspace/admin-skills/h3-prompt-writing"
skill = (skill_root / "SKILL.md").read_text()

assert managed["forced_login_method"] == "chatgpt"
assert "forced_chatgpt_workspace_id" not in managed
assert managed["approval_policy"] == "never"
assert "sandbox_mode" not in managed
assert "sandbox_mode" not in user_config
assert requirements["allowed_permission_profiles"] == {"movie_workspace": True}
assert requirements["default_permissions"] == "movie_workspace"
assert requirements["features"]["hooks"] is True
assert requirements["hooks"]["managed_dir"] == "/etc/codex/hooks"
stop_hook = requirements["hooks"]["Stop"][0]["hooks"][0]
assert stop_hook["type"] == "command"
assert stop_hook["command"] == "/usr/bin/python3 /etc/codex/hooks/enforce_image_urls.py"
assert stop_hook["timeout"] == 5
profile = requirements["permissions"]["movie_workspace"]
assert profile["extends"] == ":workspace"
assert profile["workspace_roots"] == {"/outputs": True}
assert profile["network"]["enabled"] is True

custom = seccomp["syscalls"][:6]
assert [entry["names"] for entry in custom] == [
    ["clone"], ["clone"], ["mount"], ["pivot_root"], ["umount2"], ["unshare"]
]
assert custom[0]["args"][0]["value"] == 2013397009
assert custom[1]["args"][0]["value"] == 939655185
assert custom[5]["args"][0]["value"] == 268435456
assert "https://movie.example.com" in agents
assert "fixed Qwen and DeepSeek Responses providers" in agents
assert "$h3-prompt-writing" in agents
assert "$h3-video-generation" in agents
assert "$z-image-turbo-generation" in agents
assert "$hunyuan-image-generation" in agents
assert "Primary OpenAI image generation and editing" in agents
assert "`gpt-image-2` is the fixed default" in agents_flat
assert "Do not automatically replace it with a successor model" in agents_flat
assert "A local language-model session must never call `gpt-image-2`" in agents_flat
assert "Its default is the fastest preset, `z-image-turbo`" in agents_flat
assert "only when the current user request explicitly names Hunyuan/混元" in agents_flat
assert "Never choose Hunyuan merely because a prompt is complex" in agents_flat
assert "HunyuanImage-3.0-Instruct" in agents
assert "MiniMax H3 duration routing and 30-second multishot" in agents
assert "For a shot of 15.0 seconds or less" in agents
assert "Only when one requested continuous shot is longer than 15.0 seconds" in agents
assert "727 frames and is approximately 30.29 seconds" in agents
assert "BROKER_MULTISHOT_NOT_EXPOSED" in agents
assert "/workspace/SERVER_CONTEXT.md" in agents
assert "qwen3.8-27b-uncensored" in agents
assert "deepseek-v4-flash-0731" in agents
assert "When a private model writes H3" in agents
for capability in (
    "MiniMax H3 audiovisual video generation",
    "Z-Image-Turbo text-to-image",
    "HunyuanImage-3.0-Instruct text-to-image",
    "ACE-Step 1.5",
    "ComfyUI orchestration",
    "AI post-processing assets",
):
    assert capability in agents
assert "A Gate label is not a" in server_context
assert "per-job GPU preflight condition" in server_context_flat
assert "movie-ai h3 generate" in server_context
assert "If the fixed MiniMax H3 runtime is already the only VRAM" in server_context
assert "the two-readings-below-2-GB condition applies only" in server_context
assert "Creative AI capability inventory" in server_context
assert "Movie Qwen 3.8 27B through the reservation-bound Broker" in server_context
assert "DeepSeek V4 Flash 0731 through the reservation-bound Broker" in server_context
assert "/etc/codex/skills/h3-prompt-writing" in workspace_dockerfile
assert "/etc/codex/skills/h3-video-generation" in workspace_dockerfile
assert "/etc/codex/skills/z-image-turbo-generation" in workspace_dockerfile
assert "/etc/codex/skills/hunyuan-image-generation" in workspace_dockerfile
assert "images/workspace/movie.config.toml" in workspace_dockerfile
assert "images/workspace/codex_model_router.py" in workspace_dockerfile
assert "/etc/codex/hooks/enforce_image_urls.py" in workspace_dockerfile
assert "images/workspace/tmux.conf" in workspace_dockerfile
assert "RUN php scripts/validate-ui-translations.php" in portal_dockerfile
assert (root / "app/app/Support/UiTranslationGuard.php").is_file()
assert (root / "app/lang/ui-required-keys.txt").is_file()
assert (root / "app/scripts/validate-ui-translations.php").is_file()
image_url_hook = (root / "images/workspace/hooks/enforce_image_urls.py").read_text()
assert "movie-ai image publish PATH --link-source" in image_url_hook
assert "movie-ai image url PATH" in image_url_hook
assert "stop_hook_active" in image_url_hook
assert "https://movie.example.com/workspace/projects" in image_url_hook
assert "set-option -g mouse on" in tmux_config
assert "set-option -g history-limit 10000" in tmux_config
assert "bind-key -T root WheelUpPane copy-mode -e" in tmux_config
assert "bind-key -T copy-mode WheelUpPane" in tmux_config
assert "bind-key -T copy-mode WheelDownPane" in tmux_config
assert "name: h3-prompt-writing" in skill
assert (skill_root / "references/base-en.txt").is_file()
assert (skill_root / "references/ref-en.txt").is_file()
assert (skill_root / "agents/openai.yaml").is_file()
for name in ("h3-video-generation", "z-image-turbo-generation", "hunyuan-image-generation"):
    generated = root / "images/workspace/admin-skills" / name
    assert (generated / "SKILL.md").is_file()
    assert (generated / "agents/openai.yaml").is_file()

supervisor_path = root / "images/workspace/supervisor.py"
spec = importlib.util.spec_from_file_location("workspace_supervisor", supervisor_path)
supervisor = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(supervisor)
supervisor_source = supervisor_path.read_text()
assert '"tmux", "-f", "/usr/local/share/movie-workspace/tmux.conf"' in supervisor_source
assert 'MOVIE_CODEX_AUTH_MODE' in supervisor_source
assert 'movie-company-codex-session' in supervisor_source
assert 'movie-personal-codex-session' in supervisor_source
assert "64408b45ac5a47d5444b5d21ab2ad587ca4b4609d9a389488a9a8d88d6e5af34" in supervisor.LEGACY_AGENTS_SHA256
assert "969d0f8307c40b0c5ff9ababa002ac0da8a0dd1c7825a74082b3464aaf968b02" in supervisor.LEGACY_AGENTS_SHA256
assert "4e987107c645eaa40dbb82ed6a6f4fcf884d0e239bd2c010e5b9c3d06076f192" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "ad6dc14bdd0790fd24048bc3d5f3370a28df588580998419cb5729badd297332" in supervisor.LEGACY_AGENTS_SHA256
assert "9078868684c69321958b8196d47a22c15c731eceaf77d80773b6b4419d9a2cb4" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "6f1b2879fd9042ed88bccb1df794aa132fa299c1c969fe79ad63f0b76e5f5d83" in supervisor.LEGACY_AGENTS_SHA256
assert "ab8f3ce1c87a4430a77373e1d6f1f00ba9c3ecf6ae21419ae15b4e2ef025724f" in supervisor.LEGACY_AGENTS_SHA256
assert "a1b1595c6aa8d4c571c8af6739db4b8761457359da6259bb9936e063ea65d1d4" in supervisor.LEGACY_AGENTS_SHA256
assert "a249b319ca062d167da224bf5aa3116bec9958582eec7ec3647d69b73b234c6a" in supervisor.LEGACY_AGENTS_SHA256
assert "1506441d1d07f9ad3bad54a976982a404741c8dc85e6c54949e6decadecccee9" in supervisor.LEGACY_AGENTS_SHA256
assert "dbe9049e2c164ca5f5adbf092fe829dd8daa3f7789d15cc793312eeb65d3098c" in supervisor.LEGACY_AGENTS_SHA256
assert "684f550bb833e3f83806151bdc79c2d94003440d726b19c69fe3c753a7ded3a1" in supervisor.LEGACY_AGENTS_SHA256
assert "3a3934f8245036b1d989a4ecceaf3e50b8151e060fdc2e467df3b57509afda44" in supervisor.LEGACY_AGENTS_SHA256
assert "f46d76614a2c6cfe0ddba579b7b07c9a219e269a21686850bf7f47c24ac4327f" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "2a5dd1fafdcc08838674888ef87a7343bfca9ce0da3e2cb4d53b782245cf1a0e" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "38e19472d181c13a7f5376c253cd3787d52f075d71c887aec1f513fe2caaa9cc" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "3bc1263a57ae7fa40fc1da247ee185043f2a2df275d8e0bb64e41541ed825ef5" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "7c73b519ca778324f448a455ae6d37614cc4496b5e495015b103f00aa76010c3" in supervisor.LEGACY_AGENTS_SHA256
assert "0edeb9f7d3ba9933c0015cf982df9e7e2caba5935a547bacd14e786675c67793" in supervisor.LEGACY_AGENTS_SHA256
assert "21605044ead0decf4744a551183f24f5fee5da87a61a6d8d8165af31326536bd" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
assert "6a6ae1346c20b17b0824b949789e6fb1bfd1e50c665e4164115e7c12f409e3ee" in supervisor.LEGACY_SERVER_CONTEXT_SHA256
manager_source = (root / "images/control/manager.py").read_text()
assert '"Subpath": workspace_root' in manager_source
assert '"NoCopy": True' in manager_source
assert "prepare_workspace_path(storage_uuid, workspace_root, project_directory)" in manager_source
assert "prepare_outputs_path(storage_uuid, project_id)" in manager_source
assert '"Subpath": f"{storage_uuid}/{project_id}"' in manager_source
assert "MOVIE_PROJECT_ID" in manager_source
assert '"NetworkMode": "none"' in manager_source
assert 'MOVIE_COMPANY_CODEX_VOLUME' in manager_source
assert 'com.linkprint.movie.auth-mode' in manager_source
assert 'switch_workspace_auth_mode' in manager_source
company_session = (root / "images/workspace/company_codex_session.py").read_text()
assert '[CODEX, "--profile", "movie"]' in company_session
assert "Use /model to select Qwen, DeepSeek, or an OpenAI model" in company_session
assert 'shell=True' not in company_session
personal_session_path = root / "images/workspace/personal_codex_session.py"
personal_session = personal_session_path.read_text()
assert '[CODEX, "login", "status"]' in personal_session
assert '[CODEX, "login", "--device-auth"]' in personal_session
assert '[CODEX, "--profile", "movie"]' in personal_session
assert "Use /model to select Qwen, DeepSeek, or an OpenAI model" in personal_session
assert 'shell=True' not in personal_session
assert 'movie-personal-codex-session' in workspace_dockerfile

router_path = root / "images/workspace/codex_model_router.py"
router_spec = importlib.util.spec_from_file_location("codex_model_router", router_path)
router = importlib.util.module_from_spec(router_spec)
assert router_spec.loader is not None
router_spec.loader.exec_module(router)
catalog = {"models": [{
    "slug": "gpt-5.5", "display_name": "GPT-5.5", "description": "OpenAI",
    "visibility": "list", "priority": 7, "model_messages": {"instructions_template": "base"},
}]}
routed = json.loads(router.append_local_models_to_catalog(json.dumps(catalog).encode()))
assert [item["slug"] for item in routed["models"]] == [
    "gpt-5.5", "qwen3.8-27b-uncensored", "deepseek-v4-flash-0731"
]
qwen = routed["models"][1]
assert qwen["default_reasoning_level"] == "xhigh"
assert [level["effort"] for level in qwen["supported_reasoning_levels"]] == ["low", "medium", "xhigh"]
assert qwen["model_messages"]["instructions_template"] == "base"
deepseek = routed["models"][2]
assert deepseek["display_name"] == "DeepSeek V4 Flash 0731 Uncensored (External)"
assert deepseek["context_window"] == 500000
assert deepseek["auto_compact_token_limit"] == 450000
assert deepseek["supports_search_tool"] is False
assert router.request_model(b'{"model":"qwen3.8-27b-uncensored"}') in router.QWEN_UPSTREAM_MODELS
assert router.request_model(b'{"model":"deepseek-v4-flash-0731"}') in router.DEEPSEEK_UPSTREAM_MODELS
assert router.normalized_path("/v1/models?client_version=0.149.1") == "/models?client_version=0.149.1"
assert router.broker_upstream_path("", "/responses") == "/v1/responses"
assert router.broker_upstream_path("/v1", "/responses") == "/v1/responses"
assert router.broker_upstream_path("/v1/", "/models") == "/v1/models"
movie_profile = tomllib.loads((root / "images/workspace/movie.config.toml").read_text())
assert movie_profile["model_provider"] == "movie_router"
assert movie_profile["model_providers"]["movie_router"]["requires_openai_auth"] is True
assert movie_profile["model_providers"]["movie_router"]["supports_websockets"] is False
assert movie_profile["features"]["enable_request_compression"] is False
assert '"CapDrop": ["ALL"]' in manager_source
assert '"User": "10001:10001"' in manager_source
assert 'f"/workspace/{project_directory}"' in manager_source
assert "project.is_symlink()" in manager_source
assert "workspace root is not a real directory" in manager_source
assert "other employee root" in agents
with tempfile.TemporaryDirectory() as directory:
    test_root = pathlib.Path(directory)
    source = test_root / "source"
    target = test_root / "target"
    source.write_text("managed-v2")
    assert supervisor.install_managed_file(source, target, replace_hashes=set())
    target.chmod(0o644)
    target.write_text("employee-content")
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    assert not supervisor.install_managed_file(source, target, replace_hashes=set())
    assert target.read_text() == "employee-content"
    assert supervisor.install_managed_file(source, target, replace_hashes={digest})
    assert target.read_text() == "managed-v2"
PY

if grep -R -n --exclude='*.md' --exclude='*.txt' \
    -E 'danger-full-access|seccomp=unconfined|privileged:[[:space:]]*true' \
    images compose.yaml security >/dev/null; then
  echo "forbidden Gate 3 escape hatch found" >&2
  exit 1
fi

echo "gate3 static compose and policy: PASS"
