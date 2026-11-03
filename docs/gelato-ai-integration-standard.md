# Gelato AI Agent / Brain / Chat integration standard

**Standing product requirement:** Every Gelato workflow needs an authorized read and action path through Gelato's official AI Agent/Brain/Chat, alongside its direct UI. Do not introduce a second independent assistant or a parallel permission model.

## Integration contract

1. **Canonical data.** UI and agent read/write the same domain service and tenant-scoped records. Published menu identity, saved recipes and actual inventory remain authoritative.
2. **Unified chat routing.** Route eligible typed and recognized voice requests through includes/agent-workspace-core.php to the existing canonical domain agent (api/prep-intelligence-agent.php for Daily Prep). Avoid private UI-only logic.
3. **Skills and evidence.** Every agent response identifies the action/skill, reads current source records, and links to the relevant workspace. Never infer quantities from menu reference frequency.
4. **Permission at execution.** Re-evaluate the active user, organization, domain role and protected recipe access at read, proposal and confirmation time.
5. **Confirmation-only changes.** Agent-created checklist changes are proposals first, use existing agent-confirmation-core.php, and re-read source evidence before execution. Cancel causes no writes.
6. **Voice safety.** Speech and typed text share the same domain handler and confirmation. Do not allow an unauthenticated speaker or transcript to bypass identity checks owned by voice/session routing.
7. **Audits and testing.** Keep append-only event history, standard audit log, source-drift protection, CSRF on UI POSTs, and PHP/MariaDB regression tests for permission, replay, data isolation, voice parity, and the canonical route.
8. **Deployment.** Require green PR checks before merge, merged-main verification, and one verified full ZIP with upgrade.php.

## Current Daily Prep coverage (Section 3K2D)

- The official Food Monster / Gelato Brain model-selected tool runtime (fm8d3_catalogue) exposes **get_daily_prep_checklist** and **propose_daily_prep_action** for the signed-in operator. Both Claude and OpenAI use the same server-validated schemas and never receive a private checklist in a follow-up provider call.
- Authorized read requests return numbered, current Daily Prep and active Need More rows through canonical daily-quicklist-core.php, with a short-lived, user/tenant/conversation-scoped selection snapshot.
- "Second item" is bound to the numbered snapshot and rejected if the item changed. "That item / that timer" uses a previously selected server-owned focus; when ambiguous, the agent asks for a name or list number rather than guessing.
- Explicit changes stage existing Prep Agent quicklist_item_action or quicklist_generate proposals and **never mutate records during model selection**. Confirmation rechecks current permissions, item fingerprint under row lock, saved recipe yields, and Need More duplicates. Cancel consumes no mutation.
- Employee actions: complete/reopen prep, timer start/pause/resume/stop, recipe batch target, add/remove Need More, archive prep; managers also have statuses, notes, and notifications. The model cannot supply arbitrary organization, employee or database item IDs.
- Chat routing of explicit Daily Prep/Need More requests and scoped contextual follow-ups prefers the official native model skill catalog; the specialist Prep Agent remains the sole execution and confirmation gate. Typed requests and operator-approved voice requests use the same tools. Unverified room voices do not see private tools and fail fresh voice-event validation.
- Curated Agent Brain skill discovery, official chat, and the Daily Prep UI all reference canonical records. No shadow inventory, assistant or duplicate list exists.

## Limits and deployed acceptance

### Section 3K2F — Recorded receipt reconciliation

