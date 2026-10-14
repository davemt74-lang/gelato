# Gelato Section 281 — Live Two-Hand Detection

Camera 1 is the primary glasses-camera stream managed under the existing Runtime tab.
Once the operator clicks Start Camera, the browser lazily loads a pretrained
MediaPipe Hand Landmarker and enables two real hand bounding boxes. No API key,
new database table, hand-training session or separate camera permission is needed.
Compatible iPhones exposed to Windows as ordinary webcams are treated
identically to USB webcams.

## Detection and performance

- @mediapipe/tasks-vision@0.10.32 runs a pinned Hand Landmarker in VIDEO
  mode, on the client (CPU), with numHands=2 and about eight FPS.
- Each frame's 21 landmarks become a normalized padded hand bounding box.
  The existing GelatoHandTracker maintains two short-lived session tracks,
  including temporary occluded/lost states and reacquisition up to about 2.2 seconds.
- Two boxes begin READY; a real detection turns its box green TRACKING.
  IDENTIFYING appears only when a separate ingredient-object detector also
  identifies a matching object near the hand.
- Ingredient candidates remain restricted to the selected KDS item's
  unaccounted configured ingredients. Hand detections never become ingredient
  observations or trigger kitchen actions.
- Existing governed ONNX ingredient inference may supply held-object suggestions.
  MediaPipe's hand model alone is not an ingredient classifier.
- Loading starts after explicit camera consent. Stopping, changing or revoking
  the camera clears tracks. Closing the page disposes the detector; the user
  can stop hand tracking separately and retry load failures.

## Pinned deploy assets

The full deployment workflow downloads the pinned JavaScript/WASM runtime,
the public hand_landmarker.task revision and its upstream Apache-2.0 license.
The release ZIP includes these files in assets/vendor/mediapipe and includes
ASSET-SHA256SUMS.txt. Missing downloads block the package build.
Deployments made from raw source without the certified ZIP can load from the
same pinned CDN as a fallback. Serve .mjs as JavaScript and .wasm as WASM.

Hand frame inference occurs on the user's device. The MediaPipe library may
collect usage/diagnostic metrics under the vendor privacy notice; Gelato
does not upload hand frames to its API. Existing photo and microphone consent
rules are unaffected.

## Acceptance

node tests/glasses-mediapipe-hands.test.js
node tests/glasses-dual-hand-tracking.test.js

The existing camera, KDS, Runtime, HUD, Voice and vision regression suites
and full deploy ZIP asset validation must pass on the same source revision.

Field signoff requires Chrome/Windows and actual hands with the USB iPhone,
including rapid hand re-entry, box alignment, local server MIME and frame rate.
CI simulation is not a substitute for physical testing or a native AIR3 SDK.
