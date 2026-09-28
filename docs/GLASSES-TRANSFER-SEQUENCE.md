# Gelato AR Glasses Plugin — Section 13 Ingredient Transfer & Sequence Evidence

Section 13 distinguishes **ingredient presence** from **ingredient addition**.

A high-confidence camera detection of Turkey sitting in its calibrated pan is no longer enough to count Turkey as added to the product. When a compatible station calibration includes a `build_surface` region, Gelato can require stronger physical evidence:

```
calibrated ingredient source zone
        ↓ same tracked visual instance
movement / transit
        ↓
calibrated build surface
        ↓
stable observation
        ↓
Gelato build + validation
```

## Station work regions

Section 13 extends the versioned station calibration profile with normalized station regions.

Supported region types are:

- `build_surface`
- `plate_surface`
- `handoff_surface`
- `discard_surface`

The transfer engine currently uses `build_surface`. Other types establish the stable calibration vocabulary for later finishing and workflow features.

Regions are versioned with the rest of the calibration profile and participate in its SHA-256 source hash.

## Transfer rules

Transfer evidence is active only when:

- station calibration is compatible with the current camera frame;
- the ingredient has at least one calibrated source zone;
- the profile has a `build_surface` region.

When those conditions are not met, the Section 12 visual/spatial behavior remains unchanged.

For a recipe ingredient:

1. Seeing it in its matching source zone **primes** a possible transfer.
2. Source-zone presence is held and never emitted as an `added` observation.
3. The same detector `InstanceKey` must reach the build surface within the configured frame window.
4. The build-surface evidence still has to satisfy normal temporal stability.
5. A proven transfer emits `action=added`.

If an ingredient appears directly on the build surface without a provable source transfer, it remains useful evidence but is emitted as:

```
action = seen
confidence <= 0.74
```

That keeps it below Gelato's automatic confirmation threshold and routes the component to **Verify** rather than silently counting it as complete.

## Duplicate-transfer protection

Once a tracked physical instance has completed source → build transfer, continued visibility or a short occlusion cannot create another additive event.

The same instance can count as a new transfer only after it is observed back in its calibrated source zone and then moved to the build surface again.

## Recipe sequence evidence

The active build definition supplies ordered recipe steps to the vision frame context.

A confirmed transfer receives a small bounded sequence-support boost only when its component belongs to the first incomplete ingredient-bearing build step.

Out-of-sequence recipe ingredients are not rejected: restaurant cooks may legitimately assemble in a different order. They receive transfer support but not the extra sequence support.

Defaults:

- transfer support: `+0.08`
- current-step sequence support: `+0.03`
- unprimed work-surface confidence cap: `0.74`

All boosts are bounded. Transfer or sequence evidence cannot rescue a candidate that already failed the configured visual/spatial confidence floor.

## Unexpected ingredients

When a calibrated build surface exists, an unexpected ingredient elsewhere in the camera view is not automatically treated as a product exception.

Unexpected evidence reaches Gelato validation only after it appears on the build surface and satisfies temporal stability.

This prevents a visible cheese pan elsewhere on the line from becoming a false "unexpected cheese on sandwich" alert.

## Durable explainability

Each emitted `IngredientObservation` can now carry:

- evidence kind;
- source ingredient-zone key;
- destination station-region key;
- whether sequence support applied.

The Unity gateway places this into the existing `metadata` object on `build.observe`. Gelato already persists that observation metadata, so transfer decisions remain auditable without creating a second event system.

## Safety / fallback behavior

Section 13 is conservative by design:

- source-bin presence never counts as an addition;
- a transfer needs the same detector instance when transfer proof is used;
- no work region means the previous visual/spatial workflow continues;
- no source zone means the previous visual/spatial workflow continues;
- unproven build-surface presence goes to Verify;
- station geometry never creates an observation by itself;
- KDS, validation, and Expo lifecycle rules are unchanged.
