# Style Blueprint

## Viral Evidence

The 797-vote Reddit post [MiniMax H3 Helps Me with Sprites Animation](https://www.reddit.com/r/StableDiffusion/comments/1vw755b/minimax_h3_helps_me_with_sprites_animation/) shares a simple three-field 16-bit sprite prompt and reports that first-frame/last-frame generation is better for loops. The author assembles short loops rather than one long render. This skill adds explicit pixel, silhouette, and seam locks.

## Reliable Loop Arc

- 0–20%: hold the canonical idle pose.
- 20–70%: one reaction or action with stepped poses.
- 70–90%: reverse or settle the moving parts.
- 90–100%: match the opening pose, scale, position, palette, and background.

## 10-Second T2VA Loop-Style Demo

```text
integrated_multimodal_description: [Shot 1] Crisp original 16-bit pixel-art game sprite animation with an orthographic static camera and hard nearest-neighbor pixel edges. Centered on a simple two-tone midnight-blue tiled floor is one small round coral-red salamander courier sprite with a cream belly, four short legs, a thick curved tail, one square tan satchel, two dark pixel eyes, and a three-color highlight palette. The silhouette, limb count, palette, outline, scale, and screen position remain identical throughout. No antialiasing, painterly texture, gradients, 3D depth, or subpixel motion. The salamander begins in a canonical idle pose facing three-quarters right, tail resting in a C curve. It breathes using two stepped torso poses and blinks once. At 00:02.000, a single cyan pixel butterfly enters from frame right in four held positions. The salamander's pupils track it; the head tilts through three discrete poses and the tail makes one stepped wag while all four feet remain planted. At 00:06.500, the butterfly exits upward. The salamander straightens the satchel with one paw, returns the paw to the floor, reverses the head and tail poses, and by 00:09.200 settles into the exact opening idle pose, scale, position, expression, tail curve, and background state for a loop-style ending. No camera motion, text, logo, UI, subtitles, watermarks, extra characters, or morphing.

overall_soundscape: Three tiny retro foot-and-cloth clicks, one soft 8-bit blink tone, and a short ascending butterfly chirp. No dialogue.

non_diegetic_music: A minimal four-note 16-bit synthesizer pattern that resolves to its opening note at the end.
```
