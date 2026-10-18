# FM-G14A — Kitchen Pilot field checklist

This phase captures **operator-reported observations**, not automatic certification of kitchen safety, hardware, voice recognition or employee competence. Run tests on the actual equipment in the intended kitchen.

## Agent Chat procedure

Use an authenticated **typed Super Admin** session.

1. Send **start kitchen pilot** to create a new independent run with seven untested checks.
2. Send **kitchen pilot readiness** for the current check status and browser-local diagnostic counters in the Agent Brain results sidebar.
3. Report each gate using the syntax **record kitchen pilot GATE pass: DESCRIPTION**, replacing pass with fail or blocked as appropriate. Description must be 20–500 characters and contain no personal data.
4. If the test fails, repair the problem, test again and record a new outcome. The latest report supersedes earlier reports for that gate.
5. Send **close kitchen pilot** to freeze the run. Start another run explicitly when ready.

## Seven physical acceptance checks

| Gate | Field observations |
| --- | --- |
| microphone | Permission, start/stop, disconnect/reconnect, fatal error recovery |
| transcription | Words visible in the composer, correct finalization, no duplicate sending under kitchen noise |
| handoff | Known participant transitions, unknown-speaker isolation, no permissions or history carried between people |
| camera | Explicit consent, tracking, denied permission, unplug/reconnect behavior |
| timeclock | Real clock-in, breaks, break end, clock-out and on-shift reminder suppression |
| kds | Ticket operation unaffected by pilot, next-item/next-ticket voice commands retain KDS authority |
| recovery | Network interruption, tab visibility, interrupted speech, reload and microphone restart |

Avoid recording names, voiceprints, captured footage, customer orders, or health data in evidence descriptions. Use test accounts and simulated food prep where possible.

## Acceptance boundaries

- GitHub CI verifies code paths, MySQL persistence, tenant isolation and regression checks, not hands-on accuracy.
- Pilot outcomes are labelled owner-reported; **fieldCertified is always false** in this phase, even with seven recent passes.
- A later failed/blocked report overrides earlier passes; reports older than seven days become stale.
- Only authenticated typed Super Admin turns can write a report. A time-clock event, voice match or camera guess is never a credential.
- Reports stay in the existing owner-scoped Agent Brain event ledger, with transactional membership-row locking. A repeated chat message cannot create another report.
- Browser diagnostics are temporary in-page counts; they never upload transcripts, identity details or camera footage. Counters are investigative hints, not recognition-quality metrics.
- Pilot actions do not modify KDS, shifts, menu procedures, training scores or microphones.

**Software 10/10 gate**: exact-head regression and real MySQL CI green, merged PR, and verified release ZIP against merged main. **Field gate**: requires actual kitchen/hardware testing separately.
