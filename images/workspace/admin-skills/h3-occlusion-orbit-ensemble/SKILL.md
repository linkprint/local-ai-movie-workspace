---
name: h3-occlusion-orbit-ensemble
description: Create MiniMax H3 prompts for uninterrupted close-range ensemble orbits in which each subject fills the foreground to hide a seamless handoff to the next person. Use for three-person fashion, dance, team, or character showcases with feet-to-head reveals; do not use for ordinary group coverage or edit-heavy montage.
---

# H3 Occlusion Orbit Ensemble

Turn body-scale foreground occlusion into the edit while preserving one continuous camera trajectory.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use $h3-prompt-writing.
- Invoke $h3-video-generation only when the user explicitly asks to render.
- Use T2VA for original subjects. For separate identity references, use native Ref2VA and bind each ordered image explicitly with `<Picture N>`.

## Choreograph the Orbit

1. Assign stable subject IDs A, B, and C with distinct wardrobe, silhouette, and stage marks.
2. Give each subject one complete close-range phrase: begin near the feet, rise to the face, continue horizontally from frontal through three-quarter, side, and rear-side angles.
3. At the end of each phrase, the current subject passes within inches of the lens and completely fills the frame with a natural costume or body surface.
4. Continue the same camera velocity through that occlusion and discover the next subject behind it. Never cut, stop, reverse, or dissolve.
5. Preserve screen direction, lens height, spatial order, floor plane, lighting, and subject identity across all handoffs.
6. Synchronize one audible cloth pass or percussion hit at each occlusion, without implying a cut.
7. Keep only the active subject visible during that subject's first three orbit sections. Reveal all three together only in the final close half-body composition, unless the user explicitly requests a return to A.

## Render Rules

- Use 10–15 seconds for one native render. The source case's full 20-second construction is prompt-only or requires an explicitly authorized chained workflow; never claim a single current Broker job rendered 20 seconds.
- Default to 864x480. Explicit 768p means 1344x768 and may never be downgraded.
- Exclude adult content, nudity, sexualized framing, minors, named characters, celebrity likeness, extra people, duplicate bodies, identity swaps, teleportation, hidden jump cuts, subtitles, logos, and watermarks.

## Output

Return mode, subject map, stage order, duration/resolution, complete H3 prompt, handoff locks, and Broker limitations. For a real render, verify all three identities and each full-frame occlusion before returning the absolute Portal URL.
