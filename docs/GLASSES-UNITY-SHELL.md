# Gelato AR Glasses Plugin — Section 7 AIR3 Unity Application Shell

Section 7 creates the application/runtime boundary that can be completed without the proprietary INMO Unity package.

## Core orchestration

`ArWorkflowCoordinator` owns the client workflow:

```
Unpaired
  ↓ pair
Idle
  ↓ current_work
WorkReady
  ↓ build.start
Building
  ↓ build.observe + validation.evaluate
ReadyForFinishing
  ↓ handoff.expo
HandedOff
```

Errors enter an explicit `Error` state and preserve a safe message for the HUD.

## Hardware boundary

`IGlassesPlatform` defines:

- initialization
- platform capabilities
- start/stop tracking
- camera frame access
- camera calibration access
- pose access

The mock runtime already implements the interface. The real vendor implementation is isolated in `InmoAir3Platform`.

## Network boundary

`IGelatoGateway` is vendor-independent. `UnityGelatoGateway` implements it using Unity's HTTPS runtime and the device bearer token created by Gelato pairing.

The Unity gateway intentionally sends only documented device actions and requires HTTPS outside localhost development.

## Credential boundary

The coordinator depends on `IDeviceTokenStore`; the Unity implementation currently uses `PlayerPrefsTokenStore` so token persistence can later be replaced with an AIR3/Android secure-storage implementation without touching workflow code.

## Vendor SDK arrival

The missing `InmoAir3SDK_0.7.3.unitypackage` should affect one integration file only. The rest of the client must remain buildable/testable without vendor types.

The documented AIR3 methods expected in that adapter are `Start6Dof`, `Stop6Dof`, `GetAir3ImageData`, and `GetAir3CameraParams`.
