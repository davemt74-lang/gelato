# FM-KB1 — Food Monster Knowledge API v1

This is the **first implementation** of the Food Monster → OTRO/HomeServer
knowledge integration, not a completed cross-application connection.

## Why a native API first?

Food Monster remains authoritative for recipe/menu data and permissions.
The PHP service layer in `includes/food-monster-knowledge-api.php` is reusable
by the browser-facing API and, later, a machine-authenticated connector or MCP
adapter. OTRO must **not** access Gelato's MySQL tables directly.

## Available read-only endpoint (v1)

`GET /api/food-monster-knowledge-v1.php?q=pizza&type=all&limit=10`

- `q`: required search phrase, 1–160 Unicode characters.
- `type`: `all`, `menu`, or `recipe` (default `all`).
- `limit`: 1–20 (default 10).
- Response includes `schema=food-monster-knowledge-v1`, `readOnly=true`,
  the authorized result types, and a bounded array of normalized documents.
- Each document contains a stable source ID, type, title, content, source
  attribution and a content SHA-256 revision. Recipe documents also expose
  the existing `updated_at`. Menu records have `updatedAt=null` when the
  source has no trustworthy timestamp.
- Menu ingredient/allergen observations explicitly warn that indicators
  cannot prove allergen safety; menu prices are omitted because they are live
  data, not a synchronized reference.
- Only active menu items and active, unarchived recipes are eligible.
- Unsupported methods return 405. Unauthenticated users get JSON 401, not
  an HTML redirect. Invalid requests return 422. Unauthorized categories
  return 403. Results are never publicly cached.

## Security and permissions

In KB1 this endpoint is **session authenticated only**. It is intentionally
not available to a headless HomeServer by supplying an arbitrary token,
browser cookie, URL secret, or user ID. All authorization is derived from
`app_current_user()`; no caller-provided organization or user identity is
trusted.

- Menu reads require `menu.view` or both `employee.self` and
  `training.self_view`.
- Recipe reads require both `recipes.view` and `recipes.agent`, matching
  Food Monster's existing recipe-agent read contract.
- If `type=all`, unauthorized categories are omitted, never read.
- Every data reader receives the authenticated user's organization ID.
- Read audits contain only category and result count, never raw questions
  or private recipe content.
- CORS access is not opened. No machine-to-machine credentials are issued.

## What KB1 does not yet provide

- No OTRO pairing/token exchange, and no cross-product login.
- No incremental sync, deletion/tombstones, downstream retention policy,
  or cache revocation yet.
- No staff, CRM, payroll, camera or conversation export.
- No write, publish, mutation or workflow tools.
- No standalone public MCP server.

## Next gated sections

1. **KB2**: An owner-approved, revocable, scope/organization-bound connector
   grant and authenticated HomeServer outbound HTTPS reads. Credentials
   stay in protected stores, not request URLs.
2. **KB3**: Revision/tombstone-aware knowledge sync into OTRO's existing
   SQLite search index, with provenance, revocation purge and stale markers.
3. **KB4**: Main VP3 Agent Chat retrieval with source citations and the
   existing permissions and Cognitive Runtime.
4. **KB5**: Governed live reads for changing operational datasets.
5. **KB6**: Optional MCP adapter over the same service layer.

Each section must pass its own tests, exact-head CI, PR merge, and post-merge
verification before the next begins.
