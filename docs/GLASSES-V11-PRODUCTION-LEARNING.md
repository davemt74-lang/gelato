# Vision Lab V11 Section 11 — Continuous Production Learning & Closed-Loop Retraining

Section 11 adds a governed learning-cycle ledger around the existing production evidence, mining, retraining candidate, dataset assembly and experiment systems. It does not create a second mining, curation, dataset, training or rollout engine.

A cycle starts only from an immutable production acceptance and a bounded post-acceptance evidence window. The cycle is bound to the accepted model artifact and requires a minimum production age.

The cycle then explicitly attaches:
1. an existing mining run whose governed candidates belong to the accepted model,
2. an existing retraining batch after every eligible candidate has received explicit include/exclude review,
3. the canonical dataset built by that retraining batch.

A dataset may enter `ready_for_experiment` only when it is frozen, SHA-256 addressed, and carries a V11 assembly hash. Section 11 never launches a training run, creates an experiment, approves a release candidate, activates a rollout, or includes evidence in a dataset automatically. It closes the loop by preserving lineage and readiness, while every consequential step remains governed by the existing V11 stages.
