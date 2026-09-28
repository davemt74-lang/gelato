# Vision Lab V4 — Dataset Intelligence, Coverage Maps & Automatic Collection Plans

Vision Lab V4 adds a governed intelligence layer over Vision Lab datasets. It answers three questions:

1. What does the training corpus actually cover?
2. What blocks a dataset from being release-ready?
3. What should the team collect next?

## Coverage intelligence

Analysis can run organization-wide or against a specific governed dataset.

Measured dimensions include:

- approved / pending / adjudication sample counts;
- per-class approved counts and explicit class gaps;
- device diversity;
- location diversity;
- station diversity;
- operator/wearer diversity;
- source-type diversity;
- lighting metadata diversity when instrumented.

For selected datasets, class and menu readiness are calculated from that dataset rather than from unrelated organization-wide samples.

## Recipe / menu readiness

Active menu items are mapped to their required ingredients.

For each required ingredient, Vision Lab reports:

- canonical component key;
- approved sample count;
- required minimum;
- remaining gap;
- ready / not ready.

Menu-item readiness is the percentage of required ingredients meeting the configured minimum.

This is a data-coverage readiness signal, not a claim that a model is production-safe by itself.

## Split leakage detection

Vision Lab checks build-session grouping across train / validation / test.

If samples from the same build session appear in more than one split, the dataset has leakage.

Leakage is a hard dataset-freeze blocker when V4 governance is loaded.

Draft datasets can remediate leakage by consolidating every sample from that build session into one split.

## Review disagreement

V4 stores reviewer opinions separately in `glasses_vision_sample_reviews`.

Multiple reviewers may independently record:

- approve;
- reject;
- relabel;
- needs adjudication.

Conflicting decisions or labels move the sample to `needs_adjudication`.

When recorded reviewers converge on the same approved/relabelled answer, the sample can return to approved.

Unresolved reviewer disagreement is a hard dataset-freeze blocker.

## Duplicate detection

V4 detects exact duplicate provenance when multiple samples reuse the same:

- source type;
- source reference.

This is intentionally not called perceptual image-duplicate detection.

Server-side kitchen image fingerprints/features are not yet retained, so near-duplicate visual similarity is explicitly reported as unavailable rather than guessed.

## Environment coverage

V4 reports approved-sample diversity by:

- location;
- station;
- device;
- operator;
- lighting metadata when present.

Camera-angle diversity is currently disclosed as not instrumented until governed camera-pose/image feature metadata is available in the server training evidence.

## Dataset release readiness

For a selected dataset, the V4 release-readiness flag requires:

- validation split present;
- test split present;
- no build-session split leakage;
- no unresolved reviewer disagreement.

Menu/class coverage remains visible as a separate explicit dimension instead of being hidden inside a synthetic quality score.

## Collection plans

Any intelligence snapshot can generate a draft collection plan.

Plans convert prioritized gaps into explicit tasks such as:

- collect more examples of a weak ingredient/class;
- collect from another location;
- collect from another wearer/operator;
- add lighting metadata coverage;
- instrument/capture camera-pose coverage;
- remediate split leakage;
- adjudicate review disagreements;
- inspect duplicate provenance.

Collection plans are **not automatically executed**.

An operator explicitly accepts a plan. Accepting it creates governed Vision Lab training missions only for collectable evidence gaps. Structural issues such as leakage or reviewer disagreement remain remediation work rather than being disguised as collection missions.

## Persistence

Migration `20261028_vision_lab_v4_dataset_intelligence.sql` adds:

- `glasses_vision_sample_reviews`;
- `glasses_vision_dataset_intelligence_snapshots`;
- `glasses_vision_collection_plans`.

Snapshots preserve the exact metrics, gaps and menu-readiness view used to generate a plan.

## Authority boundary

Vision Lab V4 never mutates:

- POS;
- KDS production state;
- product validation;
- Expo handoff;
- model rollout state;
- calibration;
- production build observations.

It may mutate only Vision Lab review/dataset/mission governance state.

## Operational loop

Production evidence → Active Learning → Human Review → Dataset Draft → V4 Intelligence → Remediate Leakage/Disagreement → Collection Plan → Training Missions → Dataset Freeze → Training/Evaluation → Governed Rollout.
