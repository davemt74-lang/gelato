# Vision Lab V9 Section 5 — Build Quality / Placement Verification

Section 5 evaluates ingredient placement quality without changing kitchen truth.

Governed rules come from the active station calibration build_surface region metadata:

```json
{"qualityRules":{"default":{"minCoverage":0.65,"minDistribution":0.70,"maxEdgeOverflow":0.12}}}
```

A component-specific key may override default. Scene ingredient evidence supplies placementEstimate with coverage, distribution, edgeOverflow, and confidence.

States: within_standard, needs_correction, insufficient. Product occlusion or missing/low-confidence geometry is insufficient rather than a guessed failure.

The service is advisory-only and never confirms ingredients, changes quantity, advances build/KDS/POS state, or writes inventory truth.
