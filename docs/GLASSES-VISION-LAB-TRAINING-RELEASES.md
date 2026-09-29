# Vision Lab V6 Section 5 — Training Release Builder

Section 5 converts a frozen, curated Vision Lab dataset into a deterministic, training-ready YOLO package without re-splitting governed evidence.

## Release requirements

A release can be built only when:

- the dataset is frozen and has an immutable dataset SHA-256
- a V6 group-aware split plan has been applied
- train, validation, and test each contain active training media
- positive samples have valid annotations
- all source image bytes still match their stored SHA-256
- the selected training profile is one of the governed profiles

This means a legacy frozen dataset without an applied V6 split plan cannot be exported for training.

## Package contents

The ZIP contains only relative, synthetic package paths:

- `dataset.yaml`
- `provenance-manifest.json`
- `images/train/*`
- `images/val/*`
- `images/test/*`
- `labels/train/*`
- `labels/val/*`
- `labels/test/*`

No absolute server path or private training-media storage path is included.

## Determinism and integrity

The provenance manifest records:

- frozen dataset public ID, version, and dataset hash
- applied split plan public ID, plan hash, seed, and grouping policy
- selected training profile
- sorted deterministic class map
- exact sample and media public IDs used
- dataset-item source hash
- capture group and build-session public ID
- curation decision and rationale
- preserved split for every media item
- SHA-256 and byte length for every packaged image, label, and `dataset.yaml`

The **release hash** is SHA-256 over the canonical manifest content excluding only the `releaseHash` field itself. It is the reproducible corpus/package identity.

The ZIP also has its own SHA-256 for byte-integrity during download.

## Governed split preservation

The existing Python training pipeline recognizes `gelato.vision_training_release.v1`. When that schema is present it:

1. verifies every declared packaged file hash,
2. validates YOLO labels,
3. verifies declared split counts,
4. copies train/val/test exactly as packaged,
5. writes a local training workspace,
6. does **not** invoke legacy simulator re-splitting.

Older simulator datasets continue to use the group-aware split engine.

## Training profiles

Initial governed profiles:

- `yolo11n_640`
- `yolo11s_640`
- `yolo11n_960`

Profiles are embedded in release provenance so later training/model lineage can identify the intended model, image size, epochs, batch size, seed, task, and format.

## Lineage

`glasses_vision_training_releases` is the persistent release ledger. It intentionally stores the private artifact as a relative storage key while the public catalog exposes only safe identifiers, hashes, sizes, profile, and provenance. Section 7 can attach training runs and model packages to this release ID.

Response/manifest schema: `gelato.vision_training_release.v1`.
