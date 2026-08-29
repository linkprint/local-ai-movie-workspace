---
name: h3-dark-sci-fi-motion-poster
description: Create MiniMax H3 prompts that animate one finished dark cinematic sci-fi poster through layered reconstruction, restrained environmental motion, product-style depth, and exact typography retention without changing the original layout. Use for motion posters and animated key art; do not use for narrative trailers or freeform scene redesign.
---

# H3 Dark Sci-Fi Motion Poster

Animate the supplied poster as a locked composition rather than treating it as a loose visual reference.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use $h3-prompt-writing for I2VA structure, timing, sound, and negatives.
- Invoke $h3-video-generation only when the user explicitly asks to render and has supplied the source artwork.
- Use I2VA with the source poster as first_frame. If no source artwork is available, return the prompt and request the image; only render a T2VA style preview when the user explicitly accepts that it cannot preserve an existing composition or typography.

## Lock the Poster

1. Treat the full source artwork as the sole authority for canvas, black negative space, orange-and-teal palette, female profile, face, hair, mechanical helmet, spacecraft, skyline, circular portal, title, tagline, credits, release information, hierarchy, scale, and exact layout.
2. Inventory every visible word before drafting. Preserve spelling, font character, line breaks, tracking, size, alignment, and placement. Add no new text.
3. Reveal existing typography as intact designed layers using opacity, masks, tracking expansion, and graphic pop-ins. Never redraw, paraphrase, translate, warp, or replace letterforms.
4. Build depth inside the original composition: interface marks, clouds/skyline, portal, profile/helmet, orange ring, spacecraft, typography, then ambient micro-motion.
5. Allow the spacecraft to pop forward from the portal and settle under the title, but keep its final scale and location identical to the source.
6. Keep one locked camera until the subtle final push-in. No cut, reframing, orbit, scene replacement, or layout drift.
7. End on the fully reconstructed source poster with all text stable and readable. Hold long enough to inspect.

## Motion and Audio

- Use restrained parallax and layer-specific movement: rotating helmet segments, portal growth, ring illumination, small fighters, slight hair movement, sparks, and pulsing city lights.
- Keep the human identity, helmet topology, spacecraft construction, skyline, and portal geometry stable during assembly.
- Synchronize sophisticated digital keystrokes, interface beeps, metallic clicks, soft cinematic impacts, one spacecraft approach swell, and a restrained final hold.
- No dialogue or existing music reference. Use original dark electronic-cinematic sound only when music is requested.

## Render Rules

- Selected template duration is 10 seconds. Default resolution is 864x480; allow 960x544 when requested. Explicit 768p means 1344x768 and may never be downgraded.
- Exact typography is a hard acceptance gate. If the model damages text, report the failure; do not describe the render as complete. A deterministic post-composited text pass may be proposed separately, not silently substituted.
- Exclude adult content, nudity, sexualized framing, celebrity likeness, extra characters, new ships, extra UI, new text, text mutation, face drift, helmet drift, composition drift, soft gradients, logos not present in the source, subtitles, and watermarks.

## Output

Return mode, source-poster lock, exact text inventory, duration/resolution, complete H3 prompt, continuity locks, and any limitation. For a real render, verify the final frame against the source layout and inspect every word before returning the absolute Portal URL.
