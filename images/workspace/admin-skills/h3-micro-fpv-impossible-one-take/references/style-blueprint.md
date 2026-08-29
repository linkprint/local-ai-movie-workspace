# Micro-FPV Style Blueprint

## Source and Technique

This workflow was distilled from Pierrick Chevallier's X post about using one image plus an expert-FPV-director instruction with MiniMax H3. When reviewed, the post showed about 128.5K views, 1.7K likes, and 1.7K bookmarks. Its useful technique is the detailed route logic: extremely low macro altitude, material-scale landmarks, connected clearances, controlled speed changes, and one uninterrupted destination reveal.

Use the flight grammar, not the source room, objects, or exact wording.

## Imported Case Technique: Three-Pose Anchor Flow

Technique adapted from [LoveRain1997/h3-prompt-journal Case 003](https://github.com/LoveRain1997/h3-prompt-journal/tree/77f1fa7997c9ed9daa2e20ab921a797d2e66babd/case-studies/2026-08-single-subject-three-pose), commit `77f1fa7997c9ed9daa2e20ab921a797d2e66babd`, under the MIT License, Copyright (c) 2026 LoveRain1997.

Use three pictures of the same subject as continuous spatial anchors, never as three destinations. The camera is a tiny invisible high-speed FPV unit that dodges a normally moving person. A reliable 15-second route is:

- `00:00.000-00:02.500`: establish Pose 1 while flying around the lower body.
- `00:02.500-00:05.000`: connect naturally toward Pose 2 without a cut or freeze.
- `00:05.000-00:07.500`: rise in an ascending close orbit around Pose 2.
- `00:07.500-00:09.500`: continue through the environment toward Pose 3.
- `00:09.500-00:11.800`: orbit upper body and head with identity-safe clearance.
- `00:11.800-00:13.500`: approach the face, then visibly change direction.
- `00:13.500-00:15.000`: pull back rapidly to a stable Pose 3 half-body hero frame.

Lock normal continuous subject motion, a physically connected camera path, stable room geometry, and no interpolation pauses at the three anchors. Use native multi-reference Ref2VA for identity-locked execution, with every ordered image/video assigned to the matching `<Picture N>` or `<Video N>` label.

## Selected Neo-Noir Portrait Reference Lock

The full selected route uses one reference image as authority for an adult woman's identity, pose, fully clothed dark outfit, blunt black bob and bangs, red glass pendants, red nails, resting silver handgun, raised cigarette hand, crossed black heels, cushion, white pillow, warm pleated lamp, horizontal blinds, bedspread, and complete room geometry.

- The subject remains in the same prone pose with elbows planted, head slightly tilted, feet lifted and crossed, and a calm direct gaze.
- The resting handgun never fires or points at another person. Keep the index finger extended safely along the slide and outside the trigger guard.
- Keep the camera outside the muzzle path. A close gunmetal pass may create a foreground occlusion, but the lens never crosses the bore.
- The subject is an adult. Preserve cinematic neo-noir atmosphere without nudity, sexualized body emphasis, coercion, injury, or violence.
- Because the selected flight begins at the bedspread rather than at the source portrait's full composition, identity-locked use of that image is Ref2VA, not ordinary first-frame I2VA.

## Selected 15-Second Ref2VA Route

integrated_multimodal_description: [Shot 1] One invisible micro-FPV camera completes a single continuous premium neo-noir orbit around the exact adult subject and room defined by the reference image. Preserve identity, pose, outfit, hair, jewelry, nails, resting handgun, cigarette, crossed heels, lamp, blinds, pillow, cushion, bedspread, and spatial relationships. Warm practical lamp light contrasts with cool ambience from the blinds. High-contrast macro texture and shallow depth of field, no cuts.

00:00.000–00:01.500: The invisible camera skims less than one inch above the coarse woven bedspread. Ribbed diagonal threads rush beneath the lens like a terrain map. Warm lamp light grazes each fiber. A silver glint from the resting handgun appears at the right edge as the first hook.

00:01.500–00:03.000: Bank gently left around the small textured cushion supporting the subject's elbows, using it as a massive foreground wall. Rack focus from bedspread fibers to the polished pistol grip in her resting left hand, red nails, and the safe extended index finger along the slide. Rise in a shallow arc over the muzzle line without crossing it. Let gunmetal pass close as a brief blurred foreground occlusion, then continue toward hand and forearm while the black wrist band frames the lower edge.

00:03.000–00:05.000: Continue one slow clockwise orbit just above the cushion and climb toward the fully clothed black upper-body silhouette. The two red teardrop pendants swing subtly. Pull focus from pendant facets to restrained skin highlights and then to the dark sheen of the stable black bob.

00:05.000–00:06.700: Slip upward beside the raised cigarette hand and thread through a thin layer of smoke. The adult subject's cigarette ember becomes a warm macro point before smearing into bokeh. Cigarette and red nails fill the foreground; the face remains ghosted behind. Snap focus cleanly through the smoke onto her eyes.

00:06.700–00:08.300: Float inches from the face at pillow height. Drift from the cigarette hand across cheek and lips as one blue-toned exhale rises past the forehead. Semi-transparent smoke briefly veils one eye, then reveals it with a motivated rack focus from vapor texture to the reflective gaze. Rise over the bridge of the nose and blunt bangs; individual black hair strands catch warm lamp and cool blind light.

00:08.300–00:10.000: Crest above the head and widen only slightly to reveal the stable room geometry: pleated lamp left, closed horizontal blinds behind the raised heels, smooth white pillow right, and the bedspread plane anchoring the shot.

00:10.000–00:11.800: Dive in a smooth S-curve toward the crossed glossy black heels, using the lifted legs as a foreground arch without changing the pose. Shift focus from face to patent-leather highlights while lamp and blinds move in opposite parallax. Complete a precise half-orbit around the shoes; one heel becomes a dark blurred vertical blade before the camera descends along the legs toward the bed.

00:11.800–00:13.500: Accelerate subtly beside the bedspread, race past the cushion, then climb in one smooth spiral around the subject's right side. Thread between smoke and reflective gunmetal so red nails, pendants, black hair, warm lamp, pillow, and striped blinds align in layered depth. Execute one continuous motivated focus chain: pistol highlight, cigarette ember, smoke, pendant facets, lips, then eyes.

00:13.500–00:15.000: Decelerate into a poised three-quarter frontal hero composition at bed height. Face and eyes are razor sharp; cigarette hand remains lifted in the foreground; the handgun rests safely in the opposite hand; crossed heels and blinds remain behind; the pleated lamp forms a warm practical halo; blue smoke rises in thin elegant wisps. Hold in perfect stillness. Stable identity, expression, pose, props, and room; no visible camera, cut, jump, collision, muzzle crossing, weapon discharge, anatomy drift, or spatial reset.

overall_soundscape: Close woven-fiber rush, soft cushion pass, restrained gunmetal air pass with no handling click, pendant ticks, cigarette ember crackle, breath and smoke movement, hair whisper, distant room tone, heel pass-by, blinds ambience, and one soft final settle.

non_diegetic_music: Original restrained neo-noir pulse with low brushed percussion, subtle bass, and sparse glassy tones that opens into silence for the final hold. No existing melody.

## Prompt Skeleton

integrated_multimodal_description: One invisible micro-FPV camera begins [HEIGHT] above [TACTILE SURFACE] with [LENS / SPEED]. It follows one physically connected route through [GATE 1], [GATE 2], [GATE 3], and [FINAL REVEAL]. Foreshadow each opening before entry. Repeat scale anchors such as [FIBERS / DROPLETS / FASTENERS]. Specify airflow, parallax, safe clearance, and speed ramps. The shot contains no cuts, teleportation, collision, clipping, topology changes, or hidden resets.

overall_soundscape: Material-specific pass-bys, airflow, near-miss whooshes, and synchronized contact-free mechanical details.

non_diegetic_music: An original accelerating rhythmic cue that opens space before the final reveal.

## 10-Second T2VA Style Demo

This is an original no-reference preview. It demonstrates the selected macro portrait orbit but does not claim reference identity lock.

integrated_multimodal_description: [Shot 1] One continuous invisible micro-FPV neo-noir portrait orbit around one original adult woman resting fully clothed on a dark daybed in a spatially coherent room. Stable identity and pose throughout: blunt black bob and bangs, calm direct gaze, black tailored long-sleeve outfit, two red glass pendants, red nails, one resting unloaded silver display pistol with index finger safely along the slide, one raised cigarette hand, crossed black heels, textured cushion, white pillow, warm pleated lamp left, cool horizontal blinds behind. 00:00.000–00:01.000 skim less than one inch above coarse diagonal bedspread fibers toward one silver pistol glint. 00:01.000–00:02.200 bank around the cushion, rack focus to grip and safe finger position, and rise above but never across the muzzle line as gunmetal makes a brief blurred foreground occlusion. 00:02.200–00:03.600 orbit above the cushion toward the red pendants and black hair, focusing pendant facets then hair sheen. 00:03.600–00:05.000 thread through thin cigarette smoke; ember and red nails fill the foreground before focus lands on the eyes. 00:05.000–00:06.400 drift past cheek, blue exhale, bangs, and warm/cool hair highlights, then crest to reveal lamp, blinds, pillow, and bed plane. 00:06.400–00:08.000 dive in an S-curve toward crossed heels and complete one close half-orbit, using a heel as a natural blurred foreground blade. 00:08.000–00:09.200 race low beside the bed and spiral around the right side with one focus chain: pistol highlight, ember, smoke, pendants, lips, eyes. 00:09.200–00:10.000 decelerate into a stable three-quarter hero composition at bed height and hold. No cuts, visible camera, teleportation, collision, muzzle crossing, weapon discharge, unsafe trigger contact, identity drift, pose drift, nudity, sexualized framing, minors, injury, logos, subtitles, or watermarks.

overall_soundscape: Woven-fiber rush, cushion pass, restrained gunmetal air pass, pendant ticks, ember crackle, breath, smoke, hair whisper, heel pass-by, room tone, and one soft final settle.

non_diegetic_music: Original restrained neo-noir pulse with low brushed percussion and sparse glassy tones, ending nearly silent.
