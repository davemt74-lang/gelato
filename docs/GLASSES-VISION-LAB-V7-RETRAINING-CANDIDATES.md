# Vision Lab V7 Section 4 — Retraining Candidate Builder

Section 4 turns reviewed mining results into a governed **draft** Vision Lab dataset without bypassing any V6 controls.

## Eligibility

A mined candidate can be included only when it resolves to:
- an existing human-approved Vision Lab training sample;
- active training media retained under explicit `training_media_opt_in` consent;
- a non-poor media quality state;
- a retained media file that still exists and matches its stored SHA-256;
- ground truth compatible with the production correction.

False-positive counterexamples must be reviewed as true negatives. Misclassifications and misses must have approved ground truth matching the expected component.

## Deduplication and capture control

Before review, the builder suppresses:
- exact duplicate image SHA-256 values;
- additional examples from the same capture group.

The retained item carries a hash-bound evidence record referencing only public IDs/hashes. Private storage paths are never placed in the batch manifest.

## Human review

Every eligible candidate begins in `pending` state. A reviewer must explicitly include or exclude it. Exclusions require a reason.

A dataset cannot be built while any eligible candidate is still pending.

## Dataset handoff

Building a reviewed batch creates a normal Vision Lab dataset in `draft` state and adds only explicitly included approved samples.

The builder does **not**:
- generate a split plan;
- freeze the dataset;
- qualify it for training;
- start model training;
- activate a rollout.

The resulting draft must continue through the normal V6 curation, group-aware split, freeze, training-release, qualification, training lineage and rollout governance.

A durable lineage edge connects the mining run to the candidate dataset.
