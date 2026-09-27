# Gelato AR — INMO AIR3 Unity Client

This directory contains the Gelato-side Unity client shell for the INMO AIR3 glasses integration.

The vendor `InmoAir3SDK_0.7.3.unitypackage` is intentionally **not** committed because it has not been supplied yet. The client is structured so vendor SDK types are isolated to one adapter: `Runtime/Unity/InmoAir3Platform.cs`.

## Current runtime layers

- `Runtime/Core` — pure .NET Standard 2.1 workflow/domain code with no Unity or INMO dependency.
- `Runtime/Unity` — Unity adapters for token storage, Gelato HTTPS device API, bootstrap, mock glasses runtime, and the isolated future INMO adapter. Production bootstrap requires an explicit `IDeviceTokenStore`; the bundled PlayerPrefs implementation is development-only.
- `Tests` — executable .NET contract tests for pairing → current work → build → validation → Expo handoff orchestration.

## Gelato device API used by the client

The Unity gateway uses the existing `api/glasses-device.php` actions:

- `pair`
- `current_work`
- `build.start`
- `build.observe`
- `validation.evaluate`
- `handoff.expo`

The gateway refuses plain HTTP except for localhost development.

## INMO SDK boundary

When the AIR3 package arrives, import it into the Unity project and implement only `InmoAir3Platform` against the documented hooks:

- `ArPoseManager.Start6Dof()`
- `ArPoseManager.Stop6Dof()`
- `ArPoseManager.GetAir3ImageData()`
- `ArPoseManager.GetAir3CameraParams()`

The KDS, recipe, validation, build-session, and Expo workflow must remain vendor-neutral.

## Unity setup after vendor package arrives

1. Create/open the AIR3-compatible Unity 2021 LTS project required by the vendor SDK.
2. Copy/import this module under the project's `Assets` tree.
3. Import the INMO AIR3 vendor package.
4. Implement `InmoAir3Platform`.
5. Add `UnityGelatoGateway`, `InmoAir3Platform`, and `GelatoArBootstrap` components to the app bootstrap scene.
6. Assign an `IDeviceTokenStore` implementation. `DevelopmentPlayerPrefsTokenStore` is for editor/development use only; use secure Android/AIR3-backed storage for production.
7. Configure the Gelato HTTPS base URL.
8. Pair the device with a one-time pairing code generated in Gelato.

The right-rail HUD is now implemented programmatically: ITEM, BUILD STEPS, PRODUCT VALIDATION, and conditional NEXT stay on the right side while the center remains clear except for short-lived ingredient outlines. The ingredient detector itself remains a separate release unit.
