# Vision Lab V7 Section 5 — Model Improvement Experiment Runtime

Section 5 creates an immutable experiment contract around a model-improvement hypothesis.

Each experiment binds the exact champion model artifact, frozen candidate dataset, qualified training release, passing qualification, and governed training configuration. Starting an experiment only changes experiment state; it does not launch uncontrolled training.

A completed training run can attach only when it belongs to the exact release and exact qualification attestation and its governed configuration matches the experiment contract. The resulting challenger can bind only when its artifact SHA-256 equals the training-run output and its detector, runtime, and platform family match the champion.

Experiment lifecycle:
`ready → running → trained → completed`

Durable lineage records:
- champion model → experiment;
- training release → experiment;
- experiment → completed training run;
- experiment → challenger model.

Completing an experiment does not create, activate, or advance a production rollout. Champion/challenger evidence review remains a separate Section 6 gate.
