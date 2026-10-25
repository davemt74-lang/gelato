# Agent Eyes AE5 — Proactive Greetings and Participant Timeline

The Super Admin's existing opt-in Agent Eyes browser camera now records timestamped arrival and departure events for recognized **existing, consent-enrolled Gelato participants**. Unknown/ambiguous people are never logged to this named-person journal. The UI presents a collapsible recent participant timeline without opening chat.

An arrival may offer one short spoken name greeting when the signed-in Super Admin has explicitly enabled their existing Agent Proactive Voice preference. The client honors that setting, hides no interactive approval control, and does not open the chat canvas. The server enforces a two-hour greeting-offer cooldown across page reloads. A greeting is marked as **offered**, not as guaranteed audible, since browser speech policies and other active speech can suppress playback.

Only the server's approved local Face/PIN or Agent Eyes enrollment can label a visual track. Those names are still provisional and never authorize login or tools. Departure requires seven seconds absent or an explicit camera stop; short occlusions do not create duplicate arrival events. No biometric vectors, face images, anonymous identities, audio recordings or transcripts are written to the timeline.

Timeline reads are limited to the authenticated organization Super Admin. The events are scoped by organization and may be filtered to a participant. Entries currently include arrivals, departures and greeting offers. Speech-turn history remains in the existing Agent conversation journal; fully unified per-person interaction summaries are outside AE5.

## Deployment

Run `upgrade.php` after installing the Cloud ZIP to apply `database/20261223_agent_eyes_ae5_presence_timeline.sql`. Preserve the existing app settings, uploads and storage. Reopen Agent Eyes and explicitly enable the existing Proactive Voice preference before testing speech.

## Physical acceptance still required

Webcam recognition reliability, microphone coexistence, consent signage, greeting audibility and room acoustics must be tested on site. Automated Chromium and MariaDB tests are not proof of field recognition accuracy.
