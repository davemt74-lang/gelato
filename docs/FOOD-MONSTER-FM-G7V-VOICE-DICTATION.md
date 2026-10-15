# FM-G7V — Food Monster browser voice dictation

FM-G7V restores visible browser speech recognition in the existing Gelato chat bar.

## Problem and correction
- Previous continuous listening only forwarded final utterances beginning with “Hey Gelato”; other speech produced no visible transcript, even with an active microphone.
- The one-shot mic button called a missing `speechOnce()` function.
- Active continuous listening now shows interim/final words in `#gaInput`; final segments form a draft. Manual typing overrides speech previews.
- Speech without the wake phrase **does not automatically submit** any text or tool action. The user must click Send or press Enter. Existing “Hey Gelato” action commands retain their authorization and approval requirements.
- Standby mode does not transcribe unrelated background speech.
- The one-shot mic captures a final browser result, sends via the existing verified Agent Chat flow, and restores listening if already enabled.
- Visible speech-service failures are reported. A blue listening state means the recognition session started, not necessarily that the browser returned a transcript.

## Permissions and dependency boundaries
- No OpenAI or Anthropic key is needed for browser speech-to-text or permission-scoped Gelato skills.
- The microphone is not activated on page load. Existing voice verification, account permissions, CSRF, and approval flows remain in effect.
- Some browsers use an online speech-recognition service; browser microphone permission and speech-service availability are necessary.
- No new database schema, token, external library, browser extension or server-side key.

## Acceptance
- Node behavioral test: `node tests/food-monster-g7v-listening.test.cjs`
- Real Chromium chatbar test: `node tests/food-monster-g7v-browser.test.cjs`
- Existing Food Monster and proactive voice regression gates must remain green.
- Installed-site field test with a physical microphone is still required; mocked browser tests are not proof of live audio hardware or speech-service access.
