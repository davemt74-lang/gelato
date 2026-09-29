# Vision Lab V8 Section 5 — Active Perception & Recovery Actions

Section 5 converts a governed Section 4 confidence hold into a bounded perception-recovery instruction for the authenticated glasses runtime.

It does not alter kitchen truth or silently change model behavior.

## Recovery planning

A recovery plan is created only from an intact confidence decision bound to:
- the authenticated device;
- an active build session;
- the exact current model assignment key and ready model artifact;
- the active confidence policy;
- an optional verified Section 2 context analysis;
- an optional verified Section 3 calibration selection.

The confidence decision must still be fresh. Recovery expires ten minutes after the original decision.

## Governed recovery actions

Each plan always holds validation before requesting more evidence.

Possible steps include:
- `capture_additional_frame`;
- `change_viewpoint`;
- `pause_validation`;
- `request_human_confirmation`;
- `use_calibration_selection`.

Examples:
- low confidence with insufficient/stable context → pause validation + capture one additional frame;
- context shift with a verified calibration selection → use that existing selection, then capture another frame;
- context shift without a verified calibration → change viewpoint and recapture;
- model degradation, mixed/ambiguous evidence, unmapped labels, or an explicit human-review decision → request human confirmation.

Each confidence decision permits one recovery attempt. New perception evidence must produce a new confidence decision before another recovery plan can be created.

## Device lifecycle

Authenticated device actions:
- `vision.active_perception.plan`;
- `vision.active_perception.acknowledge`;
- `vision.active_perception.complete`.

A device can complete sensor/perception recovery such as a successful recapture. It **cannot** resolve a human-confirmation action.

Human confirmation is resolved through the authenticated Vision Lab management API and is bound to the resolving user in the completion hash.

## Immutable evidence

Every action records:
- confidence decision ID/hash;
- policy ID/hash;
- device + build session;
- exact model package/artifact SHA-256;
- optional context-analysis ID/hash;
- optional calibration-selection ID/hash;
- deterministic recovery instructions;
- deterministic expiration;
- action SHA-256;
- completion SHA-256 when completed.

The current model assignment is rechecked before planning. A confidence decision becomes unusable for recovery if the assignment key changes, even when the replacement assignment points at the same model package.

## Authority boundary

Section 5 never:
- transitions KDS;
- completes/cancels the build session;
- activates, advances, pauses, or rolls back a model rollout;
- changes confidence thresholds;
- retrains a model;
- rewrites calibration profiles.

It issues and audits bounded recovery instructions only.
