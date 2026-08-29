---
name: h3-water-obstacle-variety-show
description: Create MiniMax H3 prompts for safe television-style water-obstacle comedy driven by a short list of mandatory story beats while leaving choreography, broadcast coverage, crowd reactions, and timing details open to model improvisation. Use for original adult contestants, padded courses, recoveries, splashes, and a harmless closing punchline; do not use for dangerous stunts or injury spectacle.
---

# H3 Water Obstacle Variety Show

Fix the narrative anchors and deliberately leave connective action open so the obstacle run feels alive rather than over-scripted.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use `$h3-prompt-writing`.
- Invoke `$h3-video-generation` only when the user explicitly asks to generate or render.
- Use T2VA for an original contestant. Use I2VA only when one image is also the exact opening frame. Use native Ref2VA when an image supplies identity without defining the exact opening composition.

## Build the Beat Contract

1. Define one original adult contestant and a safe televised course with shallow water, padded obstacles, lifeguard supervision, and playful crowd energy.
2. Write five to seven mandatory beats in irreversible story order. Each beat describes the result, not every limb trajectory.
3. Explicitly grant H3 freedom over connecting choreography, safe camera placement, audience reactions, broadcast framing, and small timing adjustments.
4. Preserve the essential sequence: early success, first harmless fall, immediate recovery, apparent final success, padded surprise strike, water impact, wet-hair punchline.
5. Keep spoken lines short and assign them to exact moments. Do not add captions or extra dialogue.
6. Use varied but spatially coherent television coverage; never place a camera where the contestant or obstacle would hit it.

## Render Rules

- One native render is 4–15 seconds. The source case's 20-second version is prompt-only or an explicitly authorized chained workflow; do not claim it fits one current Broker job.
- Default to `864x480`; allow `960x544` when requested. Explicit 768p means `1344x768` and may never be downgraded.
- Exclude minors, adult content, nudity, sexualized framing, real injury, hard impacts, drowning, panic, blood, unsafe water, unpadded machinery, humiliation, real show branding, logos, subtitles, and watermarks.

## Output

Return mode, fixed beat list, improvisation allowance, safety locks, duration/resolution, complete H3 prompt, and Broker limitations. For a real render, verify every beat, safe padding, harmless water entry, and the final spoken punchline before returning the absolute Portal URL.
