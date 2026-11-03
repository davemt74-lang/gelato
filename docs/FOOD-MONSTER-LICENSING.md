# Gelato License & Updates

1. In Food Monster, activate the restaurant's subscription and verify the installation hostname. Mark the installation active and issue its license.
2. Deploy this Gelato build through the usual package workflow. Preserve live config and data directories. In Gelato, sign in as the protected owner and open **Admin → License & Updates**.
3. Enter the Food Monster installation ID and activation key, then confirm with the owner password. Gelato uses the HTTPS hostname from its existing app configuration. The server stores connection credentials in its validated private storage directory.
4. Check status from the owner page or ask the Food Monster Agent Chat about the license, expiry date, installed version, release notes, or the latest update result. The agent tools are owner-only, with the existing private approval required for a room-voice read.
5. To update, approve the exact release in the central account portal and verify the downloaded ZIP SHA-256. Back up the application and database, deploy the validated package, and use Gelato's existing **upgrade.php** for pending migrations. Verify the installation, return to **License & Updates**, and report the outcome. A completion report must match the installed version and cannot bypass pending or changed migrations.

`GELATO_VERSION.txt` is the public version for the first licensed build and future licensed release packages. The deployment artifact's existing `DEPLOY_SHA.txt` continues to identify the exact packaged commit. Increase the release version when preparing the next licensed build and publish that same version in the central registry.

The central automatic-update setting records a preference. The current integration provides owner-approved downloads, deployment reporting, and status checks. A central outage or loss of update entitlement leaves the restaurant application available.
