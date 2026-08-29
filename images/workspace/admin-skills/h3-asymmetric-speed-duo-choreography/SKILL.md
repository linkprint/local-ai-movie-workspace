---
name: h3-asymmetric-speed-duo-choreography
description: Create MiniMax H3 prompts for two original adult performers whose movement is governed by an explicit asymmetric action-count ratio, usually 3:1. Use for leader-follower dance, fashion movement, training, or character comedy where one subject deliberately imitates late and never catches up; do not use for synchronized duets or speed-ramped montage.
---

# H3 Asymmetric Speed Duo Choreography

Turn “one fast, one slow” into a countable movement law that remains visible throughout one continuous shot.

## Route the Request

- For prompt writing or refinement, return text only.
- Read [references/style-blueprint.md](references/style-blueprint.md) and use `$h3-prompt-writing` for H3 field order, timing, audio, and negatives.
- Invoke `$h3-video-generation` only when the user explicitly asks to generate or render.
- Use T2VA for original subjects. Use I2VA only when one supplied image defines the exact opening composition. Use native Ref2VA for identity-only or separately layered image/video references.

## Lock the Ratio

1. Assign performer A as the fast leader and performer B as the slow follower. Give them distinct adult identities, wardrobe, screen positions, and silhouettes.
2. Define one action as a complete readable movement, not a frame or micro-twitch.
3. State the invariant twice: whenever A completes three full actions, B completes exactly one full action.
4. Make B watch A and imitate an earlier action after a deliberate delay. B never catches up, synchronizes, anticipates, or suddenly accelerates.
5. Write a beat ledger such as `A1/A2/A3 -> B1`, then repeat with new actions. Preserve the 3:1 count even when camera motion or music intensifies.
6. Use one continuous close orbit or restrained tracking shot, usually knees-to-head or waist-to-head, so both action counters remain visible.
7. End with the ratio still unresolved; do not reward B with a synchronized finale.

## Render Rules

- Keep one render to 4–15 seconds. Ten seconds should contain two or three complete 3:1 cycles, not dense uncountable motion.
- Default to `864x480`; allow `960x544` when requested. Explicit 768p means `1344x768` and may never be downgraded.
- Use only original adults and original instrumental music. Exclude named characters, celebrity likeness, existing songs, adult content, nudity, sexualized choreography, minors, duplicate bodies, identity swaps, sudden time remapping, cuts, subtitles, logos, and watermarks.

## Output

Return mode, subject map, action ledger, duration/resolution, complete H3 prompt, ratio locks, and Broker limitations. For a real render, verify the action counts cycle by cycle before returning the absolute Portal URL.
