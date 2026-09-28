# Vision Lab V3 — Active Learning Automation

Vision Lab V3 turns existing production vision signals into prioritized training work without promoting machine output to ground truth.

## Signal sources

The active-learning scanner currently mines:

- human-corrected production observations;
- low-confidence observations below 0.75 confidence;
- champion/challenger shadow disagreements;
- critical shadow mismatches;
- unresolved production drift incidents;
- canary validation/runtime/low-confidence failures;
- production classes with fewer than 20 approved samples.

## Candidate ledger

Every opportunity becomes an idempotent candidate identified by a deterministic source key.

Candidates record:

- source type/reference;
- optional observation/device/location/station/operator;
- canonical label;
- priority score;
- severity;
- reason/evidence;
- suggested mission title;
- suggested sample/positive/negative/hard-example targets;
- lifecycle status;
- created mission/sample lineage.

Repeated scans do not duplicate unchanged candidates.

## Priority

The queue ranks operationally important evidence first.

Examples:

- critical model disagreement / critical drift: near 100;
- corrected production evidence: high priority;
- canary validation/runtime failure: high priority;
- low-confidence observations: confidence-sensitive priority;
- rare-class coverage gaps: priority based on production frequency and approved coverage.

Priority is a training-work ordering signal, not an accuracy score.

## Automatic review-queue intake

Observation-backed correction and low-confidence candidates may be inserted into the Vision Lab review queue automatically.

They are always created as `pending`.

The scanner never:

- approves a sample;
- relabels a sample;
- adds a sample to a frozen dataset;
- freezes a dataset;
- trains a model;
- activates a rollout.

A human reviewer still establishes ground truth.

## Mission generation

Each candidate includes a proposed mission profile.

Operators explicitly choose **Create mission**.

The created mission retains:

- source candidate lineage;
- source evidence;
- location/station scope;
- device scope when available;
- operator assignment when attributable;
- sample, positive, negative and hard-example targets.

Queued samples are attached to the accepted mission without changing their review status.

## Dismissal

Candidates can be explicitly dismissed with a required reason.

Dismissal remains in the ledger so future scans remain idempotent and the training team can see that the signal was considered.

## Authority boundary

Vision Lab V3 is a learning/governance layer.

It does not mutate:

- POS;
- KDS;
- production build observations;
- product validation;
- Expo handoff;
- calibration;
- model rollout state.

## Recommended operating loop

Production → active-learning scan → prioritized candidate → pending review evidence → mission acceptance → human review → approved dataset → immutable freeze → model training/evaluation → governed rollout.
