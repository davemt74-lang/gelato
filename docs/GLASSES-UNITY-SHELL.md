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

The coordinator depends on `IDeviceTokenStore`; the production bootstrap requires one to be assigned explicitly. The bundled `DevelopmentPlayerPrefsTokenStore` is intentionally marked development-only so a production AIR3 build cannot silently choose insecure PlayerPrefs storage. A secure Android/AIR3-backed implementation can be added without touching workflow code.

## Vendor SDK arrival

The missing `InmoAir3SDK_0.7.3.unitypackage` should affect one integration file only. The rest of the client must remain buildable/testable without vendor types.

The documented AIR3 methods expected in that adapter are `Start6Dof`, `Stop6Dof`, `GetAir3ImageData`, and `GetAir3CameraParams`.


## Tracking lifecycle

The hardware tracker starts only after Gelato creates an active build session and stops after a successful Expo handoff. App initialization alone does not start continuous camera/tracking work.
