# Gelato Section 285D1 — Hand Box Stabilization

Baseline: certified Section 285C main `518d143adede756b61149b3d33285518b2b5f75b`.

Display-only stabilization of the Camera 1 MediaPipe hand overlay:
- Adaptive box interpolation removes minor jitter and avoids large-movement lag.
- Separate left/right slot and track-ID state. Identity changes reset interpolation.
- Up to 180ms occlusion hold displays a dim, dashed REACQUIRING box.
- Lost tracks, stale/replayed timestamps, invalid boxes, camera stop, pagehide and model reset clear visual state.

Security and KDS boundaries: Original hand observations, held ingredient evidence, model confidence, ingredient state, station scoping and KDS commands are **unchanged**. Smoothed/held boxes do not feed ingredient inference, the Agent, build.observe or KDS. No extra camera permission or network request.

Acceptance: 10 unit/integration gates run in glasses simulator and deployment packaging CI. Field latency, accuracy, HDMI capture throughput and real ingredient placement remain unverified until real kitchen testing.

Next: 285D2 cross-camera temporal evidence correlation, followed by 285D3 hand-to-food placement verification.
