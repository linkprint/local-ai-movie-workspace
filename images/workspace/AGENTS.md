# Movie AI Workspace workspace

## What this environment is

- Public portal: `https://movie.example.com`.
- Application host: `192.168.88.20`; the public Caddy reverse proxy is on
  `192.168.88.30`. These addresses are context, not authorization to connect to
  or administer either host.
- You are inside a disposable, non-root user workspace for one active
  reservation. `/workspace` is this employee's email-root only; its parent and
  every other employee root are not mounted. The terminal starts in the project
  selected in the portal. `/workspace`, `/outputs`, and the Codex identity
  selected at entry are persistent named volumes; the container itself is disposable.
- Portal login and Codex login are separate. Every entry requires choosing either
  `使用公司 Codex 账号` or `使用自己的 Codex 账号`. The company option is a
  server-managed login for this computer's designated operator and starts Codex
  directly. The personal option uses this Portal user's independent `CODEX_HOME`;
  an authenticated user enters Codex directly, while a first-time user is taken
  through `codex login --device-auth` automatically before Codex starts. No
  pre-Codex shell command is required.
- The protected host services include DeepSeek, the Qwen runtime, LibreChat,
  ComfyUI, MiniMax H3, Z-Image-Turbo, Hunyuan, and ACE-Step. They remain outside
  this workspace's direct control.
- The real Broker exposes fixed Qwen and DeepSeek Responses providers plus fixed MiniMax
  H3, Z-Image-Turbo, and HunyuanImage-3.0-Instruct capabilities. Media jobs are available only through
  `movie-ai`. The Broker never exposes ComfyUI, a host shell, service names, LAN
  addresses, or arbitrary workflow JSON. Never imply that a mock job or a
  written prompt generated media.

The managed server summary is also available at `/workspace/SERVER_CONTEXT.md`.

## Creative AI responsibility map

- **Language-model choice (available now):** inside Codex, `/model` lists the
  normal OpenAI models plus `qwen3.8-27b-uncensored` and
  `deepseek-v4-flash-0731`. Switching to either private model routes only that
  model's Responses calls through the reservation-bound local Broker;
  switching back to `gpt-5.6-sol` or another OpenAI model immediately restores
  the original OpenAI route. The private models are approved for screenplay/dialogue
  drafts, scene and shot breakdowns, storyboard text, continuity, subtitles,
  and generation-prompt composition.
- **MiniMax H3 prompt design (available now):** use `$h3-prompt-writing` for
  T2VA, I2VA, FL2VA, L2VA, and Ref2VA prompt structure. When a private model writes H3
  dialogue or an H3 generation prompt, it must use this skill. This produces
  text, not a video.
- **MiniMax H3 audiovisual video generation (available now):** use
  `$h3-video-generation` and the fixed `movie-ai h3 generate` command for T2VA,
  I2VA, FL2VA, L2VA, or native Ref2VA rendering with synchronized audio. Ref2VA
  accepts up to 9 reference images and 3 reference videos of 2–15 seconds.
  Before the final
  generation confirmation, explicitly select either the installed
  `pdd-acc-8step` high-speed preset or the ordinary `standard` workflow at 20
  steps; a user-specified custom count such as 50 steps uses `standard`. Use
  `--content-profile adult` and the installed PinkCherry UNET only for clearly
  adult/18+, erotic, pornographic, or sexually explicit content involving
  consenting people explicitly 18 or older. Use `--content-profile general`
  for all other video requests; never apply the adult profile to underage,
  youthful, or age-ambiguous people. Ref2VA uses its separate general-only UNET
  and `standard` workflow; never combine it with PinkCherry or PDD-Acc.
- **Deterministic image-model dispatch:** inspect the currently selected
  language model first, then the user's current image request. In a
  `gpt-5.6-sol` or other OpenAI language-model session, use `gpt-image-2` by
  default. Switch to the local dispatcher only when the current request
  explicitly names Z-Image-Turbo/`z-image-turbo` or Hunyuan/混元. In a local
  language-model session such as Qwen or DeepSeek, use
  `z-image-turbo` for every image request unless the current request explicitly
  names Hunyuan/混元; only then use `HunyuanImage-3.0-Instruct`. A local
  language-model session must never call `gpt-image-2`; if the user explicitly
  requests it, ask them to switch `/model` to an OpenAI model. Never choose
  Hunyuan merely because a prompt is complex, and never silently substitute a
  different renderer after the user names one.
- **Primary OpenAI image generation and editing:** in an OpenAI language-model
  session, `gpt-image-2` is the fixed default for generation, image editing,
  reference-based transformation, and final stills unless the current request
  explicitly names one of the two local renderers. Do not automatically replace
  it with a successor model. Save the result directly under `/outputs` when the
  tool accepts an output path.
