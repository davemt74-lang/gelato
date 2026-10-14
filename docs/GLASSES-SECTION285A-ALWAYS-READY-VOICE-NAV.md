# Section 285A — Always-Ready Glasses Production and Voice Ticket Navigation

## Definition
The **glasses runtime** is the employee's production assistant. "Start Ticket" is
not a prerequisite for tracking a current KDS ticket. A paired, authorized
employee runtime that is active and synchronized may show its active KDS item
and ingredient list immediately, regardless of whether that ticket has yet
entered the KDS `in_progress` status.

Hardware access still requires user consent. This section **does not**
automatically grant camera or microphone access, start a background worker,
activate a trained detector, confirm ingredient placement, or transition KDS
status.

## Changes

- Passive production-readiness indicator external to both lenses: preview,
  unassigned, waiting, ready (KDS only), observing (camera without approved
  model), recognizing (approved read-only ingredient model), paused. These
  labels distinguish visual readiness from actual model inference.
- Voice `start ticket` now means **report/focus the current KDS ticket**
  and NEVER issues a KDS status transition.
- `mark ticket started` remains the **explicit** authorized KDS
  transition, as does the KDS-specific HUD button now labeled
  **Mark KDS Started**.
- `next item` / `previous item` navigate within the current KDS ticket.
- `next ticket` / `previous ticket` navigate over the actual eligible
  station KDS tickets; no ticket or item gets marked finished.
- `go to ticket 104` / `select order 104` use **exact** current KDS
  check-number lookup; no prefix guessing or unrelated location search.
- The selection preserves each item's existing ingredient checklist, resets
  transient hand overlay evidence, and does not start a governed build or
  invoke POS/KDS write actions.
- Active governed builds remain **pinned** to their original item; switching
  is blocked until that governed session completes to avoid attributing
  camera evidence to a different food item. Build suspend/resume requires a
  separate, tested implementation.

## Acceptance cases
- 1, 2, and 3+ station tickets; first/last wrap, next/previous item.
- No-ticket, one-ticket, unknown-number, duplicate-number, cancelled and
  completed ticket handling.
- No duplicate or accidental KDS status transitions.
- A pending/queued ticket may be the read-only production focus.
- Voice Start Ticket and KDS Mark Started do not collide.
- Camera-off, camera-on and verified-model states are honestly distinguished.
- Auto-focus **from visual production-action evidence** requires the later
  Section 285B detection and hand-to-food mapping. Current fallback follows
  the KDS server focus or a deliberate employee selection; it does not
  fabricate scene understanding.

## Deployment
Update the existing application using the certified full deployment ZIP.
Preserve credentials, uploads and storage. No schema migration is added in
this section. The AIR3 physical SDK remains pending.
