---
name: image-style
description: List and render prompt-only images with the approved remote ComfyUI style models through the reservation-scoped movie-ai Broker. Use when the user asks for image-style model selection or names one of the supported safetensors models; do not use for reference-image Kontext or Flux2 Klein composition.
---

# Image Style

Use only `movie-ai`; never contact ComfyUI, `ai-task-server`, a host, or a LAN address directly.

When the user has not selected a model, run `movie-ai image style --list` and present the returned prompt-only model names. Ask for the exact model and prompt before starting a real generation.

Run the approved request with:

```sh
movie-ai image style --model 'MODEL.safetensors' --prompt 'PROMPT'
```

The command waits for completion, downloads the JPEG into `/outputs`, computes its SHA-256, and returns the authenticated Portal `url`. Always return that URL to the user; `/outputs` is also the current project's Image Library source.

Use `--width` and `--height` only when requested; each must be 512 through 1536 and divisible by 64. `--seed` accepts a non-negative integer. Do not retry a failed job automatically.

The prompt-only list intentionally excludes `svdq-fp4_r32-flux.1-kontext-dev.safetensors` and `flux-2-klein-4b-fp8.safetensors` because those workflows require reference images.
