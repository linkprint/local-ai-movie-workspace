---
name: h3-creature-motion-replacement
description: Create MiniMax H3 prompts for replacing a performer with an original creature while preserving a source video's path, timing, camera, prop interactions, and environment, often with a new cinematic lighting treatment. Use for creature stunt doubles, actor-to-animal replacement, or motion-transfer edits; do not use for ordinary text-to-video creature shots.
---

# H3 Creature Motion Replacement

Treat this as a controlled edit: motion and staging come from the source, while creature identity and optional lighting come from separate references.

## Route the Request

- For prompt writing or analysis, return a Ref2VA plan without rendering.
- Only attempt real replacement when the active generation tool explicitly supports video and image references.
- A true motion replacement must use native Ref2VA with the source motion clip under `reference_videos`; never substitute T2VA and call it equivalent.
- For an original no-reference demonstration, use the T2VA adaptation in the blueprint and clearly label it as a style demo, not motion replacement.
- Use `$h3-prompt-writing` and read [references/style-blueprint.md](references/style-blueprint.md).

## Separate Reference Jobs

1. `<Video 1>` supplies the source edit, camera motion, temporal structure, actor path, and prop timing.
2. `<Subject 1>` supplies creature anatomy, scale, skin or fur, locomotion, and silhouette from its image reference.
3. Define a setting or lighting reference separately; never let it overwrite the source geometry unless requested.
4. Mark retained props and contact events explicitly, including doors, handles, toys, floor contact, shadows, and impact timing.
5. Replace human sounds with creature-specific footsteps, breathing, claws, fabric, or weight while preserving the timing of the source action.
6. Keep anatomy stable and physically compatible with the interaction. Do not invent extra limbs or let the creature pass through objects.

## Render Rules

- True replacement uses full-reference format, not the three-field T2VA format.
- A T2VA style demo may depict an original creature performing a simple domestic action with cinematic night lighting.
- Keep clips to 4–15 seconds. Default demo resolution is `864x480`; explicit 768p means `1344x768`.
- Exclude copyrighted characters, real-person likenesses, visible source watermarks, and unsupported claims of exact motion transfer.

## Output

Return the reference-role map, retention decisions, complete prompt, supported mode, and render status. For supported real generation, verify the finished media and return the absolute Portal URL.
