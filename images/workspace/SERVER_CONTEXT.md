# Movie AI Workspace server context

This file is a managed, non-secret orientation sheet for Codex sessions in the
Movie AI portal. The rules in `/workspace/AGENTS.md` take precedence.

## Topology

| Component | Location | Purpose | Workspace access |
| --- | --- | --- | --- |
| Public portal | `https://movie.example.com` | Login, reservations, profile, admin, workspace | Through the browser |
| Public Caddy | `192.168.88.30` | TLS reverse proxy | No administrative access |
| Portal host | `192.168.88.20:8443` | Private application ingress from Caddy | No host shell or direct LAN access |
| User workspace | Disposable hardened container | Codex CLI, tmux, ttyd, ffmpeg, `movie-ai` | Current reservation only |
| AI Broker | Internal `movie_broker` network | Qwen/DeepSeek Responses plus fixed H3/Z-Image/Hunyuan jobs, GPU policy, artifacts, and mock checks | `/model` private-model selection or `movie-ai` only |
| Egress proxy | Internal `movie_egress_client` network | Narrow Codex authentication/use egress | Policy-controlled only |

The workspace has no Docker socket, host directory mount, NVIDIA device, SSH
authority, or access to the application control plane. It runs as UID/GID 10001
with a read-only root filesystem, all capabilities dropped, no-new-privileges,
a custom seccomp profile, and the Bubblewrap-specific AppArmor profile.

## Persistent data and identity

- `/workspace`: the current employee's email-root only. The named-volume parent
  and every other employee root are not mounted; the shell starts in the project
  selected in the portal.
- `/outputs`: the current employee's selected Project output scope. Videos and
  published images are kept across reservations and exposed only to that
  employee through authenticated Portal links.
- `/home/codex/.codex`: the `CODEX_HOME` selected at Workspace entry. Company
  mode uses the server-managed login for this computer's designated operator;
  personal mode uses the Portal user's independent device-login cache. Neither
  cache may be browsed, copied, archived, committed, or exposed.
- Portal authentication does not authenticate Codex. Every entry explicitly
  chooses the company or personal identity. Personal mode checks its private
  login cache, automatically starts `codex login --device-auth` when needed,
  and then enters Codex; company mode starts the already authenticated Codex
  session directly. Neither path requires a pre-Codex shell command.

## Current release boundary

The terminal, routed Codex `/model` picker, persistent per-user identity volume, hardened sandbox, real
`movie-ai h3 generate`, real `movie-ai image generate`, and mock control-path
check are available. A completed mock job still proves only the control path;
it does not use a GPU or create media. A Gate label is not a per-job GPU
preflight condition: every real job performs its own fixed preflight. Existing
Direct access to DeepSeek, the Qwen runtime, LibreChat, ComfyUI, MiniMax H3,
Z-Image-Turbo, Hunyuan, ACE-Step, driver, CUDA, Caddy, firewall, and router
configuration remains protected. Only the fixed Broker contracts described
here are available to a Workspace.

## Creative AI capability inventory

The following table distinguishes creative responsibility, installed host
inventory, and what the current workspace can actually call. An installed
model or workflow is not permission to start it or contact it directly.

