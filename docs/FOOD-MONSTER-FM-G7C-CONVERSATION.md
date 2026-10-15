# FM-G7C — Natural conversation and Claude routing

## Root cause and fix
A broad manager-menu fallback sent generic chat prompts to restaurant menu search, returning the same first 20 menu items and bypassing the existing Claude/OpenAI adapter. FM-G7C removes the unconditional menu route in the authenticated Agent Workspace router and a secondary broad menu search fallback in Restaurant Brain. Explicit menu questions still route through existing permission-gated tools. Unrouted conversation now reaches the preexisting server-side LLM adapter; Auto prioritizes a configured Anthropic Claude key.

## Hands-free Conversation Mode
- Opt in using ↔ in the existing Agent footer; listening and verified voice permissions remain required. Mode is off by default.
- Final browser speech recognition results appear in the existing chatbar and send after a 1.2-second pause. Interim text remains editable. Standard dictation never autosends.
- Agent responses speak through the existing VoiceTurns API. Fresh, non-echo user speech interrupts the response and becomes a new turn, including without a wake phrase.
- Browser speech-service/microphone and TTS support required. Echo suppression is best effort; field test with speakers and microphone is still required.
- No separate login, API keys, permission bypasses, or automatic device activation. Server controls all Claude credentials, role permissions, audit records and approvals.

Tests: php tests/food-monster-g7c-routing.php; node tests/food-monster-g7c-browser.test.cjs. FM-G1–G7V regressions remain required.
