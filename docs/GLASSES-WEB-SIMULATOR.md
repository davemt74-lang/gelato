# Gelato Web Glasses Simulator

The Web Glasses Simulator is a browser-based design, training, and integration surface for the Gelato AR glasses workflow.

## Modes

### Mock
Mock mode never writes to POS, KDS, or the AR build ledger. It ships with a deterministic Club Sandwich + Fries scenario and lets designers test product, build steps, NEXT, product validation, ingredient boxes, unexpected ingredients, and Expo-ready states.

### Live Gelato
Live mode is backed by the real Gelato POS/KDS/AR build system.

The browser does **not** receive or store a glasses bearer token. A logged-in Gelato user selects an existing active glasses device; the server validates organization, location, AR-glasses access, and write authority before using the same domain functions as the device API.

Read access requires `glasses.view`. Live state-changing actions require both `glasses.manage` and `kds.update` at the device location.

## Live data path

POS / KDS → `glasses_current_work()` → web simulator → `glasses_build_start()` → simulated detector events → `glasses_build_observe()` → `glasses_validation_evaluate()` → controlled `glasses_handoff_to_expo()`.

The simulator never directly updates KDS item status.

## Visual contract

The current page intentionally uses a CSS glasses shell and neutral kitchen placeholder so final artwork can be dropped in later without changing the workflow engine.

The projection contract keeps the optical center clear:

- connection / station state at upper-left;
- item, build steps, NEXT, and Product Validation on the right;
- ingredient detection boxes only over the work area;
- validation controls in the developer console, not the projected HUD.

## Future image integration

Replace the placeholder scene with the final kitchen POV artwork and, if desired, replace the CSS glasses frame with transparent glasses-frame artwork. The HUD remains a separate DOM layer, so artwork changes do not affect POS/KDS integration.


## Visual calibration pass

The simulator now includes a browser-local projection calibration layer designed for the real kitchen POV and glasses artwork.

- Calibrate / Lock layout mode.
- Projection safe-area guide.
- Independent left-eye and right-eye alignment offsets.
- HUD opacity, brightness, and scale.
- Adjustable safe-area width and height.
- Draggable/resizable status and right-rail projection regions.
- Named layout presets persisted in localStorage.
- Factory reset for deterministic baseline geometry.

Calibration settings never alter POS, KDS, build sessions, observations, validation, or Expo state. They are presentation-only simulator data.


## SVG frame and optical mask

The default simulator frame is now native SVG rather than a raster dependency. The vector layer contains separate frame artwork and a lens occlusion mask, allowing the HUD to remain an independent live DOM projection while the area outside the optical lens openings is visually suppressed.

Frame modes:

- **SVG** — default scalable vector frame + optional optical mask.
- **Uploaded** — local custom glasses artwork with the same optional optical mask.
- **None** — scene/HUD-only development view with no frame or mask.

Frame and mask preferences persist only in browser localStorage. They do not alter POS, KDS, AR build, validation, or Expo data.


## HUD fidelity pass

The simulator now follows the kitchen AR reference layout while retaining the real Gelato POS/KDS workflow underneath it.

- Top optical status strip with clock, station, runtime, connection and battery indicators.
- Left-side **Active Orders** rail backed by the station KDS work projection.
- Center **NEXT** instruction with target quantity and optical leader line.
- Right-side active build rail with ticket, product metadata, build sequence and current-step emphasis.
- Lower-right ingredient validation panel with accounted, missing and unexpected counts.
- Detection boxes now distinguish confirmed, verify/low-confidence and unexpected observations.
- Mock-only demo controls provide Auto Play, Next Detection, Low Confidence and Complete Mock actions. These controls are disabled in Live Gelato mode.
- The built-in **Pizza Line Reference** calibration preset seeds the reference HUD geometry and remains editable through the existing calibration system.

No new HUD control bypasses the existing build, validation or KDS/Expo authority boundaries.


## Live station synchronization

In **Live Gelato** mode the simulator now keeps the selected glasses/KDS station synchronized automatically.

