# FM-G9E — Structured conversational memory & unresolved reports

Person collections remain ordinary dated `agent_conversations` / `agent_messages` associated with the current employee's **existing user ID**. No parallel participant tables, demographic profiling, employee scoring, or new persistent UI.

**Three useful derived memory categories** are tagged in the existing mirrored spoken-turn metadata: operational reports (only conservatively matched present-tense reporting), ingredient/recipe questions, and training/procedure questions. All other content stays general. Earlier untagged FM-G9D turns can still be read using the same conservative classifier.

**Outstanding reports** are a filtered view of tagged person turns, not a second task system. The signed-in Super Admin can inspect them and explicitly mark one resolved; the resolution is appended as a timestamped, auditable event in the **same canonical conversation**, not a destructive edit. Repeated resolution calls are idempotent. Nothing is silently marked resolved from casual speech. Other systems can later attach a governed action receipt or task to these report message IDs.

**Conversation continuity**: for the current *server-validated* speaker only, Food Monster's LLM adapter receives a very small, source-labeled, time-bounded recap of recent questions and open reports. When speaker verification is absent/stale, or someone merely claims to be another person, no employee memories are injected. Recognition never grants roles, login, permission to change records, or clock-in privileges. Context is framed as untrusted historical quotations, not commands.

**Boundaries**: 90-day query window, max three to ten items per category in the API, max 1,100 characters in model context; no biometric templates, voiceprint vectors, full transcript dumps, or arbitrary unbounded employee dossier. No background monitoring or automatic employee evaluation. Existing clock, camera, and normal conversation mode are unchanged.

**Field limits**: reporting classifier is deterministic and intentionally conservative, and no independent AI accuracy certification is claimed. Automatic speaker diarization still needs real environment testing. Without a configured LLM provider, the durable memory API works but the model cannot incorporate context into freeform replies.
