# Vision Lab V9 Section 6 — Final Product Validation / Presentation Quality

Section 6 performs an immutable final visual review after the canonical build summary says all required components are accounted for.

Product/container scene entities may provide presentationEstimate with:
- score (0..1)
- confidence (0..1)
- defects[] containing type and confidence

Governed presentationRules can be stored in build_surface calibration metadata, including minimumPresentationScore, blockingDefects, and warningDefects.

States:
- ready_candidate — visual presentation meets the governed standard
- needs_correction — blocking defect or presentation score failure
- insufficient — product is occluded or presentation evidence is weak/missing
- not_ready_for_final_validation — canonical build is not fully accounted

A ready_candidate is advisory only. This service never marks KDS ready, completes the build, changes POS state, confirms ingredients, or writes inventory truth.
