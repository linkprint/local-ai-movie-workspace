---
name: h3-character-intro-motion-card
description: Create parameterized MiniMax H3 character-introduction motion cards from a purpose-built character-sheet image, with a recognizable hero, exactly 13 cuts, readable status labels, rhythmic action inserts, and a final identity lockup. Use for game, anime, comic, or original-IP character reveals; do not use for long narrative scenes or generic title cards.
---

# H3 Character Intro Motion Card

Build a reusable 15-second premium action-game hero reveal from explicit text parameters and one carefully structured character concept board.

## Route the Request

- Read [references/style-blueprint.md](references/style-blueprint.md) before drafting; it includes the full 13-cut template and the required reference-sheet style.
- For prompt writing or refinement, return text only and use $h3-prompt-writing.
- Invoke $h3-video-generation only when the user explicitly asks to render.
- A supplied character sheet is the sole authority for identity, proportions, outfit, materials, colors, and rendering style. Use native Ref2VA for the true reference render and bind that sheet as `<Picture 1>`. A no-reference T2VA render remains an original style preview.
- If the user lacks a character sheet and asks to create one, first produce the landscape concept-board prompt in the reference file, generate or approve that still image, then use it as the sole H3 character authority.

## Parameter Contract

Collect or infer CHARACTER_NAME, STATUS_TEXT_1, STATUS_TEXT_2, INTERSTITIAL_TEXT_1, INTERSTITIAL_TEXT_2, INTERSTITIAL_TEXT_3, SUBTITLE, and optional PROJECT_TEXT. Preserve user-provided strings exactly and add no other prominent readable words.

## Reference-Sheet Lock

- Use one warm off-white 16:9 editorial concept board.
- Place a large full-body neutral hero at center-left, four orthographic turnarounds across the upper-right, three silhouette/readability studies at mid-left, seated and action poses at mid-right, five face-expression studies along the lower-left, and cropped material/accessory details along the lower edge.
- Keep the board airy and premium: fine black serif display type, tiny handwritten-style annotations, thin rules, muted beige paper, and the character's restrained accent palette.
- Every inset must depict the same adult original character, identical face, body proportions, hairstyle, outfit topology, accessories, materials, and palette. No alternate costumes or inconsistent anatomy.
- Annotations are layout texture rather than required readable copy. The H3 render must not derive new costume pieces from them.

## Build the 13 Cuts

1. Keep exactly 13 distinct compositions across 15 seconds at 24fps.
2. Follow the reference timeline: face, activation, active card, engage card, world, detail, interstitial 1, impact, interstitial 2, velocity, interstitial 3, hero moment, character ID.
3. Show each text parameter clearly before the character overlaps it. Reserve the strongest and longest hold for CHARACTER_NAME and SUBTITLE in the final card.
4. Alternate cinematic character shots with white/grey editorial motion-design cards using huge condensed type, circles, diagrams, halftone, scan lines, color bars, and strong parallax.
5. Every cut must introduce a different composition or visual mode. Use hard cuts, graphic matches, whip pans, freeze frames, foreground wipes, and short speed ramps; no slow dissolves.
6. Preserve face, proportions, outfit, materials, colors, and signature gear in every crop, silhouette, and action angle.

## Render Rules

- Full template is 15 seconds at 24fps. A 10-second demo may condense to 9 cuts but must not be mislabeled as the exact 13-cut version.
- Default real resolution is 864x480; allow 960x544 when requested. Explicit 768p means 1344x768 and may never be downgraded.
- Exclude adult content, nudity, sexualized framing, minors, named IP, celebrity likeness, identity drift, costume drift, extra characters, mutated typography, logos, subtitles, and watermarks.

## Output

Return the parameter block, reference-sheet brief, mode, duration/resolution, complete H3 prompt, identity/text locks, and Broker limitations. For a real render, verify the sheet first, then hero continuity across all cuts and title legibility before returning the absolute Portal URL.
