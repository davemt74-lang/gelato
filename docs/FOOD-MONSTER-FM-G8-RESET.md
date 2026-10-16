# Gelato FM-G8 — Legacy participant and onboarding reset

Removed the FM-G8A/G8C/G8D1/G8D2A/G8E room tracking, onboarding modal, visible participant cards, guided biometric setup, and old camera face-identification UI. Food Monster mascot and existing Agent Chat, voice transcription, Agent Brain, KDS and time-clock APIs remain.

Listening begins directly after browser microphone authorization, without a preliminary biometric recording or enrolled-speaker challenge. Optional identity verification still exists in Gelato's underlying voice service for independent identity-specific features; it is not a precondition to speaking.

**Data retained:** Existing Gelato accounts, roles, time-clock entries, voice profiles and samples, PIN/face login systems, stored historical participant records, and database migrations. No SQL rollback, database purge, or automatic deletion of existing encrypted face data is performed.

**Temporarily unavailable until redesign:** Food Monster camera capture, room presence cards, face recognition and guided enrollment. Do not claim identity detection is complete.

**Replacement design pending:** Distinguish authenticated operator (e.g. owner), on-duty employee, room-observed person and recognized speaker, with one shared person record; supervised consent-based enrollment must not require employee website login.