- Read-only station work is polled about every 1.5 seconds through the authenticated simulator API.
- Newly entered POS orders appear once normal Gelato routing places their items on the selected KDS station.
- Ticket status, table/service mode, item/option, special instructions, modifiers, focus and Active Orders update without pressing **Refresh work**.
- A stable station fingerprint suppresses no-op change logging.
- The selected KDS item is preserved while it still exists; an active build item takes precedence if the previous selection leaves the queue.
- Synchronization pauses while the browser tab is hidden and resumes immediately when visible.
- Network/timeout failures back off to a maximum 10-second retry interval and recover automatically.
- Browser offline/online events explicitly suspend/restart synchronization.
- A visible **LIVE SYNC / SYNCING / SYNC RETRY / SYNC PAUSED** badge reports synchronization health.

The synchronization endpoint is read-only and does not bypass any POS, KDS, build-session, validation or Expo mutation authority.


## Browser camera and vision preview runtime

The simulator can now use a real browser camera as the scene underneath the existing SVG glasses frame and HUD.

- Scene source can switch between uploaded **Image** and **Browser Camera**.
- Camera startup uses the browser MediaDevices API with environment-facing preference when no explicit device is selected.
- Available video inputs are enumerated after camera permission is granted.
- Resolution presets include 640×480, 1280×720 and 1920×1080.
- Preview supports Cover/Contain fit and optional mirroring.
- Camera health reports OFF, STARTING, READY, ERROR or UNSUPPORTED.
- Permission denial, missing hardware and stream-ended conditions are surfaced without affecting POS/KDS state.
- Camera tracks are released when stopped.
- The current frame can be captured locally as PNG without server upload.
- Manual Vision Target mode lets the operator click a location in the live scene, select the current or a specific build component, set confidence and submit an observation.
- In Mock mode, targeted observations remain isolated to the local mock build.
- In Live Gelato mode, targeted observations use the existing authenticated canonical build observation endpoint and are explicitly labelled `web_glasses_simulator_camera`.
- Live Station Sync continues independently while the camera preview is active.

This browser camera runtime is a development/preview input and does not claim AIR3 hardware or vendor model-loader parity.


## Browser automatic vision runtime

The browser camera preview now includes a hardware-neutral detector loop that can be exercised before the proprietary AIR3 model-loader SDK is available.

- Vision mode can be **Manual**, **Assisted** or **Automatic**.
- Manual preserves the existing click-to-target workflow.
- Assisted runs detections and draws tracked boxes without writing observations.
- Automatic runs the same detector loop and submits accepted detections through the canonical build observation endpoint.
- Inference runs on a bounded operator-selectable FPS budget.
- A detector adapter contract keeps the simulator independent from any specific browser model runtime.
- A deterministic fixture adapter is included so CI and simulator demos do not require a physical camera or proprietary model package.
- Detections are confidence-filtered, normalized to the video frame, mapped through Cover/Contain and mirror presentation geometry, and assigned persistent tracking IDs.
- Tracks expire on a bounded TTL and duplicate component submissions are suppressed for the active build.
- Vision processing pauses with hidden tabs and stops with the camera lifecycle.
- The simulator reports approximate detection FPS, inference latency and active detection count.
- Automatic live observations are explicitly labelled `web_glasses_simulator_auto_vision`.

The automatic browser detector remains a development runtime. It does not claim production model accuracy and does not bypass governed label profiles, build-session authority, product validation, KDS lifecycle or Expo handoff.


## Multi-ingredient temporal tracking and full product validation

Automatic browser vision now reasons across time instead of treating every detector frame as an isolated ingredient event.

- Multiple expected ingredients can remain tracked simultaneously.
- New detections must remain present for three detector frames before they become stable build evidence.
- Stable tracks receive persistent tracking IDs and remain distinct from pre-stable candidates.
- An ingredient is not considered removed because of one missed frame. Stable disappearance must exceed a 2.2-second grace period.
- Stable disappearance submits the canonical `removed` build observation, allowing Gelato to recalculate detected quantity and component status.
- A new stable track spatially overlapping a disappearing stable ingredient can be classified as a replacement rather than two unrelated events.
- Replacement inference is descriptive only; the canonical ledger still receives explicit removal/addition observations.
- Required component sort order is checked when a stable ingredient is added. Out-of-order detections become explicit temporal sequence violations.
- The temporal readiness gate combines missing, verification-required, unexpected, sequence-violation and still-pending track state.
- A complete build must remain temporally ready for a short dwell period before the simulator automatically calls the existing canonical product-validation endpoint.
- Expo / Finishing handoff remains disabled in Automatic mode unless both canonical product validation and the temporal readiness gate are ready.
- Temporal state resets at build, mode and device boundaries, so evidence cannot leak between tickets.
- The simulator shows current temporal status plus recent added, removed, replaced and sequence-violation events.

