# Style Blueprint

## Viral Evidence

The reusable insight comes from the 1,891-vote Reddit post [2D chibi girl Added “Just a Pinch”. MiniMax H3](https://www.reddit.com/r/StableDiffusion/comments/1vgynd7/2d_chibi_girl_added_just_a_pinch_minimax_h3/). Its key prompting advice is to state that the chibi is the only flat 2D sticker character and everything else is photorealistic. The skill generalizes that contrast with original characters and situations.

## Reliable Arc

- Establish a real, tactile environment before the mascot moves.
- Repeat the “only flat 2D subject” lock once when physical contact begins.
- Show one real material reaction—crumbs, steam, liquid, shadow, or utensil movement.
- Keep the camera restrained so the visual boundary remains legible.

## Imported Case Technique: Sticker Character Kitchen Comedy

Technique adapted from [LoveRain1997/h3-prompt-journal Case 006](https://github.com/LoveRain1997/h3-prompt-journal/tree/77f1fa7997c9ed9daa2e20ab921a797d2e66babd/case-studies/2026-08-sticker-character-kitchen-comedy), commit `77f1fa7997c9ed9daa2e20ab921a797d2e66babd`, under the MIT License, Copyright (c) 2026 LoveRain1997.

The case adds four controls that should be reused without copying its character or scene:

- Treat flat 2D construction as the mascot's identity, not merely the overall art style.
- Use a closed prop contract when a lid, cap, tool, or extra object would break the gag. List every forbidden equivalent and forbid stray copies elsewhere in frame.
- Anchor the three decisive beats at exact timestamps rather than describing only their order.
- Let realistic hands and materials carry the physical action. Keep the sticker's reaction graphic, flat, and readable. Use kitchen ambience only when silence sharpens the punchline.

### Kitchen Comedy Timing Skeleton

```text
00:00.000-00:03.000 establish one flat sticker troublemaker and one exhaustively defined real prop.
00:03.000 first contact: one photoreal connected hand taps or interrupts the sticker.
00:05.000 consequence: the real food or object produces the gag's material result.
00:08.000 reaction: the sticker performs one clearly 2D squash, collapse, or offended pose.
00:10.000 hold a clean readable ending with no new prop and no music.
```

## 10-Second T2VA Demo

```text
integrated_multimodal_description: [Shot 1] Mixed-media live action. A photoreal breakfast table in soft morning window light holds a real ceramic cup of cocoa, a real silver spoon, and a real buttered toast slice with visible crumbs and steam. Standing on the toast is one original flat 2D chibi sticker character: a tiny round teal baker with a white paper hat, thick navy outline, two-color cel shading, oversized dot eyes, and no realistic depth, skin, fur, or 3D volume. This baker is the only drawn character; every other object and the entire environment remain photoreal live action. The camera holds a close static three-quarter view. The baker braces both flat cartoon feet, pushes the real spoon handle with both hands, and the heavy spoon slowly pivots against the saucer with believable resistance. At 00:04.000, the spoon tips a small real cocoa droplet onto the toast; the droplet darkens the bread fibers and scatters several real crumbs. The flat baker hops backward, lands with a squash-and-stretch pose that remains strictly 2D, then fans the rising photoreal steam with the paper hat. At 00:07.000, a real human fingertip enters slowly, slides the cup away from the edge, and the baker gives a crisp two-frame salute. End with the baker in the same flat teal design, reflected only as a faint flat shape on the spoon. No extra drawn characters, subtitles, logos, watermarks, or text.

overall_soundscape: Quiet kitchen room tone, spoon scraping ceramic, soft cocoa drip, toast crumbs ticking on the plate, tiny paper-like foot taps, and a light fingertip slide.

non_diegetic_music: A sparse playful pizzicato motif at a moderate tempo, ending on one short plucked note with the salute.
```
