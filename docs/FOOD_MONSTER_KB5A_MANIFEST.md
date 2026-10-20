# KB5A — Bounded Food Monster Knowledge Manifest API

## Purpose

The main VP3 assistant can already search live, authorized Food Monster
knowledge (KB4A), connect through chat (KB4C), and revoke connections on
both systems (KB4B). KB5A provides a **read-only, versioned collection
enumeration** API so the next OTRO phase can build an explicitly approved,
locally searchable knowledge index with change detection and deletion
handling. This does not share Gelato's database, logs, passwords or connectors.

## HTTPS wire format

The owner-approved bearer is used only from HomeServer's protected
credential vault; never in a URL or browser-side script.

```http
POST /api/food-monster-vp3.php
Authorization: Bearer fm2_<opaque secret>
Content-Type: application/json

{"action":"manifest","type":"recipe","afterId":0,"limit":20}
```

`type`: required, **exactly** `menu` or `recipe` (no `all`); owner approval
must include that category. `afterId`: nonnegative 32-bit source row ID,
initially zero. `limit`: integer 1–20. The response contains
`schema=food-monster-knowledge-manifest-v1`, collection identity,
`results` using the existing KB1 safe normalized evidence and SHA-256
content revisions, `complete` boolean, and `nextAfterId` either the last
emitted source row ID or null after the complete final page.

All source database queries filter on the **server-validated organization**
and active status, and sort by stable internal ID. An inactive, draft,
archived or wrong-restaurant record cannot be enumerated.
The internal numeric row ID is only an opaque source-local page cursor
and never grants access to another tenant; source result identifiers
remain `gelato:menu:<id>` and `gelato:recipe:<publicId>`.

No source updates are performed. Audits contain only category, result
count and completion status, not ingredients, private recipes or query text.
Discovery advertises `knowledge.manifest` only as a supported capability,
not an authorization grant.

## Critical deletion and freshness rules for KB5B

1. The HomeServer must scope its local index by **connection ID, installed
   Food Monster instance ID, category and original record ID**. Shared
   display titles do not identify records.
2. Enumerate all pages of one category under its current granted scope.
   Do **not** delete anything merely because a record was absent from a
   partial, interrupted, timed-out or failed sweep.
3. A changed SHA-256 revision means the source content changed. Re-fetch
   and replace the previously indexed projection for that source key only.
4. The current protocol is forward-ID pagination, **not an atomic
   transaction snapshot**. Source edits during a scan may race with
   pagination. To avoid false tombstones, require two complete consistent
   observed enumerations before pruning a previously indexed record, or
   implement a subsequent source snapshot epoch/version contract.
5. If a connection is revoked, becomes unauthorized, or has a reduced
   scope, immediately stop and delete that connection's local private
   cached documents. Do not use stale excerpts as current facts.
6. When offline, the assistant must label any retained authorized cached
   evidence as stale. Do not claim inventory or other changing operations
   are current from a cached snapshot.
7. Cache includes only the approved, normalized KB1 projections, not
   arbitrary raw database rows, payroll, CRM, employee documents or
   customer conversations.

KB5B will implement HomeServer's local indexing, cursor control, TTL,
revision replacement and cautious deletion reconciliation. KB5A by itself
does not synchronize any data or enable new AI tools.

## Deployment

Original repository: `davemt74-lang/gelato` (not `pizzathehut`).
Current example installation: `https://gelato.vp3.me`. The code uses
relative PHP include paths and the existing instance/pairing database,
without a hardcoded domain. The protected owner can keep hosting the
installation on any public HTTPS subdomain.

Tests cover explicit grant restrictions, per-restaurant MySQL isolation,
stable ordering/cursors, active/archive filtering, changed revisions,
single-page and multi-page collection completion, and bearer revocation.
