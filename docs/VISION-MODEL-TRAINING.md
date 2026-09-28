# Vision Model Training, Evaluation & ONNX Release

Gelato's vision training pipeline converts the browser simulator's local YOLO dataset export into a governed ONNX model release without adding ML dependencies to the restaurant runtime.

## Environment

Use Python 3.11+ in a dedicated training environment:

```bash
python -m venv .venv-vision
source .venv-vision/bin/activate   # Windows: .venv-vision\\Scripts\\activate
pip install -r tools/vision_training/requirements-train.txt
```

The training environment is intentionally separate from PHP production deployment. Current pinned releases are Ultralytics 8.4.164, ONNX 1.23.0, and ONNX Runtime 1.30.0.

## Validate and split a simulator dataset

```bash
python tools/vision_training/pipeline.py validate gelato-vision-dataset.zip
python tools/vision_training/pipeline.py prepare gelato-vision-dataset.zip training-workspace
```

Validation rejects unsafe ZIP paths, unsupported manifests, missing images, out-of-range classes, non-normalized coordinates, and boxes that leave the image.

The default split is 70% train / 15% validation / 15% test. Assignment is deterministic from the image filename and seed (default 74), so the same dataset and seed produce the same split.

## Train, evaluate, export, and package

```bash
python tools/vision_training/pipeline.py train-release gelato-vision-dataset.zip build/vision-model \\
  --model-name gelato-kitchen-food \\
  --model-version 1.0.0 \\
  --detector-name food-detector \\
  --base-model yolo11n.pt \\
  --epochs 100 \\
  --imgsz 640
```

The command:

1. validates and deterministically splits the dataset;
2. trains with a fixed seed and deterministic mode;
3. evaluates the best checkpoint on the held-out test split;
4. records overall and per-class precision, recall, mAP50, and mAP50-95;
5. enforces release thresholds;
6. exports the accepted model to ONNX (opset 17);
7. opens the ONNX artifact in ONNX Runtime and performs a zero-tensor smoke inference;
8. computes artifact bytes and SHA-256;
9. writes `gelato-package.json` with `gelato.browser_onnx_detector.v1` metadata;
10. creates a Gelato release ZIP containing the ONNX artifact, package metadata, and metrics.

## Default quality gate

Overall minimums:

- Precision: 0.70
- Recall: 0.70
- mAP50: 0.75
- mAP50-95: 0.45

Every class must also meet:

- Precision: 0.60
- Recall: 0.60
- mAP50: 0.65

A model that misses any gate is not packaged as a release. Thresholds can be raised for production; lowering them should be an explicit engineering decision.

## Release output

A successful run produces a `release/` directory with:

- `<model>-<version>.onnx`
- `gelato-package.json`
- `metrics.json` in the release ZIP
- `<model>-<version>-gelato-release.zip`
- `release-summary.json` including model and ZIP SHA-256 values

The generated package metadata matches the browser ONNX contract already consumed by Gelato's governed model registry and Web Glasses Simulator.

## Important training practice

The code pipeline can be complete before model quality is. Production accuracy depends on a representative dataset. Include negative/background frames and variation in ingredients, portion sizes, occlusion, hands, utensils, containers, lighting, camera angle, station layout, and build stage. Keep the test split held out from training decisions.


## Champion / challenger evaluation

A newly trained model can now be evaluated against the currently deployed champion before it becomes eligible for rollout.

Both models must be evaluated on the **same golden test split**. Gelato hashes every file in `images/test` and `labels/test` to produce a deterministic `goldenTestHash`. A comparison is rejected if the champion and challenger hashes differ.

### Standalone comparison

```bash
python tools/vision_training/pipeline.py compare champion-metrics.json challenger-metrics.json
```

The default comparison policy allows at most:

- 0.01 overall regression in precision, recall, mAP50, or mAP50-95;
- 0.03 per-class regression in precision, recall, or mAP50.

The challenger must also demonstrate at least one material gain:

- mAP50 improvement of 0.005 or more; or
- recall improvement of 0.005 or more.

The absolute release-quality gate still applies separately.

### Training directly against a champion

```bash
python tools/vision_training/pipeline.py train-release dataset.zip build/vision-model \
  --model-name gelato-kitchen-food \
  --model-version 1.1.0 \
  --champion-metrics champion-metrics.json
```

A challenger that fails the comparison is not packaged.

### Explicit tradeoff override

A comparison may be made eligible with an explicit engineering rationale:

```bash
python tools/vision_training/pipeline.py compare champion.json challenger.json \
  --override-reason "Recall improved materially for bacon while precision regression is accepted for a shadow-only canary."
```

Overrides are not silent. The reason, regressions, golden-test hash, deltas, and override flag are written into immutable `gelato.vision_model_comparison.v1` metadata.

### Rollout governance

Comparison-aware model packages cannot be activated when their immutable comparison metadata says `eligible=false`.

Legacy model packages without comparison metadata remain supported. This preserves compatibility with packages registered before the champion/challenger gate existed while making newly evaluated releases fail closed.

The intended path is:

Golden test set → champion evaluation → challenger evaluation → deterministic comparison → absolute quality gate → ONNX verification → immutable comparison metadata → governed canary rollout.
