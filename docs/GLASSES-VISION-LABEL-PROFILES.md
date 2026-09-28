# Gelato AR Glasses Plugin — Section 18 Vision Label Registry & Detection Profiles

Section 18 decouples machine-learning class labels from Gelato recipe names.

A detector may emit labels such as `turkey_slice`, `bacon_strip`, or `romaine_leaf`. Gelato maps those labels to canonical recipe ingredients at runtime instead of requiring the model to know Gelato component/database IDs.

## Registry model

Each organization can define mappings with:

- Gelato ingredient;
- detector name;
- model label;
- normalized label;
- optional minimum detector confidence;
- active/inactive status;
- operator notes.

Detector `*` means a generic mapping.

A detector-specific mapping overrides a generic mapping with the same normalized label.

## Fail-closed precedence

Precedence is resolved **before** recipe filtering.

Example:

- generic `mystery_slice → Turkey`;
- `food-model-v3` override `mystery_slice → Swiss Cheese`;
- current recipe requires Turkey but not Swiss Cheese.

For `food-model-v3`, Gelato does **not** fall back to the generic Turkey mapping. The detector-specific Swiss Cheese mapping wins first and is then removed because Swiss Cheese is not part of the active recipe. The label therefore has no usable mapping and the detection is rejected.

This prevents a detector-specific truth from being silently replaced by a generic recipe-compatible alias.

## Runtime profile

The glasses request:

```
vision.profile
```

with:

- active build-session public ID;
- detector name.

Gelato returns schema:

`gelato.vision_label_profile.v1`

containing mappings usable by that active build after precedence is resolved, plus an explicit `blockedLabels` set for labels whose winning registry mapping intentionally targets an ingredient outside the active recipe.

The response includes a deterministic SHA-256 `profileHash` over both usable mappings and blocked labels.

Device/build isolation is enforced by the existing AR build-session authorization contract.

## Resolution order

For normal recipe evidence, `VisionPipeline` resolves in this order:

1. explicit component key from a trusted adapter/test detector;
2. active Vision Label Profile;
3. reject any explicit blocked label;
4. exact normalized recipe display-name fallback only when the label is not blocked;
5. reject.

Unexpected detections continue to use the explicit unexpected-evidence path and are never legitimized by a recipe label profile.

A profile from another build session or another detector is ignored.

Duplicate client mappings for one normalized label fail closed.

If the governed profile cannot be loaded because of a transport/runtime error, automated vision evidence is held fail-closed and the client retries. The cook workflow, manual confirmations, and KDS flow remain available; the client does not silently downgrade to ungoverned recipe-name matching.

## Confidence thresholds

The normal global vision floor is applied first.

An optional mapping threshold can only **tighten** the raw detector-confidence requirement.

For example:

- global floor: `0.50`;
- `turkey_slice` profile floor: `0.85`;
- raw detector confidence: `0.82`.

The candidate is rejected before spatial or transfer evidence can boost it.

Thresholds are constrained to `0.50–1.00`.

The profile threshold is a policy on raw model confidence. Spatial/transfer evidence is applied only after that model-confidence policy passes.

## Observation explainability

Every emitted profiled observation can retain:

- original detector label;
- whether a registry mapping matched;
- applied profile minimum confidence;
- existing spatial/transfer evidence metadata.

The Unity gateway sends this in the existing observation `metadata` object, so no second event system is created.

## Vision Learning integration

Section 16 now groups human intervention signals by detector label and distinguishes:

- profile-mapped labels;
- recipe-name fallback labels.

For each label it reports:

- observations;
- corrected observations;
- rejected observations;
- reclassifications;
- configured minimum confidence;
- human correction rate.

This remains an intervention signal, not a claim that uncorrected detections are verified correct.

The governed learning export also includes:

- `detectorLabel`;
- `profileMatched`;
- `profileMinimumConfidence`.

## Admin

`glasses-vision-profiles.php` provides:

- detector / generic scope;
- model label;
- canonical ingredient selection;
- optional confidence floor;
- status;
- notes;
- search/edit/enable-disable.

Management writes require `glasses.manage` and CSRF validation. Read access requires `glasses.view`.

## Vendor boundary

Section 18 does not assume a specific INMO model runtime or ML framework.

A production detector only needs to emit a stable class label, confidence, instance identity and bounding box. Gelato owns the mapping from that label to restaurant recipe truth.

## Scope boundary

Section 18 does not train, download, select, canary, or deploy a machine-learning model.

Model package management and governed runtime rollout should remain a separate release unit.
