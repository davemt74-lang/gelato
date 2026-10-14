# Section 274 — Runtime Device Connection Manager

## Identity and connection boundaries
- **Physical device:** Pair once using `api/glasses-admin.php` (`pairing.create`), then the glasses app exchanges the expiring one-time code at `api/glasses-device.php` (`pair`). Only the glasses receives the bearer token.
- **Operator profile:** A manager assigns an active employee to a physical device and station through the existing runtime-create action. Assignment is not user authentication. An authenticated employee must click **Confirm my profile** within their own Gelato session. A manager cannot impersonate the employee.
- **Online status:** Physical device online requires an actual device heartbeat within 60 seconds. The browser runtime heartbeat is tracked independently and never passed off as proof of a connected device.
- **Runtime:** An active runtime binds a single device, employee and station. End Runtime releases the lease but does not revoke the device pairing. Device revocation is a separate admin action.
- **Settings:** Per-user UI preferences remain scoped by user. Per-runtime browser camera, recognition, calibration and voice state keep their existing runtime storage isolation. Physical device config is not shared as an employee secret.
- **Pairing flow:** One-time code is shown only to the operator creating it, cleared when consumed or expired, and is not stored in browser persistent storage. The pairing-status endpoint verifies org, location permissions and grant creator; it does not return the secret.
- **Offline/reconnect:** A paired offline device remains paired. Open the glasses app to reconnect; do not re-pair unless credentials are revoked and replacement is expressly needed.
- **Native hardware limit:** The INMO AIR3 vendor SDK is still absent. Browser virtual preview is not a physical device, and this section does not claim vendor-native deployment/identity biometrics.

## Install
Run existing `upgrade.php` to apply `database/20261215_glasses_runtime_connections.sql` before pairing new glasses or using the new profile confirmation. Do not run the SQL twice.

## Validation
Check device pairing/expiry, physical heartbeat independently from browser, employee self-confirmation (including forbidden manager and wrong tenant), one active runtime per device/employee, reassign and revoke isolation, simulated previews, browser tab behavior, PHP and JS syntax, and complete deployment packaging.
