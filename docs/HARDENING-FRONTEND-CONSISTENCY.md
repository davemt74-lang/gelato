# Hardening frontend consistency

## Scope

This change addresses audit findings **FE-06** and **FE-07** plus the related
**FE-O1** cart badge defect. The changes operate on the shipped KDS, both online
cart entrypoints and the shared public header. Server-side kitchen permission,
location checks, pricing and order validation remain authoritative.

## FE-06: a kitchen response belongs to one selected view

Every KDS read captures the location, station and live/history mode, a view
generation and a request identity. A new selection immediately clears incompatible
tickets, action controls and spoken order-slot references and starts a new read.
The old request is cancelled when possible. Its completion is also checked against
the captured identity; cancellation alone is not relied upon. Returning to a
previous selection does not make an older response current again.

Reads for the same view are coalesced during polling. A read has a 15-second
deadline. A failed history request remains eligible for polling retries, and the
Refresh button can replace a stalled request. A response that reports a different
location is refused.

Kitchen mutations require a loaded matching view and the existing update
permission. Their requests retain the location and station visible when the action
was chosen. Writes are serialized in the client; the backend still checks each
write independently. A write invalidates older in-flight reads, and a late write
response cannot replace a newly selected location/station/history board. Completion
refreshes the current view. An action response discarded after navigation cannot
set the new view's last-bumped ticket or display a misleading success message.

## FE-07: customization changes require a ready editor for the exact row

Each Customize opening owns an object identity, the original cart-row object, its
price identity, its own cancellation controller and a detached draft. Save is
disabled while the current catalog loads and on error. The save handler checks the
same conditions even if invoked programmatically. A catalog must identify the
requested price and provide the expected ingredient, substitution and add-on
collections before the draft becomes saveable.

Closing or reopening the editor replaces its identity. Both delayed successes and
delayed errors from an old editor are ignored. Removing the edited row closes the
editor; shifting another cart row into its previous numeric index cannot retarget
an outstanding response or Save.

Controls modify the detached draft, and Save copies that draft to its exact row.
It does not rebuild a replacement customization from whatever controls happen to
exist in a loading or error DOM. Existing removals, substitutions, paid add-ons and
notes survive failed or partial catalog availability. Saved choices absent from
the current catalog are retained and explicitly identified as unavailable. They
still undergo the existing server validation at checkout. Deliberate checkbox,
substitution, add-on and note edits remain supported. Cancel discards draft edits.
Both the active Food cart and the retained older cart entrypoint use this behavior.

Catalogs are refreshed on each opening rather than reusing a potentially stale
successful response from an earlier item edit. Both entrypoints also retain paid
add-on amounts and display them in their cart totals.

## FE-O1: one cart version and one pickup location per badge

The public shell defines the shared `GelatoPublicCart` adapter before the order
entrypoint starts. Both cart implementations and the badge use the current v3
storage keys. If v3 is absent, the adapter migrates one v2 or v1 cart and its order
note. It prefers v2, retires old keys only after saving v3, and never adds legacy
versions together. An existing empty v3 cart stays empty. If migration storage is
blocked, the still-readable legacy cart is returned without deleting its source.

Each pickup location keeps its own cart. On the ordering page the server-rendered
location is used. Other public pages obtain the selected eligible pickup location
from the public shell endpoint, using the same saved location selection and
online-order eligibility rules as checkout. The badge counts only that location;
switching locations preserves each location's separate cart rather than combining
orders from different kitchens.

Successful cart mutations issue `stonefellows:cart-changed`. The shell updates its
badge from the same adapter, including when it replaces the initial page header.
The obsolete v1-only observer no longer overwrites the active cart's count. A live
in-memory quantity remains available if browser storage becomes full.

## Validation

Run the dependency-free shipped-code tests with Node:

```sh
node tests/hardening-frontend-consistency.cjs
```

The local suite passes **66 behavioral checks**, including delayed requests whose
mock transports deliberately ignore AbortSignal cancellation. Its KDS and both
editor cases fail against the immutable pre-change source at
`5f328cc67babaa4a654f1562c8b0e623c3defe98`: the old KDS exposes stale order slots after
a selection change, and both old editors leave Save enabled during loading.

The real Chromium journey uses the actual production browser files, their event
handlers and DOM rendering, with controlled API responses on a temporary localhost
server. It requires Playwright and its Chromium binary, with no PHP/database
fixture:

```sh
node tests/hardening-frontend-browser.cjs
```

It covers rapid KDS location/station/history changes and a delayed write response;
loading/error editor guards; close/reopen to another item; ordinary ingredient,
substitution, paid add-on and note edits; preservation of unavailable choices;
removal of an edited row; and public-header badge consistency in both cart
entrypoints. API responses in this UI suite are fixtures, not a claim of backend
or live restaurant acceptance. The PHP/domain suites separately exercise backend
behavior. Release CI is responsible for the Chromium execution result because the
local workspace does not include the browser binary.

The existing static contracts continue to check the current active cart,
customization/paid-add-on server propagation, KDS CSRF, dedicated history endpoint,
continuous order slot numbers, and public shell mounting. Their asset markers now
refer to the cache-invalidated files. This scope adds no schema migration.
