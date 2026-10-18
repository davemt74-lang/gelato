# FM-G14C — Live device diagnostics and pilot repairs

This release builds on the G14A manual kitchen pilot and G14B recovery fixes. It does **not** certify any physical device, food preparation, staff competency, or microphone recognition accuracy automatically.

## Owner-only Agent Chat commands

- `start kitchen pilot` starts a separate pilot run if no run is active.
- `record kitchen pilot microphone fail: USB headset stopped responding during controlled recovery test.` creates an unresolved issue for that gate.
- `plan kitchen pilot fix microphone: Replace the faulty USB connection and retest with three controlled utterances.` records a proposed repair **without** performing it.
- After carrying out the repair and independently retesting hardware, report `record kitchen pilot microphone pass: Three real microphone stop and reconnect cycles completed successfully.`
- `resolve kitchen pilot fix microphone: Tested three physical disconnect and reconnect cycles and approved the result.` closes the issue **only if** the repair was planned and a **new passing report** was recorded after the plan.
- `kitchen pilot fixes`, `show kitchen pilot failures`, or `kitchen pilot readiness` displays the current pilot and open issues.
- `close kitchen pilot` freezes reporting, but does not discard outstanding failures. A new run starts clean and retains old history in the append-only ledger.

Repeat these steps for microphone, transcription, handoff, camera, timeclock, kds, and recovery.

## Diagnostics

The existing Agent Brain results sidebar shows a small browser-local snapshot of microphone status, recent restart/echo counters, KDS listening state, and camera slots when those runtimes are available. It reuses the already privacy-filtered voice diagnostic service and projects **only** recognized enum statuses, capped numeric counters and boolean state. Device snapshots, speech, camera images, face identifiers, customer details and raw logs are **not** uploaded.

When diagnosing a failure, use concise sanitized observations in the manually entered 20–500 character pilot note. Never include names, biometrics, voice transcripts, order IDs, credentials or personal health information.

## Safety and verification

- All writes require authenticated typed Super Admin Agent Chat, CSRF and canonical message correlation; row-level locking protects the existing per-owner/organization Agent Brain ledger from cross-tab duplicate events.
- Failed or blocked field reports open new issues, including new regressions after resolution. A passing report alone is insufficient for repair resolution.
- A repair plan is not an automated code change, deployment, KDS action, microphone reconfiguration, or employee authorization.
- A successful automated test suite certifies the **software contracts only**. Real kitchen device acceptance remains pending a physical pilot.
- Existing G14A seven-gate field checks remain self-reported. The server always reports `fieldCertified=false`, including when every check has a passing operator report.
