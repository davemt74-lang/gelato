# Section 266 — Glasses HUD notifications, employee runtimes & voice acceptance

Status: **implementation candidate**, pending CI and acceptance. Baseline: merged Section 265. Training: reuse V11 glasses programs, wearer identity, supervised sessions, Vision Lab datasets; do not create a second training product.

## Intended operator experience

1. Keep only numbered occupied-ticket tabs and the selected item's actual menu assembly ingredients in the glasses. Simulator/debug information (READY · WEB-LIVE · BROWSER, command list) is outside the projection, under Runtime/Voice help if needed.
2. Display a single compact, readable, severity-styled on-lens notification at a time. Prioritize blocking > warning > success > info. Bound queue, auto-dismiss, and deduplicate bursts. The center view and ingredient card stay readable.
3. Every notification category offers an independent **Speak** toggle: **Information**, **Success**, **Warning**, **Blocking/error**. Additionally give **Ticket started**, **Ticket ready**, **Ticket paused**, **Ticket resumed**, **Ticket finished** individual toggles. Ticket-event toggles are independent of the four category switches (the category is still used for visual priority), so enabling Ticket Started speaks even if general Information is muted. The main **Enable Voice** and **Speak replies** controls gate ALL notification speech; a disabled category never prevents visual notices. Persist voice toggle preferences per authenticated user and employee runtime.
4. Ticket lifecycle notices include the **actual POS check/ticket number** and **service type** (dine-in, pickup, delivery etc. as supplied by POS). The visible local occupied-tab slot `#1` is not a durable ticket number. Sample copy:
   - `Ticket #260914 · Pickup · STARTED`
   - `Ticket #260914 · Pickup · PAUSED`
   - `Ticket #260914 · Pickup · RESUMED`
   - `Ticket #260914 · Pickup · READY`
   - `Ticket #260914 · Pickup · FINISHED`
5. Ticket lifecycle events derive from **acknowledged KDS commands**, verified ticket-item state after syncing, and/or actual KDS status transitions on an established live sync baseline; do not claim a ticket finished merely because it disappeared or when an API request fails. First poll, changed ticket order, or late reconnect never fabricate events. Only explicit successful KDS finish/bump can raise a local Finished notice.
6. A scoped kitchen-station runtime may see only some ticket items. Such notices explicitly name the station (for example, `Ticket #260914 · Pizza Line · PAUSED`). A partial held or ready subset must not be narrated as an entire station ticket paused or ready.
7. Ingredient checklist events are separate from canonical POS/KDS status. **Added**, **Skipped**, **Selected next item**, **Checklist assembled** may display/speak, but never imply model verification, KDS readiness or customer fulfillment. Training or low-confidence detections are suggestions until governed evidence is available.
8. Voice commands already produce spoken replies. Suppress double narration from the event dispatcher when an action originated from speech. Avoid speaking every auto-detection frame, don't speak on initial page render, avoid speech echo into the listening microphone, and support stop/recovery.
9. Multi-employee glasses runtime events are isolated by authorized runtime ID, physical device, employee, station and logged-in actor; user-visible voice preferences are per runtime. Do not let a different employee's runtime or ticket emit notifications in the focused session. Do not conflate a selected employee with authenticated API authorization.

## Data contract

`notification={ event, severity, ticketPublicId?, ticketNumber?, serviceMode?, itemPublicId?, ingredientId?, message, source, at, spokenFromVoice? }`

Allowed event names:
- `ticket.started`, `ticket.ready`, `ticket.paused`, `ticket.resumed`, `ticket.finished`
- `item.selected`, `item.completed`
- `ingredient.added`, `ingredient.skipped`, `ingredient.low_confidence`, `ingredient.manual_confirmation`, `ingredient.wrong`
- `runtime.warning`, `runtime.blocked`

A notice has **no KDS write effect**. Its category determines visual priority; the event controls its additional voice toggle. Notification source includes KDS-command, KDS-transition, operator, governed-recognition or runtime (no inferencing from recipes).

## Build milestones

### A — Display and voice settings
- [ ] Remove the right-lens READY/WEB-LIVE/BROWSER debug row
- [ ] Remove production-facing static voice-command instructions; retain Voice help
- [ ] Build compact on-lens toast and bounded prioritized queue
- [ ] Add 4 severity and 5 ticket-event voice toggles; persistence and accessibility
- [ ] Add central TTS gate: voice listening enabled + Speak replies + severity/event enabled + dedupe + runtime active

### B — Ticket and ingredient event wiring
- [ ] Notify only after confirmed KDS ticket actions, with exact POS number and supplied service type
- [ ] Reconcile external started/paused/resumed/ready changes from live KDS transitions; no initial-sync noise
- [ ] Never guess finish from ticket disappearance or success from denied/failed KDS writes
- [ ] Manual ingredient Added/Skip and next-item messages route through shared notifier
- [ ] Recognition low-confidence/manual-confirmation/wrong-ingredient hooks remain non-authoritative
- [ ] No double spoken result when voice command already replied

### C — Multiple runtimes / existing training
- [ ] Employee roster from active organization memberships; verify assigned wearable device and location permission
- [ ] Keep canonical Vision Lab wearer identity, V11 employee training assignments and training sessions
- [ ] Each runtime's speech, camera, recognition, queue, selected ticket and browser preferences are isolated
- [ ] End/revoke runtime stops capture and prevents subsequent voice/KDS use

### D — Regression & release certification
- [ ] Unit: category/event toggles, persistence by runtime, voice disabled/enabled, repeat suppression, queue priority
- [ ] Unit: POS ticket `checkNumber` used (not local `#1`), ticket type included when present
- [ ] Unit: start/pause/resume/ready/finish transitions; no initial-poll notification; no disappearance-to-finish
- [ ] Unit: failed/denied KDS action produces no success notification
- [ ] Unit: no notification may mutate KDS or certify untrained vision evidence
- [ ] Contract: viewport layout, controls accessible, voice help preserved; no right-lens debug row
- [ ] Regression: Section 265 assembly, KDS voice/manual parity, live virtual preview, browser runtime certification and deployment compatibility
- [ ] CI green on **exact PR head**; review security, UX and race/duplicate prevention
- [ ] Merge only when green, verify main head and build deployment ZIP + SHA-256 manifest

## Scoring rubric — each must reach 10/10 before merge

| Category | Gate |
|---|---|
| On-lens clarity | No technical-status header or command wall, no obstruction of ingredient checklist |
| Notification correctness | Every message grounded in a confirmed source; severity and priority follow policy |
| Ticket identity and type | Exact POS ticket number, accurate POS service mode, no local-slot confusion |
| Lifecycle validity | No phantom start/pause/finish, safe polling and failure behavior |
| Per-category speech controls | Independent persisted toggles; visual alerts always work |
| Voice safety | opt-in, once-only speech, no unnecessary playback or feedback loops |
| Ingredient safety | acknowledgement != AI verification or KDS ready |
| Multi-runtime isolation | no cross-employee speech or context leakage |
| Training reuse | V11 training and Vision Lab are the only training sources |
| Release evidence | automated tests and CI green, PR merged, verified deploy package |

**Non-goal**: real-world vision accuracy certification. Ingredient photo collection and calibration remain in later sections 267–270.
