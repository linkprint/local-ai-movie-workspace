# Style Blueprint

## Viral Evidence

The 1,322-vote Reddit post [Pushing Minimax H3 V2V to the Absolute Limit](https://www.reddit.com/r/StableDiffusion/comments/1vp9nvj/pushing_minimax_h3_v2v_to_the_absolute_limit/) demonstrates a clean role split: a source video supplies exact motion and camera timing, a creature image supplies identity, and a location image supplies nocturnal lighting. Its prompts explicitly retain doors, toys, shadows, and contact timing. Use that architecture only with authorized original media.

## True Ref2VA Pattern

Define `<Video 1>` as the source edit and temporal structure; define the creature and optional lighting as separate subjects; state what is fully preserved, transferred, or replaced; then describe every prop interaction in playback order. The current Broker cannot render this mode.

## 10-Second T2VA Style Demo

This no-reference demo shows the cinematic visual language only; it is not proof of motion replacement.

```text
integrated_multimodal_description: [Shot 1] Live-action cinematic night scene inside a small blue-lit apartment hallway. An original feathered marsh raptor, about the size of a large dog, has stable birdlike anatomy, two powerful hind legs, two small forelimbs, a long counterbalancing tail, dark indigo feathers, and amber eyes. The camera holds a low static angle toward a closed wooden door. At 00:01.000, the brass handle rotates from the other side and the door opens inward. The raptor gently pushes through with its shoulder, keeping both feet on the floor and casting a crisp moving shadow across the door. It steps over the threshold with realistic weight; each claw clicks on the wood. At 00:05.000, its left foot accidentally nudges a small red toy truck. The truck rolls forward in a straight line, wheels spinning and reflecting blue light. The raptor freezes, lowers its head to track the truck, then carefully stops it with one claw without crushing it. At 00:08.000, the raptor looks toward the unseen room, gives one quiet curious chirp, and closes the door with the side of its tail. Keep anatomy, feather pattern, scale, lighting, door geometry, and toy position physically consistent. No humans, injury, subtitles, logos, watermarks, or text.

overall_soundscape: Low apartment hum, brass latch click, door hinge creak, rhythmic claw taps, plastic wheels on wood, feather rustle, one soft chirp, and a muted door close.

non_diegetic_music: A low sustained synthesizer tone with two sparse bass pulses, fading after the door closes.
```
