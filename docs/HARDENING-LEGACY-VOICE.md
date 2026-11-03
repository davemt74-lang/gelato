# Hardening E — legacy Agent microphone provenance (FE-08)

## Scope and defect

The general Restaurant Agent microphone, used by the owner workspace and Floor Planner, previously created a separate `SpeechRecognition` instance. Its shipped `onresult` called the typed specialist submission handler without voice options. A spoken “clock me in” or “Confirm” was therefore saved and sent as `voice:false` with an empty event ID. Operations had the same pattern. Time Clock maintained a separate recognizer and reused a manual voice-match event for up to fourteen minutes.

Those paths were authenticated, but they bypassed the additional spoken-turn conditions. This fix preserves account authorization and makes the channel truthful. It does not treat a voiceprint as authentication or grant another speaker the signed-in employee's permissions.

## Shipped behavior

The three generic writable Agent microphone controls now call the shared `GelatoAgentReview.microphone()` handoff:

| Entry point | Current behavior |
| --- | --- |
| `js/restaurant-agent-bridge.js` / owner workspace / Floor Planner | Opens the page's existing canonical Agent conversation, then invokes `GelatoGlobalAgent.talkOnce()`; the legacy typed-send callback never receives a microphone transcript. |
| `js/operations-core.js` / Operations microphone | Uses the same canonical handoff; Operations now loads the shared global runtime. |
| `js/timeclock-voice.js` / Talk, listening, wake standby | Uses canonical one-shot or listening controls. The duplicate recognizer and cached-match action fallback were removed. Enrollment, manual profile verification, and manual clock/break buttons remain available. |

The handoff keeps the page's saved conversation and exact previously displayed proposal. If another current Gelato conversation has a pending reviewed action, it refuses to switch microphones and gives an explanation. A thread change during capture rejects the utterance. Missing or unready shared runtime fails clearly; it does not fall back to typed submission.

Canonical one-shot speech now starts the same consented-profile acoustic sampler used by continuous speech. The sample is tied to the completed transcript, and a new identity event is requested for each turn. The stream closes after the one-shot turn, cancellation, hidden-tab interruption, or failure. Unknown speakers stay recorded as voice with no usable identity event. An old manual voice check cannot supply a later action's event.

“Confirm” and other recognized decisions about an existing reviewed proposal now request the same explicit signed-in private-turn approval as other protected spoken requests. Approval authorizes private-tool use for that exact saved turn; acoustic matching remains a separate provisional speaker correlation.

## Server boundary

`includes/agent-confirmation-core.php` resolves protected proposal creation and confirmation from the saved canonical user message. The saved message's channel is authoritative even if the caller submits `voice:false`.

For a spoken protected turn, the guard requires:

1. The conversation, message, authenticated user, and organization agree, and this is the latest user message in that conversation.
2. The delegated command matches the saved transcript. Canonical short Confirm/Cancel synonyms may be normalized to the same decision; a different command or opposite decision cannot reuse the receipt.
3. The saved voice event is fresh within the existing 30-second correlation window, was claimed by canonical append, and occurs on exactly one canonical user message.
4. The active profile and active membership still match the signed-in employee. Manager `voice.manage` recognition of a different employee does not qualify for a personal action.
5. The exact canonical voice turn has the existing explicit authenticated private-turn approval. A speaker match alone is insufficient.
6. An atomic metadata update spends that event for one protected Agent operation. A later proposal or confirmation needs another event.

The metadata field `protectedAgentTurnConsumedAt` lives on the existing `voice_identity_events.metadata_json`; no voice schema migration is needed. Canonical append continues to own the original `agentTurnConsumedAt` claim. Expired/reused events fail closed. Bad spoken confirmation evidence leaves the pending proposal and domain records unchanged.

Time Clock records the confirming turn's channel and event, instead of borrowing the original proposal's event. It rechecks current voice evidence after acquiring the employee lock and writes the canonical decision message ID plus confirming event ID into the action audit. The original proposal payload remains immutable. An explicitly typed confirmation continues to use account-authorized typed behavior, including when the original proposal was spoken.

## Other microphone paths reviewed

The repository's remaining speech recognizers have separate purposes:

