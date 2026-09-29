# Vision Lab V6 Section 7 — Dataset Lineage & Model Traceability

Section 7 makes the full training-to-production chain queryable and hash-addressed.

## Trace chain

`dataset → applied split plan → training release → passing qualification → completed training run → model package → shadow/canary/production lifecycle`

Every durable transition records public IDs plus SHA-256 evidence where available. A completed training run can bind to a model package only when the model artifact SHA-256 exactly matches the run output SHA-256.

## Immutable lineage rules

- Training-run registration is for completed runs only.
- A completed run must name the exact qualified release and passing qualification hash.
- Re-registering the same run key is idempotent only when all immutable evidence matches.
- Conflicting reuse of a run key fails closed.
- Lineage edges are append-only/idempotent identities. Existing edges are never rewritten with different hashes or evidence.
- Model-package binding requires exact artifact-hash equality.

## Queries

The Vision Lab exposes:
- model trace: exact release, dataset, split plan, training profile, images/labels, curation rationale, run metadata, comparison evidence, rollouts, shadow runs, canary history, and production assignment footprint;
- sample trace: datasets/curation decisions, releases, and model versions that used the sample;
- dataset diff: added, removed, and materially changed sample lineage across dataset versions.

## Authority boundary

Lineage is observational and auditable. It does not change POS/KDS truth, advance build sessions, alter production validation, or bypass rollout governance.
