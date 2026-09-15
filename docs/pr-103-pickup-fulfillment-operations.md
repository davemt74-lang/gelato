# PR #103 — Pickup Fulfillment Operations

This phase closes the physical pickup loop after Online Ordering → POS → KDS.

## Operating model

Payment, kitchen readiness, and physical fulfillment are separate facts:

- **Kitchen readiness** comes from every live KDS line being `ready` or `completed`.
- **Payment** comes from the canonical POS check/tenders.
- **Fulfillment** is an explicit staff action recorded only when the order is physically handed to the customer.

A paid ticket can still be cooking. A ready ticket can still have payment due. Neither condition alone marks the order fulfilled.

## Pickup workstation

`pickup-fulfillment.php` is a purpose-built counter workstation with:

- location-scoped pickup queues
- Active / Ready / Fulfilled / Cancelled views
- customer name, phone, email and notes
- promised pickup time and past-promise pressure
- Ready aging from the time the complete live KDS ticket became ready
- canonical POS payment status
- explicit **Handed to Customer** action
- 20-second live refresh while visible

## Handoff rules

`online_orders.fulfill` is the mutation permission. Existing roles that already carry `pos.use` or `kds.update` inherit it through the migration, and existing location scoping continues through `user_roles.location_id`.

Handoff is rejected when:

- the order is cancelled
- any live kitchen line is not ready/completed
- a pay-at-pickup order still has payment due

The first successful handoff stores `fulfilled_at`, `fulfilled_by_user_id`, and an optional fulfillment note, writes an audit event, and sends one transactional customer Inbox update. Repeated submissions are idempotent and do not duplicate the notification or audit event.

## Database

Migration: `database/20261005_pickup_fulfillment_operations.sql`

No parallel order system is introduced. Fulfillment metadata remains attached to the existing `online_orders` wrapper around the canonical POS check.
