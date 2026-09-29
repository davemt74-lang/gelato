# Vision Lab V11 Section 10 — Governed Canary Release & Automatic Safety Rollback

Section 10 keeps the existing model rollout, canary samples, health engine, stage sequence, automatic rollback, package cooldown and drift monitoring canonical.

It adds immutable V11 stage attestations for 5%, 10%, 25%, 50% and 100%. Each attestation is bound to the approved release candidate and passing Section 9 shadow validation. Stage policy increases evidence requirements as exposure grows and checks cohort sample/device coverage, location/station/operator coverage, correction/validation/low-confidence/unexpected/runtime deltas, inference latency, build duration, canonical canary health and production drift.

V11 rollout advancement requires a passing attestation for the current stage. Existing automatic rollback remains active at every stage. V11 automatic rollback applies an extended 72-hour default hold through the Section 10 enforcement path.

At 100%, a separate immutable production acceptance binds the final canary attestation back through the RC and shadow-validation lineage. Acceptance does not disable continuous drift monitoring or automatic rollback.
