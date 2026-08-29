---
name: h3-editorial-fashion-motion
description: Create MiniMax H3 high-fashion editorial-motion prompts and render them through the Workspace Broker when the user explicitly asks for video. Use for couture films, fashion campaigns, lookbooks, magazine-collage motion, beat-synced poses or outfit changes, controlled editorial camera movement, and identity-locked fashion references; do not use for ordinary narrative video.
---

# H3 Editorial Fashion Motion

Create an original fashion film in the same visual language, not a replica of a named campaign, model, title, or source prompt.

## Choose the Deliverable

- If the user asks to write, design, improve, or show a prompt, produce text only. Do not submit a render.
- If the user explicitly asks to generate, render, or make the video, compose the prompt and then use `$h3-video-generation` for the real job.
- Use `$h3-prompt-writing` for the exact H3 field order and keyframe syntax. Read [references/editorial-motion-blueprint.md](references/editorial-motion-blueprint.md) before writing this style.

## Select the Editorial Profile

- **Fluid couture editorial — default:** one identity and one hero garment remain locked while poses, crop, camera distance, and graphic layout evolve smoothly. Prefer this when the user asks for “High Fashion in Motion” or gives no montage instruction.
- **Beat-cut lookbook:** outfits, poses, and layouts transform on strong beats. Use only when the user asks for outfit changes, a lookbook, or an MV-like montage. Limit each beat to one dominant change so identity and garment physics remain readable.

## Non-Negotiable Style Decisions

1. Lock identity, face proportions, skin texture, hair, body proportions, and any garment that should persist. Keep distant full-body framing brief; use medium shots and close-ups for important face beats.
2. Build rhythm from pose, fabric, crop, scale, graphic blocks, and music. Keep camera movement controlled: static hold, small slow push-in or pull-out, slight truck, or small arc. Do not stack several large moves at once.
3. Keep the subject center-safe against a clean editorial field. A reliable default palette is white, black, and one deep-red accent, but preserve a user-specified palette.
4. Describe believable inertia in hair, jewelry, layered fabric, and steps. Avoid teleporting limbs, instant body morphs, and motion that ignores garment weight.
5. Treat generated typography as graphic texture. By default request only a few large abstract letterforms, number blocks, barcodes, crop marks, grids, or registration marks—never dense readable copy.
6. If exact words are required, keep the H3 plate clean or single-color and reserve the exact typography for post-production. Do not claim that fast-moving small AI text will remain correct.
7. End on a stable, centered hero pose with the graphic field simplified. Do not request an actual freeze unless the user wants one.

## Reference and Mode Routing

- No image: T2VA.
- One suitable opening image: I2VA; preserve its identity, garment, palette, and spatial anchors.
- First and last frames: FL2VA when the request is a continuous motion path; L2VA when only the ending is supplied.
- Multiple independent character, wardrobe, or layout references require native Ref2VA conditioning. Pass them in prompt order and bind every `<Picture N>` or `<Video N>` to its intended identity, garment, motion, or layout authority.

## Prompt and Render Rules

- Write the H3 audiovisual prompt in English, while preserving user-supplied dialogue or exact visible text verbatim.
- Match the timeline to 4–15 seconds. For a 15-second beat-cut lookbook, use approximately 1.5–3 seconds per major transformation; for fluid couture, use fewer longer phases.
- Specify music tempo, beat accents, fabric movement, footsteps or jewelry movement, and the intended end cadence. Do not imply that the source clip's music is reused.
- If resolution is unspecified, use `864x480`; `960x544` is allowed for a modest quality increase. If the user explicitly requests `768p` or `768P`, use `1344x768` and never downgrade it.
- The Broker has no native 4:3 preset. If 4:3 is requested, compose center-safe, render `864x480` then crop to `640x480`; for explicit 768p, render `1344x768` then crop to `1024x768`. Verify and publish the cropped file with `movie-ai video url`.
- A standard real render is one shot of at most 15 seconds. The source post joined two 15-second clips using video-and-audio continuation, but that continuation schema is not currently exposed by the Broker. For a requested continuous shot longer than 15 seconds, follow the Workspace stop condition and report `BROKER_MULTISHOT_NOT_EXPOSED`; do not fake continuity with unrelated clips.

## Output

For prompt-only work, return:

1. profile and input mode;
2. duration, resolution, and aspect treatment;
3. the complete H3 prompt;
4. a short note identifying any exact typography reserved for post.

For a real render, wait for completion, download and verify the media, apply an explicitly requested 4:3 crop if needed, and return the absolute Portal URL.
