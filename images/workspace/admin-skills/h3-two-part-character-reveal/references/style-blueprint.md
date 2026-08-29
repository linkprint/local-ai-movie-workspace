# Two-Part Character Reveal Blueprint

## Source and Adaptation

Technique adapted from [LoveRain1997/h3-prompt-journal Case 007](https://github.com/LoveRain1997/h3-prompt-journal/tree/77f1fa7997c9ed9daa2e20ab921a797d2e66babd/case-studies/2026-08-cinematic-character-reveal-two-part), commit `77f1fa7997c9ed9daa2e20ab921a797d2e66babd`, under the MIT License, Copyright (c) 2026 LoveRain1997.

The reusable construction is a mirrored seam contract plus a Part 2 non-repetition list. This adaptation uses original adult characters and original music. It treats continuity as a target to verify, not a guarantee across independent generations.

## Mirrored Seam Contract

Paste a concrete version of this block into both prompts:

```text
SEAM CONTRACT: same adult character identity; same face, costume, hair, accessories, environment, weather, practical lights, and color response. Part 1 ends and Part 2 begins on [EXACT SHOT SIZE], [LENS], [CAMERA HEIGHT], [BODY POSE], [HAND STATE], [EYE LINE], [EXPRESSION], [BACKGROUND ELEMENT POSITIONS], and [LIGHT DIRECTION]. Room tone and reverb remain open. Music remains mid-phrase with no ending cadence in Part 1 and no new intro in Part 2. This is a target for editorial matching across two independent renders, not a claim of native continuous generation.
```

## Pose and Shot Discipline

- Build an approved pose ledger before writing shots.
- Mark every pose consumed by Part 1.
- Part 2 uses remaining poses and may not replay Part 1's macro reveal, silhouette wipe, accessory insert, eye reveal, or camera path.
- Face and eyes get the highest pixel priority, hands second, environment third.

## 10-Second T2VA Demo

This is one original preview, not the full two-job continuity workflow.

```text
integrated_multimodal_description: Original cinematic reveal of one fully clothed adult archivist-warrior inside a fictional circular stone observatory, stable identity throughout: warm brown skin, short silver curls, dark teal layered coat, copper astrolabe pendant, leather gloves, and no weapon. Eight crisp motivated shots form one 10-second introduction. 00:00.000-00:01.000 extreme macro of the copper astrolabe turning under one gloved thumb. 00:01.000-00:02.000 low silhouette behind rotating brass rings. 00:02.000-00:03.000 side profile crosses a shaft of moonlight. 00:03.000-00:04.000 close hand places a star map on the table. 00:04.000-00:05.200 rack focus from map ink to both eyes. 00:05.200-00:06.500 a restrained half orbit reveals the complete coat and observatory. 00:06.500-00:08.000 overhead rings align behind the head without changing identity. 00:08.000-00:10.000 settle into a stable frontal half-body hero key visual as the pendant stops moving. Prioritize face, then eyes, then hands, then environment. Clean hard cuts only; stable costume, anatomy, accessories, lighting, room geometry, and adult identity. No repeated shot mechanism, adult content, nudity, sexualized framing, minors, named characters, celebrity likeness, weapon, injury, extra text, logo, subtitle, or watermark.

overall_soundscape: Brass ticks, glove leather, paper slide, observatory room tone, distant wind, and long stone reverb that remains open through the final frame.

non_diegetic_music: Original low pulse, glass harmonics, and restrained drum impacts that rise continuously to the final hero frame without quoting an existing melody.
```
