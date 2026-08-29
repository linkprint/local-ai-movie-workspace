# Interactive Creature Encyclopedia Blueprint

## Technique

The full version uses one interface reference plus several independent creature references. Each input has a strict role: the UI image controls grid, chrome, palette, and interaction language; creature images control only anatomy, silhouette, surface, palette, and signature effect. The video then repeats a clear selection cycle without mixing creatures.

The Workspace Broker exposes that multi-reference mode through native Ref2VA. Use the ordered UI and creature images as explicit `<Picture N>` references; the existing demo remains an original no-reference T2VA preview.

## Full Reference Role Map

- Image 1: interface topology only — roster rail, central stage, focus frame, attributes, scan language, palette, and graphic materials.
- Image 2 = card A = LUMI HARE.
- Image 3 = card B = CLOUD WISP.
- Image 4 = card C = EMBER FENNEC.
- Image 5 = card D = TIDE BEHEMOTH.
- Image 6 = card E = PETAL VULPIN.
- Image 7 = card F = ORCHARD EYE.
- Image 8 = card G = MEADOW CROWN.
- Image 9 = card H = FROST GLIDER.
- Never borrow a creature's limbs, head, markings, particles, or palette for another card.
- Keep the UI coordinate system fixed while selected cards, central specimen, and attribute graphics change state.

## Reference-Image Preparation

- Image 1 should be a clean 16:9 UI-only reference with eight visible cards A–H, one main creature stage, one main title area, a single consistent typography system, and no baked-in creature that would compete with Images 2–9.
- Images 2–9 should each show one isolated original creature clearly on a simple background. Favor readable three-quarter full-body views; avoid crops, multiple poses, text, frames, props, or other creatures.
- Keep the eight creature references visually compatible in rendering quality while preserving clearly different silhouettes, anatomy, surfaces, and palettes.
- Reject the set before video generation if a card letter, creature image, or exact name mapping is ambiguous.

## Selected 15-Second Ref2VA Template

Use Image 1 as the exact UI, layout, typography, panel, icon, color, spacing, and overall composition reference for the creature encyclopedia screen. Use Images 2–9 as the exact creature references according to the role map above. Image 1 controls no creature identity; each creature image controls only its assigned creature.

Create a 15-second 16:9 video. Keep the camera locked. Preserve the interface, layout, typography, panels, icons, card positions, buttons, and overall composition throughout. The UI is a modern minimal digital creature encyclopedia. Use exactly one cursor. No scene cuts, camera motion, extra text, extra buttons, extra cards, extra cursor, extra creature, UI distortion, or composition drift.

0.00–2.50: The cursor clicks card A. The selected card becomes A, the main creature becomes the exact Image 2 LUMI HARE, and the main title changes to exactly “LUMI HARE” in one synchronized state update. LUMI HARE blinks and rotates slightly.

2.50–5.00: The cursor clicks card C. The selected card becomes C, the main creature becomes the exact Image 4 EMBER FENNEC, and the main title changes to exactly “EMBER FENNEC” in one synchronized state update. The same cursor drags left and right; EMBER FENNEC rotates left and right with restrained motion.

5.00–7.50: The cursor clicks card E. The selected card becomes E, the main creature becomes the exact Image 6 PETAL VULPIN, and the main title changes to exactly “PETAL VULPIN” in one synchronized state update. The same cursor pokes it a few times; PETAL VULPIN gives a small annoyed reaction.

7.50–10.00: The cursor clicks card G. The selected card becomes G, the main creature becomes the exact Image 8 MEADOW CROWN, and the main title changes to exactly “MEADOW CROWN” in one synchronized state update. The same cursor taps near its face or horns; MEADOW CROWN recoils slightly.

10.00–12.00: The cursor clicks card D. The selected card becomes D, the main creature becomes the exact Image 5 TIDE BEHEMOTH, and the main title changes to exactly “TIDE BEHEMOTH” in one synchronized state update. The same cursor keeps poking it.

12.00–15.00: TIDE BEHEMOTH becomes angry, opens its mouth very wide, lunges forward inside the stable main creature panel, and swallows the only cursor. Add one comedic gulp. TIDE BEHEMOTH returns to idle; the cursor remains gone and the main title stays exactly “TIDE BEHEMOTH”. The UI, camera, panels, icons, and all other content remain unchanged.

Only animate the one cursor, selected-card state, main-title update, and selected creature. Keep motion subtle and clean before the final swallow. Every selected card, main creature, and main title must agree. Do not cross-blend creatures or exchange anatomy.

overall_soundscape: Soft UI click sounds, subtle hover sounds, tiny creature reaction sounds, a sharper aggressive TIDE BEHEMOTH sound, and one comedic swallow gulp at the end.

non_diegetic_music: N/A

## Adaptable Prompt Skeleton

Create a [DURATION]-second 16:9 animated creature encyclopedia. Image 1 defines the interface only. Images 2–[N] define creatures A–[N] independently. Use this cycle for each selection: roster focus moves to the next card, focus locks, scan passes, the matching creature resolves in the central stage, abstract attribute bars react, creature performs one stable idle or signature motion, focus advances. UI geometry, camera, and lighting remain fixed. Creatures never cross-blend, transform into one another, duplicate, or exchange anatomy. Add no text beyond [EXACT ALLOWED LABELS].

## 10-Second T2VA Style Demo

integrated_multimodal_description: [Shot 1] A polished original fantasy-science creature encyclopedia fills the locked 16:9 frame: dark graphite glass UI, thin mint lines, a fixed eight-card roster A–H on the left, one large specimen stage at center, one main title area above it, and abstract attribute bars on the right. Use exactly one cursor. At 00:00.400 the cursor clicks A; selected card, a small luminous moon-hare creature, and the exact title “LUMI HARE” update together. It blinks and turns slightly. At 00:02.200 the same cursor clicks C; selected card, an ember-orange long-eared fennec creature, and “EMBER FENNEC” update together. A short drag rotates it left and right. At 00:04.300 the cursor clicks E; selected card, a pale floral fox creature, and “PETAL VULPIN” update together. Two pokes produce a restrained annoyed reaction. At 00:06.400 the cursor clicks D; selected card, a compact blue tide-beast, and “TIDE BEHEMOTH” update together. Repeated pokes make it tense. At 00:08.000 TIDE BEHEMOTH becomes angry, opens its mouth very wide, lunges forward only inside the main stage, and swallows the one cursor with a comedic gulp. At 00:09.000 it returns to idle; the cursor remains gone and the title stays “TIDE BEHEMOTH” through 00:10.000. All panels, cards, icons, typography positions, and camera remain unchanged. No mismatched card, creature, or title; no extra cursor, text, buttons, creatures, scene cuts, camera motion, UI distortion, cross-morphing, gore, adult content, named IP, logos, subtitles, or watermarks.

overall_soundscape: Four soft selection clicks, subtle hover tones, tiny distinct creature reactions, one sharper angry creature sound, and one comedic swallow gulp.

non_diegetic_music: N/A
