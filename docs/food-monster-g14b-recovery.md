# FM-G14B — Kitchen pilot device recovery

This release addresses recovery behavior discovered in a source audit after G14A. It is a software hardening phase; operator field observations still require the G14A physical pilot.

## Main Agent Chat microphone

- On a browser SpeechRecognition end event, retry with a **new** recognizer instance instead of starting the same ended instance.
- Cap consecutive failed/empty restarts, with the existing visible stop state when the budget is exhausted.
- Cancel pending retry timers on manual microphone-off, page hidden, fatal permission error, or replacement recognizer. The page never starts listening automatically after a reload.
- Suppress repeated final wake-command callbacks for a short window; a later deliberate request remains allowed. Normal editable dictation and existing low-confidence echo protections are retained.

## KDS microphone

- A sequence of five **rapid** recognition exits or start failures stops hands-free KDS listening and requires an explicit operator retry. A healthy long session does not count as a rapid-loop failure.
- Ignore events from recognizers that have already been superseded or disabled.
- Suppress immediate duplicate finalized commands; later deliberate commands remain valid.
- No changes are made to KDS permissions or ticket mutation APIs. Read-order commands continue to use the existing authorized endpoint and local read-only fallback.

## Camera reconnect (glasses)

- Camera slots 2–5 allow one start/recovery request at a time.
- A manual stop, location switch, page close or permission change invalidates an outstanding start. A late camera-open result is immediately stopped and is not reported as ready.
- Existing bounded retry, explicit device enablement, per-source calibration and manual confirmation rules remain in place.
- No camera feed, detected face, transcript or biometric data is newly uploaded or saved.

## Acceptance checks

Run Node test `tests/food-monster-g14b-recovery.test.cjs` for fake speech/camera device event sequences. Also run inherited speech, KDS slot/voice and camera production tests and G14A field-readiness behavior tests.

**Automated acceptance** requires exact-head GitHub checks, PR merge, merged-main source comparison and a verified full deploy ZIP. **Field acceptance** still requires real USB/HDMI input, noisy kitchen microphone use, speaker handoffs, camera unplug/reconnect and KDS usability under active tickets. No simulated result should be labeled as a physical pass.