- **Local image dispatcher (available now):** `movie-ai image generate`
  exposes exactly two administrator-approved local text-to-image models. Run
  `movie-ai image models` to inspect the contract. Its default is the fastest
  preset, `z-image-turbo`. Select `HunyuanImage-3.0-Instruct` only when the
  current user request explicitly names Hunyuan/混元. These bounded workflows
  are text-to-image only; editing, ControlNet, and fusion remain unavailable.
- **Z-Image-Turbo text-to-image (fastest local default):** use
  `$z-image-turbo-generation` and
  `movie-ai image generate --model z-image-turbo --spec SPEC.json` for quick
  1024x1024 character, location, prop, and storyboard drafts.
- **HunyuanImage-3.0-Instruct text-to-image (local instruction preset):** use
  `$hunyuan-image-generation` and
  `movie-ai image generate --model HunyuanImage-3.0-Instruct --spec SPEC.json`
  for complex 1024x1024 composition/instruction requests. Do not claim editing
  or reference-image support from this fixed workflow.
- **ACE-Step 1.5 (installed on the host, not exposed in Gate 3):** fixed
  workflows cover text-to-music and audio-to-audio score/transition work.
- **ComfyUI orchestration (installed on the host, not user-controlled):** it is
  the fixed-workflow execution layer for H3, Z-Image-Turbo, Hunyuan, ACE-Step, and approved
  post-processing. Its presence never authorizes direct HTTP, host, service, or
  workflow access.
- **AI post-processing assets (installed, no Gate 3 Broker contract):** the
  host has approved model assets for upscaling, face detection/restoration,
  segmentation, and related image cleanup. Treat these as inventory, not as a
  callable capability, until a bounded Broker schema is released.
- **CPU media finishing (available now, not AI):** use `ffmpeg`/`ffprobe` for
  inspection, trimming, concatenation, codecs, subtitles, and muxing within the
  user volumes.

Codex plans and directs the work; local GPU models render media only after a
Broker capability is enabled. Do not claim that `gpt-5.6-sol` itself rendered
video, images, or music.

## Safe capabilities

- Create and edit project files under `/workspace` and generated artifacts
  under `/outputs`.
- `/outputs` is the selected Project's persistent media scope. After producing
  any video, run `movie-ai video url /outputs/NAME.mp4` (the Broker download
  command already returns the same URL) and always give the user the absolute
  Portal URL so it can be opened in a separate browser tab.
- After generating or editing any image, always give the user an absolute Portal
  URL, never only a local `/workspace`, `/outputs`, or `uploads/` path. A Broker
  image downloaded into `/outputs` already includes `url` in the result. When
  using Codex's built-in imagegen, set its destination directly to a unique image
  filename under `/outputs` whenever the tool supports an explicit output path,
  then run `movie-ai image url /outputs/NAME.png` and return its `url`. If
  imagegen still creates the image elsewhere, run
  `movie-ai image publish PATH --link-source` before the final answer and return
  its `url`; this moves the image entity to a collision-safe path under
  `/outputs` and leaves only a symbolic link at the original project path, so the
  image is stored once while both the old local path and Web URL keep working.
  Never say that public image URLs are unavailable when either command can
  publish the local file.
- Use `ffmpeg` and `ffprobe` directly for CPU-only media inspection/editing.
- Check identity without exposing tokens: `codex login status`.
- Check the Broker: `movie-ai gpu status`, `movie-ai job list`, and
  `movie-ai job status <id>`.
- Control-path test: `movie-ai mock submit --prompt 'description'`; it never uses GPU.
- For MiniMax H3 prompt design, invoke `$h3-prompt-writing`. It supports T2VA,
  I2VA, FL2VA, L2VA, and Ref2VA and writes prompts only; it does not submit H3.
- For requested real image rendering in a local-model session, use only
  `$z-image-turbo-generation` or `$hunyuan-image-generation` through
  `movie-ai`; default to Z-Image-Turbo and use Hunyuan only when the current
  request explicitly names Hunyuan/混元. Never call `gpt-image-2` from a local
  session. In an OpenAI-model session, use `gpt-image-2` by default and switch
  to a local renderer only when the current request explicitly names
  Z-Image-Turbo or Hunyuan/混元. For video, use
  `$h3-video-generation`; wait for Broker jobs to complete and download their
  results into `/outputs`.

## MiniMax H3 duration routing and 30-second multishot

- Route by the requested final duration of each individual generated shot, not
  by the total duration of a scene, edit, storyboard, or project.
- For a shot of 15.0 seconds or less, use the existing standard single-shot H3
  workflow through the Broker. Do not invoke Joey Gambino's MiniMax-H3
  Multishot Seamless Chain, do not split the shot into chained segments, and do
  not use a multishot skill merely because it is installed.
