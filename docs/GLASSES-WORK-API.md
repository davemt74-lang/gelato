# Gelato AR Glasses Plugin — Section 2 AR Work API

Section 2 projects existing Gelato kitchen work into a compact, device-safe payload.

## Source of truth

The glasses do not create a parallel order queue.

- KDS station assignment and lifecycle come from `kds_production_board()`.
- POS snapshots provide quantity, size/option, special instructions, service context, seat/course information, and structured modifiers.
- Menu Manager / training knowledge provides canonical menu ingredients, preparation notes, and conservative allergen reference data.
- The existing Recipe Library is exposed only when there is exactly one active recipe with the exact menu-item name. Zero matches remain unlinked; multiple exact matches are marked ambiguous. Section 2 never guesses which recipe to use.

## Device action

Authenticated devices POST to `api/glasses-device.php` with:

```json
{"action":"current_work"}
```

The response includes:

- assigned device/location/station
- station-scoped active KDS items
- one focus item (in progress → queued → ready → held)
- ticket/table/server/seat/course context
- POS special instructions and structured modifiers
- canonical menu ingredients/preparation notes
- conservative recipe source projection
- KDS timing/warning/late state
- a structural revision hash and recommended poll interval

Reading current work updates device `last_seen_at` but does not transition KDS state or write high-volume heartbeat events.

If a device has no station assignment, it receives `assignmentRequired=true` and an empty work list rather than all location work.
