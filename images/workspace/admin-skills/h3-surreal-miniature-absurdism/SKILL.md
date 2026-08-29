---
name: h3-surreal-miniature-absurdism
description: Create MiniMax H3 prompts for photoreal absurdist comedy built on extreme scale contrast, miniature people or objects, uncanny food or household creatures, macro camera work, and explicit physical cause-and-effect. Use when the gag depends on a bizarre premise feeling materially real; do not use for ordinary fantasy or generic comedy.
---

# H3 Surreal Miniature Absurdism

Create an original impossible situation with internally consistent scale, physics, and comic escalation. Never copy a celebrity, named meme, or source scene.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when the user explicitly asks to generate or render, compose the prompt and invoke `$h3-video-generation`.
- Use `$h3-prompt-writing` for H3 field order, timestamps, audio, and dialogue syntax.
- Read [references/style-blueprint.md](references/style-blueprint.md) before drafting.

## Direct the Gag

1. Define the normal material world first: surface, liquid, glass, metal, food, room light, and camera scale.
2. State the impossible subject literally, including its exact size and what it is not. Keep its anatomy stable.
3. Use one readable three-beat escalation: calm setup, physical disruption, payoff or reveal.
4. Describe contact physics: displacement, compression, dripping, drag, weight, collision, and reaction.
5. Keep the camera near the smallest subject until the reveal. Prefer one continuous macro tracking move over unrelated cuts.
6. Give each speaking subject a stable ID and one short line only. Use `non_diegetic_music: N/A` unless music is essential.
7. End immediately after the visual payoff. Exclude subtitles, logos, watermarks, and extra text.

## Mode and Render Rules

- Use T2VA by default. Use I2VA only when the supplied first frame already establishes the scale relationship.
- For independent identity, creature, and setting references, use native Ref2VA, pass the ordered files under `reference_images` or `reference_videos`, and name every `<Picture N>` or `<Video N>` role in the prompt.
- Keep one real render to 4–15 seconds. Default to 10 seconds for a complete gag.
- If resolution is unspecified, use `864x480`; `960x544` is allowed when requested. If the user explicitly asks for 768p, use `1344x768` and never downgrade it.
- Avoid real-person likenesses unless the user has supplied an authorized reference and the active workflow supports it.

## Output

For prompt-only work, give the selected mode, duration/resolution, the complete H3 prompt, and the physical continuity locks. For real generation, wait for completion, verify the downloaded media, and return the absolute Portal URL.
