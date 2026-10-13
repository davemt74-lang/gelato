# Gelato Proactive Agent — Saved Listening & Hey Gelato Controls

## Existing preference system reused

Gelato already stores one row per organization/user in `proactive_agent_preferences`, including `listening_enabled`, `proactive_voice_enabled` and `wake_phrase`. This release reads those fields through `GET api/proactive-agent.php?action=preferences` without fetching or consuming pending proactive events. CSRF-protected POST updates continue to write the existing row for the authenticated user. No new table, migration, voice enrollment, or password reset is needed.

## State meanings

| State | Persisted preference | Actual microphone |
|---|---|---|
| Saved On · microphone Off | Enabled for that user | Off until they click Start/Resume |
| Actively Listening | Enabled | Active; accepts authorized wake-prefixed commands and polls proactive events |
| Wake Standby | Disabled for full command listening | Active; recognizes only Hey Gelato start / stop / microphone off |
| Microphone Off | Disabled | Fully released, no wake-word detection |
| Paused / Blocked | Saved preference unchanged | Inactive due to tab visibility or browser restrictions |

A browser cannot detect a spoken Start Listening command after **Microphone Off** because it is not capturing audio. Wake Standby is explicitly enabled by the user and clearly labeled as **microphone on**. Reloading a page never silently starts capture, even if saved preferences say On. A fresh voice verification is required before enabling voice commands if the previous match expired; a saved preference does not authenticate anyone.

## Spoken controls

- **Hey Gelato, start listening** — when wake standby is active, re-enable full proactive listening after the signed-in user's enrolled voice is verified.
- **Hey Gelato, stop listening** — persist Off, stop proactive delivery and ordinary voice commands, but leave the explicitly armed microphone in wake-only standby.
- **Hey Gelato, microphone off** — persist Off and fully release speech recognition. To restart, click a button in the visible UI.

Time Clock offers buttons for Start/Stop Listening, Enable Hey Gelato Wake and Microphone Off, plus a visible status line. The global footer provides Start/Pause and Microphone Off. The Time Clock and Global Agent reuse the same microphone runtime for users with the required Global Agent permission rather than competing for audio.

## Spoken alerts

The **Speak proactive alerts** checkbox is saved per user separately from `listening_enabled`; it no longer silently resets when the browser reloads. Disabling spoken alerts does not disable listening, and enabling spoken alerts does not open the microphone. The proactively spoken messages still use the already established Agent Brain and permission-scoped events.

## Privacy and limitations

No unattended ambient listening is started on page load. Wake standby still records microphone audio in the browser speech recognition session. No profile switch changes the signed-in user or grants permissions. Browser Web Speech support, microphone permissions, tab suspension, and speaker feedback determine how reliably wake recognition functions on real devices. The microphone can be fully disabled at any moment.

## Acceptance

Automated tests cover separate user preference storage, persisted On restoring with the microphone Off, explicit voice-enrolled start, spoken stop entering wake standby, spoken start returning to full listening, unrelated background speech ignored, and hard microphone shutdown. Production validation still requires testing a real device and confirming microphone privacy indicators with Chrome.

Preserve `config.php`, `uploads/`, `storage/` and enrolled voice recordings when deploying.
