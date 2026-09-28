# Gelato AR Glasses Plugin — Section 14 Station Calibration Studio

Section 14 turns the station-calibration API into an operator-facing browser tool.

## Purpose

A manager can calibrate a physical kitchen station without editing JSON by hand.

The Studio supports:

- location and KDS-station selection;
- local reference-frame loading;
- camera platform / dimensions / pixel-format settings;
- ingredient source-zone drawing;
- build / plate / handoff / discard region drawing;
- pointer-based move + resize;
- normalized 0–1 geometry editing;
- source/region priorities;
- operator notes;
- validation before publish;
- version history and geometry reload;
- versioned save through the existing calibration API.

The reference image remains local to the browser and is never uploaded.

## Validation contract

The Studio will not publish unless:

- a valid location and KDS station are selected;
- camera dimensions are valid;
- platform and pixel format are present;
- at least one ingredient source zone exists;
- at least one build surface exists;
- all rectangles are valid normalized frame geometry;
- zone keys are unique;
- region keys are unique.

This is intentionally stricter than the lower-level API because the current AR transfer-evidence workflow needs both a source zone and a build surface to provide useful physical evidence.

## Permissions

The page requires `glasses.view`.

Users with only `glasses.view` can inspect calibration history and geometry. Saving requires `glasses.manage`, enforced both in the page UI and by `api/glasses-calibrations.php`.

The Admin dashboard exposes the Studio only when the user's role includes a glasses permission (or owner/wildcard access).

## Reference frames

A JPEG, PNG, or WebP reference image up to 20 MB may be opened from the operator's computer.

The browser uses an object URL only. The file is not sent to Gelato.

When the image loads, its natural width/height seed the calibration frame dimensions. Operators may then adjust them if the actual runtime camera profile differs.

## Versioning

Saving does not overwrite an existing calibration.

The existing Section 11/13 backend creates a new version, activates it, and supersedes the previous active version. The history panel can reload any saved version into the editor as the starting point for a new version.

## Testable geometry core

`assets/js/glasses-calibration-geometry.js` contains the normalized geometry and payload logic independent of the DOM.

This module is used by the browser editor and by Node-based release tests for:

- reverse-direction drawing;
- frame clamping;
- source/build requirements;
- duplicate-key rejection;
- save-payload generation;
- persisted calibration rehydration.

## Scope boundary

Section 14 is a manual calibration Studio. It does not yet auto-detect station bins or generate calibration geometry from live glasses video.

The persisted profile remains the same Section 11/13 contract consumed by the current spatial and transfer-evidence pipeline.
