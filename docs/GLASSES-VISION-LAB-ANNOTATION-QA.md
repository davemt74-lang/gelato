# Vision Lab V6 Section 4 — Annotation QA & Agreement

Section 4 evaluates annotation quality without changing POS, KDS, build truth, curation state, or split assignment.

## Checks
- annotation completeness
- normalized bounding-box validity
- tiny/oversized box detection
- canonical-label vs annotation-label consistency
- class-relative box-area outliers
- reviewer decision + label agreement
- unresolved disagreement/adjudication queue
- class-specific annotation guidance

## Scoring
The QA score combines annotation completeness (70%) and multi-review agreement (30%). A corpus with no multi-reviewed samples receives a neutral agreement factor rather than being penalized.

## Adjudication
Samples enter the queue when their review status is `needs_adjudication` or multiple reviewers have conflicting decision/label signatures. Resolution continues through the existing governed review path; the QA analyzer itself is read-only.

Response schema: `gelato.vision_annotation_qa.v1`.
