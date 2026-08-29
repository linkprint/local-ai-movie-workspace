---
name: h3-anime-character-showcase-pv
description: Create MiniMax H3 anime or graphic-novel character showcase prompts from a character sheet plus an environment reference, with strict reference-role separation, centered 360-degree character rotation, comic-panel world assembly, readable title choreography, and a seamless loop. Use for character introduction PVs, model-sheet showcases, hero turnarounds, or game/anime promotional loops; do not use for ordinary anime narrative scenes.
---

# H3 Anime Character Showcase PV

Turn a character sheet and environment reference into a bold, loopable introduction while preserving the hero's identity and separating what each image controls.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/showcase-template.md](references/showcase-template.md) and use `$h3-prompt-writing` for exact H3 format.
- True generation needs two independently assigned references and therefore native Ref2VA. Put both images in `reference_images` in prompt order and bind them explicitly as `<Picture 1>` and `<Picture 2>`.
- Only when the user explicitly asks for a no-reference style preview may you adapt the template to T2VA and invoke `$h3-video-generation`. Label it as a style preview, not an identity-locked reference render.

## Lock Reference Roles

1. Image 1 controls environment architecture and spatial identity only. Reinterpret its rendering in the requested graphic style; do not copy its lighting or surface textures unless requested.
2. Image 2 controls the hero only: face, hair, body proportions, full-body silhouette, outfit, accessories, props, mechanical details, and palette.
3. Never let Image 1 alter the hero or Image 2 overwrite the environment.
4. Keep the hero at the exact center and constant scale. The character rotates; the camera and framing remain stable.
5. State that identity, costume, proportions, palette, and topology may not drift at rear or profile angles.

## Build the Showcase

- Use one uninterrupted 360-degree rotation with three or four evenly spaced world-assembly impacts.
- Use bold contour ink, clean cel shading, flat saturated blocks, crosshatching, hard silhouettes, comic gutters, diagonal panel splits, ink wipes, and radial action lines.
- Keep the underlying rotation perfectly smooth even when overlays cut sharply.
- Allow only two exact title lines supplied by the user. Place them in negative space, keep them away from the silhouette, then shrink them into one fixed emblem and remove them before the loop closes.
- Return to the exact opening angle, pose, scale, framing, environment, and downbeat. Do not freeze or hide a reset with a jump cut.
- Synchronize assembly impacts, panel wipes, title movement, mechanical hits, percussion, brass or plucked strings, and the loop's first/final beat.

## Duration and Resolution

- Use 15 seconds for the full four-impact reference template unless the user specifies another supported duration.
- For a 10-second demo, use three impacts and shorter title holds; do not cram the four-impact 15-second schedule into 10 seconds unchanged.
- Default real resolution is `864x480`; allow `960x544` when requested. Explicit 768p means `1344x768` and may never be downgraded.
- Exclude adult content, nudity, sexualized framing, extra characters, camera orbit, identity drift, realistic rendering, painterly blur, organic morphing, illegible extra text, logos, subtitles, and watermarks.

## Output

Return the mode, reference-role map, duration/resolution, exact title strings, complete H3 prompt, and any Broker limitation. For a supported real render, wait, verify identity across the rotation and first/final-frame similarity, then return the absolute Portal URL.