This layer does not replace the Gelato build ledger or product validator. It filters noisy detector timing into canonical `added` / `removed` observations and adds a stricter simulator-side readiness gate before the existing validation and handoff authority.


## Governed real browser ONNX inference

The browser simulator can now execute an actual governed ONNX detector against the live camera feed instead of relying only on the deterministic fixture.

### Governed load path

A Live Gelato build selects a detector name and requests the existing vision-model assignment for the selected device and build session.

The browser accepts an assignment only when:

- assignment action is `apply`;
- package compatibility is true;
- the selected package runtime is `onnx`;
- the artifact URL comes from the immutable registered package;
- downloaded bytes stay below the 512 MiB browser safety ceiling;
- optional registered artifact byte size matches exactly;
- SHA-256 computed with Web Crypto matches the registered package SHA-256;
- package metadata contains the supported `gelato.browser_onnx_detector.v1` contract.

Only after those checks does the simulator create an ONNX Runtime Web inference session.

ONNX Runtime Web is explicitly version-pinned by the simulator. The model artifact itself continues to come only from Gelato's governed package assignment.

### Browser inference metadata

An ONNX package intended for browser preview declares `metadata.browserInference` similar to:

```json
{
  "schema": "gelato.browser_onnx_detector.v1",
  "decoder": "yolo_v8",
  "input": {
    "name": "images",
    "width": 640,
    "height": 640,
    "layout": "nchw"
  },
  "output": {
    "name": "output0",
    "layout": "channels_first",
    "boxScale": "pixels"
  },
  "labels": ["turkey_slice", "bacon_strip", "lettuce"],
  "nmsIou": 0.45,
  "maxDetections": 25
}
```

The first supported decoder is YOLOv8-style output. Unsupported decoder/runtime metadata fails closed rather than guessing model semantics.

### Inference path

Live camera frame → RGB tensor preprocessing → ONNX Runtime Web → YOLOv8 decode → non-maximum suppression → active Gelato Vision Label Profile → temporal multi-ingredient tracking → canonical observations → canonical product validation.

Raw detector labels do not directly become recipe evidence.

The active `gelato.vision_label_profile.v1` is requested for the same build and detector. Explicit blocked labels are rejected, detector-specific mappings are honored, per-label minimum confidence can only tighten the UI confidence floor, and exact normalized recipe display-name fallback remains available only when the label is not blocked.

### Lifecycle

The ONNX session is build-specific for governance purposes. It is released on build reset, device switch, mode switch, explicit unload, or page teardown. A model from a prior ticket therefore cannot silently continue producing evidence for a new build.

The deterministic fixture remains available as a simulator/test adapter and is visually distinct from the Governed ONNX adapter.


## Vision dataset capture and YOLO training export

The Web Glasses Simulator now includes a browser-local dataset collection surface so kitchen footage can become training data for the production ingredient detector.

### Capture workflow

1. Start the browser camera.
2. Start/select a Gelato build so canonical ingredient classes are available.
3. Enable **Label mode**.
4. Optionally freeze the camera frame.
5. Choose the ingredient class.
6. Drag one or more bounding boxes over the visible ingredient instances.
7. Capture the labeled sample.
8. Repeat across ingredients, lighting, angles, stations and build stages.
9. Export the dataset as a YOLO ZIP.

Bounding boxes reuse the existing camera/video geometry calculation, including Cover/Contain and mirror handling, so exported annotations are normalized against the original camera frame rather than screen coordinates.

### Local-only dataset state

Dataset capture does not create a server-side Gelato record.

Frames, annotations and samples remain in browser memory until export or page exit. Capturing training data therefore does not:

- update POS;
- update KDS;
- change build observations;
- change validation;
- alter model rollout state;
- upload kitchen images to Gelato.

### Export format

The generated ZIP contains:

