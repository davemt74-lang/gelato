# Vision Lab V7 — Production Feedback & Continuous Learning Governance

## Section 1 — Production Error & Outcome Ledger

Section 1 records production vision mistakes as durable evidence without allowing feedback to mutate kitchen truth or automatically enter training.

### What is recorded

Each event is bound to:
- an immutable event key and SHA-256 event hash;
- the original build session and observation when one exists;
- the human correction when the event came from a correction;
- device, location, station and menu item;
- the model package and rollout assignment active when the observation occurred;
- predicted component, corrected/expected component and original confidence;
- structured contextual evidence and occurrence time.

Supported outcomes include false positives, false negatives, misclassifications, low-confidence misses, corrections, validation disagreements and unexpected detections.

### Correction synchronization

Existing `glasses_observation_corrections` can be synchronized into the V7 ledger. Synchronization is idempotent. Reusing an existing event key with different evidence is rejected rather than rewriting history.

### Lineage

When a model package is known, V7 appends a `model_package --produced_error--> production_error` edge to the V6 lineage graph. This allows future V7 analysis to trace production failures back through model package, training run, qualification, release, split plan and dataset.

### Safety boundary

The ledger is observational. It does not:
- change KDS status;
- advance or complete a build;
- rewrite original observations;
- promote evidence into a training dataset;
- retrain or roll out a model automatically.
