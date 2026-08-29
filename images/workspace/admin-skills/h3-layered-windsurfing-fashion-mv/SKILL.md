---
name: h3-layered-windsurfing-fashion-mv
description: Create MiniMax H3 prompts for original adult windsurfing or fashion-sports music-video arcs using separate identity, equipment, and cinematography reference roles. Use for three-part vertical campaigns, music-shaped action peaks, and text-directed climax segments; do not use for unsafe stunts, real brands, named characters, or a single undifferentiated reference collage.
---

# H3 Layered Windsurfing Fashion MV

Give every reference one authority and build each 15-second segment around a musical energy curve.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use `$h3-prompt-writing`.
- Invoke `$h3-video-generation` only when the user explicitly asks to render a named segment. Never submit all three segments implicitly.
- The full design is three separate 15-second jobs. Use native Ref2VA for identity/equipment references in each job, preserve the same ordered references and prompt labels across all three, then assemble the approved outputs; the Broker still does not expose a single multishot job.

## Assign Reference Authority

1. Picture 1 is the key visual and opening-state authority: adult identity, face, hair, costume, palette, and first frame.
2. Picture 2 is the turnaround and equipment authority: body proportions, board, sail, mast, boom, rigging, fasteners, and color relationships. It is not a storyboard or camera guide.
3. Picture 3 is the segment-specific cinematography guide: framing, lens, movement, subject scale, and composition only. It cannot change identity, costume, equipment, or environment logic.
4. For the climax segment, omit Picture 3 and direct the giant wave, takeoff, airborne hero hold, landing, and fashion finish entirely in text.

## Shape Each Segment

- `00:00-00:03`: build anticipation.
- `00:03`: musical drop and decisive action onset.
- `00:03-00:10`: sustained high energy.
- `00:06-00:07`: optional compact turnaround that proves equipment integrity.
- `00:09-00:10`: absolute visual peak with a stable hero camera moment.
- `00:10-00:12`: release.
- `00:12-00:15`: fashion ending or bridge to the next phrase.

Keep water physics, rig tension, hand placement, wind direction, and board contact coherent. Use a trained adult athlete, controlled fictional conditions, and no injury.

## Render Rules

- Default orientation for the full fashion campaign is 9:16, one 15-second segment at a time. The modal demo is a 10-second 16:9 T2VA style preview.
- Default to `864x480`; allow `960x544` when requested. Explicit 768p means `1344x768` and may never be downgraded. For vertical delivery, use the Broker-supported vertical equivalent only if available and explicitly requested.
- Exclude adult content, nudity, sexualized framing, minors, celebrity likeness, named characters, real brands, logos, unsafe weather, injury, broken rigging, changing board geometry, extra limbs, unreadable text, subtitles, and watermarks.

## Output

Return reference authority map, segment map, duration/resolution, complete prompt for each requested segment, seam notes, and Broker limitations. For real renders, verify equipment continuity, safe athlete motion, water physics, and the musical peak before returning each absolute Portal URL.
