# Gelato AR Glasses Plugin — Section 15 Human Correction & Learning Ledger

Section 15 adds a non-destructive human correction layer to AR vision evidence.

The core rule is:

> Never rewrite the model observation. Preserve what the system saw, append what the human corrected, and recompute build state from the effective evidence.

## Why this is needed

Vision will occasionally:

- count an ingredient that was visible but not added;
- identify the wrong ingredient;
- estimate the wrong quantity;
- trigger a false unexpected-ingredient exception.

Before real kitchen rollout, a cook or manager must be able to correct those mistakes without corrupting the event history.

## Immutable observation + append-only correction

Original rows in `glasses_build_observations` remain unchanged.

Human corrections are written to `glasses_observation_corrections`.

Each correction contains:

- build session;
- original observation;
- idempotent correction key;
- resolution;
- replacement component, when applicable;
- corrected quantity, when applicable;
- reason;
- actor glasses device;
- optional metadata;
- timestamp.

Supported resolutions:

- `reject` — the observation contributes nothing to current build state;
- `replace` — use the original observation as evidence for a configured recipe component, optionally with a corrected quantity.

Multiple corrections may exist for the same observation. The latest correction is the effective one, while every earlier human decision remains in the ledger.

## Deterministic build recomputation

After a correction, Gelato recomputes all component quantities/statuses from:

1. immutable original observations;
2. the latest correction for each observation;
3. explicit manual `component_confirmed` events;
4. explicit `unexpected_resolved` events.

This prevents delta drift and makes repeated correction sequences deterministic.

The recomputation preserves explicit human decisions:

- a manually confirmed component stays confirmed when unrelated evidence changes;
- an explicitly resolved unexpected component stays ignored;
- rejecting an observation can revoke `ready_for_finishing`;
- restoring/replacing evidence can make the product valid again.

The KDS lifecycle is never changed by a correction.

## Reclassification safety

A `replace` correction may target only an existing configured recipe/build component.

It cannot create a new arbitrary component or reclassify evidence into an unexpected component. Unknown ingredients continue to use the existing unexpected-evidence path.

## Device API

New device actions:

### Correct an observation

```json
{
  "action": "build.correct_observation",
  "buildSessionPublicId": "build-...",
  "correctionKey": "reject:vision-build-1-track-7",
  "observationKey": "vision-build-1-track-7",
  "resolution": "reject",
  "reason": "Wrong transfer"
}
```

Replacement example:

```json
{
  "action": "build.correct_observation",
  "buildSessionPublicId": "build-...",
  "correctionKey": "replace:vision-build-1-track-7:1",
  "observationKey": "vision-build-1-track-7",
  "resolution": "replace",
  "targetComponentKey": "ingredient:84",
  "correctedQuantity": 2,
  "hasCorrectedQuantity": true,
  "reason": "This was Bacon, not Turkey"
}
```

### Review evidence

```json
{
  "action": "build.evidence",
  "buildSessionPublicId": "build-...",
  "limit": 20
}
```

The evidence response preserves the original observation and attaches its latest correction separately.

## Unity / AIR3 client

`IGelatoGateway` now supports:

- `CorrectObservationAsync`
- `GetEvidenceAsync`

`ArWorkflowCoordinator` adds:

- `CorrectObservationAsync`
- `RejectObservationAsync`
- `RejectLastObservationAsync`
- `GetEvidenceAsync`
- `LastSubmittedObservationKey`

The last-observation cursor is cleared whenever work/build state changes so a correction can never accidentally target the prior menu item.

The desktop simulator maps **Z** to Reject Last Observation, making the correction loop testable before final INMO button/gesture bindings are available.

## Learning value

The correction ledger deliberately keeps both sides of the event:

- what the detector originally classified;
- what component/quantity the human said was correct;
- why it was rejected or replaced;
- associated vision metadata such as transfer source/destination and sequence support.

That produces a future model-evaluation/training signal without changing production recipe truth or requiring a second vision database.

## Scope boundary

Section 15 creates the correction and learning foundation. It does not automatically retrain or deploy a model from those corrections. Model training/export and governed rollout remain separate release units.


## Release gate

Section 15 is not eligible for PR/merge until the correction ledger contract, deterministic recomputation, AIR3 client workflow, all prior glasses/KDS regressions, and upgrade idempotence pass on the exact feature head.
