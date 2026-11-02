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

## Current Daily Prep coverage

- daily-prep.php and api/daily-prep.php share includes/daily-quicklist-core.php.
- Official Prep Agent handles **Daily Prep checklist read**, current **Need More list read**, and **menu-backed Daily Prep list proposals**. Confirmed list generation reuses the same server-side selection function as the page.
- Saved recipe viewing and batch targets continue to use canonical Recipe Library records.

## Remaining integration requirements

The following existing UI operations still need **individual model-selected agent skills** before the Daily Prep/Inventory domain can be called fully integrated: staff check-off/undo; timer start/pause/resume/stop; recipe target changes; ad hoc Need More additions and removals; manager inventory status/notes/notifications. Each must use the current confirmation and permission workflows without weakening inventory-count permissions.

The current section does not claim these actions are already model-selectable. Treat them as explicit follow-on work.
