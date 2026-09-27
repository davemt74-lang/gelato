# Gelato AR Glasses Plugin — Section 3 Build Session Engine

Section 3 adds persistent AR build state without replacing Gelato recipes, POS, or KDS.

## Session model

A build session belongs to one paired glasses device and one KDS order item. Starting the same item again on the same device is idempotent. A second device cannot silently take over an active session.

At session start, Gelato snapshots:

- KDS item identity
- POS line / ticket identity
- special instructions
- structured modifiers
- the canonical menu ingredient list

The ingredient list is intentionally quantity-neutral in Section 3: each canonical ingredient begins as one expected portion. Recipe-specific quantities and ordered build steps belong to the next recipe/build-definition release unit.

## Component states

Expected components move through:

`waiting → detected → confirmed`

Low confidence becomes `verify` rather than a failure. Unknown vision components become `unexpected` and must be explicitly resolved.

The session summary exposes required, confirmed, verify, unexpected, current component, and an `accounted` flag.

## Observation contract

Vision clients send a unique `observationKey`. Replaying the same observation is safe and does not double-count ingredients.

Supported observation actions:

- `added`
- `removed`
- `seen`

The event stream is append-only. Bounding boxes and tracking IDs may be attached to observations for the future AIR3 renderer.

## Safety boundary

Section 3 does not transition KDS status. It records build evidence only. KDS handoff to Ready/Expo remains a later controlled release unit.
