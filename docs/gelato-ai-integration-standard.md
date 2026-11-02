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

- Native tool selection is real, but a model may still ask for missing context, abstain or choose the wrong intent; the server never executes an unsafe selection. Run end-to-end acceptance prompts in Gelato chat with actual data, including ambiguous names and concurrent edits.
- Need More "received" never adjusts inventory on-hand. Recipe targets are display-only and never modify saved recipes. Recording a real inventory receipt remains the separate authorized inventory workflow.
- CI verifies provider schema/selection, proposal safety, role restrictions, stale snapshots and voice gating. It does **not** certify real voice identification quality, acoustic overlap, or employees' deployed browser acceptance.
