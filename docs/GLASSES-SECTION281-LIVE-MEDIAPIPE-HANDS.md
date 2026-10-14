# Gelato Section 281 — Live Two-Hand Detection

Camera 1 is the primary glasses-camera stream managed under the existing Runtime tab.
Once the operator clicks Start Camera, the browser lazily loads a pretrained
MediaPipe Hand Landmarker and enables two real hand bounding boxes. No API key,
new database table, hand-training session or separate camera permission is needed.
Camera 1 supports both USB webcams and HDMI camera feeds recognized by Windows as browser video inputs. An iPhone can supply HDMI video to a compatible Windows HDMI capture device (typically a UVC capture adapter); connecting only to a computer's HDMI output does not create a video input. Both sources appear in the existing Camera 1 device selector. Use Refresh Camera Inputs after connecting hardware. Camera 2–5 share this browser video-input mechanism.

### Camera discovery troubleshooting (USB and HDMI)

The Refresh Camera Inputs control only enumerates video devices that Chrome currently exposes.
It does **not** request permission or start a camera. If a USB or HDMI input is missing:

1. Verify the device is visible in the Windows Camera app or Device Manager. An
   iPhone HDMI cable requires a compatible HDMI **capture input** device attached
   to Windows (often identified as "USB Video" or "Video Capture").
2. In Chrome, open `chrome://settings/content/camera` and allow the Gelato site.
   Use HTTPS or localhost for browser camera access.
3. Click **Allow Camera Access & Scan**. The button explicitly requests browser
   camera permission with a temporary video track, enumerates sources while
   permission is active, and stops that temporary track immediately afterward.
   It does not start continuous camera capture, hand detection, or KDS writes.
4. Select the appropriate device in **USB / HDMI CAMERA INPUT**, then separately
   click **Start Camera** when ready.
5. If Windows Camera does not list the capture input, install/repair the capture
   adapter driver, reconnect the capture card, or test another port. Browser
   software cannot convert a PC's HDMI output jack into an HDMI capture input.

The picker does not silently switch to a different camera when a previously
selected video input goes missing. A failed permission request reports its
specific browser error rather than fabricating a detected source.


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

Field signoff requires Chrome/Windows with live USB-camera and iPhone-to-HDMI-capture feeds, including device enumeration, selected source persistence, disconnect/reconnect, rapid hand re-entry, box alignment, local server MIME and frame rate.
CI simulation is not a substitute for physical testing or a native AIR3 SDK.
