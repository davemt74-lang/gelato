# Gelato AR Glasses Plugin — Section 1 Foundation

This section adds the hardware-neutral device foundation used by the INMO AIR3 client.

## Responsibilities

- one-time pairing grants scoped to a Gelato organization, location, and optional KDS station
- long-lived per-device bearer credentials stored only as SHA-256 hashes
- device inventory and station assignment
- heartbeat/version/capability reporting
- revoke/re-pair lifecycle
- append-only device event history
- explicit `glasses.view` and `glasses.manage` permissions

The browser KDS API remains session + CSRF protected. Glasses do not reuse browser sessions.

## Device flow

1. An authorized Gelato user creates a short-lived pairing code.
2. The glasses POST `action=pair` to `api/glasses-device.php`.
3. Gelato consumes the pairing grant once and returns the device token once.
4. The glasses store that token securely and use `Authorization: Bearer <token>`.
5. Heartbeats update device software/system versions, capabilities, and `last_seen_at`.
6. Revocation immediately rotates the stored token hash so the old bearer credential stops authenticating.

No recipe/KDS work payload is included in Section 1. That is intentionally reserved for the next release unit.
