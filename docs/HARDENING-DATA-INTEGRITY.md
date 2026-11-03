# Hardening D — complete totals, covered shift dates and current recipe sources

This change follows merged Release C at
`5f328cc67babaa4a654f1562c8b0e623c3defe98` and addresses original findings
DATA-06, DATA-07, DATA-09 and DATA-10. DATA-08 was already closed in Release A:
loading Jobs or the authenticated workspace no longer publishes demo jobs. That
behavior remains an existing regression requirement, not a new closure credit.

## Complete wholesale receivables

`wholesale_receivables_aging` now calculates balances and aging buckets in SQL
over the complete tenant ledger. An optional account argument scopes the same
calculation to one customer. Account, invoice, order and receivable entry joins
retain organization scope; entry account IDs must also match their invoice.
Draft and void invoices do not contribute debt or credit, paid balances remain
zero, and customer credits remain separate from positive receivables. Money is
summed using the existing database decimal columns and converted to the existing
response number contract after aggregation.

The internal invoice list remains bounded at 300 records per page. The read API
accepts `limit` (1–300) and nonnegative `offset`, and returns `invoicePage` with
`totalCount`, `displayedCount`, `limit`, `offset` and `nextOffset`. A stable ID
tiebreaker gives invoices with equal dates deterministic ordering. The portal
keeps its 100-record page, accepts `invoiceOffset`, and returns the same page
metadata. Both use `totalsScope: complete_ledger`; changing a page never changes
the monetary totals. Existing first-page clients remain compatible.

The native PDO regression includes empty ledgers and boundaries of 100, 101,
300, 301 and 2,001 invoices, aging bucket boundaries, mixed payments/credits,
drafts/voids, account and tenant isolation, adjacent pages and the original
portal case of 100 recent paid invoices hiding older unpaid and credit invoices.

## Every covered scheduling date

Shift validation loads the restaurant's saved timezone. It validates complete
timestamps, converts explicit offsets into that timezone, retains database
microseconds and evaluates each covered local date. Intervals are half-open:
an end exactly at midnight does not include the following day, while a later
end does. Approved time off and unavailability are checked on every included
date. Adjacent or overlapping availability windows can jointly cover a shift;
gaps cannot. Approved dated availability can cover a recurring gap or restriction;
approved time off always blocks an overlap. Days without recorded recurring
available windows retain the existing unrestricted-by-default behavior, subject
to explicit unavailable windows and approved leave.

Availability times are validated before replacing saved windows. `24:00` is
accepted only as an ending boundary. Invalid input leaves the previous windows
intact. The 24-hour shift limit measures actual elapsed time in the restaurant
timezone, including daylight-saving changes.

The existing database stores local `DATETIME(6)` values without a timezone
offset or repeated-hour marker. A shift boundary that falls in a nonexistent
spring time or a repeated autumn time is therefore rejected with an explanation
instead of silently choosing or changing it. An unambiguous shift can span the
clock change: midnight to 4 AM across spring-forward is three elapsed hours;
midnight to 3 AM across fall-back is four. Recurring wall-clock windows cover
both occurrences of their saved wall-clock range. Each window is intersected with
constant UTC-offset segments; a repeated hour can produce separate intervals,
so gaps between positive windows remain gaps in both occurrences. Nonexistent
spring pieces vanish without authorizing times outside the saved window. Calendar
dates advance independently of local midnight, including zones where a clock
change skips midnight. This release does not change the stored time model or
claim support for choosing a particular repeated-hour shift boundary.

Native regression coverage exercises daytime and overnight windows, following-day
leave, exact-midnight and fractional-second ends, adjacent windows and gaps,
dated exceptions, isolation, malformed dates/times, daylight-saving changes,
explicit offsets and the actual shift save service.

## Ingredient amounts and names

