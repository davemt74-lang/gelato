# Gelato Section 285D3 — Hand to Ingredient Movement & Placement Review

**Baseline:** Section 285D2 certified main (`3935ae0719fefee9f5835bf57e4b78796232e4b8`).

This stage adds **software-only placement hypotheses**, NOT autonomous physical verification.

## Evidence gates
- Approved ONNX read-only Camera 1 ingredient detections synchronized with fresh, unsmoothed MediaPipe hand tracks
- Explicit current KDS item, runtime, station, location, verified model SHA-256 and operator-calibrated preparation zone
- At least three distinct held-object frames over 200 ms, from outside the zone
- Observed hand motion into the calibrated zone with at least 0.14 normalized-screen travel
- Two distinct frames without held-object detection after 130 ms in the zone while the tracked hand remains visible
- A time-limited operator-review candidate, never an automatic Added, moved-on ingredient, or KDS mutation
- One-time operator Confirm / Reject produces an **ephemeral, local receipt** clearly labeled cameraPlacementVerified=false
- Candidate invalidation on new item/device/model/station/location, stale streams, model reset, runtime revocation, pagehide or detector disconnection
- Ambiguous hands/objects, vanished hands, wrong ingredient classes and detector gaps are rejected rather than inferred as successful placement

Review is **not** proof of a physical placement and does **not** update an ingredient checklist, ticket state, or Expo readiness. Human confirmation remains operator-reported. Future Section 285D4 will require its own governed, certified write permissions and physically validated placement model before automated checklist updates.

## Software acceptance
Synthetic camera sequences test pickups, movement, zone entry, potential release, negative and conflicting evidence, confidence, timestamp/replay, scope changes, reset, operator review and no-write contracts. Both glasses-simulator and full deployment CI run the new tests. Actual kitchen accuracy cannot be scored until consented field testing.
