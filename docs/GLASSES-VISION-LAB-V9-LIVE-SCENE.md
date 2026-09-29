# Vision Lab V9 Section 1 — Live Scene Understanding

V9 Section 1 creates a single governed scene snapshot for the active kitchen build.

It does not replace POS, KDS, recipes, build sessions, station calibration, model assignment, confidence policy, or V8 governance. It composes those systems into one immutable frame-level scene that later V9 sections can reason over.

## Scene inputs

An authenticated glasses device or browser simulator submits:

- active build-session public ID;
- detector name;
- frame key and UTC capture time;
- frame dimensions and pixel format;
- normalized scene entities;
- optional entity relationships.

Supported entity kinds:

- ingredient;
- tool;
- container;
- hand;
- product;
- equipment;
- surface;
- unknown.

Each entity can carry:

- stable entity key;
- detector label;
- optional canonical build component key;
- tracking ID;
- confidence;
- normalized bounding box;
- bounded structured attributes.

A component key is accepted only when it belongs to the active canonical build. Scene understanding cannot invent recipe mappings.

## Canonical context

The server binds every scene to:

- exact authenticated device;
- exact active build session;
- KDS item and status;
- POS line and order context;
- special instructions and modifiers;
- menu item;
- build definition;
- expected recipe components and current canonical component;
- exact vision model assignment key;
- exact model package and artifact SHA-256;
- exact compatible station calibration, ingredient zones and work regions.

If no compatible calibration exists, the scene remains valid but does not claim calibrated spatial matches.

## Spatial understanding

For entities with bounding boxes, the server resolves the entity center against the active compatible calibration:

- ingredient zones;
- build surface;
- plate surface;
- handoff surface;
- discard surface.

This is descriptive scene context only. It does not increase detector confidence or confirm a recipe component.

## Relationships

Section 1 accepts bounded normalized relationships between entities:

- near;
- touching;
- inside;
- on;
- held_by;
- contains;
- overlaps;
- approaching.

Both referenced entity keys must exist in the same frame.

## Recipe-step boundary

Section 1 exposes:

- declared recipe/build steps;
- current expected build component;
- current canonical component statuses.

It deliberately returns:

- `recognizedStep = null`;
- `recognizedStepReason = reserved_for_v9_section_2`.

Actual recipe-step recognition belongs to V9 Section 2.

## Immutable evidence

Every scene stores:

- source fingerprint SHA-256;
- exact assignment key;
- model artifact SHA-256;
- calibration source hash when applicable;
- per-entity SHA-256;
- relationships;
- immutable context snapshot;
- deterministic scene summary;
- scene SHA-256.

Reusing the same frame key with different evidence fails closed.

Verification rechecks:

- exact model-assignment ledger binding;
- every entity hash;
- source fingerprint;
- scene hash.

## Authority boundary

Live Scene Understanding never:

- confirms an ingredient;
- creates a build observation;
- transitions KDS;
- completes or cancels a build;
- changes calibration;
- changes confidence thresholds;
- activates, advances, pauses or rolls back a model;
- retrains a model.

It is the governed perception/context layer that V9 Sections 2–7 can reason over.
