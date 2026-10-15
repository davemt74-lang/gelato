# FM-G7C — Natural conversation and Claude routing

## Root cause and fix
A broad manager-menu fallback sent generic chat prompts to restaurant menu search, returning the same first 20 menu items and bypassing the existing Claude/OpenAI adapter. FM-G7C removes the unconditional menu route in the authenticated Agent Workspace router and a secondary broad menu search fallback in Restaurant Brain. Explicit menu questions still route through existing permission-gated tools. Unrouted conversation now reaches the preexisting server-side LLM adapter; Auto prioritizes a configured Anthropic Claude key.

## Hands-free Conversation Mode
- Listening can be enabled with the existing Listening button; ↔ additionally opts into Conversation Mode for spoken replies and interruption. Both are off by default until microphone permission and voice verification are explicitly granted.
- With either Listening or Conversation Mode explicitly active, final browser speech results appear in the chatbar and **send automatically after 1.2 seconds of silence**. Interim text stays visible. Pressing Send is unnecessary. Typed text does not auto-submit; one-shot microphone results submit only once. Conversation Mode adds spoken interruption and reply turn-taking.
- Agent responses speak through the existing VoiceTurns API. Fresh, non-echo user speech interrupts the response and becomes a new turn, including without a wake phrase.
- Browser speech-service/microphone and TTS support required. Echo suppression is best effort; field test with speakers and microphone is still required.
- Turning off Listening, microphone access, or switching to a hidden tab cancels pending auto-send. Manually edited text takes priority. No separate login, permission bypass, or automatic device activation; the server controls all Claude credentials, role permissions, audit records and approvals.

Tests: php tests/food-monster-g7c-routing.php; node tests/food-monster-g7c-browser.test.cjs. FM-G1–G7V regressions remain required.
