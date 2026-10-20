# KB4B — Food Monster source-side HomeServer revocation

This change extends the private, self-hosted Food Monster connector from
Gelato KB2. It is designed for the original `davemt74-lang/gelato` script
at **any configured HTTPS domain or subdomain**. The current installation
is `https://gelato.vp3.me`, but the hostname is not embedded in source.

## Authenticated wire contract

`POST /api/food-monster-vp3.php`

```http
Authorization: Bearer fm2_<opaque secret>
Content-Type: application/json

{"action":"revoke"}
```

The exact bearer credential identifies **one** previously approved VP3
connection. A valid active credential may permanently revoke **itself
only**. The server atomically changes that connection to `revoked`,
clears any pending pairing-code and bearer-token hashes, and returns:

```json
{
  "ok": true,
  "schema": "food-monster-vp3-v1",
  "revoked": true,
  "instanceId": "<source installation UUID>",
  "connectionId": "<approved connection ID>"
}
```

The owner HomeServer must compare both returned identities to its stored
connection before reporting source revocation verified. A repeated
self-revocation, unknown bearer or malformed credential fails closed.

## Security

- The existing HTTPS check applies to every request. Credential values
  must be sent in Authorization headers, never query strings or cookies.
- Only the holder of an active, randomly generated 256-bit connection
  bearer can invoke this endpoint; a different token cannot revoke it.
- The operation is read-authority-decreasing only. It cannot list grants,
  create grants, change scopes or return restaurant data.
- Revocation is permitted even if the original owner was suspended, so a
  stale grant can be destroyed safely; knowledge reads remain denied.
- The existing protected owner page continues to offer manual revocation.
- No global Food Monster registry, shared owner login, Slack dependency,
  central database, MCP server or hardcoded domain is introduced.

## Follow-on OTRO change

The local OTRO disconnect action must POST `action=revoke` with its stored
bearer token **before** deleting the local token. If Food Monster is offline,
or the remote response is unexpected, OTRO must still support local secret
destruction but prominently explain that the Food Monster admin must manually
revoke the lingering source-side credential from `/vp3-connections.php`.
Never say remote revocation succeeded merely because the local token was
deleted.

This PR contains only source-side code and real MySQL revocation tests.
No live production installation was contacted or changed.
