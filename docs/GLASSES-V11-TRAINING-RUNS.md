# Vision Lab V11 Section 6 — Training Run Orchestration & Experiment Lineage

Section 6 extends the existing V6 `glasses_vision_training_runs` and V7 model-experiment workflow in place. It does not introduce a second training-run registry.

## Contract

A V11 run may be created only from a ready/running V7 experiment whose candidate dataset is frozen, hash-addressed, V11-assembled, and represented by a qualified training release. The run snapshots the dataset hash, V11 assembly hash, release hash, qualification hash, canonical experiment training configuration, and a canonical configuration hash.

Run identity is immutable and SHA-256 addressed. The lifecycle is `planned -> running -> completed|failed|cancelled`. Failed or cancelled runs may create a controlled retry with an explicit parent run and incremented attempt number. A retry inherits the exact experiment, dataset, assembly, release, qualification, trainer, and configuration lineage.

Completion requires an output SHA-256 plus an artifact manifest containing that output. Metrics and artifacts are canonicalized and retained with a metrics hash. A completed run is attached through the existing V7 experiment contract, preserving the existing champion/challenger workflow.

## Governance boundaries

Section 6 does not create, advance, activate, or roll back production model rollouts. It does not mutate POS, KDS, build state, or kitchen truth. Production activation remains behind the existing evidence-review, promotion, shadow, and canary gates.

## API actions

- `training_run.create`
- `training_run.start`
- `training_run.complete`
- `training_run.fail`
- `training_run.cancel`
- `training_run.rerun`
- `training_run.compare`

The Vision Lab catalog exposes `trainingRunsV11` for audit and operations UI.
