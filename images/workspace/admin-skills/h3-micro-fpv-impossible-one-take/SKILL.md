---
name: h3-micro-fpv-impossible-one-take
description: Create MiniMax H3 prompts for impossible micro-FPV flythroughs that skim tactile surfaces, pass close to props or people, choreograph macro rack focus and foreground occlusion, and resolve into a larger reveal or hero composition in one continuous take. Use for miniature obstacle flights and premium subject-scale FPV portrait orbits; do not use for ordinary drone footage or unrelated montage.
---

# H3 Micro FPV Impossible One-Take

Direct one continuous micro-camera path with legible geography, tactile scale, and accelerating but collision-free motion.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use $h3-prompt-writing for exact H3 field order, timing, audio, and negatives.
- Invoke $h3-video-generation only when the user explicitly asks to generate or render.
- Use T2VA by default. Use I2VA only when one supplied opening frame actually matches the opening camera position. When a source portrait supplies identity, pose, prop, or room authority but the flight begins elsewhere, use native Ref2VA and bind the ordered files with `<Picture N>` or `<Video N>`.
- For the selected neo-noir portrait route, read the exact spatial and focus choreography in the reference file. Use 15 seconds for the full route or its explicit 10-second safe demo compression.
- When the user supplies three images of the same subject in different poses, use the anchor-flow route in the reference: the poses are spatial waypoints, not frozen destinations or cuts.

## Build the Flight

1. Define camera size, lens height, speed, and the first surface at macro scale.
2. Map a single connected route through three to five spatial gates or around one stable subject. Each next prop, limb, opening, or landmark must be visible or foreshadowed before the camera reaches it.
3. Anchor scale repeatedly with fibers, dust, droplets, scratches, fasteners, seams, or tool marks.
4. Choreograph focus as part of the route. Every rack-focus handoff must be motivated by camera distance and connect named foreground, midground, and background anchors.
5. Use natural foreground occlusions from props, fabric, hair, smoke, heels, tools, or architecture, but remain spatially coherent and never conceal a cut.
6. Use speed ramps around obstacles, then one short breath before the final reveal. Keep forward momentum continuous.
7. Specify exact clearances and parallax. Do not teleport, clip through solids, change lens size abruptly, cross a weapon muzzle, or hide cuts in motion blur.
8. Add airflow reactions and synchronized pass-by sounds for every near miss.
9. End on one strong scale reversal, destination reveal, or stable hero composition, not a random crash.

## Three-Pose Anchor Flow

1. Confirm all three references depict the same adult subject and lock identity, wardrobe, props, and environment across them.
2. Treat Pose 1, Pose 2, and Pose 3 as successive motion anchors. The subject moves naturally through and beyond each pose and never freezes to match a still.
3. Make the invisible micro-camera actively dodge the moving body: lower-body flight, transition, ascending orbit, upper-body/head orbit, face approach, then an extreme pull-back.
4. Keep one continuous path and forward intent. Do not convert the reference sequence into three shots, a slideshow, or hidden cuts.

## Render Rules

- Keep one render to 4–15 seconds; use 15 seconds for the full selected portrait orbit and 10 seconds for the modal demo.
- Default resolution is 864x480; allow 960x544 when requested. Explicit 768p means 1344x768 and may never be downgraded.
- Exclude adult content, nudity, sexualized framing, minors, celebrity likeness, weapon discharge, unsafe trigger contact, smoking by minors, camera collisions, topology jumps, identity or pose drift, unreadable text, logos, subtitles, and watermarks.

## Output

Return the mode, duration/resolution, route map, focus-handoff chain, complete H3 prompt, continuity locks, and any Broker limitation. For a real render, wait for completion, verify the file, continuous flight path, subject identity, prop safety, and final composition, then return the absolute Portal URL.
