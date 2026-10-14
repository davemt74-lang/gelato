# Gelato — KDS-backed left-lens ticket completion

The left lens continues to list the active KDS ticket and its configured item
ingredients. Once every item is **locally accounted for**, a contextual action
appears below that ticket: **Start Ticket** (for queued items), **Ticket Ready**
(for in-progress items), then **Finish Ticket** once all items are **officially
KDS Ready**. Items can also be KDS Ready without being marked on the local
checklist, in which case authoritative KDS status takes precedence.

**Safety:** ASSEMBLED is a local checklist statement; it never writes directly to
KDS. Every button and supported spoken command uses the *existing*
\`simulatorNavigation → kitchenCommand → api('kds.ticket.action') →
glasses_simulator_kds_command → kds_production_ticket_action\` pipeline.
This enforces authentication, \`kds.update\`, restaurant/station scope, canonical
item status, active build constraints, and Expo-only whole-ticket bumping.
No new KDS endpoint or local fake completion state.

**Finish Ticket** is enabled only at Expo / All stations for a KDS-ready
ticket and requires two manual presses or two "finish ticket" voice commands
within 12 seconds (voice and manual confirmations may be mixed). The initial
press never changes KDS. Successful finishing refreshes canonical KDS work and
removes completed ticket items from the queue; POS payment is independent.
The left-lens action must be accessible by mouse, keyboard and delegated voice
UI without starting any microphone or camera automatically.

The bottom action rail is unchanged for fallback operation. Confirm the
new contextual control on the physical display, in simulator preview, with
multiple real tickets, and when KDS permissions are absent before deployment.
