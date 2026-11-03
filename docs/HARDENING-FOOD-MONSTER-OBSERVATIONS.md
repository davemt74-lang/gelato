# AI-01 — bounded Food Monster observations

## Defect and fix

The execution loop and step checkpoint helper independently rejected every
observation above 3,072 encoded JSON bytes. Legitimate owner capability
snapshots, supplier comparisons with price history, and low-stock responses
could therefore execute their read successfully and then return a generic
unverified failure. Several other read adapters limited only the answer's
character count while also returning structured records. Escaped Unicode and
the combined prose/record payload still exceeded the wrapper contract.

`includes/food-monster-observation-contracts.php` now owns the finite policy for
each reviewed pure read. Producers, the execution loop, checkpoint writes and
checkpoint replay use the same JSON encoding and the same byte limits. The
encoding includes JSON quoting, backslash escaping and escaped Unicode. Invalid
UTF-8, non-finite numbers, objects, recursive arrays and excessive nesting fail
validation; they cannot be converted into successful partial observations.

Unknown tools, navigation, proposal staging, domain delegation, pending actions
and routine writes retain the original strict 3,072-byte result contract.
`impact=read` alone never enables compaction: some such tools stage proposals or
continue actions. Large write/proposal outcomes are not modified by the new
compactor. Existing shared action wrappers remain unchanged; dedicated read
wrappers were added for scheduling, training and Vision mission/dataset reads.

## Explicit budgets

All values below are encoded JSON bytes. The complete allowlist is executable
server code in `fm8do_contracts()`; no budget is accepted from tool arguments,
the provider, the browser or a returned observation.

| Registered read family | Observation limit | Answer limit | Policy |
| --- | ---: | ---: | --- |
| Current user and personal hours | 3,072 | 3,000 | Bound prose; preserve other fields |
| Scheduling, menu/recipes, training reads, Vision reads, record broker, A/R aging | 4,096 | 3,000 | Bound prose; preserve other fields |
| Availability, CRM, marketing, catering, wholesale detail/search, stored prep shortages | 6,144 | 3,000 | Bound prose and retain complete prefixes of declared record collections |
| Capability catalogue | 8,192 | 3,000 | Producer emits counts and a bounded registry sample |
| Low-stock insights | 8,192 | 3,000 | Producer budgets whole alert rows and matching prose |
| Recorded receipt list | 8,192 | 7,000 | Producer budgets complete numbered entries |
| Daily Prep checklist | 12,288 | 11,000 | Producer budgets complete numbered entries |
| Need More supplier comparison | 16,384 | 10,000 | Producer budgets complete numbered supplier terms |
| Unlisted tools and all routine outcomes | 3,072 | Part of complete result | Strict validation; no new compaction |

Ordinary read answers remain at most 3,000 encoded bytes so a four-read response
fits both the existing 24,000-byte task receipt and the 12,000-character
canonical chat message limit. Checklist, receipt and supplier reads run alone:
multiple numbered reads in one turn would also replace their session selection
context before the user could use it. The model instruction explains this rule;
the server stops an invalid batch before execution and asks the user to request
one numbered list at a time.

## Result semantics

### Capability catalogue

The authenticated capability UI continues to receive the full diagnostic
snapshot. The terminal tool observation includes the original complete counts,
readiness limitations, owner-only inventory visibility, and a bounded sample of
registered tool IDs and agent domains. It does not duplicate every native-tool
descriptor or all planned source-contract descriptions. Displayed/total counts
and omitted detail are explicit. Registry presence still does not claim an
execution test, and proposed source contracts still grant no authority.

### Supplier comparisons and selection safety

The supplier read keeps full supplier names, exact pack/price terms, overage,
minimum warnings, eligibility and global open-order blockers. Each displayed
supplier includes the two most recent entries from the source helper's maximum
five price-history entries, with retrieved counts and a truncation flag.

The adapter builds a complete candidate observation for each next supplier and
checks its encoded size before adding it. It stops at a complete prefix of at
most 20 options; it never slices the answer through a supplier or silently
renumbers an option. It then saves only that exact displayed prefix in the
existing user/organization/thread/item-bound selection context. The original
complete comparison hash is retained, so all existing fresh term checks and
the separate Confirm contract continue to apply. Undisplayed numbers do not
resolve.

At most three complete open-order detail rows are retained. Total retrieved
order counts, omitted rows and the source helper's bounded-count status are
reported, and the complete draft blocker is preserved even when order details
are omitted. If the source helper finds more than its 60 supplier/50 open-order
limits, the prose uses an explicit lower bound instead of claiming an exact
total. No price, pack, ordering permission or stock movement is inferred.

