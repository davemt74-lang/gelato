# Vision Lab V8 Section 3 — Calibration Profile Runtime

Section 3 adds governed, environment-specific calibration selection on top of the existing station calibration system.

It does **not** replace station calibrations. Existing calibration versions remain the spatial truth. V8 profiles reference those immutable versions and define when each version is appropriate.

## Profile contract

A profile binds:
- a station calibration public ID + source hash;
- location and station;
- optional model package + artifact SHA-256;
- device/runtime platform and calibration frame geometry;
- explicit context rules;
- operator-defined priority;
- hard governance guardrails;
- SHA-256 profile identity.

Profiles are created in `draft` state and require explicit activation. Retired profiles cannot be silently reactivated.

## Context rules

Profiles can constrain:
- brightness;
- contrast;
- camera pitch;
- camera yaw;
- camera roll;
- menu signatures;
- ingredient signatures.

Frame width, frame height, pixel format and platform are hard compatibility checks rather than soft scores.

## Selection

Selection is deterministic:

1. organization/location/station must match the paired device;
2. optional model package must match;
3. runtime platform/frame/pixel format must be compatible;
4. every specified context rule must match;
5. priority ranks compatible profiles;
6. rule specificity breaks equal-priority ties;
7. stable public ID breaks any remaining tie.

If no governed profile is compatible, the runtime asks the existing station-calibration subsystem for its current active calibration. If that calibration is also incompatible with the runtime signature, selection returns `none` rather than guessing.

## Audit

Every selection records:
- device public ID;
- model package/artifact identity when supplied;
- optional verified Section 2 analysis;
- canonical live-context fingerprint;
- exact runtime signature;
- selected profile hash or fallback calibration source hash;
- deterministic decision and match score;
- SHA-256 selection hash.

Profile and selection verification re-hash their evidence. Selection identity includes the runtime signature so camera-format changes cannot alias to an earlier decision.

## Authority boundary

Section 3 does not:
- rewrite station calibration geometry;
- alter confidence thresholds;
- change the deployed model;
- activate/advance/rollback a model rollout;
- mutate POS, KDS or build truth.

It only returns a governed calibration decision that later perception/runtime code may explicitly consume.
