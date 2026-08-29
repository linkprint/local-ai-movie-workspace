---
name: h3-two-part-character-reveal
description: Create MiniMax H3 prompts for a cinematic original-character reveal split into two independent 15-second clips with an explicit visual, pose, ambience, and music seam contract. Use for dense shot-driven introductions that establish a character in Part 1 and escalate without repeating coverage in Part 2; do not use when a single short shot is enough or when seamless continuity cannot be honestly qualified.
---

# H3 Two-Part Character Reveal

Design both halves together so the second clip begins from a declared handoff state and expands the visual language instead of repeating it.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use `$h3-prompt-writing`.
- Invoke `$h3-video-generation` only when the user explicitly names the part to render. Never submit both independent jobs implicitly.
- Use native Ref2VA for multi-image identity, pose-library, or reference-video motion. Audio embedded in a reference video is forwarded automatically; standalone reference-audio files are not exposed by the current CLI contract.

## Assign Reference Authority

1. Picture 1 is the complete pose-and-world library: body, costume, hair, accessories, environment, lighting, and approved poses.
2. Picture 2 is face identity only. It cannot replace costume, body, environment, or pose logic from Picture 1.
3. Supplementary pose images may add approved poses only when they preserve the same character and scene.
4. A motion reference controls timing and movement quality, not identity or costume. A BGM reference controls energy structure, not copyrighted melody reproduction.

## Write the Two Parts

- Give each 15-second half its own complete prompt and shot ledger. The full pattern may use about 13 concise cuts per half when every cut has one purpose.
- Part 1 establishes place, materials, silhouette, hands, accessories, eyes, and full identity. It ends on a clearly described pause-state handoff.
- Part 2 begins from that exact declared camera, pose, expression, lighting, ambience, and musical energy. It escalates to action peak, hero framing, and a final key visual.
- Repeat the seam contract verbatim in both prompts: same character, costume, environment, final/opening pose, light direction, ambient reverb, and music energy.
- Give Part 2 a non-repetition list naming every Part 1 shot mechanism it may not reuse.
- Use only approved poses. Mark which poses Part 1 consumes and reserve the remaining poses for Part 2.
- Allocate detail priority as `face > eyes > hands > environment` whenever motion and resolution compete.

## Continuity Honesty

Independent H3 jobs cannot guarantee pixel-matched frames or uninterrupted BGM. Phrase the prompt as a seam target, verify both outputs, and plan a post-production picture/audio bridge if required. Never report independent generations as a flawless native 30-second take.

## Render Rules

- Each native job is 4–15 seconds. The intended full reveal is two 15-second jobs only when explicitly authorized.
- Default to `864x480`; allow `960x544` when requested. Explicit 768p means `1344x768` and may never be downgraded.
- Use an original adult character and original score. Exclude adult content, nudity, sexualized framing, minors, celebrity likeness, named characters, weapons aimed at people, injury, unapproved pose invention, face drift, repeated shots in Part 2, unreadable text, logos, subtitles, and watermarks.

## Output

Return reference authority map, pose allocation, both shot ledgers, mirrored seam contract, Part 2 non-repetition list, duration/resolution, complete requested prompts, and Broker limitations. For real renders, verify the handoff frames and audio energy before returning absolute Portal URLs.
