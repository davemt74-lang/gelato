# Gelato AR Glasses Plugin — Section 6 Controlled KDS Handoff to Expo

Section 6 makes the AR `NEXT → Expo / Finishing` action real while preserving Gelato's existing kitchen lifecycle.

## Gate

A paired device may hand off only its own active build session and only when a **fresh validation evaluation** returns:

```
status = ready_for_finishing
nextStage = expo_finishing
```

The handoff re-evaluates product evidence inside the same database transaction as the KDS transition. A previously-ready validation is not trusted if new evidence has appeared.

## Existing KDS lifecycle remains authoritative

The plugin does not invent a parallel Expo queue.

For a queued item:

```
queued → in_progress → ready
```

For an item already in progress:

```
in_progress → ready
```

If the legitimate KDS UI has already moved the item to `ready`, the AR session may close without duplicating a transition.

`held`, `completed`, and `cancelled` items cannot be handed off from the glasses.

A `ready` item appears in Gelato's existing Expo/all-stations production board. Ticket completion remains an Expo action and is not performed by the glasses.

## Audit and terminal state

A successful handoff atomically:

- writes one `glasses_kds_handoffs` record;
- records the exact validation snapshot hash;
- moves the existing KDS item to `ready`;
- closes the AR build session as `completed`;
- moves validation to `handed_off` with downstream stage `expo`;
- appends a validation `handoff_to_expo` event;
- appends a build `session_completed` event.

KDS's existing audit actor is the accountable user who generated the pairing grant; the KDS event note also records the glasses device and validation IDs.

## Retry behavior

The build session has at most one handoff. Replaying the same request after success returns that existing handoff and creates no duplicate KDS transitions or events.
