# Section 282 — Held Ingredient Evidence & Training Review

This extends certified Section 281 without adding a second camera, KDS action endpoint, or training database.

**Runtime → Camera 1 → Live hands:** MediaPipe continues to track two hands independently. A held ingredient suggestion requires an *additional* governed, checksum-verified ONNX ingredient detector (a hand-only model cannot distinguish mozzarella, sauce or basil). The existing governed model currently loads through an authorized live build session; without it, show real hand tracks and a clear "ingredient model not loaded" status, never invented labels.

For each real object-model inference frame:
- Only the selected active KDS item's first **three remaining configured ingredients** qualify. When an operator marks one added/skipped, the focus advances.
- The detector label must match the configured ingredient or its existing reviewed mapping. No fuzzy catalog-wide guessing.
- Valid boxes must be sufficiently near exactly one observed hand. Ambiguous inter-hand proximity is discarded.
- A suggestion requires **three distinct fresh inference frames** within the temporal window; reusing the same result 8 times while the hand model runs cannot satisfy this threshold.
- Expired detections, incorrect model SHA-256 metadata, switched orders, newly registered hand identities and changes to the completed ingredient list invalidate relevant evidence.
- Suggestions explicitly say **POSSIBLE** or **REVIEW**. No automatic checkbox progress, POS/KDS writes, identity verification or order completion is allowed.
- The operator can capture Camera 1 photos locally and open the existing Camera → Training review workflow. Uploading to private Vision Lab requires explicit consent. Existing supervised training requirements remain in force.

Regression gate: node tests/glasses-held-ingredient-evidence.test.js, plus all Section 280/281 camera, voice, KDS, Runtime and deployment checks.

**Next independent dependency:** Train and approve ingredient classes for a governed ONNX model, or extend model authorization with an explicitly reviewed KDS read-only inference assignment. This section does not claim out-of-box classification accuracy from MediaPipe alone. Physical iPhone/Windows Chrome validation remains required.