| Creative capability | Backend | Confirmed scope | Portal status |
| --- | --- | --- | --- |
| OpenAI language model | Codex through the identity selected at Workspace entry | Strongest available OpenAI coding/reasoning and creative workflows | Available in `/model`, including `gpt-5.6-sol` |
| Local language model | Movie Qwen 3.8 27B through the reservation-bound Broker | Screenplay/dialogue drafts, scene/shot breakdown, continuity, subtitles, and H3 prompt/spec authoring | Available in `/model` as `qwen3.8-27b-uncensored` |
| External language model | DeepSeek V4 Flash 0731 through the reservation-bound Broker | The same text/agent workflow through an operator-supplied Responses-compatible endpoint | Available in `/model` as `deepseek-v4-flash-0731` |
| H3 prompt design | `$h3-prompt-writing` | T2VA, I2VA, FL2VA, L2VA, and Ref2VA structured audiovisual prompts | Available now; text output only |
| Audiovisual video generation | MiniMax H3 through fixed ComfyUI workflows | T2VA, I2VA, FL2VA, L2VA, and Ref2VA video with synchronized audio; Ref2VA accepts up to 9 images and 3 videos of 2–15 seconds through its separate general-only UNET and standard workflow; FL2VA-family modes retain the selectable PDD-Acc and PinkCherry profiles | Available through `$h3-video-generation` and `movie-ai h3 generate --workflow-preset ... --content-profile ...` |
| Local image generation | Z-Image-Turbo NVFP4 through a fixed workflow | Default 1024x1024 text-to-image renderer for every local-language-model image request unless Hunyuan is explicitly named | Available through `$z-image-turbo-generation` and `movie-ai image generate --model z-image-turbo` |
| Local image generation | HunyuanImage-3.0-Instruct through a fixed workflow | 1024x1024 text-to-image renderer used only when the current request explicitly names Hunyuan/混元 | Available through `$hunyuan-image-generation` and `movie-ai image generate --model HunyuanImage-3.0-Instruct` |
| Hosted image generation/editing | `gpt-image-2` | Default generation/editing renderer for OpenAI language-model sessions unless the current request explicitly names one of the local renderers | Available only from an OpenAI model session, never from Qwen or DeepSeek |
| Music and audio transformation | ACE-Step 1.5 through fixed workflows | Text-to-music and audio-to-audio score/transition work | Installed on host; unavailable until Gate 4 Broker |
| Workflow execution | ComfyUI | Runs only administrator-approved fixed workflows for the generation backends | Installed on host; not user-controlled |
| Image post-processing | Approved upscaling, face detection/restoration, segmentation, and cleanup assets | Media enhancement after generation | Installed inventory; no Gate 3 Broker contract |
| CPU media finishing | `ffmpeg` and `ffprobe` | Inspect, trim, concatenate, transcode, subtitle, and mux user media | Available now inside user volumes |

Use `/model` to switch among `qwen3.8-27b-uncensored`,
`deepseek-v4-flash-0731`, and an OpenAI
model such as `gpt-5.6-sol`; no terminal restart is required. The same Codex
conversation, instruction chain, project files, and tool results remain in
scope for the next model request. Private hidden reasoning is not transferred
between models. The loopback router never forwards an OpenAI credential to
either local model or the Broker. Local models receive no hosted
search/plugin/app/imagegen tools and must use only local AI capabilities. A
local model must invoke `$h3-prompt-writing`
when preparing MiniMax H3 dialogue or prompts.

Image dispatch is deterministic. An OpenAI language-model session defaults to
`gpt-image-2` and switches to a local renderer only when the current request
explicitly names Z-Image-Turbo or Hunyuan/混元. A local language-model session
defaults to Z-Image-Turbo, switches to Hunyuan only when Hunyuan/混元 is
explicitly named, and can never call `gpt-image-2`.

Codex produces text, decisions, plans, prompts, and specs. H3, Z-Image-Turbo,
HunyuanImage-3.0-Instruct, and ACE-Step produce video, images, and music only through their implemented and
enabled bounded Broker capabilities. Never describe an installed model, an H3
prompt, or a mock job as a generated media artifact.

## MiniMax H3 workflows available now

The administrator-installed `$h3-prompt-writing` skill is available from
`/etc/codex/skills/h3-prompt-writing`. It covers:

- T2VA: text-to-video with audio.
- I2VA: first-frame image-to-video with audio.
- FL2VA: first/last-frame guided video with audio.
- L2VA: last-frame guided video with audio.
- Ref2VA: full-reference image/video prompting; audio embedded in a reference video is forwarded when present.

It prepares structured prompts. `$h3-video-generation` submits T2VA, I2VA,
FL2VA, L2VA, and Ref2VA jobs; `$z-image-turbo-generation` submits the fast default
local T2I workflow, while `$hunyuan-image-generation` submits the local
instruction-focused T2I workflow. Save approved prompt/spec drafts under `/workspace` and generated media
under `/outputs`. Do not try to reach ComfyUI directly. Each submission must
stop every non-H3 VRAM service through the fixed Broker/
Manager allowlist. If the fixed MiniMax H3 runtime is already the only VRAM
consumer, keep H3 running; the two-readings-below-2-GB condition applies only
when H3 is not running. Unknown GPU processes block submission and the power
limit must remain no greater than 550 W.

## Quick checks

```text
codex login status
movie-ai gpu status
movie-ai job list
movie-ai h3 generate --help
movie-ai image models
movie-ai image generate --help
```

Use `$h3-prompt-writing` explicitly when the task is to create or rewrite a
MiniMax H3 video prompt. If Codex was already running when this context or a
skill changed, exit and start a new `codex` session so its instruction chain is
rebuilt.
