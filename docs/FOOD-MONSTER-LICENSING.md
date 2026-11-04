# Gelato License & Updates

1. In Food Monster, activate the restaurant's subscription and verify the installation hostname. Mark the installation active and issue its license.
2. Deploy this Gelato build through the usual package workflow. Preserve live config and data directories. In Gelato, sign in as the protected owner and open **Admin → License & Updates**.
3. Enter the Food Monster installation ID and activation key, then confirm with the owner password. Gelato uses the HTTPS hostname from its existing app configuration. The server stores connection credentials in its validated private storage directory.
4. Check status from the owner page or ask the Food Monster Agent Chat about the license, expiry date, installed version, release notes, or the latest update result. The agent tools are owner-only, with the existing private approval required for a room-voice read.
5. To update, approve the exact release in the central account portal and verify the downloaded ZIP SHA-256. Back up the application and database, deploy the validated package, and use Gelato's existing **upgrade.php** for pending migrations. Verify the installation, return to **License & Updates**, and report the outcome. A completion report must match the installed version and cannot bypass pending or changed migrations.

`GELATO_VERSION.txt` is the public version for the first licensed build and future licensed release packages. The deployment artifact's existing `DEPLOY_SHA.txt` continues to identify the exact packaged commit. Increase the release version when preparing the next licensed build and publish that same version in the central registry.

The central automatic-update setting records a preference. The current integration provides owner-approved downloads, deployment reporting, and status checks. A central outage or loss of update entitlement leaves the restaurant application available.

For new Food Monster Cloud installations, use the `gelato-hosted.zip` from **Full Deploy Package**. Its private provisioning command seeds the Gelato schema and first Super Admin from the customer’s Food Monster setup, and writes the scoped license connection to private storage. Managed installations close the public first-user route. Self-hosted deployments still use Gelato's usual installation workflow.

## Build a signed Food Monster release

Food Monster requires an Ed25519 signature over the stable version and SHA-256 of the exact hosted ZIP. Choose a new stable `GELATO_VERSION.txt` for each release you intend to publish.

1. Create the GitHub Actions environment `hosted-release` in the Gelato repository and restrict deployment to the `main` branch. Enable required reviewers when available. Put the base64 Ed25519 secret key in the environment secret `GELATO_HOSTED_SIGNING_KEY`. If you previously configured a repository-wide copy of this secret, remove that copy. Keep the private key out of Git and both web deployments.
2. In **Actions → Full Deploy Package → Run workflow**, select `main`, enable **Build a signed Food Monster release from main**, and enter the exact version and release notes. The signing job uses the tested ZIP from that run. It checks the version and commit inside the archive and stops if the key or release details are missing. Regular runs produce the unsigned hosted ZIP.
3. Download the `gelato-hosted-release-VERSION` artifact. It contains the deployable `gelato-hosted.zip`, `gelato-hosted.sha256`, `gelato-hosted.sig`, `release-notes.txt`, and `release.json`. The manifest lists the source commit, version, ZIP checksum, and public key fingerprint. Compare the checksum against the ZIP before upload.
4. In Food Monster, add the detached signature for that version to `hosting.release_signatures` in the private configuration. Use the trusted public key obtained from your own signing key setup. Upload the inner ZIP as a stable draft under **Admin → Releases**, paste the notes, and review the version, checksum, and deployment flags before publishing. Choose the provisioning version separately in `hosting.release_version`. Set file-update compatibility only when the release requires no migrations and the database files match the supported installed version.

The artifact does not publish or deploy a release. Food Monster's existing admin publishing and owner approval steps control rollout. The detached signature authenticates the ZIP and version. The admin reviews the separate release notes.

For offline signing, download the verified `gelato-hosted` artifact from the chosen main-branch run and check its SHA-256. Supply the operator's base64 secret through the command environment and run:

```bash
php scripts/sign-hosted-release.php /path/to/gelato-hosted.zip \
  --bundle /path/to/new-output-dir "$RELEASE_VERSION" /path/to/release-notes.txt "$SOURCE_COMMIT"
```

`SOURCE_COMMIT` is the full commit shown by the trusted workflow run. To generate only the detached signature, pass just the ZIP path.
