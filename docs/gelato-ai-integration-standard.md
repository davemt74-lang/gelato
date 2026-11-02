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

## Current Daily Prep coverage (Section 3K2C)

- Daily Prep UI, Prep Agent and Brain/Chat route share the canonical daily-quicklist-core.php records.
- The Prep Agent can read the dated checklist and Need More inventory, generate published-menu suggestions, and propose individual checklist actions for explicit natural-language requests.
- Staff agent proposals: check off/reopen prep items, start/pause/resume/stop timers, set authorized recipe batch targets, add/remove Need More requests, and soft-remove prep items.
- Manager agent proposals: change checklist/Need More statuses, add notes and send manager notifications, using the existing permission check on both proposal and confirmation.
- Requests are resolved to a live, tenant-scoped item; proposals store a source fingerprint, and confirmation checks it again under the mutation's row lock. Recipe yield permissions and canonical units are rechecked. Changes produce standard checklist events and audit records.
- Typed and voice requests enter the same official Prep Agent handler. The existing voice session is responsible for speaker authorization; an input flag by itself cannot certify identity.
- Curated Agent Brain skill discovery offers a Daily Prep entry and a direct prompt into the same chat route.

## Limits and deployment acceptance

- The explicit Daily Prep action phrases are routed deterministically through the official Prep Agent. This is not a claim that every spontaneous sentence is model-selected as a structured tool call; broader natural-language coverage and embedded "this item" references require a separate model tool/catalog integration pass.
- Need More "received" is a request status, **not** an inventory count change. Batch targets are display-only projections and never edit saved recipes.
- CI verifies authorization, stale/replay protections and voice/text handler parity, **not** actual face/voice matching quality or deployed employee acceptance. Test these on gelato.vp3.me before production reliance.