- Only when one requested continuous shot is longer than 15.0 seconds may Codex
  use Joey Gambino's MiniMax-H3 Multishot Seamless Chain. It must still be
  exposed as an administrator-approved fixed Broker workflow; never contact
  ComfyUI or the host directly.
- Do not try to make a standard H3 spec exceed its supported single-shot limit.
  Do not silently replace a greater-than-15-second request with several
  unrelated standard clips.

### Verified approximately 30-second recipe

- Use three prompt blocks separated by a line containing only `---`.
- Render 243 frames per block at 24 fps. With the one-frame overlap removed at
  each seam, the master contains 727 frames and is approximately 30.29 seconds.
- Verified validation settings: 960x544, 14 steps, `res_multistep` sampler,
  `simple` scheduler, `seed_per_shot=true`, `shot_count=0` so the script decides
  one shot per block, and `save_every_shot=true`.
- Repeat the exact character, costume, location, lighting, and camera anchors in
  every block. Each later block must explicitly begin from the prior block's
  final pose, eye-line, action, and camera motion. Keep one clear action or short
  utterance per approximately 10-second block and avoid abrupt viewpoint or
  scene changes at a seam.
- Preserve individual shot outputs as recovery artifacts. The master must carry
  both video and audio.

### Broker submission and stop condition

- Save the approved long-video spec under the current project, validate its
  JSON, then submit only with `movie-ai h3 generate --spec <path> --workflow-preset standard --content-profile general --wait`. Use
  `movie-ai job status <id>`, `movie-ai job wait <id>`, and
  `movie-ai job download <id>` as applicable; never call Broker or ComfyUI
  endpoints directly.
- Before any real render, follow the existing service-aware Broker preflight and
  GPU policy in this file.
- The public H3 spec supports the bounded Ref2VA fields `reference_images`,
  `reference_videos`, and `ref_image_size` in addition to the ordinary H3
  fields. It does not expose `workflow`, `multishot`, `shot_count`, or
  `frames_per_shot`.
- Therefore, until the Broker explicitly exposes an administrator-approved
  long-video/multishot schema, do not submit guessed fields, do not repeatedly
  retry, do not fall back to direct host access, and do not use Multishot for
  shots of 15 seconds or less. Report `BROKER_MULTISHOT_NOT_EXPOSED` and ask an
  administrator to enable the fixed workflow.

### Completion checks for long video

- Verify the downloaded master with `ffprobe`: expected duration near 30.29
  seconds for the three-by-243-frame recipe, 24 fps video, and a present audio
  stream.
- Inspect both seams around 10.08 and 20.17 seconds for identity, costume,
  background, pose, camera-motion, and audio continuity. Report prompt-following
  defects separately from seam continuity.
- Do not claim completion from queue success alone; require downloaded-artifact
  and seam-QC evidence.

## Non-negotiable boundaries

- Do not modify server configuration, services, containers, networks, firewall,
  Caddy, routers, GPU drivers, CUDA, models, or GPU power limits.
- Do not use or recommend `sudo`, `ssh`, `docker`, `systemctl`, `journalctl`,
  `mount`, `nsenter`, privileged containers, `SYS_ADMIN`,
  `seccomp=unconfined`, or `danger-full-access`.
- Treat `CODEX_HOME` as Codex-owned identity state. Never inspect, print, copy,
  commit, package, upload, or share `auth.json` or any token. Never inject a
  company API key or another person's login.
- Use only the `movie-ai` CLI for Broker operations; do not call Broker or host
  service endpoints directly and do not probe LAN addresses.
- When the selected language model is Qwen or DeepSeek, use only private
  AI backends: its Responses endpoint plus the two local image models and
  MiniMax H3 through `movie-ai`. Never invoke OpenAI imagegen, web search,
  plugins, apps, or any other hosted AI API. Switching back to an OpenAI model
  restores the normal hosted-tool policy for that next request.
- Do not weaken Codex sandbox, approval, login-method, managed configuration,
  AppArmor, seccomp, network, or egress settings.
- Every real H3 submission must use the Broker's service-aware preflight. It
  stops every allowlisted VRAM service except the fixed MiniMax H3 runtime. If
  H3 is already the only VRAM-consuming service, keep it running and do not
  require total used VRAM below 2 GB. Only when H3 is not running must the
  Broker obtain two readings below 2 GB three seconds apart before starting
  it. Unknown GPU processes block submission, and the power limit must remain
  at most 550 W. Never stop host services directly from this workspace.
- If a request requires unavailable capability or a server-side change, stop,
  preserve files, and ask an administrator. Do not bypass the boundary.
