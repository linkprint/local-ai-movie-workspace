---
name: z-image-turbo-generation
description: Submit and retrieve fast local Z-Image-Turbo text-to-image jobs through the reservation-scoped movie-ai Broker. It is the default for every local-language-model image request unless the user explicitly requests Hunyuan, and is used from an OpenAI-model session only when the user explicitly requests Z-Image-Turbo.
---

# Z-Image-Turbo Generation

Use only `movie-ai`; never contact ComfyUI, the host, or a LAN address directly.

When the selected language model is local, use this skill for every image
request unless the current user request explicitly names Hunyuan/混元. When the
selected language model is an OpenAI model, use this skill only when the current
request explicitly names Z-Image-Turbo/`z-image-turbo`; otherwise the OpenAI
session defaults to `gpt-image-2`.

Save a JSON spec under `/workspace` with:

- `model`: `z-image-turbo`. This is also the Broker default when omitted.
- `prompt`: the approved image prompt.
- `resolution`: currently `1024x1024`.
- `seed`: optional non-negative integer.
- `steps`: 4 through 12; default 8.

Run `movie-ai image generate --model z-image-turbo --spec SPEC.json`, then `movie-ai job wait JOB_ID`. After the job is `completed`, download it with `movie-ai job download JOB_ID --output /outputs/NAME.png` and verify the reported SHA-256. The download result includes an absolute `url`; always give that Portal URL to the user, never only the `/outputs` path.

The Broker uses the administrator-approved Z-Image-Turbo NVFP4 workflow and may keep the shared fixed ComfyUI worker running between H3 and image jobs. If preflight or generation fails, report the job status and do not retry automatically or bypass the Broker.
