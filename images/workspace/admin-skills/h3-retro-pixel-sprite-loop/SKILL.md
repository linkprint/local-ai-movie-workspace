---
name: h3-retro-pixel-sprite-loop
description: Create MiniMax H3 prompts for looping 16-bit or pixel-art character animation with stable sprite geometry, limited palette, stepped motion, readable idle actions, and matching first/last poses. Use for game mascots, idle loops, attack loops, emotes, or sprite studies; do not use for smooth painterly animation.
---

# H3 Retro Pixel Sprite Loop

Preserve pixel logic and design consistency while giving the character one loopable action.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when explicitly asked to generate or render, compose the prompt and invoke `$h3-video-generation`.
- Use `$h3-prompt-writing` and read [references/style-blueprint.md](references/style-blueprint.md).

## Lock the Sprite

1. Define the sprite silhouette, limb count, proportions, palette, outline color, and distinctive features once.
2. Specify crisp square pixels, hard nearest-neighbor edges, limited shading bands, no antialiasing, and no painterly texture.
3. Keep the camera orthographic and static with a plain or simple tiled background.
4. Use one action loop: idle glance, tail sway, hop, attack, or emote. Do not combine several gameplay states.
5. Describe stepped pose changes with held frames rather than high-frame-rate fluid motion.
6. Return to the exact opening pose, scale, position, expression, and background state at the end.
7. Keep audio sparse: small movement effects and optional retro synthesis; no speech by default.

## Mode and Render Rules

- Use T2VA for an original sprite. Use FL2VA with identical authorized first/last frames when exact loop closure is required and supported.
- If FL2VA is unavailable in the active Broker, render T2VA only as a loop-style preview and say exact seamless closure is not guaranteed.
- Keep clips to 4–15 seconds. Default demonstration is 10 seconds at `864x480`; explicit 768p means `1344x768`.
- Exclude subpixel blur, camera motion, 3D volume, extra limbs, changing palette, morphing silhouette, text, logos, and watermarks.

## Output

Return the mode, sprite lock, loop seam plan, duration/resolution, and complete H3 prompt. For real generation, verify first/last-frame similarity and file integrity before returning the absolute Portal URL.
