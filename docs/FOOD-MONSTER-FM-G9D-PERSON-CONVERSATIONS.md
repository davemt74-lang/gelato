# FM-G9D — Person-scoped conversational collections

Gelato already owns the authoritative `agent_conversations` and `agent_messages` tables. No duplicate employee database, account, history system, or onboarding workflow was created.

A person has a logical **Food Monster conversation collection** per day, keyed by the existing organization's ID and employee's permanent Gelato user ID. Each collection contains timestamped speech turns, Food Monster replies, optional skill/source metadata, provenance and voice event evidence. The owner-operated Agent Chat continues to preserve the shared room conversation and its existing full history.

An unknown or uncertain speaker stays only in the shared room history. **Only a fresh (up to 60 seconds), server-recorded, consenting enrolled voice match** from the Super Admin's current session can link a turn to an individual's collection. Each voice event can be used for **one** person-specific user turn, preventing stale voice labels from being reused for later people. A room camera match alone is never treated as a speaker match. The agent's following answer is mirrored to the same person's canonical collection.

The Super Admin can retrieve person collections through an authenticated server route with organization scoping; normal employee login/role capabilities are not conferred. All writes are server-authoritative and audited by provenance. No raw recordings, voiceprints or camera templates enter the transcript collection.

Existing topic areas—recipes/training, work context, reports and continuity—can be represented by ordinary message metadata, with later supervised summarization if wanted; they are **not separate physical folders**. FM-G9D does not generate inferred behavioral traits or hidden employee scores.

**Current limitation:** Gelato's existing voiceprint capture is opt-in, manually sampled, and is not reliable continuous real-time diarization. The first implementation adds conversational speaker recognition on explicit voice identification or fresh voice events; it does not pretend every ambient spoken sentence can be reliably attributed in a multi-speaker room. Recognition never blocks chat listening, and is never a substitute for the shift PIN or Super Admin approvals.