- `images/train/frame-000001.jpg` style camera frames;
- matching `labels/train/frame-000001.txt` YOLO detection labels;
- `data.yaml` with deterministic class indexes;
- `gelato-manifest.json` using schema `gelato.vision_training_dataset.v1`;
- a short README.

YOLO label rows use:

`class_index center_x center_y width height`

with all geometry normalized to 0–1.

Class indexes are generated deterministically from the captured class names sorted alphabetically.

The ZIP is written directly in the browser with store-mode ZIP entries and CRC-32 checksums, so dataset export does not depend on an external ZIP library or server endpoint.

### Training intent

This section prepares the data needed to train the actual ingredient-recognition model. It does not claim that a production detector exists yet.

A useful production dataset should include broad variation in:

- ingredient appearance;
- portion size;
- hands and utensils;
- partial occlusion;
- containers and packaging;
- lighting;
- camera angle;
- station layout;
- recipe stage;
- negative/background frames.

The exported dataset can be used by a YOLO-family training pipeline and then exported to ONNX for the governed browser inference runtime already implemented in Gelato.


## Active learning and hard-example capture

The browser simulator now turns difficult vision cases into a **human-reviewed** retraining queue without changing Gelato's privacy or authority boundaries.

### Candidate triggers

When local hard-example capture is enabled and a browser camera is active, the simulator can queue a frame for review when it sees:

- detector confidence below the current acceptance threshold but close enough to be informative;
- a temporal sequence violation;
- a previously stable ingredient track disappearing;
- a spatial replacement event;
- a manually reviewed low-confidence target.

Capture is deliberately bounded:

- candidates remain in browser memory only;
- the queue is capped at 30 frames;
- repeated trigger keys have a 10-second cooldown;
- candidates never become training samples automatically.

### Human review outcomes

The first queued candidate can be reviewed as:

- **Accept Labels** — preserve the suggested detector/ingredient boxes;
- **Reclassify + Accept** — replace suggested labels with a selected canonical build ingredient;
- **Keep as Negative** — retain the frame with no boxes, useful for false-positive/background training;
- **Discard** — remove the candidate entirely.

Only accepted, reclassified, or explicitly negative examples are copied into the existing local YOLO dataset.

This prevents uncertain model output from silently becoming ground truth.

### Export provenance

Reviewed hard examples use:

`gelato.vision_active_learning.v1`

inside the existing `gelato.vision_training_dataset.v1` manifest.

The manifest records:

- reviewed hard-example count;
- review outcome totals;
- per-sample hard-example trigger reason;
- human review outcome;
- source candidate ID.

The actual exported frames and YOLO labels remain compatible with the model training pipeline introduced after dataset capture.

### Relationship to the correction ledger

The existing server-side correction ledger remains metadata-only by design and does not store kitchen camera frames.

Active-learning images remain local to the browser unless the user explicitly exports the training dataset. This keeps human correction analytics and image training data as separate governed surfaces.

The intended loop is:

Camera inference → hard condition → local candidate → human review → YOLO export → training/evaluation pipeline → governed ONNX release.


## Live champion / challenger shadow evaluation

The simulator can now run a governed challenger beside the loaded champion on the same live kitchen camera frames.

Use **Start Shadow Challenger** after:

- Live mode is active;
- a build is running;
- the governed champion ONNX model is loaded;
- an eligible comparison-aware draft rollout exists for the same detector/scope.

Gelato verifies the draft rollout and returns its baseline as champion and target as challenger. The browser refuses shadow startup if that champion does not match the currently loaded authoritative model.

The challenger artifact receives the same HTTPS, byte-size, SHA-256 and ONNX-session verification used by governed browser models.

For each shadow frame:

1. champion inference runs through the normal authoritative path;
2. challenger inference runs separately on the same camera frame;
3. mapped detections are paired by component identity and IoU;
4. disagreement, confidence and critical mismatch metadata are reported;
5. only champion detections enter temporal tracking and build observations.

Shadow telemetry never contains camera image bytes.

The simulator reports current shadow frame count, disagreement rate, critical mismatch rate, and whether the accumulated run currently satisfies the canary gate.

**Complete Shadow Run** closes the run explicitly. Completion alone does not promote a model. Rollout activation remains a separate governed action and will reject comparison-aware challengers unless a completed passing shadow run exists.
