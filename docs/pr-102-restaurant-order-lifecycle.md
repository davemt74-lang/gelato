# PR #102 — Restaurant Order Lifecycle

This phase makes the canonical POS check the shared operational source of truth across online ordering, KDS, CRM, Sales Intelligence, customer order history, and transactional customer Inbox updates.

## Lifecycle

`submitted → in_kitchen → preparing → ready → kitchen_complete → completed`

Cancellation is terminal. Kitchen recall can intentionally move a bumped kitchen ticket back from `kitchen_complete` to `ready` without duplicating the customer Ready notification.

## Readiness rule

An order is Ready only when every non-cancelled KDS item is `ready` or `completed`. A single ready line while another item is queued or preparing cannot mark the full order Ready.

## Mutation wiring

- Online checkout persists the live KDS-derived status immediately after kitchen send.
- KDS item transitions, recalls, and whole-ticket actions synchronize the online order.
- POS kitchen send, kitchen-line void, final tender, and check cancellation synchronize the online order.
- Non-online POS/KDS checks safely no-op because the synchronizer only acts when an `online_orders` record exists for the canonical POS check.

## Customer communication

Transactional order updates are sent for Preparing, Ready, Completed, and Cancelled milestones. Repeated synchronization is idempotent, and Kitchen complete after Ready does not send a duplicate Ready notice.

## Regression coverage

The end-to-end contract creates a real customer account and two-line online pickup order, routes both lines into KDS, verifies line/order notes survive into POS/KDS, proves partial readiness remains Preparing, proves all-lines readiness becomes Ready, bumps the ticket at Expo, tenders the POS check, verifies CRM and Sales Intelligence, and exercises cancellation plus notification idempotency.
