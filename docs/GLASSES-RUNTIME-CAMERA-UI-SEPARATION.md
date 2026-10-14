# Glasses Simulator: Runtime Camera vs System Cameras

This is **UI-only organization**. No new camera device IDs, capture endpoints,
stored photo types, training database tables, or recognition policies.

- **Runtime → Glasses 1, Glasses 2, …** contains that browser runtime's primary
  glasses POV camera, configuration, video input (including an iPhone registered as a
  Windows webcam), capture zone, Capture Photo, photo history, inference and frame
  dataset tools, and the assigned employee's supervised training controls. The
  actual primary camera remains slot **1**; each saved physical runtime uses its
  existing isolated URL/session rather than creating duplicate stream machinery.
- **Camera** contains independent system cameras **2–5** plus shared **Training**
  for image review, annotation and optional private Vision Lab upload.
- Slot 1 photographs are still associated with their original slot and station;
  their **Review / Train** action opens Camera → Training. Capture never
  silently uploads photos or advances KDS.
- Previously stored Camera → Cam 1 active-tab preferences now navigate to
  **Runtime** on restore; no stored camera settings are deleted or remapped.
- Both flows keep explicit Start Camera and camera consent behavior. The existing
  per-location camera role/zone configuration and all original element IDs are
  retained to preserve event handlers.

Run \`node tests/glasses-primary-runtime-camera-ui.test.js\` and the existing
camera, runtime, Vision Lab and KDS suites. Browser/hardware signoff is separate.
