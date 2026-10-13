# Gelato Agent — Natural Kitchen Speech, Recognized Speakers and Conversation History

## Existing systems retained

Gelato already maintains voice enrollment samples, voiceprint vectors, matched voice events, staff records, per-user Agent conversations, message timestamps, channels and audit/action receipts. This section reuses those records instead of creating a second Agent Brain, shadow chat log, or voice-enrollment system. No database migration is required.

## Spoken KDS orders

Readouts use canonical location/station KDS data, the live **Order N** reference (across all pages), and the actual POS check number. Quantities are natural numbers where possible, with exact menu item names, selected size/option, and special instructions. Newly routed items are announced as updates; late and ready statements require the corresponding real KDS flags. The voice read path uses the canonical KDS API; the local browser read-only view remains a fallback if the network fails.

Example: "Order twelve, ticket nine thousand eighty-one. Table Patio Three. Two Breakfast Pizzas, large. Note: add bacon, no onions. One Calzone. Note: ricotta on the side."

## Recognize an enrolled speaker

On the KDS Voice sidebar choose **Recognize speaker**, then speak naturally for the existing verification sample. This reuses `api/voice-identity.php`, not browser speech-to-text as a biometric. The verified event lasts about 13 minutes client-side and at most 15 minutes server-side. A permitted manager can identify another active enrolled employee on a shared device using the existing `voice.manage` matching capability. Their Agent chat history is stored under the matched employee's user ID with the authenticated operator ID preserved in metadata.

The kitchen remains read-only and authorized as the **signed-in KDS operator**. Recognizing another employee does not switch accounts, expose private chat contents to the manager, or confer that employee's permissions. Without a fresh verified match, the KDS speaks orders without an employee name and does not falsely attach voice turns to any employee's private history.

Web Speech recognition produces words but no trusted employee identification. For reliable name recognition an explicit short verification sample is required; background recordings or automatic ambient biometric matching are not silently enabled.

## Natural greetings

A verified speaker is greeted by first name at most when:
- The prior Agent user message was on an earlier database day, including the first conversation.
- There has been no user message for at least two hours.

Other replies omit the name. The calculation happens on the server using database message timestamps **before** recording the newest request, across the employee's conversations. Names are taken from server-side enrolled user records, never recognized transcript text or browser-supplied fields.

## Full Agent Brain history

The existing `agent_conversations` and `agent_messages` data continue to store staff history, roles, channels, structured results, sources, and `created_at`. New owner-scoped `history_threads` and `history_page` endpoints reveal older and archived conversations with stable cursor pagination; the Agent canvas includes **Full chat history**, **Load older conversations**, and **Load earlier messages** controls, plus visible message times and channels. KDS voice exchanges from a freshly verified speaker enter a daily `KDS voice conversation` thread, with the authenticated device operator recorded in metadata.

## Acceptance and safety

Verify correct order quantities, original POS numbers, modifiers, and updates for more than eight active orders; voice-matched and unverified behavior; first-day, idle and routine greetings; manager recognizing staff without privilege transfer; owner-only access to archived and older messages; cross-device persistence; and preservation of old messages during upgrade. CSRF and KDS location permission checks remain enabled.

Existing enrolled voice samples and all private storage must be preserved on deployment. Never delete the voice identity directory or Agent database records. Hard-refresh KDS and Agent chat assets after upgrading. Browser microphone/voice-match reliability still requires real-device testing with enrolled staff.
