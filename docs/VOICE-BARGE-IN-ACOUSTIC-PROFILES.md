# Gelato Voice Turn Taking — Barge-in and Acoustic Profiles

## Scope

Shared by Global Agent chat, the Training workspace, and KDS voice assistant. Uses one browser-local speech output controller to allow TTS cancellation and to reduce accidental listening to its own words. The existing permission-gated voice identity and protected actions are not changed.

## Interaction

| Situation | Behavior |
|---|---|
| Gelato is speaking | Press **Stop speaking** (■) to cancel all queued and current speech immediately |
| Wake-mode speech recognition can hear you | Say **Hey Gelato stop**, or **Hey Gelato read order 12** (KDS) to interrupt |
| Agent speech is recognized by the microphone | Suppress matching words; do not treat them as a user answer/command |
| Voice engine just finished | Brief post-TTS echo quarantine; user can interrupt manually at any time |
| Busy kitchen / background television | Require **Hey Gelato** for ordinary commands even with Always-on enabled |
| Training quiz | Spoken replies are **opt-in**, and recognized answers go only to the local graded chat |
| New Agent question typed | Previous spoken Agent output is interrupted, without losing the conversation |
| Another page or tab | Existing pause/stop behavior and user permissions remain in force |

The Web Speech Recognition API does not reliably support true full-duplex acoustic echo cancellation in all browsers and audio devices. Voice-only barge-in requires that the browser still recognizes the **wake phrase while Gelato is speaking**. No JavaScript filter can guarantee that a television, loudspeaker or another person cannot imitate the wake phrase. The **Stop speaking** button is the reliable fallback; a headset or directional microphone is recommended in a noisy restaurant.

## Device-local voice profiles

- **Quiet room**: permissive speech-confidence threshold, normal command recognition
- **Normal workspace**: moderate confidence filtering
- **Busy kitchen / TV**: more aggressive filtering, always requires the wake phrase

Select Gelato's speaking voice and speed where supported. These device preferences are stored locally and contain no recorded audio or biometric sample. They do not change enrolled employee voice identity.

Do not equate an acoustic noise profile with identity verification. Global Agent voice identity, user permissions, CSRF validation, and KDS read-only boundaries remain independently enforced.

## Security and privacy

Listening remains off until explicitly enabled; no microphone capture is started by a saved preference. Browser tab hiding pauses recognition where applicable. Neither confidence nor wake-word recognition authorizes restricted restaurant actions. There is no automatic background audio recording or remote upload from the shared turn-taking controller.

## Acceptance cases

1. Global Agent: interrupt an active spoken answer with the button and with **Hey Gelato stop** in a browser that supports concurrent recognition; no duplicate Agent send from a final echo.
2. KDS: read a long multi-order list, interrupt halfway, verify no further speech chunks play, and read an off-screen order on demand.
3. Training: enable spoken replies, answer a guided question, interrupt spoken feedback, and confirm echo text never becomes a second answer.
4. Busy kitchen: without wake phrase, reject incidental background transcripts (where the browser provides recognition confidence); accept a wake-prefixed authorized read-only command.
5. Denied/unsupported microphone: keep manual controls and display existing listening status; do not silently activate the microphone.
6. Page visibility, Agent profile identity permission, CSRF, and protected actions remain as before.

No database migration is needed. Test with headphones and room speakers separately after deployment on HostGator.
