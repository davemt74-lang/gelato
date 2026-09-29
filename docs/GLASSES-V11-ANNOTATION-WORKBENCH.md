# V11 Section 3 — Human Corrections & Annotation Workbench

Section 3 adds immutable correction revisions on top of the existing Vision Lab sample/review/QA system.

Supported corrections:
- label correction;
- bounding-box correction;
- false positive;
- false negative / missed object;
- recipe-step correction;
- ingredient correction;
- combined correction.

A submitted correction never overwrites its own history. It snapshots the previous label/annotations/recipe step, the proposed replacement, submitter, reason and immutable hash.

Review reuses glasses_vision_sample_reviews. The submitter cannot review their own work. Reviewer disagreement moves the sample to needs_adjudication. An independent adjudicator resolves the disagreement. Only an approved correction is projected into the canonical glasses_vision_training_samples row and the associated media recipe-step context.

This section does not directly curate datasets, retrain models, mutate POS/KDS/build truth or promote a model.
