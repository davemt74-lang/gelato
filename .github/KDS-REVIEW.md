# KDS review checklist

- Explicit station routing; no category-based station inference.
- Unrouted items remain visible and cannot start cooking until assigned.
- Sent POS lines are immutable; correction is void + replacement.
- Repeat send only creates KDS rows for newly-added POS lines.
- Hold/fire/prep/ready/completed lifecycle is validated server-side.
- POS void and unpaid check cancellation propagate to live KDS items.
- KDS activity does not alter ticket totals, payment, or sales posting.
- KDS events are append-only audit history.
- Organization and location scoping are enforced.
- KDS timing is operational and not an employee-decision score.
