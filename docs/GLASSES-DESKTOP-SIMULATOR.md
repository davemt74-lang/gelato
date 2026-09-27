# Gelato AR Glasses Plugin — Section 9 Desktop AIR3 Simulator

Section 9 provides a desktop runtime for exercising the complete Gelato glasses workflow before the proprietary INMO AIR3 Unity package is available.

## Purpose

The simulator uses the same `ArWorkflowCoordinator`, Gelato device API gateway, build definitions, validation state, Expo handoff, and Section 8 HUD as the eventual AIR3 application.

Only the platform adapter changes.

## Desktop platform

`DesktopSimulatorPlatform` implements `IGlassesPlatform` and provides:

- optional live webcam input;
- grayscale camera frames;
- approximate camera calibration for projection testing;
- simulated 6DoF pose;
- keyboard-controlled translation/rotation;
- build-scoped tracking start/stop.

The simulator does not claim its approximate camera calibration is production AIR3 calibration.

## Workflow controls

The default keyboard controls are:

- `P` — pair using the one-time code configured in the inspector;
- `F1` — fetch current Gelato station work;
- `F2` — start the focused build;
- `1–9` — recognize the corresponding expected component;
- `Shift + 1–9` — recognize it at low confidence to exercise Verify;
- `U` — inject an unexpected ingredient;
- `C` — manually confirm the first Verify component;
- `X` — resolve the first unexpected component;
- `V` — re-evaluate product validation;
- `E` — perform the controlled Expo / Finishing handoff;
- `R` — reset for the next item.

Simulated recognition defaults to the remaining expected quantity, so a recipe expecting three Turkey slices can be completed in one event or exercised incrementally with a quantity override from test code.

## Ingredient outlines

Every simulated observation includes a deterministic normalized bounding box in the central/lower work area. The existing Section 8 transient outline renderer displays it for the same short lifetime used by the production HUD.

The bounding-box contract deliberately keeps simulator boxes away from the persistent right rail.

## Debug overlay

`DesktopSimulatorDebugOverlay` displays simulator-only information:

- coordinator state;
- whether tracking is active;
- control shortcuts;
- recent simulator events/errors.

This overlay is development tooling and is not part of the AIR3 production HUD.

## Scene wiring

For a desktop simulator scene:

1. Add `DesktopSimulatorPlatform`.
2. Add `UnityGelatoGateway`.
3. Add `DevelopmentPlayerPrefsTokenStore` for local testing only.
4. Assign those three components to `GelatoArBootstrap`.
5. Add `ArHudController`, `IngredientOutlineOverlay`, and `ArHudRuntimeBinder`.
6. Add `DesktopSimulatorController` and optionally `DesktopSimulatorDebugOverlay`.
7. Optionally assign a Unity `RawImage` to the platform's webcam preview.
8. Configure the Gelato URL and a pairing code, then enter Play Mode.

The production AIR3 build must continue to use a secure device-token store rather than the development PlayerPrefs implementation.
