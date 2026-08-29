---
name: h3-video-generation
description: Submit and retrieve real MiniMax H3 audiovisual video jobs through the reservation-scoped movie-ai Broker. Select the general or adult PinkCherry content profile and the approved workflow preset, then render T2VA, I2VA, FL2VA, L2VA, or Ref2VA only after the user approves every final value. Do not use for prompt-only drafting.
---

# MiniMax H3 Video Generation

Use only `movie-ai`; never contact ComfyUI, the host, or a LAN address directly.

## Prepare

Use `$h3-prompt-writing` when the prompt still needs H3 structure.

## Select Content Profile

Choose the content profile from the user's current request before the final generation confirmation:

1. Use `adult` when the request or approved prompt clearly asks for adult/18+, erotic, pornographic, or sexually explicit content involving consenting adults who are all explicitly 18 or older. This profile loads `PinkCherry_fl2va_MiniMax_H3_int8_convrot-beta-0.6.safetensors` instead of the ordinary H3 UNET.
2. Use `general` for every other request. An adult-aged character, romance, kissing, swimwear, fashion, anatomy, or mature dramatic themes alone do not select the adult profile.
3. Do not use the adult profile when any depicted person is under 18, described as youthful or underage, or has ambiguous age. Stop and ask for a clearly adult-only revision when age is unclear.
4. If the classification is genuinely ambiguous, ask the user whether they intend ordinary or adult/18+ content before the final confirmation. Never infer the adult profile from a filename, style skill, or prior project.
5. The content profile and workflow preset are independent. Adult content uses PinkCherry with either `pdd-acc-8step` or `standard`; the selected workflow still controls sampling and step rules.
6. Ref2VA uses the separate `minimax_h3_ref2va_pruned_int8_convrot.safetensors` weight and supports only the `general` content profile. Do not route an adult/PinkCherry request through Ref2VA.

## Select Workflow and Steps

Resolve the workflow before asking for the final generation confirmation:

1. If the user already explicitly requested PDD-Acc/high-speed 8-step, ordinary 20-step, or a custom step count, preserve that choice and restate it in the final summary. Do not silently replace it.
2. Otherwise, ask a separate localized workflow-choice question before the final generation confirmation. In Chinese, use header `工作流`, question `本次 MiniMax H3 视频使用哪套工作流？`, and these choices:
   - **高速 8-step（推荐）** — use `pdd-acc-8step`, locked to the installed MiniMax-H3-PDD-Acc recipe.
   - **普通 20-step** — use `standard` with 20 sampling steps.
   Keep the structured tool's automatic free-form `Other` field available for a custom ordinary-workflow step count, for example `普通 50-step`.
   Stop and wait for this answer before assembling and asking the final generation confirmation.
   For Ref2VA, do not offer this PDD choice: select `standard` and ask only whether to keep the ordinary 20 steps or use a custom 8–50 step count.
3. A custom step count selects the `standard` workflow and may be any integer from 8 through 50. PDD-Acc is trained for its locked 8-step path; never run `pdd-acc-8step` at 20, 50, or another custom count.
4. The PDD preset is the fixed recipe: `MiniMax-H3-FL2VA-Acc-8Step.safetensors`, Euler sampler, PDD-provided sigmas, NFE 8, LoRA/head strengths 1.0, and MiniMax H3 video/audio sigma shifts 12/3. Do not combine it with Turbo, FirstBlockCache, EasyCache, or another distillation LoRA.

## Mandatory User Confirmation

After the prompt, content profile, and workflow selection are complete and before creating a spec or starting any video job:

1. Show the user the exact final prompt, resolution, duration, workflow name, step count, content profile, and selected UNET filename that would be submitted.
2. When the runtime provides a structured user-input tool, use it instead of a plain-text question. In Codex, use `request_user_input`; in another supported CLI, use its equivalent choice-question tool. Ask exactly one localized final confirmation question and keep the tool's automatic final free-form “Other” or supplemental-input field available. For a Chinese conversation, use header `生成确认`, question `请确认上方最终提示词、分辨率、时长、工作流、步数和内容配置；是否开始生成视频？`, and these choices:
   - **确认并开始生成** — submit exactly the prompt, resolution, duration, workflow, steps, and content profile just shown.
   - **暂不生成** — stop without creating a spec or job.
   - **修改后再确认** — do not generate; let the user provide changes in the supplemental-input field.
   Use equivalent labels in the user's language for non-Chinese conversations.
3. If no structured user-input tool is available, ask the same question in plain text and invite the user to confirm, decline, or supply revisions.
4. Stop the turn and wait for the user's reply. Do not run `movie-ai h3 generate`, create a job, or begin GPU work in the same turn that produced or revised the prompt.
5. Only the confirm choice or an unambiguous approval of the exact displayed values authorizes generation. A decline, revision choice, or supplemental change does not. Apply any requested change, show every final value again, and request confirmation again.
6. Treat an earlier request such as "generate a video" as permission to prepare the prompt and select a content profile/workflow, not as this required post-draft approval. Start generation only after the user has seen the final values and then clearly agrees to begin.
7. If the prompt, resolution, duration, workflow, steps, content profile, or selected UNET changes after approval, invalidate the approval, show the updated values, and request confirmation again.

## Generate After Approval

Only after the required post-draft confirmation:

