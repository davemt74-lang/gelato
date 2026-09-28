# Gelato AR Glasses Plugin — Section 12 Spatial Evidence Fusion

Section 12 fuses the Section 10 visual-recognition signal with the Section 11 calibrated station layout.

The fusion is intentionally conservative:

```
visual candidate is required
        +
active recipe component mapping
        +
optional calibrated station evidence
        ↓
effective observation confidence
```

A station zone never creates an ingredient observation on its own.

## Evidence rules

For a normal recipe ingredient detection:

- if the center of the visual bounding box falls inside a calibrated zone for the **same canonical ingredient**, the candidate receives a small bounded confidence boost;
- if it falls inside a calibrated zone for a **different ingredient** and no matching zone also contains it, the candidate receives a confidence penalty;
- if it falls outside all relevant zones, visual confidence is unchanged;
- if calibration is absent or camera-incompatible, visual confidence is unchanged.

Matching spatial evidence wins over a conflicting overlapping zone so deliberate nested/overlapping station maps remain deterministic.

Unexpected detections never receive a recipe-zone confidence boost.

## Default limits

The default policy uses:

- spatial support boost: `+0.06`
- spatial conflict penalty: `-0.20`

Both are configurable inside bounded option ranges.

The existing raw visual minimum is checked **before** spatial fusion. A visually weak detection that fails the detector floor cannot be rescued just because it appeared near an ingredient bin.

This is important: physical location is corroborating evidence, not a substitute for seeing the ingredient.

## Example

A Turkey candidate with raw confidence `0.81` whose center is inside the calibrated Turkey pan becomes:

```
0.81 + 0.06 = 0.87
```

That stronger evidence may allow the existing Gelato build validator to confirm a complete expected quantity.

The same Turkey candidate at `0.93` inside the calibrated Bacon pan becomes:

```
0.93 - 0.20 = 0.73
```

Gelato's existing confidence rules can then route that evidence through Verify rather than silently accepting it.

## Pipeline placement

Fusion occurs only after:

1. the detector meets the raw confidence floor;
2. the detection has a valid normalized bounding box;
3. the label/component maps deterministically to the active build.

It happens before temporal tracking emits the durable `IngredientObservation`, so the observation sent to Gelato carries the effective fused confidence.

Temporal stability, idempotent track keys, build validation, and Expo handoff remain unchanged.

## Diagnostics

Per-session vision diagnostics now include:

- spatial supports;
- spatial conflicts.

These metrics are for calibration/model tuning and do not replace durable Gelato build/validation event history.

## Scope boundary

Section 12 uses calibrated 2D ingredient zones. It does not yet:

- infer station zones automatically;
- use hand trajectories;
- identify the source bin of a hand transfer;
- fuse 6DoF world anchors;
- train or select the final production ingredient model.

Those can be layered on top without changing the recipe, KDS, build, validation, or calibration contracts.
