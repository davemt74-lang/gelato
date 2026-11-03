# Gelato AR — INMO AIR3 Unity Client

This directory contains the Gelato-side Unity client shell for the INMO AIR3 glasses integration.

The vendor `InmoAir3SDK_0.7.3.unitypackage` is intentionally **not** committed because it has not been supplied yet. The client is structured so vendor SDK types are isolated to one adapter: `Runtime/Unity/InmoAir3Platform.cs`.

## Current runtime layers

- `Runtime/Core` — pure .NET Standard 2.1 workflow/domain code with no Unity or INMO dependency.
- `Runtime/Unity` — Unity adapters for token storage, Gelato HTTPS device API, bootstrap, mock glasses runtime, and the isolated future INMO adapter. Production bootstrap requires an explicit `IDeviceTokenStore`; the bundled PlayerPrefs implementation is development-only.
- `Tests` — executable .NET contract tests for pairing → current work → build → validation → Expo handoff orchestration.
- `AdapterTests` — .NET 8 integration contracts compiling the actual Unity gateway, model fetcher, and vision controller with a single-thread engine/transport facade. These tests do not run the Unity editor, Unity player, Android transport, or the vendor SDK.

## Gelato device API used by the client

The Unity gateway uses the existing `api/glasses-device.php` actions:

- `pair`
- `current_work`
- `heartbeat` (authenticated acknowledgement for automated vision health)
- `build.start`
- `build.observe`
- `validation.evaluate`
- `handoff.expo`

The gateway refuses plain HTTP except for localhost development.

## Runtime network health and deadlines

Automated vision starts with unknown connectivity. `VisionRuntimeController` sends an independent authenticated heartbeat and records a timestamp only after the gateway receives a successful response with `ok: true` and a device identity. Failed probes and stale success timestamps block automated processing; probes continue while processing is blocked or another API/model request is pending. A scene restart or replacement device credential requires a fresh heartbeat. Thermal and resource-pressure telemetry remain explicitly unknown until a real adapter supplies measurements.

API requests have a default 10-second deadline, heartbeat probes run every 5 seconds after completion, heartbeat success expires after 15 seconds, and model downloads have a default 60-second deadline. The corresponding inspector settings are bounded. Request creation, polling, cancellation, abort, and disposal use the Unity synchronization context captured during `Awake`; initialize a standalone model fetcher from that context too. A caller cancellation stays cancellation; elapsed deadlines raise `TimeoutException`. Network deadlines require a running engine context; they cannot repair a frozen main thread.

Run the core and actual adapter contracts from the repository root:

```sh
dotnet run --project glasses/inmo-air3-unity/Tests/Gelato.Ar.Core.Tests.csproj -c Release
dotnet run --project glasses/inmo-air3-unity/AdapterTests/Gelato.Ar.Unity.Adapter.Tests.csproj -c Release
```

The adapter facade supports bounded completion, network failure, stalled transport, oversized downloads, cancellation from another thread, and Unity-context enforcement. Its sources are guarded by `GELATO_UNITY_ADAPTER_CONTRACT` so they cannot replace Unity types when imported into an engine project. See [runtime hardening and acceptance limits](../../docs/HARDENING-UNITY-RUNTIME.md) for coverage and the behavioral before/after proof.

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


## Desktop simulator

While the INMO vendor package is pending, use `DesktopSimulatorPlatform` with `DesktopSimulatorController` to exercise the same Gelato pairing, station work, build, validation, transient outline, Verify/unexpected exception handling, and Expo handoff workflow on a desktop computer. See `docs/GLASSES-DESKTOP-SIMULATOR.md`.
