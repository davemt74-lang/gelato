# Vision Lab V8 Section 6 — Fleet Model Health & Rollback Intelligence

Section 6 correlates Section 1 model-health snapshots and Section 2 context analyses across a model fleet before any rollback decision is made.

## Exact-window fleet evidence

A fleet analysis is bound to:
- the exact target model package + artifact SHA-256;
- an optional existing rollout;
- a UTC health window no longer than 31 days;
- verified **device-level** Section 1 health snapshots whose windows exactly match the requested fleet window;
- the rollout's location/station deployment scope when an existing rollout is supplied;
- verified Section 2 context analyses tied to those snapshots.

Exact-window matching plus device-only evidence avoids double-counting overlapping aggregate health windows. Rollout-scoped analysis never borrows evidence from devices outside the rollout's location/station scope.

## Fleet states

The deterministic fleet classifier can return:
- `insufficient` — fewer than two health snapshots/devices;
- `healthy` — no meaningful fleet-wide degradation;
- `watch` — localized or early warning signals;
- `context_shift` — widespread context change without model-degradation evidence;
- `degraded` — substantial affected/model-degradation ratio;
- `critical` — degradation/critical evidence spans multiple devices and locations.

## Recommendations

Recommendations are advisory:
- `maintain`
- `observe`
- `collect_more_health`
- `investigate_context`
- `investigate_model`
- `pause_review`
- `rollback_review`

Analysis alone never pauses or rolls back a rollout.

## Immutable evidence

Each analysis records:
- exact health snapshot IDs/hashes/source fingerprints;
- exact context-analysis IDs/hashes;
- device/location/station counts;
- affected-device/location counts;
- fleet state and recommendation;
- source fingerprint and analysis SHA-256;
- model-package → fleet-analysis lineage.

Late evidence creates a new immutable analysis; it does not invalidate or rewrite an earlier analysis. Verification therefore checks the exact referenced evidence, not the current number of records in the window.

## Governed rollback

A rollback can only execute when:
- the fleet analysis verifies;
- recommendation is `rollback_review`;
- the analysis is bound to a rollout;
- the rollout still targets the analyzed model;
- rollout status is active or paused;
- an authenticated human supplies a non-empty reason;
- the exact-window source fingerprint still matches current evidence, so late-arriving fleet health forces a fresh analysis before rollback.

Execution uses the existing canonical `glasses_vision_model_rollout_rollback` path. The fleet layer does not create a second rollout engine.

The rollback audit record binds:
- analysis ID/hash;
- rollout ID;
- target and baseline model IDs;
- explicit reason;
- resulting rollout state;
- actor user ID.

Repeated execution for the same analysis is idempotent and returns the original audit action after the rollout is already rolled back.

## Authority boundary

Section 6 never:
- automatically rolls back or pauses;
- automatically promotes;
- changes KDS/POS/build truth;
- changes confidence thresholds;
- rewrites calibration or training evidence.
