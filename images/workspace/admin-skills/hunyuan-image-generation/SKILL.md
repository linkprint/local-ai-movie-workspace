---
name: hunyuan-image-generation
description: Submit and retrieve real local HunyuanImage-3.0-Instruct text-to-image jobs through the reservation-scoped movie-ai Broker. Use only when the current user request explicitly names Hunyuan or 混元, not merely because a prompt is complex and not for prompt-only writing.
---

# Hunyuan Image Generation

Use only `movie-ai`; never contact ComfyUI, the host, or a LAN address directly.

Invoke this skill only when the current user request explicitly names
Hunyuan/混元. Do not select Hunyuan based on prompt complexity, composition, or
quality judgment alone. A local-language-model session otherwise uses
Z-Image-Turbo, while an OpenAI-language-model session otherwise uses
`gpt-image-2`.

Save a JSON spec under `/workspace` with:

- `model`: `HunyuanImage-3.0-Instruct`.
- `prompt`: the approved image prompt.
- `resolution`: currently `1024x1024`.
- `seed`: optional non-negative integer.
- `steps`: 4 through 12; default 8.
- `guidance_scale`: 1 through 5; default 2.5.
- `flow_shift`: 1 through 5; default 2.3.

Run `movie-ai image generate --model HunyuanImage-3.0-Instruct --spec SPEC.json`, then `movie-ai job wait JOB_ID`. After the job is `completed`, download it with `movie-ai job download JOB_ID --output /outputs/NAME.png` and verify the reported SHA-256. The download result includes an absolute `url`; always give that Portal URL to the user, never only the `/outputs` path.

The Broker may keep the shared fixed ComfyUI worker running between H3 and Hunyuan jobs. If preflight or generation fails, report the job status and do not retry automatically or bypass the Broker.
