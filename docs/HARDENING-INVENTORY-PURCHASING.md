# Hardening Release B — inventory and purchasing integrity

This change addresses audit findings DATA-02, DATA-04, and DATA-05. It applies to
manual operations, Purchasing, and the existing Food Monster confirmed actions.

## Stock movements

The canonical stock service takes the inventory row lock, validates the requested
movement, and calculates the balance at the database's four-decimal precision.
Every new committed transaction satisfies:

`previous quantity + recorded delta = resulting quantity`

Use, waste, and negative adjustments that exceed available stock are rejected.
Counts cannot be negative. Nonfinite, out-of-range, excess-precision, and zero
non-count movements are rejected. A physical count of zero remains valid. An
unconfigured stock item needs a base unit before its first movement.

The stock balance, immutable inventory transaction, and runtime audit record are
committed together. An audit failure rolls back the business change. Existing
historical transactions are preserved; this release does not rewrite earlier
inconsistent ledger entries.

## Inventory units

A saved nonempty base unit cannot be changed through ordinary item editing.
Case-only inputs retain the original spelling. Initial unit assignment is allowed
only before quantities, costs, stock history, purchase-order lines, or numeric Need
More requests exist. Metadata such as the name, location, par level, reorder point,
and unit cost remains editable with validation.

Changing kilograms to grams or each to case requires an explicit reviewed
conversion or a separate inventory item. This release does not silently convert
balances, prices, purchase orders, or historical requests.

Receiving compares the immutable PO line unit against the current inventory unit.
Missing or mismatched units fail before receipt, stock, price-history, or audit
writes. This also protects installations with legacy inconsistent unit data. The
Need More purchasing board displays order quantities in the saved PO unit.

## Purchase-order transitions

Submission, receipt, and cancellation re-read the tenant-scoped PO header while
holding its row lock inside a transaction. Agent proposal status and revision
checks are repeated under the lock. All existing permission and high-impact
approval requirements remain in place.

A cancellation that wins the race prevents subsequent receiving. A receipt that
fully completes the order prevents cancellation. Partial cancellation closes only
the unreceived remainder: prior receipts, delivered quantities, inventory, and
price history remain intact. The cancellation event records each cancelled
remainder in its saved unit. A cancelled order is removed from open-order coverage,
while any unfulfilled Need More request remains available for a new reviewed draft.
No vendor message is sent by these actions.

The stock IDs on a receiving order are locked in ascending order before the header,
matching the existing draft-creation lock order. The initial identity read grants
no status authority; the order and all quantities are revalidated after the locks.
Cancellation and submission never wait for stock rows. This avoids introducing a
stock/header lock inversion between drafts and receiving.

The canonical cancellation service also replaces the legacy agent helper that
wrote a nonexistent `purchase_orders.cancelled_by` column. The existing
`updated_by`, event actor, and audit actor record the responsible user without a
schema migration.

## Verification

Run after the normal database install and upgrade on MariaDB with native PDO
prepares enabled:

```sh
php tests/hardening-inventory-purchasing-contract.php
node tests/hardening-inventory-http.cjs
```

The contract suite uses independent PHP processes and database connections. It
covers concurrent stock deductions, concurrent receipts, both cancellation/receipt
interleavings, cancellation during submission, partial cancellation, exact final
fractional units, tenant boundaries, stale proposals, and forced audit failures.

The HTTP suite signs in independent owner, viewer, and foreign-tenant clients. It
checks actual persisted API writes, denied permissions, CSRF failures, insufficient
stock, unit changes, partial receipts, cancellation, and guarded test entrypoints.
Both fixtures are CLI-only and return 404 over HTTP. Tests use isolated fixture
organizations and do not contact production services.
