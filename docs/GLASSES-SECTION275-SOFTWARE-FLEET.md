# Section 275 — Glasses Software & Fleet Management

## Scope
The existing glasses device registry and app/SDK/system versions remain canonical. The **Runtime** tab adds a fleet view, per-device diagnostics and version-policy editing, without creating second pairing records or another employee directory.

Version policy is **per organization + restaurant location + device platform**. A manager needs glasses.manage permission at that location; browsing devices requires glasses.view at that location. All actions use the authenticated Gelato user. Policy changes use a revision check to reject stale edits. No installed version or capability is taken from browser settings; only device-reported heartbeat versions are displayed.

## Compatibility
- Numeric dotted versions (e.g. 1.2, 1.2.3) can be compared; unusual formats remain unknown, never silently accepted.
- Unreported version, unmanaged devices, below-minimum, recommended update, and current are distinct states.
- Online status comes from server-measured physical device heartbeat, not the browser simulator.
- Manual update readiness is advisory. The vendor-native AIR3 integration and signed software package delivery are unavailable. There is **no push-update, firmware installation, or remote execution** in this section.

## Diagnostics
Device diagnostics return a bounded list of event types and timestamps (excluding high-volume heartbeat events). Metadata and device bearer tokens never appear in the response. Revoked or offline devices can be inspected if the user remains authorized.

## Upgrade and acceptance
Run the existing upgrade.php once to apply database/20261216_glasses_software_policies.sql. No existing device/assignment/preferences rows are modified. CI checks the Section 275 contract plus the complete simulator, runtime, PHP and release packaging suites. Physical AIR3 app installation, camera/display functionality and live OTA actions require an SDK/device and remain an explicit hardware acceptance gate.
