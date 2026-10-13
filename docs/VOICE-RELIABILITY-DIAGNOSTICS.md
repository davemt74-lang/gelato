# Gelato Voice Reliability & Diagnostics — acceptance and privacy

## Runtime
Agent Brain Next Moves exposes **Voice Diagnostics**. Time Clock has a local button. Both open the same session-only panel and use the existing listening settings and Global Agent coordination. No new database or microphone controller is introduced.

The diagnostic snapshot reports the browser recognition owner, saved versus active listening, wake standby, actual microphone state, TTS speaking owner, environment profile, counted echo suppressions and barge-ins, restart attempts, and allowlisted browser error codes. Session events are capped at 24 entries; no raw transcripts, recordings, user IDs, voice biometrics, cookies, access tokens, or names are ingested or persisted. Copy creates a report on the user's explicit click.

## Fail-safe behavior
Recognition permission/capture denial clears the active microphone state. Unexpected recognition restart failures stop retrying and turn the microphone off. A saved listening preference never activates capture on reload. Wake standby is an active microphone state, clearly distinguished from microphone off.

## Release acceptance
Automated: `node --check js/voice-diagnostics.js`, `node tests/voice-diagnostics.test.js`, existing proactive global listening, Time Clock and voice turn-taking suites. These commands are release-gated by agent workspace and full deploy workflows.

Human device acceptance remains required: Chrome on the real Time Clock and Global Agent pages; grant and deny microphone permission; switch pages; check browser mic indicator; reload; test wake standby and true off; interrupt TTS; verify diagnostics only contain sanitized metadata.

Deployment must preserve `config.php`, `uploads/`, `storage/` and existing enrolled voice files.
