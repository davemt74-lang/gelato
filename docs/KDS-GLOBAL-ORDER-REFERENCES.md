# KDS Global Order References (Eight Visible Per Screen)

The active KDS queue is **not limited to eight orders**. The UI displays up to eight ticket cards per page to keep each ticket readable, while numbering the entire current location/station queue continuously in its live sort order.

| KDS page | Displayed references |
|---|---|
| 1 | Order 1–8 |
| 2 | Order 9–16 |
| 3 | Order 17–24 |
| 4 | Order 25–32 |

Use the existing **Previous 8 / Next 8** controls to view another page. Every card retains its separate real POS check number.

The KDS voice agent can answer **Read order 12**, **Repeat order 21**, or **Read all orders** regardless of which page is currently displayed. "Read order N" refers to the queue reference, while "ticket 8012" explicitly refers to the real POS check number. The server checks spoken order positions against the current, permission-scoped canonical KDS board; if the sort changes between the browser snapshot and response, the assistant asks for a refresh instead of reading the wrong ticket.

Positions are **dynamic** rather than permanent IDs. If an order is bumped, cancelled or reprioritized, the visible numbers may shift after the next refresh. They are voice/navigation references, not replacement order IDs. Changing restaurant or station also changes the queue numbering. With zero tickets, all order references are empty.

New-order announcements mention the global position if it is in the current active queue. This feature is read-only; it cannot bump, cancel, edit or mark an order complete. The existing wake-phrase and always-listening controls are unaffected.

Acceptance tests cover at least 27 active orders, voice reading an offscreen Order 12 while page 1 is shown, a lookup beyond the active queue, sort changes, spoofed client references and preserving the POS check number.
