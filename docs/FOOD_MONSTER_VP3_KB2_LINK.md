# FM-KB2 — Private Food Monster → VP3 HomeServer authorization

## Scope

KB2 implements the **Food Monster side** of individually authorized,
read-only HomeServer links. This is in the original `davemt74-lang/gelato`
repository, not `pizzathehut`. It runs on `https://gelato.vp3.me` and any
other **HTTPS** domain/subdomain where Gelato is installed: no hostname,
database name, external registry, or account is hardcoded.

KB2 is not yet OTRO's link UI. OTRO's multi-installation connector comes next.

## Installation

Deploy the merged Gelato package then run the protected `upgrade.php`
migration manager. Migration `20261020_fm_vp3_kb2_owner_pairing.sql`
adds one per-installation UUID and revocable per-owner connection records.
Never run migrations by manual SQL edits in a live installation.

## Owner approval

Open `/vp3-connections.php` while logged in as the protected Food Monster
owner on HTTPS. Create a connection with a human label and **explicitly
selected** `menu` and/or `recipe` scopes. The site displays a cryptographically
random, 256-bit one-time pairing code. It is shown only once, lasts 10 minutes
and is stored only as a SHA-256 digest.

Enter that code in the future OTRO owner-only connection flow. The OTRO
connector POSTs the code over HTTPS; exchange atomically consumes the code
and returns a separate 256-bit bearer credential. Food Monster stores only
its digest. A second redemption of the same code fails.

Every connection is independently listed and revocable from the owner page.
Revocation deletes BOTH pending code and access token digests; subsequent
access fails. Admin/user suspension or removal of the owner role also
immediately invalidates token-authenticated requests.

This is a one-time admin-issued enrollment-code protocol, **not OAuth2/PKCE**.
It must not be described as an OAuth authorization-code implementation.

## HTTPS wire contract

`GET /api/food-monster-vp3.php`: public metadata-only discovery, schema
`food-monster-vp3-v1`, persistent per-install UUID, supported capabilities,
pairing method. It returns **no** database data, users, keys or connected
applications.

`POST /api/food-monster-vp3.php` JSON `{"action":"exchange","code":"fmlink_..."}`:
redeems the one-time pairing code; response includes an accessToken once,
instanceId and approved scopes. Do not log, bookmark, embed, copy into
JavaScript, send in URL, or persist outside OS secret storage.

`POST /api/food-monster-vp3.php` JSON `{"action":"search","q":"dough","type":"recipe","limit":10}`
with `Authorization: Bearer fm2_...`: returns the existing KB1 structured
knowledge results. Search reads are bounded and permission-restricted to
the connection scopes; queries are POST-only to avoid URL history/logs.
Apache hosts may pass the Authorization header as
`REDIRECT_HTTP_AUTHORIZATION`. Permissions are refreshed against the
original owner's active organization membership and role on every request.

All API actions require actual HTTPS from the web server; client-supplied
`X-Forwarded-Proto` is not trusted. Deployments behind a TLS-terminating
proxy must configure the web server to correctly report HTTPS from a
**trusted** proxy before enabling this connector.

## Threat boundaries

- Only protected owner/super-admin identities can issue/revoke grants.
- Session CSRF required for owner-side grant mutations.
- No access to another organization's SQL rows; no shared token across
  installations.
- The bearer credential is never accepted from a query string, cookie, or
  cross-origin web application.
- No private content is returned by discovery; no permissive CORS policy.
- Only live authorized staff-menu and recipe reference data are readable.
  All other sources and actions remain unavailable.
- No write privileges, raw employee logs, payroll, HR, CRM, footage or
  general connector delegation.
- A revoked/suspended owner, revoked token or disconnected installation
  fails closed. OTRO must additionally wipe any local cached knowledge
  after disconnect, revocation, or scope reduction (KB3).
- This connector does **not** run commands submitted by the assistant.

## Follow-on OTRO work

1. Owner-only OTRO connection UI using a user-entered HTTPS origin, without
   accepting private/LAN or untrusted redirected destinations.
2. Check the pinned origin's discovery metadata and instance ID, then
   redeem the one-time code at exactly that origin.
3. Save the bearer token in HomeServer's protected credential store
   (Windows DPAPI); keep per-instance independent tokens and scopes.
4. Add bounded read-only query/search and UI connection status.
5. Add source-cited knowledge retrieval, incremental sync, revocation cache
   deletion, and Agent Brain access **only after** these contracts pass.

## Validation

Dedicated FM-KB2 workflow includes PHP 8.2 syntax/contract checks,
existing Food Monster KB1 and G13B knowledge regressions, and real MySQL
tests for one-time redemption, token hash-only storage, cross-tenant scope
isolation, code replay, owner suspension/role revocation and explicit revoke.