### Low-stock insights

The read filters active tenant inventory against saved reorder points in SQL
before applying a 400-row retrieval bound. A scalar count in the same SELECT
counts all matching inventory rows in the same database read snapshot. The
observation reports the exact total match count, retrieved count, displayed
count and truncation status. This avoids treating the general inventory
picker's first 400 items as the entire inventory or missing all low-stock
items that sort after those first 400.

The final observation includes at most 12 complete alerts and only the alerts
whose prose fits the answer budget. Recipe association still requires current
Recipe View permission and exact saved ingredient names. The existing source
helper returns up to five recipe links per item, which the answer states. The
response remains a reorder-point indicator, not a recipe deficit forecast or
purchase quantity.

### Checklist, receipt and other reads

Checklist counts cover all active matching rows, not just the first 20. Only
complete displayed checklist entries are saved in ordinal selection context.
Optional numeric and purchasing summaries are included only when they fit;
the answer directs the user to Daily Prep and Purchasing for complete details.
Receipt output states the bounded recent-ledger lookup, reports retrieved and
displayed candidate counts, and saves only the displayed numbered entries.

For other explicitly reviewed reads, compaction can shorten the prose and
remove complete suffixes of declared record collections. It preserves the
original order and every field of retained records. A declared aggregate
record, such as catering readiness metrics, can be omitted only as a whole.
The `observationSummary.collections` metadata reports retrieved and included
counts; these counts describe the adapter's result, not an invented total
database count. The algorithm halves large collections while fitting them,
avoiding repeated serialization of thousands of nearly identical large arrays.
Unlisted oversized fields remain a contract failure. Existing source-level
search limits are retained.

Read-only adapters no longer silently clip their answer before this shared
policy can report truncation. CRM notes are retained as complete records or
omitted as complete records, rather than silently cutting note bodies.

## Local evidence, links and recovery

Tool evidence stays in the local execution/checkpoint path. It is never sent
back to the external provider for summarization. `fm8d3_turn()` propagates
`data.readDetails` entries containing `tool`, `label` and `url`; every destination
comes from the fixed server contract, not a model/observation-supplied URL.
The response also includes human-readable directions to the relevant screen.

Fresh actor refresh, per-tool authorization, argument validation, audit and
checkpoint order are unchanged. Before reusing a saved step, the executor
rechecks authority and validates the already stored result against its contract.
Replay never trims a saved observation into success. Checkpoint reads enforce
the byte bound before JSON decoding, and writes reject oversized/malformed
observations before starting their database transaction. The task journal's
24,000-byte final result limit is unchanged.

## Verification

Run against PHP with mbstring, PDO SQLite and PDO MySQL. The MariaDB test uses
the normal application configuration and an isolated database with all current
migrations applied. Both new test entry points reject non-CLI execution before
bootstrap. Provider transports are in-process stubs; tests make no external AI
or production calls.

```sh
php tests/hardening-food-monster-observations.php
php tests/hardening-food-monster-observations-mariadb.php
php tests/food-monster-b8d2-execution-loop.php
php tests/food-monster-b8d3-tool-selection.php
php tests/food-monster-b8d17a-capability-truth.php
php tests/food-monster-b8d13c-step-checkpoints.php
php tests/need-more-vendor-agent-contract.php
php tests/daily-prep-quicklist-contract.php
```

The pure test audits the real owner catalogue, executes it through `fm8d2_run`
and `fm8d3_turn`, exercises malformed/unknown/write outcomes, checks complete
record compaction, rejects oversized saved observations and verifies the
four-read aggregate reply budget.

The MariaDB test executes the production catalogue without a catalogue override
through fresh SQL authorization, real reads, step checkpoint persistence and
the final task journal. Its fixtures include 1, 3, 10 and 20 supplier mappings,
five real history entries each, long Unicode supplier names and multiple open
orders; a 600-item low-stock inventory; recipe access changes; long numbered
checklists; 20 physical receipt ledger entries; and oversized checkpoint
tampering. It verifies exact displayed-number mappings, complete comparison
hashes, no hidden options, source counts, the 24 KB/12,000-character reply
limits, fresh revocation checks and absence of private result records from
provider payloads. It also stages the real native inventory-change proposal
through `fm8d3_turn()` with a canonical request context, verifies the final
response retains its proposal ID/thread/source-message metadata, and verifies
that no stock or receipt write occurred.

PHP and MariaDB are not installed in the local editing environment. Syntax and
runtime execution of these tests must be supplied by the release CI job; a
static diff check alone is not evidence that the composed PHP/SQL path passed.
