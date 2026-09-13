# Kitchen Display + Order Routing

Gelato's Kitchen Display System (KDS) is the execution layer between the Native POS and restaurant kitchen stations.

## Order lifecycle

1. POS items remain editable while they are unsent.
2. `kitchen.send` creates one KDS item for each unsent active POS line.
3. A configured menu route sends the item to its active station. Missing routes remain visible in **Unrouted** and cannot begin preparation until assigned.
4. Kitchen lifecycle is `held → queued → in_progress → ready → completed` with allowed backward movement from `ready → in_progress` and terminal cancellation.
5. Sending a check again is idempotent; only newly-added unsent POS lines are added to KDS.
6. Once a POS line has been sent, quantity and instructions are immutable. Correct it by voiding the line and adding a replacement line.
7. Authorized POS voids and unpaid check cancellations propagate to nonterminal KDS items.
8. Kitchen state never changes ticket totals or tender history.

## Station routing

Stations are location-scoped and explicitly configured. Menu categories do not imply stations. A menu item can be mapped to one active station per location. Expo can reassign a live item or move it to Unrouted.

## Timing

A station owns a target duration. Timing begins when an item is fired into `queued`, pauses when moved back to `held`, and remains visible through preparation and ready states. The board marks live items late when elapsed time exceeds the station target.

## Audit model

Every send, lifecycle transition, and reassignment writes an append-only `kds_order_events` record. KDS rows also retain fired, started, ready, completed, and cancelled timestamps.

## Permissions

- `kds.view` — view station and Expo boards.
- `kds.update` — change kitchen state and reassign live items.
- `kds.configure` — create stations and configure menu routing.

Owners and operational managers inherit KDS permissions. Ordinary POS use alone does not grant access to the Kitchen Display workspace.
