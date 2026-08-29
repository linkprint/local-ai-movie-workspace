---
name: h3-japanese-craft-commercial
description: Create MiniMax H3 prompts for refined Japanese craft and product commercials using tactile macro photography, disciplined compositions, precise handwork, material reflections, restrained color, rhythmic sound design, and a clean hero ending. Use for knives, ceramics, tea tools, lacquerware, stationery, or artisan processes; do not use for generic product spins.
---

# H3 Japanese Craft Commercial

Show the dignity of making through material detail and precise action. Do not copy a brand, logo, existing campaign, or named director.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when the user explicitly asks to generate or render, compose the prompt and invoke `$h3-video-generation`.
- Use `$h3-prompt-writing` for field order and timed cuts.
- Read [references/style-blueprint.md](references/style-blueprint.md) before drafting.

## Build the Commercial

1. Lock the product geometry, material, finish, edge, handle, glaze, grain, or construction details.
2. Use no more than three visual beats in 10 seconds: process macro, functional proof, hero still-life.
3. Prefer controlled side light, negative fill, dark wood, paper, stone, steam, water, or one vermilion accent.
4. Direct hands precisely and keep finger count, grip, tool contact, and product orientation stable.
5. Describe reflections as specular roll, edge glints, water caustics, brushed metal, or lacquer depth—not merely “shiny.”
6. Use small, deliberate camera moves and motivated cuts. Let impact sounds, scraping, slicing, pouring, or cloth movement define rhythm.
7. Keep the final product centered and clean. Reserve exact brand marks or typography for post-production unless a simple supplied label is essential.

## Mode and Render Rules

- Use T2VA for an unbranded concept. Use I2VA when an authorized pack shot must anchor geometry.
- Multiple product, hand, and location references require native Ref2VA; pass them in prompt order and state exactly what each `<Picture N>` or `<Video N>` controls.
- Keep clips to 4–15 seconds. Default demonstration is 10 seconds.
- Default to `864x480`; allow `960x544` if requested. Explicit 768p means `1344x768` with no downgrade.
- Exclude copied logos, extra tools, warped product geometry, unsafe hand placement, illegible small text, subtitles, and watermarks.

## Output

Return the mode, shot list, duration/resolution, complete H3 prompt, and post-production typography note. For real generation, wait, verify geometry and media integrity, and return the absolute Portal URL.
