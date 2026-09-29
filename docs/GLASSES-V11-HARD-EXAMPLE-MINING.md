# V11 Section 4 — Hard Example & Failure Mining

Section 4 reuses the existing V7 mining tables and governance. It does not create a second hard-example queue.

The existing glasses_vision_mined_candidates table now supports:
- V7 production-error candidates;
- V11 production-evidence candidates;
- V11 annotation-correction candidates.

V11 scoring considers correction type, final-validation failure, reviewer disagreement, repeated corrections for the same operator/recipe-step context, and poor media quality. Dominance caps prevent one operator or one recipe step from overwhelming a run.

Excluded training evidence is never mined. A mined candidate remains only a ranked training opportunity: it is not automatically added to a dataset, retraining batch, experiment, rollout, or production model.

Existing V7 production-error mining continues unchanged and legacy candidates remain queryable.