1. Save a JSON spec under `/workspace`. Supported fields:
   - `mode`: `t2va`, `i2va`, `fl2va`, `l2va`, or `ref2va`. Use `ref2va` only when the output should reproduce or replace motion, identity, or style from supplied reference images or videos through the native H3 reference-to-video model.
   - `prompt`: approved audiovisual prompt.
   - `resolution`: `608x352`, `736x416`, `864x480`, `960x544`, `1344x768`, `768x768`, `480x864`, `416x736`, or `352x608`.
     - If the user does not specify a resolution, use `864x480` by default; `960x544` is also available when a modest quality increase is useful.
     - If the user explicitly requests `768p` or `768P`, use `1344x768`. Never silently downgrade an explicit 768p request to `960x544` or `864x480`.
   - `duration_seconds`: integer from 4 through 15.
   - `steps`: integer from 8 through 50. Use exactly 8 with `pdd-acc-8step`; the `standard` workflow defaults to 20 and accepts custom values such as 50.
   - `seed`: optional non-negative integer.
   - `first_frame` and/or `last_frame`: image paths inside `/workspace` or `/outputs`, as required by the mode.
   - `reference_images`: Ref2VA-only array of up to 9 image paths inside `/workspace` or `/outputs`.
   - `reference_videos`: Ref2VA-only array of up to 3 video paths inside `/workspace` or `/outputs`. Each reference video must be a valid 2–15 second MP4, WebM, MOV, or M4V; its audio is forwarded when present.
   - `ref_image_size`: Ref2VA-only `match` (default) or `max`.
   Ref2VA requires at least one reference image or video. Refer to them in prompt order as `<Picture 1>`…`<Picture 9>` and `<Video 1>`…`<Video 3>`. Do not combine Ref2VA fields with `first_frame` or `last_frame`.
   Example:
   ```json
   {
     "mode": "ref2va",
     "prompt": "<Picture 1> keeps the exact creature identity and performs the full-body movement from <Video 1>, with synchronized environmental sound.",
     "reference_images": ["/workspace/project/uploads/creature.png"],
     "reference_videos": ["/workspace/project/uploads/motion.mp4"],
     "ref_image_size": "match",
     "duration_seconds": 6,
     "resolution": "864x480",
     "steps": 20
   }
   ```
2. Submit the selected content profile with the CLI flag:
   - Ordinary content: `--content-profile general` selects `minimax_h3_fl2va_int8_convrot.safetensors`.
   - Adult content: `--content-profile adult` selects `PinkCherry_fl2va_MiniMax_H3_int8_convrot-beta-0.6.safetensors`.
   - Ref2VA: `--content-profile general` selects `minimax_h3_ref2va_pruned_int8_convrot.safetensors`.
   Do not put `content_profile` inside the JSON spec. The explicit CLI flag is the auditable model-selection boundary.
3. Submit the explicitly approved workflow preset with the CLI flag:
   - High-speed PDD: `movie-ai h3 generate --spec SPEC.json --workflow-preset pdd-acc-8step --content-profile general`.
   - Ordinary/custom steps: `movie-ai h3 generate --spec SPEC.json --workflow-preset standard --content-profile general`.
   - Adult PDD example: `movie-ai h3 generate --spec SPEC.json --workflow-preset pdd-acc-8step --content-profile adult`.
   - Ref2VA: `movie-ai h3 generate --spec SPEC.json --workflow-preset standard --content-profile general`.
   The CLI rejects a missing or unknown preset; the Broker rejects any PDD submission whose spec does not use exactly 8 steps.
   The Broker also rejects Ref2VA with PDD or the adult content profile because those installed weights are FL2VA-specific.
4. Preserve style provenance explicitly:
   - When this render was initiated through a registered H3 style skill, pass that exact active skill name with `--style-skill`, for example `movie-ai h3 generate --spec SPEC.json --workflow-preset pdd-acc-8step --content-profile general --style-skill h3-editorial-fashion-motion`.
   - For a general H3 render that did not use a registered style skill, omit `--style-skill`. Never infer a style from prompt wording, filenames, or the user's project.
   - Do not put `style_skill` inside the JSON spec. The explicit CLI flag is the attribution boundary.
   The Broker performs the fixed service-aware GPU preflight and returns a job ID. After a successful MP4 is fully downloaded into Broker storage, the first completed render for a style with no demo atomically becomes that style's demonstration video. An existing demo is never overwritten.
5. Run `movie-ai job wait JOB_ID`. Do not resubmit while the job is queued or running.
6. After `completed`, inspect `style_demo` when a style skill was supplied: `bound` means this artifact became the first demo, `existing` means the style already had a demo and it was preserved, and `error` means generation succeeded but demo binding failed and must be reported accurately. Then run `movie-ai job download JOB_ID --output /outputs/NAME.mp4` and verify the reported SHA-256 and media with `ffprobe`.
7. The download result includes an absolute `url`. Always give that URL to the user, not only the `/outputs` path. It opens the authenticated Portal player in a separate browser tab. For an `ffmpeg`-created video, run `movie-ai video url /outputs/NAME.mp4` and return its URL.

If the job reports an unknown GPU process, power-limit violation, NVIDIA Xid, device-copy failure, timeout, or failed service switch, stop and report the exact job status. Do not retry automatically or bypass the Broker.