- Operations Inventory remains the **only** action that records physical receiving and adjusts on-hand quantities. Need More reconciliation only references an existing, completed `inventory_transactions` receipt ledger entry.
- A separate immutable link table permits exactly **one Need More request per receipt transaction** and **one receipt per request**. Confirmation validates tenant, exact saved inventory link, unit, positive received quantity, request/receipt chronology, outstanding status and both unique relationships inside one database transaction.
- Managers with Inventory View can inspect eligible recorded receipts and explicitly link one in the Inventory UI; links remain visible as receipt evidence and in the append-only Need More event history. A received/reconciled request cannot be reopened without creating a new request.
- The official Food Monster catalog adds `get_need_more_receipts` (read and numbered selection) and `link_confirmed_receipt` in `propose_daily_prep_action`. User, tenant, conversation and item bind the numbered receipt selection for 15 minutes; the model cannot supply raw ledger IDs. Agent proposals are separate Confirm/Cancel actions with fresh permission and stale-record checks, including approved private voice.
- No receipt is auto-selected based on a free-text requested amount, and partial/unmatched receipts are never silently assumed to satisfy a request. Unlinked requests continue to support manual manager status and notes.

### Section 3K2E — Inventory and Need More stock context

- `get_need_more_stock_insights` is an additional native Food Monster read tool for operators with **Inventory View plus Prep Agent access**. It reads saved on-hand quantities and configured reorder points, joins active Need More requests, and displays exact-name ingredient links from incomplete dated prep recipes **only with Recipe View**.
- The Daily Prep Inventory tab shows the same server-generated reorder-point indicators with one-click *+ Need More*. Threshold indicators are not projected ingredient shortages, suggested order quantities, or permission to update actual inventory.
- Managers can change the **requested amount text** of an existing Need More item in the UI or by `propose_daily_prep_action` with `set_requested_amount`. Agent changes require separate Confirm/Cancel and fresh per-item and permission checks; staff can continue to add and archive their own needs.
- All changed requested amounts retain append-only quicklist history. They never mutate the inventory ledger or stock counts. Model-selected stock receipts remain delegated to the existing Operations Agent action, not conflated with marking Need More as received.
- Reorder-point and recipe checks are read-only, tenant-scoped and safe when thresholds or recipe permissions are missing; no inferred unit conversions, unknown-stock matches, or synthetic menu demand.

- Native tool selection is real, but a model may still ask for missing context, abstain or choose the wrong intent; the server never executes an unsafe selection. Run end-to-end acceptance prompts in Gelato chat with actual data, including ambiguous names and concurrent edits.
- Need More "received" never adjusts inventory on-hand. Recipe targets are display-only and never modify saved recipes. Recording a real inventory receipt remains the separate authorized inventory workflow.
- CI verifies provider schema/selection, proposal safety, role restrictions, stale snapshots and voice gating. It does **not** certify real voice identification quality, acoustic overlap, or employees' deployed browser acceptance.


### Section 3K2G — Partial recorded receipts and outstanding Need More

- The requested stock quantity is an optional, explicit numeric value in the **linked inventory item's saved base unit**. Legacy \`quantityNote\` remains free-form and is never parsed as a quantity. Only managers with Inventory View can manage numeric targets.
- Existing recorded positive \`receive\` transactions may be linked once each. The reconciliation record stores the **allocated** portion of that immutable receipt. One Need More request may collect several distinct receipts, but a transaction cannot be assigned to a second request.
- The target, cumulative allocations, and remaining quantity appear in Daily Prep. Partial links leave the request outstanding (ordered); the final allocation closes it. A numeric target cannot be lowered below already allocated amounts, and manual Received status is rejected while quantity remains outstanding.
- Stock and the inventory transaction ledger are never updated during receipt allocation. Operations remains the only source of actual physical receiving. Existing Section 3K2F full-receipt links are backfilled with their original receipt quantity by the migration.
- The official Food Monster model-selected tools expose numeric stock-target proposals, current receipt progress, numbered existing receipt options, and optional partial allocation. Typed and verified-voice actions use the same Prep Agent pending Confirm workflow, expiring selection context, fingerprint and fresh permissions. Unverified voice never obtains private receipt data or changes.
- Deploy with \`upgrade.php\` before enabling partial reconciliation, verify MariaDB contracts, and acceptance-test manager ACL, receipt uniqueness, race/replay rejection, unit consistency, and outstanding-quantity rendering on the actual restaurant installation.
