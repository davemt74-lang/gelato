# Vision Lab V9 Section 7 — Governed Human Confirmation & Kitchen Handoff

Section 7 is the consequential boundary between V9 visual intelligence and the existing canonical kitchen workflow.

A handoff requires:
- an immutable Section 6 final validation in ready_candidate state;
- integrity verification of that final validation;
- the same glasses device that captured the validated scene;
- the canonical build still active and fully accounted;
- an exact current build-context hash match;
- an explicit human confirmation key for durable idempotency.

After those checks, Section 7 delegates to the existing glasses_handoff_to_expo() path. That existing canonical path re-evaluates product validation, transitions KDS to ready, completes the build session, and writes the canonical handoff record.

Section 7 does not create a second completion engine. It adds an auditable human-confirmation ledger tied to the final-validation hash and canonical handoff.
