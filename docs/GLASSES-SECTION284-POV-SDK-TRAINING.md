# Section 284 — Glasses POV, SDK-ready Sources and Training Provenance

## Scope and acceptance
This section reuses the existing Camera 1 runtime, Vision Lab capture, KDS item
context, browser MediaPipe hand tracker, and fixed Camera 2–5 preview paths.
The physical glasses vendor runtime is **not installed**. No fictitious SDK
devices, detections, source frames or kitchen operations are introduced.

### 284A — Camera source contract
- Camera 1 remains a **first-person/head-direction** view, *not eye-gaze tracking*.
- USB web cameras and Windows-recognized HDMI capture sources continue through
  `navigator.mediaDevices.getUserMedia`. No HDMI input is invented by Gelato.
- `window.GelatoGlassesCameraSources` is the future SDK bridge.
  The adapter must explicitly register with `adapterId: 'air3.vendor.v1'`
  and supply `listSources(): Promise<Array<{id,label,connected}>>` and
  `openStream(id,{video,audio:false}): Promise<MediaStream>`.
- Only sources reported `connected:true` appear. Missing or disconnected
  selected sources do **not** silently fall back to another physical camera.
- The adapter must deliver a real MediaStream with at least one video track.
  A future SDK that provides raw frames instead will require an explicit
  vendor-specific MediaStream bridge; such conversion is **not assumed**.
- Explicit user action is still required to start Camera 1. Camera access,
  recognition, training submission and remote KDS writes never begin merely
  from SDK registration, reconnection or browser page load.

### 284B — First-person POV quality
- Operator can toggle an optional local center aiming guide and invoke
  **Check POV Frame** to sample the *already active* Camera 1 feed.
- Frame quality checks describe exposure, contrast, and detail in a downsized
  64×36 analysis, without retaining or uploading the pixels.
- The guide is for head-camera aim; it is not eye-tracking calibration, does
  not prove ingredient identity and cannot authorize POS/KDS transitions.

### 284C — Agent and vision source context
- A read-only `gelato-glasses-camera-source-change` event includes active
  source kind, adapter ID, station and current KDS item context, and a disconnect
  event marks it inactive. No raw frames enter Agent events.
- Existing `GelatoGlassesNavigation.context()`,
  `GelatoGlassesAssemblyContext.current()`, and hardware health snapshots
  include live camera kind/perspective. Consumers must not equate camera
  presence or POV alignment with a verified ingredient addition.

### 284D — Vision Lab provenance
- Selected source kind, glasses/stationary perspective, and SDK adapter
  identity are snapshotted with each reviewed local sample and included in the
  existing local YOLO dataset manifest and opted-in Vision Lab media metadata.
- Original stream settings are snapshotted when captured, so later camera
  switching cannot misattribute an earlier image.
- Auxiliary Cameras 2–5 remain stationary, with independent enablement,
  station assignment and explicit freeze/review capture. No SDK routing is
  added to their fixed-camera slots.

## Real-hardware signoff still outstanding
1. Connect and authorize an actual supported AIR3 SDK device.
2. Confirm its real video stream can be bridged to a browser MediaStream.
3. Verify that discovery, start, MediaPipe, frame check, labeled capture,
   disconnect and reconnection function on the physical glasses.
4. Confirm optional consent-gated upload, Vision Lab package qualification,
   and allowed Agent/KDS governance. No untrained ingredient detector should
   automatically mark KDS tasks done.

CI can certify the software contract, but it cannot certify the unreleased
vendor SDK or physical head-mounted video quality.
