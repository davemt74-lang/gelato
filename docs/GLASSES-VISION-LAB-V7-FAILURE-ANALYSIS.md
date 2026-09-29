# Vision Lab V7 Section 2 — Lineage-Aware Failure Analysis

Section 2 converts the immutable production-error ledger into auditable failure concentration analysis.

## Dimensions

Analyses group production failures by:
- error type and outcome;
- exact model package/version;
- predicted and expected component/class;
- location and station;
- device;
- menu item;
- confidence bucket;
- lighting, pose, build-step and capture-environment context when present.

The output reports failure counts and each group's share of the analyzed failure set. It does **not** claim an error rate because Section 2 does not yet carry a complete production denominator.

## Exact source binding

Every persisted analysis snapshot includes the exact source production-error public IDs and event hashes plus a SHA-256 source fingerprint. If the underlying evidence set changes, the analysis hash changes.

## Training ancestry

For every attributed model package, the analysis resolves:
`model package → training release → dataset → governed split plan → training profile`

This uses the V6 lineage graph instead of trying to infer ancestry from model names or filenames.

## Persistence

Identical filters over the exact same evidence deduplicate to the same immutable analysis snapshot. Different filters or a changed source evidence set create a different hash-bound snapshot.

## Authority boundary

Failure analysis is read-only. It does not:
- modify POS/KDS/build truth;
- change original production-error events;
- add samples to a dataset;
- retrain a model;
- advance a model rollout.
