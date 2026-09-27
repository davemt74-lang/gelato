# Gelato AR Glasses Plugin — Section 5 Product Validation State Machine

Section 5 converts the live build evidence into the deterministic product-validation state shown in the glasses right rail.

## States

A build validation is one of:

- `pending` — one or more required quantities are still missing;
- `blocked` — an ingredient needs verification or an unexpected component is unresolved;
- `ready_for_finishing` — every required component is confirmed and no blocking exceptions remain.

Validation does not guess and does not silently modify the KDS lifecycle.

## Right-rail contract

The validation summary exposes:

- required components
- passed components
- missing components
- Verify components
- unexpected components
- per-component expected and detected quantities
- confidence and build state
- blockers
- the active build-definition identity

When every required component is accounted for and all exceptions are resolved:

```
NEXT
Expo / Finishing
All ingredients accounted for.
```

Until then, `next.available=false` and the blocking reason remains visible.

## Quantity behavior

Validation uses the compiled recipe quantities stored in the build session. A Club Sandwich that expects three slices of bread remains pending when only two have been detected. A complete quantity with low confidence enters `verify` rather than being accepted automatically.

## Durability

Each build session owns one validation record. Re-evaluating an unchanged snapshot is idempotent. Meaningful state/evidence changes advance the validation revision and append a validation event.

## Safety boundary

Section 5 only decides whether finishing is available. It does **not** move a KDS item to Ready or Expo. That transition remains an explicit controlled handoff in the next release unit.
