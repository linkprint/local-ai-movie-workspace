---
name: h3-multiverse-portal-ensemble
description: Create MiniMax H3 prompts for one to three original characters emerging from a consistent luminous portal and landing in a confident ensemble formation. Use for crossover-like arrivals, hero team reveals, fantasy or sci-fi entrances, and reference-locked character lineups; do not use named franchise characters.
---

# H3 Multiverse Portal Ensemble

Stage a clean entrance with original characters, a consistent portal, readable blocking, and one final group composition.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when the user explicitly asks to generate or render, invoke `$h3-video-generation` after composing the prompt.
- Use `$h3-prompt-writing` for H3 structure and read [references/style-blueprint.md](references/style-blueprint.md).

## Stage the Arrival

1. Limit the ensemble to three characters. Give each a unique silhouette, color family, gait, and final position.
2. Define one portal design and keep its color, geometry, particle behavior, and location stable.
3. Use sequential blocking: center arrives first, side character second, opposite-side character last.
4. Assign only one characteristic gesture per subject. Avoid simultaneous combat, transformations, and dialogue.
5. Keep the camera at medium-wide or knee-up framing; use a small slow pull-out or push-in, not a frantic orbit.
6. End with all subjects visible, separated, anatomically stable, and looking toward one common off-screen point.
7. Synchronize portal hum, footsteps, sparks, cloth or armor movement, and one musical rise to the final formation.

## Mode and Render Rules

- Use T2VA for original characters described in text.
- Multiple character identity references require native Ref2VA. Pass the ordered character images under `reference_images` and bind each one explicitly with `<Picture N>` in the prompt.
- Keep the clip to 4–15 seconds; a 10-second trio arrival is the default demonstration.
- Default to `864x480`; allow `960x544` if requested. Explicit 768p means `1344x768` with no downgrade.
- Exclude named franchises, copied costumes, logos, watermarks, extra arrivals, duplicate faces, and uncontrolled weapons.

## Output

Return the mode, character blocking map, duration/resolution, complete prompt, and any reference limitation. For a real supported render, wait, verify, and return the absolute Portal URL.
