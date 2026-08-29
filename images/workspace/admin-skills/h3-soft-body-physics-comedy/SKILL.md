---
name: h3-soft-body-physics-comedy
description: Create MiniMax H3 prompts for tactile physical comedy in which an animal, creature, food, or object squeezes through a rigid opening or behaves with explicitly assigned elasticity. Use for glass-container gags, rubbery impacts, compression, rebound, fluff, and near-plausible physics; do not use for injury or cruelty.
---

# H3 Soft-Body Physics Comedy

Make the gag satisfying because the materials nearly obey reality: the soft subject yields, the hard constraint does not, and contact remains visible.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when the user explicitly asks to generate or render, compose the prompt and invoke `$h3-video-generation`.
- Use `$h3-prompt-writing` and read [references/style-blueprint.md](references/style-blueprint.md).

## Assign the Physics

1. Define the soft subject's material behavior: rubber-like elasticity, plush compression, gel wobble, dough stretch, or water-balloon inertia.
2. Define the rigid constraint's dimensions, thickness, transparency, friction, and immobility.
3. Use a single continuous action: approach, compress, pass or settle, rebound, and satisfied reaction.
4. Track contact across the whole body. Describe fur flattening, skin or fabric folding, volume conservation, pressure release, and the final restored form.
5. Use a static side or three-quarter view with a small push-in. Do not hide the critical contact behind cuts.
6. Keep the subject comfortable and unharmed; use an obviously fantastical, non-distressing premise.
7. Synchronize squeaks, glass taps, soft friction, release pops, and one quiet reaction. Avoid dialogue unless the joke needs a single word.

## Mode and Render Rules

- Use T2VA by default; I2VA may anchor the opening subject and container.
- Keep one action to 4–15 seconds. Default demonstration is 10 seconds.
- Default to `864x480`; `960x544` is optional when requested. Explicit 768p means `1344x768` with no downgrade.
- Exclude clipping through glass, broken containers unless requested, extra limbs, anatomical collapse, pain, panic, visible injury, subtitles, logos, and watermarks.

## Output

Return the mode, material rules, duration/resolution, complete prompt, and safety/continuity locks. For real generation, wait, inspect the full contact sequence, verify the file, and return the absolute Portal URL.
