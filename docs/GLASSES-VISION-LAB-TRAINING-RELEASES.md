# Vision Lab V6 Section 5 — Training Release Builder

Section 5 turns a **frozen, curated dataset** into a deterministic YOLO training package without creating a second split decision.

## Governance contract

- A release requires a frozen dataset with an immutable dataset SHA-256.
- A release requires an applied V6 group-aware split plan.
- Every dataset item's stored split must equal the applied split assignment.
- Every governed sample must have active private training media and every source image is SHA-256 verified before packaging.
- The builder never reads filenames or hashes to invent a new train/validation/test assignment.
- Negative/background frames receive an empty YOLO label file.
- Positive annotations are converted from normalized top-left boxes to normalized YOLO center boxes.

## Package

Each ZIP contains:

- `images/train|val|test/*`
- `labels/train|val|test/*.txt`
- `data.yaml`
- `provenance.json`
- `release-manifest.json`
- `checksums.sha256`

The reproducibility identity is a SHA-256 over sorted package-relative payload paths and their SHA-256 values. ZIP entries are emitted in sorted order with normalized timestamps. The identity therefore does not depend on server directories, current time, ZIP timestamps, or private media paths.

## Privacy boundary

Private `storage_relative_path` values are used only by the authenticated server-side builder to read governed media. They are never written into the release manifest, provenance, YAML, labels, or download filename.

## Training profile

Section 5 starts with `yolo_detection_v1`. The profile is persisted in the immutable release record so later pre-training qualification and model-lineage phases can reference exactly what was built.
