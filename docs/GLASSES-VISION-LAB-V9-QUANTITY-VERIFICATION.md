# Vision Lab V9 Section 4 — Portion / Quantity Verification

Section 4 compares governed scene quantity evidence with the current canonical recipe component.

## Evidence contract

Ingredient scene entities may include:

```json
{
  "attributes": {
    "quantityEstimate": {
      "value": 1.0,
      "unit": "portion",
      "confidence": 0.93,
      "method": "vision_estimate"
    }
  }
}
```

Supported evidence methods are vision_estimate, count, scale_fusion, and sensor_fusion. The verification runtime accepts only measurements at or above the governed confidence threshold and only compares compatible normalized units.

## States

- within_tolerance
- under_portioned
- over_portioned
- insufficient
- ambiguous

A frame without the product/container is insufficient. Missing quantity evidence is insufficient. Conflicting high-confidence quantity estimates are ambiguous instead of being averaged into false certainty.

## Authority boundary

Quantity verification is advisory-only. It never changes canonical detected quantity, confirms a component, advances a build step, changes KDS/POS state, or writes inventory truth.
