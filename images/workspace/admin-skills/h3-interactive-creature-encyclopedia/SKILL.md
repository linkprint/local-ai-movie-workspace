---
name: h3-interactive-creature-encyclopedia
description: Create MiniMax H3 prompts for animated creature-encyclopedia interfaces that scan, select, compare, and showcase multiple original beings inside one coherent collectible UI. Use for bestiary, game database, specimen catalog, or creature roster videos; do not use for ordinary wildlife footage or static dashboards.
---

# H3 Interactive Creature Encyclopedia

Treat the UI as a locked spatial stage: every creature selection must atomically update the selected card, main creature, and exact main title.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use $h3-prompt-writing.
- Invoke $h3-video-generation only when the user explicitly asks to render.
- A UI reference plus independent creature images requires native Ref2VA. Put the UI and creature images in `reference_images` in prompt order and assign each `<Picture N>` a single explicit role.
- For the selected eight-creature cursor-interaction recipe, use the exact role map and 15-second timeline in the reference file. Do not substitute images, card letters, creature names, or selection order.

## Build the Encyclopedia

1. Image 1 controls the complete interface only: layout, typography system, panels, icons, card positions, colors, chrome, spacing, and overall composition. It may not alter any creature.
2. Images 2–9 independently control cards A–H and their matching creature identities. Never exchange anatomy, markings, palette, surface, card, or title.
3. Keep camera, UI coordinate system, panels, icons, buttons, and all non-selected content completely fixed for the entire shot.
4. Use exactly one cursor. Cursor click or drag changes only the selection highlight, main creature, exact main title, and selected creature motion.
5. Update the main creature and main title in the same UI state transition. Never show a new creature with the previous title or vice versa.
6. Keep interactions subtle until the final creature lunges and swallows the cursor; after the gulp, return that creature to idle without restoring the cursor.
7. Add no extra text, buttons, cursor, cards, creature, panel, pop-up, scene cut, camera move, or UI distortion.
8. Synchronize soft UI clicks, hover sounds, tiny creature reactions, the final aggressive vocalization, and one comedic swallow gulp.

## Render Rules

- Use 15 seconds for the selected eight-reference recipe. The 10-second no-reference demo may condense the interaction order but must preserve the synchronized card/creature/title updates and final cursor swallow.
- Default to 864x480. Explicit 768p means 1344x768 and may never be downgraded.
- Exclude adult content, nudity, sexualized creatures, graphic gore, named IP, copied game UI, identity mixing, mismatched titles, duplicate cursors, extra UI, logos, subtitles, and watermarks.

## Output

Return mode, UI map, exact image/card/name registry, duration/resolution, complete H3 prompt, and Broker limitations. For a supported render, verify every card/creature/title synchronization, single-cursor continuity, UI stability, and the final swallow before returning the absolute Portal URL.