Operations and Catering share the pure `recipe-ingredient-parser.php` parser.
It recognizes complete decimal, simple-fraction, mixed-fraction and Unicode
fraction quantities. Plain-text unit recognition uses a bounded known-unit
vocabulary, with multiword units matched before the ingredient name. For example,
`2 tbsp olive oil` retains the name `olive oil`, and `1 1/2 fluid ounces extra
virgin olive oil` retains both the full name and the correct amount.

Structured ingredient fields remain preferable for custom units. A unitless
description is retained without inventing a unit. Ranges, qualitative quantities,
embedded unit text in a numeric field, zero denominators, negative amounts and
nonfinite values are not converted into the first number they happen to contain.
The result carries the original input and an explicit review reason. Recipe cost
detail reports the gap, and an incomplete ingredient cost no longer becomes a
supposed complete per-serving unit cost. Catering retains the review reason in
the requirement note and leaves the unreviewed amount unavailable.

## Atomic recipe source reconciliation

Inventory source synchronization starts its own transaction or a savepoint when
the caller already owns one. It shares the organization lock with stock writers'
foreign-key checks and locks saved recipe rows in ID order to serialize recipe
projections. It then validates and stages the complete active recipe source set before changing
inventory or source links. An ambiguous or malformed ingredient aborts the sync
with the recipe and line requiring review. The previous complete source set
remains available until the recipe is corrected and synchronization succeeds.

Duplicate normalized ingredient names with the same normalized unit are summed.
Conflicting duplicate names/units require review. The existing source column has
four decimal places; the summed amount is rounded once to that storage scale.
A positive sum that would become zero requires review before any source writes,
so a small ingredient cannot silently disappear from downstream demand.
The original ingredient lines remain in metadata together with the parser
version, saved recipe timestamp and SHA-256 fingerprint of the recipe name and
ingredients.

The service upserts desired links and removes obsolete recipe links, including
links belonging to inactive or archived recipes. It preserves menu/manual links,
inventory items, stock units, on-hand quantities, prices and physical inventory
transaction history. Each changed recipe source set writes its full before/after
snapshot to the existing audit log in the same transaction. Audit failure rolls
back the source writes; failure inside a savepoint preserves caller-owned work.
No new schema migration is required.

Prep forecasts and wholesale commitments are separately saved domain projections.
Source synchronization does not silently replace those existing projections.
Their normal explicit rebuild/refresh uses the current source links and active
recipes. The regression runs both actual services after ingredient removal and
recipe archival: removed demand disappears, obsolete commitments become released,
and commitment/physical-stock history remains preserved. Existing completed
consumption is never deleted to make projected demand appear current.

## Verification

Run against the normal clean-installed and fully upgraded isolated MariaDB
database with native PDO prepares:

```sh
php tests/hardening-recipe-ingredients.php
php tests/hardening-receivables-totals.php
php tests/hardening-scheduling-intervals.php
php tests/hardening-recipe-sources.php
php tests/hardening-recipe-source-concurrency.php
```

The source-reconciliation regression uses one randomized database trigger to
prove rollback after a real audit insert failure, then drops the trigger in a
`finally` block. It needs `TRIGGER` privilege on the isolated test database.
The source concurrency regression observes a real InnoDB wait while a stock
transaction holds the first inventory item, then inserts its ledger entry using
the actual organization foreign key. Both the stock transaction and source
refresh must finish without a deadlock or database error, preserving the physical
quantity, ledger and recipe link. It needs the same process and InnoDB transaction
inspection access as the existing concurrency suites.
All entrypoints reject web execution before loading application bootstrap.

The original source reproductions fail before later new-helper assertions:
the parser drops `olive` from the first ingredient name; the 301-invoice total
is truncated; the daytime window incorrectly accepts an overnight shift; and
the recipe source stores `1/2` as `1`. These are behavioral failing-before cases,
not claims based only on static pattern matching.

Existing scheduling, sales-cost, wholesale receivables, wholesale demand and
workspace privacy suites remain required. CI results and release certification
are recorded separately after execution; this document describes the contracts
and does not claim production or physical-device testing.
