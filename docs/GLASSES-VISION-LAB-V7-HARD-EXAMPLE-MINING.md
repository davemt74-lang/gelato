# Vision Lab V7 Section 3 — Hard Example & Counterexample Mining

Section 3 mines high-value production failures from the immutable V7 feedback ledger without silently promoting evidence into training.

## Candidate classes

Production outcomes are converted into advisory mining candidates:
- false negatives → missed positives;
- false positives and unexpected detections → counterexamples;
- misclassifications → misclassification candidates;
- low-confidence, correction and validation-disagreement events → hard positives.

## Deterministic scoring

Each candidate receives:
- a base score derived from the production error type;
- bounded recurrence bonus for repeated failures in the same lineage/environment cluster;
- bounded confidence bonus, rewarding confident wrong predictions and difficult low-confidence cases;
- deterministic cluster identity from model, predicted/expected class, station, menu item, lighting, pose, build step and capture environment.

Open-candidate caps prevent one repeated cluster or one model from dominating the review queue.

## Immutable evidence

Each mining run is bound to:
- the exact set of production-error public IDs and event hashes;
- a SHA-256 source fingerprint;
- the mining policy;
- a SHA-256 run hash;
- per-candidate SHA-256 evidence hashes;
- exact V6 model-training ancestry when model lineage exists.

Identical source evidence and policy deduplicate to the same run.

## Governance boundary

Mining is advisory only. It does not:
- add candidates to a dataset;
- mark evidence as approved ground truth;
- start retraining;
- activate or advance a rollout;
- alter POS, KDS or build-session truth.

Dismissal is an explicit human governance action with a preserved reason.
