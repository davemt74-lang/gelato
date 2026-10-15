# FM-G7 — Read-only multi-node Gelato Agent orchestration

## Scope
This section adds an authenticated manager-level multi-domain *briefing*, not autonomous execution. Example: "Check today's orders, inventory and employee schedule and tell me what needs attention."

The existing Gelato Agent Chat routes explicit multi-area, read-only review requests to `api/food-monster-orchestrate.php`. The endpoint validates Gelato authentication, manager Brain permissions, CSRF, the user-owned canonical conversation and its latest message. It then reads the existing permission-filtered Agent Brain orchestration snapshot and produces prioritized findings and suggested reviews.

## Authorization and integrity
- All data comes from the existing Brain snapshot, already scoped to organization and role. Unrelated signal groups are filtered out.
- Requests to create, approve, change, delete, publish, confirm or execute actions are not intercepted.
- The response contains source labels and an explicit read-only status. Empty exception signals do not certify that everything is healthy.
- A 3-minute session cache keyed to the authenticated user, organization, thread and latest message avoids duplicated briefing work.
- No model-generated tool calls, arbitrary endpoints or credentials. The existing confirmed-action flows remain responsible for writes.
- Non-manager accounts continue through the original permission-scoped domain routing.

## Software acceptance
Run `php tests/food-monster-g7-contract.php` and `node tests/food-monster-g7-browser.test.cjs`. Existing FM-G1–G6 tests remain in the dedicated workflow.

## Limitations and next work
A multi-node briefing is not unrestricted access to all Agent skills. It reports **exception signals**, not full order, schedule or inventory listings. A future independently certified section can add specific approved, read-only tool adapters, and later governed multi-step changes with per-step confirmation, evidence receipts, and idempotent action logging.

Field acceptance requires a manager account, seeded records across multiple areas, a non-manager denial check, and live verification on the installed Gelato instance.
