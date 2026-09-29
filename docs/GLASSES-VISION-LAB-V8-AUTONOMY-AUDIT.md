# Vision Lab V8 Section 7 — Production Autonomy Decision Ledger & Explainability

Section 7 is the V8 integration and explainability layer. It captures immutable snapshots of production perception decisions without adding new execution authority.

## Audit subjects

An autonomy audit captures exactly one subject:

- a governed **confidence decision** from Section 4; or
- a governed **fleet-health analysis** from Section 6.

The same subject can produce multiple immutable audits over time as downstream evidence changes.

Examples:

- confidence hold before recovery;
- the same decision after bounded recovery completes;
- fleet rollback recommendation before human action;
- the same fleet analysis after an authenticated rollback is executed.

Older audits remain valid replays of what was known when they were captured.

## Runtime decision explanation

Runtime audits can bind:

- exact confidence decision ID/hash;
- policy ID/hash;
- device and build session;
- exact model package/artifact SHA-256;
- Section 1 health snapshot when present;
- Section 2 context analysis when present;
- Section 3 calibration selection when present;
- Section 5 active-perception action when present;
- threshold components and effective threshold;
- authority state and outcome.

Authority/outcome examples:

- governed runtime / accepted;
- governed runtime / below-threshold hold;
- human required / human review;
- bounded recovery / recovery in progress;
- bounded recovery / recovery completed;
- human authorized / human confirmed or rejected.

## Fleet decision explanation

Fleet audits can bind:

- exact fleet-health analysis/hash/source fingerprint;
- target model artifact;
- rollout and baseline model identity;
- fleet state and recommendation;
- device/location/station evidence counts;
- Section 6 governed rollback action when present;
- explicit rollback reason.

A rollback recommendation is recorded as `human_required`. A completed explicit rollback becomes a new `human_authorized / rollback_executed` audit.

## Replay

`autonomy_audit.replay` returns only stored, verified evidence and explanation.

Replay always declares:

- `replayOnly=true`;
- `changesProductionState=false`.

It never:

- transitions KDS;
- changes build state;
- confirms an ingredient;
- changes calibration;
- changes confidence thresholds;
- advances or rolls back a model;
- retrains a model.

## Integrity

Every audit is SHA-256 addressed over:

- subject kind/identity;
- authority state;
- outcome;
- exact evidence references/hashes;
- deterministic explanation.

Verification revalidates the referenced Section 1–6 records. Audit rows use `RESTRICT` foreign keys so deleting individual production records cannot silently erase decision history.

## V8 complete chain

The production perception chain is now:

`model health → context drift → calibration selection → confidence policy → active perception/human recovery → fleet rollback intelligence → immutable autonomy explanation`.

This is the final V8 governance layer.
