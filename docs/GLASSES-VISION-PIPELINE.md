# Gelato AR Glasses Plugin — Section 10 Computer Vision Pipeline Contract

Section 10 defines the production computer-vision boundary without choosing or hard-wiring a specific ML model.

## Goal

The detector should recognize visual evidence. Gelato remains responsible for recipe/build truth, quantities, validation, and KDS state.

The pipeline is:

```
IGlassesPlatform camera frame
        ↓
IVisionDetector
        ↓
VisionDetection candidates
        ↓
deterministic active-build mapping
        ↓
temporal track stabilization / deduplication
        ↓
IngredientObservation
        ↓
existing Gelato build + validation APIs
```

## Detector isolation

`IVisionDetector` receives:

- the current `CameraFrame`;
- the active build-session identity;
- expected build components;
- camera calibration when available;
- current pose when available.

A production detector may return a model-facing `Label` such as `Turkey` without knowing Gelato's database/component ID. The pipeline maps that label to the active build only when there is exactly one normalized exact display-name match.

A detector may still supply a direct `ComponentKey` when it has a deterministic mapping table.

Unknown normal labels fail closed. They are **not** automatically treated as unexpected ingredients. A detector must explicitly mark evidence `IsUnexpected=true`; when it does, the pipeline can create a stable `vision:unexpected:<label>` component key.

This keeps model classification separate from Gelato recipe identity and avoids fuzzy recipe mutations in the glasses client.

## Temporal stability and counting

A candidate must satisfy the configured number of **distinct camera frames** before it becomes an observation.

The pipeline rejects duplicate or stale camera timestamps so repeatedly reading the same frame cannot create false stability.

After emission, the same active physical track is not emitted again. A track must disappear for the configured missing-frame window and then restabilize before it can become a new observation.

Separate model instance IDs allow multiple objects of the same ingredient to be counted independently, such as individual bacon strips or bread slices.

## Spatial association

Tracking prefers a detector-provided `InstanceKey`. When one is unavailable, same-component detections are associated by normalized bounding-box intersection-over-union.

Bounding boxes are clamped to normalized image coordinates before they reach the HUD or backend observation API.

## Safety and resource bounds

The runtime enforces:

- minimum confidence;
- minimum stable frames;
- maximum missing-frame window;
- IoU association threshold;
- maximum detections per frame;
- maximum active tracks;
- positive quantities;
- valid camera dimensions/data;
- recipe-scoped component mapping.

Low-confidence accepted observations are still allowed to reach Gelato's existing `verify` path; the vision pipeline does not decide that the product is valid.

## Runtime integration

`VisionRuntimeController`:

1. runs only while a Gelato build session is active;
2. pulls frames through `IGlassesPlatform`;
3. limits inference FPS;
4. supplies camera calibration and pose to the detector;
5. shows the transient ingredient outline;
6. submits stable observations through `ArWorkflowCoordinator`;
7. therefore reuses the existing durable build, validation, and Expo flow.

The controller and core pipeline have no direct dependency on INMO vendor types. The future vendor SDK remains isolated to `InmoAir3Platform`.

## Diagnostics

`VisionPipelineDiagnostics` exposes per-session counts for:

- frames processed;
- duplicate/stale frames skipped;
- detections received;
- detections accepted;
- detections rejected;
- observations emitted.

These are for development/model tuning and do not replace Gelato's durable observation/build event history.

## What Section 10 intentionally does not choose

Section 10 does not select YOLO, TensorFlow Lite, ONNX, MediaPipe, or another inference runtime.

That choice should be made after the INMO package confirms:

- actual camera access paths and formats;
- usable RGB availability, if any;
- GPU/NPU/runtime support;
- thermals and sustainable inference rate.

The production detector can then implement `IVisionDetector` without changing recipes, KDS, validation, the HUD, or the rest of the AIR3 application.
