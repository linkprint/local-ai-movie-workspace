---
name: h3-first-person-finger-controlled-dance
description: Create MiniMax H3 prompts for a high-angle first-person smartphone view where one connected foreground hand controls an original adult dancer with literal up, down, left, and right finger commands. Use for playful non-sexualized movement with zero reaction delay and full-body directional shifts; do not use for ordinary dance coverage, disembodied hands, or ambiguous gesture control.
---

# H3 First-Person Finger-Controlled Dance

Treat each finger command as an immediate physical input, not a metaphor or a loose musical suggestion.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use `$h3-prompt-writing`.
- Invoke `$h3-video-generation` only when the user explicitly asks to generate or render.
- Use I2VA when one image defines the exact identity, costume, environment, lighting, high camera angle, and opening composition. Otherwise use T2VA with an original adult dancer.

## Lock Composition and Control

1. Picture 1, when supplied, is the sole authority for dancer identity, costume, environment, lighting, and opening high-angle smartphone POV.
2. Show exactly one connected foreground arm: forearm, wrist, palm, and fingers remain anatomically joined. Keep it in the lower-right quadrant at roughly 10–18% of frame area.
3. Keep the hand away from frame center, the dancer's face, and torso. No second hand or floating fingers.
4. Hold the camera about 1.0–1.2 meters from the dancer at a high angle. Keep the dancer at roughly 70–80% of frame height and do not zoom out.
5. Map controls literally: `UP = whole body rises`, `DOWN = whole body lowers`, `LEFT = full body and weight shift left`, `RIGHT = full body and weight shift right`.
6. Finger motion and dancer motion begin on the same beat: no reaction delay, no one-beat delay, no anticipation, and no independent choreography between commands.
7. Prevent twitch substitutions. LEFT and RIGHT must move the full body and center of mass, not only hips, shoulders, or a tiny local sway.
8. Use a declared command ledger and finish with `LEFT -> RIGHT -> UP`, holding the final raised pose.

## Render Rules

- Keep one render to 4–15 seconds. A 10-second clip can execute the ten-command sequence in the reference when every command remains readable.
- Default to `864x480`; allow `960x544` when requested. Explicit 768p means `1344x768` and may never be downgraded.
- Use one original adult dancer and energetic, stylish, non-sexualized movement. Exclude minors, adult content, nudity, sexualized choreography or framing, celebrity likeness, named characters, extra hands, floating fingers, delayed reactions, zoom-out, camera cuts, text, logos, subtitles, and watermarks.

## Output

Return mode, composition map, command ledger, duration/resolution, complete H3 prompt, zero-delay locks, and Broker limitations. For a real render, verify every command direction, full-body displacement, hand anatomy, and final triplet before returning the absolute Portal URL.
