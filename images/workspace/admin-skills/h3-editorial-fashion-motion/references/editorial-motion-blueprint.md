# Editorial Motion Blueprint

Use this reference to construct the creative content before formatting it with `$h3-prompt-writing`.

## What the Source Demonstrates

The source is the Reddit post [High Fashion in Motion | MiniMax H3](https://www.reddit.com/r/StableDiffusion/comments/1vv7mqq/high_fashion_in_motion_minimax_h3/). Treat it as technique evidence, not as a prompt to copy.

- The published artifact is approximately 30 seconds in 4:3 and is described by its author as two connected 15-second clips. The second part used the end of the first part as video and audio reference for continuity.
- The observed film keeps one model and a sculptural black outfit visually stable. Motion comes mainly from pose changes, hair and fabric inertia, changing crop, smooth push-ins and pull-outs, and a white magazine field whose black typography and red accents reorganize around the model.
- A detailed prompt template shared in the comments proposes a locked opening, graphic-wall reveal, facial close-up, beat-synced pose or outfit changes, continuous layout redesign, and a final hero composition. It also emphasizes identity lock, controlled camera movement, believable physics, and explicit negative constraints.
- The author reports that small words dissolved or became incorrect during motion. Larger words or slower motion may hold better; exact typography is more reliable as a post-production layer over a clean plate.

## Build the Visual System

Define these anchors once and repeat only when continuity needs reinforcement:

- **Identity anchor:** face geometry, eye color, hair shape and color, skin texture, body proportions, and distinctive jewelry.
- **Wardrobe anchor:** silhouette, fabric, color, construction details, openings, hardware, and which parts may flutter.
- **Graphic anchor:** background field, two type scales at most, red accent behavior, crop marks, barcodes, numbers, grids, halftone, paper grain, or scan lines.
- **Camera anchor:** dominant framing and one controlled move per phase.
- **Physics anchor:** direction and strength of air, fabric weight, hair inertia, foot contact, and jewelry response.
- **Audio anchor:** tempo, beat pattern, transition hits, fabric movement, heels or footsteps, and final downbeat.

## Default Fluid-Couture Arc

Adapt the timing proportionally; do not add cuts unless the user asks for them.

1. **0–20% — Graphic reveal:** centered medium shot, held pose, restrained field, first beat expands the editorial graphic system.
2. **20–45% — Intimate motion:** small slow push-in; gaze meets camera; head or shoulder turns; hair and jewelry follow with natural inertia.
3. **45–70% — Sculptural pose:** pull back or make a small arc; the model steps, leans, crouches, or half-turns while the garment keeps its construction.
4. **70–90% — Graphic peak:** bolder black shapes and one red accent reorganize; preserve face and readable silhouette instead of increasing camera chaos.
5. **90–100% — Hero landing:** return to stable medium or full framing; simplify the background and land on a confident centered pose at the final beat.

## Optional Beat-Cut Lookbook Arc

Use four to seven looks in 15 seconds, not an arbitrary number of changes. Each beat changes one primary dimension—outfit, pose, or layout—while the other anchors stabilize it. Alternate medium framing with selected close-ups; avoid consecutive extreme changes in framing, garment, and background.

## H3 Content Pattern

Translate the chosen arc into the exact fields required by `$h3-prompt-writing`:

```text
integrated_multimodal_description: [Shot 1] Live-action high-fashion editorial film, ... The model remains identity-locked and center-safe ... The camera [one controlled motion] ... At the next strong beat, [one dominant visual transformation] while [continuity anchors] remain unchanged ... The sequence lands on [stable hero pose and simplified graphic field].

overall_soundscape: Controlled studio room tone with physically synchronized fabric movement, jewelry movement, and footsteps where visible. No dialogue unless supplied by the user.

non_diegetic_music: A precise electronic fashion beat at [tempo range], with clear accents aligned to the named pose or layout transitions and a clean final downbeat.
```

Use exact H3 timing and keyframe syntax from the base prompt-writing skill. Do not paste this scaffold unchanged.

## Typography Decision

- **No exact copy requested:** use abstract oversized letterforms, solid bars, simple numbers, barcodes, crop marks, and registration graphics. State that there are no subtitles, logos, watermarks, or dense small text.
- **Exact words requested:** quote the text verbatim in the H3 prompt only if the user accepts generation risk. Prefer a clean plate and add the exact typography after generation.
- **Fast montage:** one large word or number system per phase; no paragraphs or fine-print blocks.

## Failure Guard

Always exclude face drift, identity replacement, plastic skin, unstable anatomy, extra limbs or fingers, warped collarbones, garment topology changes, foot sliding, weightless fabric, chaotic camera motion, repetitive layout, unreadable dense text, random backgrounds, unintended subtitles, logos, and watermarks. Do not overfill the negative list with generic quality adjectives; connect each exclusion to a likely fashion-film failure.
