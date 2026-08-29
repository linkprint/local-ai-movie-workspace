---
name: h3-deadpan-mockumentary-interview
description: Create MiniMax H3 prompts for original deadpan mockumentary interviews with restrained framing, dry question-and-answer timing, awkward pauses, reaction inserts, and documentary room tone. Use for fictional hosts, experts, workplace interviews, or parody-like social clips; do not imitate a living performer or named show.
---

# H3 Deadpan Mockumentary Interview

Build comedy from timing and contrast, not from copying a recognizable comedian, program, voice, or catchphrase.

## Route the Request

- For prompt writing or refinement, return text only.
- Only when explicitly asked to generate or render, compose the prompt and invoke `$h3-video-generation`.
- Use `$h3-prompt-writing` for stable speakers, dialogue, cuts, and audio fields.
- Read [references/style-blueprint.md](references/style-blueprint.md) before drafting.

## Control Performance and Timing

1. Use two original subjects at most: interviewer and guest. Give each a stable speaker ID and distinct delivery.
2. Keep spoken text short enough for the duration. In 10 seconds, use one question, a deliberate pause, one answer, and one reaction.
3. State when each line starts. Leave visible silence before and after the answer; do not fill every second with speech.
4. Use a static or slightly imperfect documentary camera, neutral office or location lighting, and one restrained reaction insert or slow push-in.
5. Direct micro-performance: blink, breath, glance, tiny posture shift, and held eye contact. Avoid theatrical gesturing.
6. Keep room tone and small physical sounds. Use `non_diegetic_music: N/A` by default so silence carries the joke.
7. Exclude laughter tracks, subtitles, logos, watermarks, gibberish, overlapping dialogue, and off-screen lines assigned to the wrong mouth.

## Mode and Render Rules

- Use T2VA for original people and settings. Use I2VA only to anchor an authorized original host or room.
- Voice or likeness references require supported reference generation; the current Broker does not expose them. Never claim a copied voice.
- Keep clips to 4–15 seconds. Default to 10 seconds and `864x480`; explicit 768p means `1344x768`.

## Output

Return the mode, timing budget, duration/resolution, complete H3 prompt, and any likeness or reference limitation. For real generation, wait, verify lip/audio timing and media integrity, then return the absolute Portal URL.
