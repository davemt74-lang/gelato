# Vision Lab V6 — Curated Dataset Builder & Group-Aware Split Governance

Vision Lab V6 replaces filename-level dataset splitting with governed curation and lineage-aware split planning.

## Curated Dataset Builder

Every draft dataset member can receive append-only curation events.

Decisions:

- include;
- exclude.

Every decision requires:

- curator identity;
- rationale;
- timestamp.

The current state is derived from the latest event. Historical decisions are never overwritten.

Applying a split plan removes samples whose current curation state is `exclude` from the draft dataset. Frozen datasets remain immutable.

## Protected lineage grouping

The split engine creates connected evidence groups before assigning train / validation / test.

Default protected dimensions are:

- browser capture group / burst;
- production build session.

If two samples share any enabled protected dimension, they are placed into the same connected group and can never cross dataset splits.

This closes the filename/hash leakage weakness in the original training pipeline.

## Optional environment holdout grouping

Operators may additionally enable:

- operator/wearer;
- location;
- station;
- menu item.

These are intentionally optional.

For example, grouping every sample from one restaurant location together is useful when testing location holdout generalization, but it may collapse a small dataset into fewer than three independent groups. V6 rejects a split plan when fewer than three independent groups remain.

## Deterministic group splitting

A split plan records:

- dataset;
- seed;
- train/validation/test ratios;
- grouping policy;
- connected group keys;
- per-sample assignments;
- counts;
- deterministic plan SHA-256.

The same dataset, curation state, policy and seed produce the same plan hash and assignments.

Default ratios are:

- train 70%;
- validation 15%;
- test 15%.

Assignments are made at the group level, never the individual member level.

## Explicit application

Creating a plan does not mutate dataset membership or split assignments.

An operator must explicitly choose **Apply split**.

Applying the plan:

1. removes currently excluded samples from the draft dataset;
2. assigns every included sample to the planned split;
3. preserves all samples in a protected group in one split;
4. marks the plan applied with actor/time.

## Freeze gate

New dataset freezes require an applied V6 group-aware split plan.

The existing V4 and V5 gates still apply:

- no build-session leakage;
- no unresolved review disagreement;
- no exact duplicate retained media;
- no capture-group leakage;
- no poor-quality retained media.

Historical frozen datasets remain immutable/idempotent.

## Python training pipeline

`tools/vision_training/pipeline.py` now uses lineage-aware deterministic grouping when the source manifest provides:

- `captureGroup`;
- `buildPublicId`.

Capture group takes precedence, then build session.

The Web Glasses Simulator now includes capture-group and burst lineage in `gelato-manifest.json`.

If a legacy dataset has no grouping metadata, the Python pipeline remains backward compatible but records the split provenance as:

`sample_fallback`

instead of claiming group-aware protection.

If grouping metadata exists but fewer than three independent groups remain, training preparation fails rather than leaking related evidence across train/validation/test.

## Authority boundary

V6 affects only Vision Lab dataset governance.

It does not mutate:

- POS;
- KDS;
- production observations;
- product validation;
- Expo;
- calibration;
- model rollout state.

## Next V6 sections

After curated membership and split governance, the next sections are:

- dataset balance/diversity optimizer;
- annotation QA/agreement;
- training-release builder;
- pre-training qualification gate;
- dataset → model lineage and traceability.
