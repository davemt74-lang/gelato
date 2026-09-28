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