- `js/kds-voice-listener.js` performs order/status readouts and uses the separate read-only KDS Agent. It is not one of the generic writable specialist adapters changed here.
- `js/training-workspace-voice.js` answers the local guided-training interaction through the bounded Training workspace. It does not submit generic restaurant Agent clock/inventory commands.
- `assets/js/glasses-simulator-voice.js` dispatches the separate Glasses navigation/KDS device workflow, with its own explicit ticket-finish confirmation. Its device policy is not converted to Agent proposal receipts in FE-08.
- `assets/js/glasses-voice-device.js` reports device capability/consent diagnostics; it is not a generic Agent action submission path.

This release does not certify those separate device or training workflows as speaker-verified, nor does it claim physical microphone, acoustic identity accuracy, or live room testing.

## Verification

The regression fixtures execute complete shipped scripts, not extracted replacement `onresult` implementations. Browser recognition, acoustic frames, and identity-service responses are controlled adapters in the frontend tests. The database and HTTP tests use real canonical append, identity-event claims, proposal binding, domain mutations, and authenticated sessions. No paid AI provider is required; the HTTP-only CLI fixture consumes a real UI-issued private-turn token in place of the model gateway's token-consumption step.

```sh
node tests/hardening-legacy-voice.cjs
php tests/hardening-legacy-voice.php
node tests/hardening-legacy-voice-http.cjs
node tests/hardening-legacy-voice-browser.cjs
node tests/proactive-global-listening.test.js
node tests/agent-private-voice-contract.test.cjs
node tests/hardening-agent-confirmation-browser-state.cjs
php tests/hardening-agent-confirmation-contract.php
php tests/timeclock-agent-lifecycle.php
```

- The primary Node runner covers all three shipped microphone controls, fresh command and Confirm events, separate per-turn consent, unknown-speaker isolation, typed compatibility, retained conversation/review, conflicting pending conversations, mid-capture thread changes, hidden-tab callbacks, and sampler cleanup.
- The PHP/MariaDB runner exercises action and confirmation rejection, tenant/employee/message/command context, expiry/replay, disabled profiles, permission revocation, original proposal retention, audit data, and successful typed/spoken clock transitions.
- The real HTTP runner covers authenticated routes, canonical append, UI consent, protected action/confirmation, stale/reused and other-principal/tenant receipts, typed-source concealment, and manual compatibility.
- The Chromium runner clicks real legacy controls, drives their shipped canonical recognizer callbacks, checks the visible approval modal, and verifies a new event and unchanged conversation/proposal for each spoken confirmation. Its screenshot is written to `artifacts/hardening-legacy-voice-browser.png`.

The identical Node acceptance assertion fails on the original Release D code (`82eda028f40af0c849b403ee037740f96bc3173b`):

```sh
node tests/hardening-legacy-voice.cjs --baseline /path/to/release-d-checkout
```

Expected baseline failure: `FE-08: microphone onresult must preserve voice:true, never downgrade speech to a typed command.` Actual `false`, expected `true`. This reaches the actual shipped callback and transport; the failure is not a missing helper or bootstrap error.

Local verification at implementation time: primary Node regression **48 assertions passed**; existing proactive listening, private-voice contract, and browser-review state runners passed; modified JavaScript parses and `git diff --check` passes. The implementation workspace has no PHP, MariaDB, or Chromium runtime. Their checks are CI gates, not locally claimed results.

## Cache and operational notes

All references to the changed runtime/adapter/confirmation assets use `20261103-e1`. The review also updates dependent JavaScript loaders and their page references so a cached Floor Planner, auth guard, universal shell, or workspace shell cannot keep requesting the old microphone implementation. Changes in unrelated page files are asset-cache references only; Operations additionally includes the canonical runtime.

Generic model domain delegation forwards the original spoken text unchanged. Native proposal tools retain the canonical input context established by `api/food-monster-llm.php`. The pending-action continuation tool alone may normalize a decision to Confirm or Cancel; only the existing short decision synonyms are accepted for spoken normalization. A broader spoken paraphrase is safely rejected rather than treated as evidence for a different command. Saying “Confirm” or “Cancel”, or using the typed controls, remains available. Typed model-selected paraphrases retain their existing behavior.

A voice command that waits beyond the correlation window must be spoken again or entered with the typed controls. No previous speaker identity or earlier approval is carried forward to make a delayed request succeed. Model/provider delays and browser microphone availability can therefore produce a safe rejection. Production deployment and physical voice recognition verification are separate steps.
