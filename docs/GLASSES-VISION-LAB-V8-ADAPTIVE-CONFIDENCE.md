# Vision Lab V8 Section 4 — Adaptive Confidence & Decision Policy

Section 4 adds a governed decision layer between detector confidence and production acceptance.

## Non-negotiable floors

The runtime has a permanent global confidence floor of **0.50**. A policy cannot lower it.

The effective threshold is built from the strictest applicable controls:
- global 0.50 hard floor;
- policy hard floor;
- policy default threshold;
- existing Vision Label per-label minimum confidence;
- optional class-specific policy floor.

A context adjustment may only be zero or positive. It can raise the effective threshold by up to 0.30; it can never lower a threshold.

## Context behavior

Default governed behavior is:
- stable: no adjustment;
- context_shift: +0.05;
- model_degradation: +0.10 and human review;
- mixed: +0.15 and human review;
- ambiguous: +0.10 and human review;
- insufficient_context: +0.10 and human review.

Organizations may make these rules stricter, but negative deltas are rejected.

## Decisions

The decision result is one of:
- `accept`: mapped label, confidence clears the effective threshold, and no human-review gate applies;
- `human_review`: confidence clears the threshold but governance requires a person;
- `below_threshold_hold`: confidence is below the governed threshold;
- `unmapped_hold`: detector label is not mapped to the active recipe.

The decision layer does not itself add an ingredient, advance KDS, validate a product, modify calibration, change a model, or mutate a rollout.

## Production device integration

The authenticated glasses endpoint exposes `vision.confidence_decision`. The device identity is supplied by bearer authentication rather than trusted from request JSON.

The runtime binds decisions to:
- authenticated device;
- build session;
- detector;
- current model assignment when present;
- exact label-profile hash;
- optional verified Section 2 context analysis;
- applicable Section 3 calibration selection when one exists;
- active confidence policy and policy hash.

Machine-originated decision lineage uses the authenticated device for operational attribution while the nullable lineage user field remains empty; no fake human actor is created.

## Policy lifecycle

Policies are immutable definitions with draft → active → retired lifecycle events.

Only one active policy may exist for the same detector/model scope. A retired policy cannot be reactivated. Model-scoped policies override generic detector policies.

## Audit

Every decision has canonical evidence and a SHA-256 decision hash. Verification re-checks the policy, Section 2 analysis, and Section 3 calibration selection when those dependencies apply.

The full decision remains advisory with respect to kitchen state; the existing build/validation authority remains unchanged.
