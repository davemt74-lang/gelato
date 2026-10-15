# FM-G7A — Ambient Floating and Adaptive Engagement Zoom

Food Monster now drifts gently across the available viewport when not being directly controlled. Motion stops on hover, during drag, while minimized, during the agent's speaking or thinking cycle, in open companion dialogs, when the browser tab is hidden, and when reduced motion is requested.

The existing drag handle is the highest priority: releasing a drag suspends roaming and camera-follow for nine seconds. Manual +/- sizing stores a personal preferred baseline, applies immediately, and pauses automatic zoom for twelve seconds. The roaming toggle remembers the preference on regular Gelato pages.

The sizing model uses these engagement states: speaking grows most, alerts and replies have an attentive intermediate size, thinking is slightly larger, while passive listening, idle, and sleepier states are smaller. Camera proximity independently contributes to size when the existing *Grow/shrink with distance* option is enabled, even if positional camera-follow is disabled. It never automatically requests camera or microphone access.

The animation calculates displacement by elapsed time rather than assuming a frame rate. Viewport bounds are recalculated against the actual Agent chat footer, including changing sizes. Existing Agent Brain permissions, chat routing, MediaPipe hand tracking, and face enrollment logic remain unchanged.

Reduced-motion users get no autonomous roaming or engagement zoom. Explicit opt-in face-follow continues functioning to preserve existing camera controls and compatibility tests.

## Acceptance gate

Run `node tests/food-monster-g7a-browser.test.cjs` with Playwright installed. The existing FM-G1–G7 test suites must also remain green on the exact PR head before merge. Manual production validation on an installed Gelato site is still recommended.
