---
name: h3-chibi-live-action-sticker
description: Create MiniMax H3 prompts that place one flat 2D chibi or sticker-like character inside a photoreal live-action environment with readable mixed-media interaction. Use for cute drawn mascots affecting real food, desks, streets, hands, or props; do not use for fully animated scenes.
---

# H3 Chibi Live-Action Sticker

Keep the drawn character unmistakably flat while the surrounding world remains fully photographic.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when the user explicitly asks to generate or render, compose the prompt and invoke `$h3-video-generation`.
- Use `$h3-prompt-writing` for exact H3 audiovisual structure.
- Read [references/style-blueprint.md](references/style-blueprint.md) before drafting.

## Preserve the Mixed-Media Contract

1. State that the chibi is the only flat 2D sticker character in the scene; everything else is photoreal live action.
2. Lock its outline thickness, fill palette, head-to-body ratio, facial features, and absence of volume, pores, fur, or realistic shading.
3. Give it one simple action arc with clear anticipation, contact, and reaction.
4. Let the real world respond through crumbs, steam, droplets, shadows, utensil movement, or a human hand. Keep contact points explicit.
5. Use a mostly static camera or a small slow push so the style boundary stays readable.
6. Avoid morphing the real objects into drawings or turning the character into 3D CG.
7. Keep dialogue optional and short; prefer synchronized foley and one playful musical motif.

## Use Closed Prop Contracts and Beat Anchors

- When a gag depends on a prop state, define the allowed object exhaustively. For example: `one open-top glass jar, no lid, no cap, no removable cover, and no lid-like object anywhere in frame`.
- Bind irreversible story events to exact timestamps, such as contact at `00:03.000`, consequence at `00:05.000`, and cartoon reaction at `00:08.000`.
- Keep the human hand photoreal and anatomically connected whenever it enters. The hand performs the real-world action; the flat sticker reacts without gaining volume.
- If silence is part of the comedy, state `no music` and use only room tone and synchronized object sounds.

## Mode and Render Rules

- Use T2VA by default. Use I2VA when an opening composition or mascot design must be anchored.
- If multiple reference images are needed to lock mascot identity and environment separately, use native Ref2VA and bind the ordered images to distinct `<Picture N>` roles.
- Keep the clip to one 4–15 second scene. A 10-second arc should use no more than three action beats.
- Default to `864x480`; allow `960x544` if requested. Explicit 768p means `1344x768` with no downgrade.
- Exclude subtitles, logos, watermarks, extra drawn characters, photoreal skin on the mascot, and unexplained style changes.

## Output

Return the mode, duration/resolution, complete H3 prompt, and the exact flat-vs-real style locks. For a real render, wait, download, verify, and return the absolute Portal URL.
