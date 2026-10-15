# FM-G6 release and field acceptance

Food Monster AI uses the authenticated Gelato Global Agent session. The dedicated `food-monster.php` page has no sidebar, keeps the existing fixed Agent chat footer, and floats the chef mascot in front of the page. On other authenticated pages it loads from the shared shell.

## Production capabilities
- Animated eye, mouth and expressions respond to Gelato events.
- Webcam access is user-initiated. On-device face tracking moves the character and scales apparent distance. Facial enrollment and encrypted local profile storage are optional.
- Existing Gelato verified-speaker events personalize greetings; neither face nor voice creates or changes an account session.
- The LLM fallback uses configured, encrypted organization credentials. Specialized actions go through Gelato permission-gated tool routes first, and destructive changes still require confirmation.
- Next Moves recommendations are permission-scoped, non-speaking by default, and require Review before any action.

## Software certification
Run `php tests/food-monster-g6-contract.php` and `node tests/food-monster-g6-browser.test.cjs` alongside G1–G5 browser and PHP suites. GitHub Actions runs on the exact PR revision; the merged main revision is independently certified before the next phase.

## Field acceptance still needed
1. Open `food-monster.php` over HTTPS on HostGator, verify dark background, floating mascot, no sidebar, single sticky chat composer.
2. Grant webcam permission intentionally; watch eye movement, body follow and size variation on real camera. Deny permission to confirm a clear failure state and no extra access.
3. Enroll a real face only with the intended user's consent; inspect recognition reliability, including different faces and multiple people.
4. Test browser microphone and existing verified voice enrollment; verify that another speaker never switches the Gelato session.
5. Configure actual OpenAI/Anthropic keys through Gelato Admin (not the mascot), then perform live chat and permission-denied tool tests.
6. Send an operational recommendation and confirm Review/Dismiss behavior, approval screens, and lack of automatic execution.
7. Verify the installed build updated; if a third-party cache still serves older static assets, purge that cache once.

## Deployment safety
Deploy the **full** GitHub Actions ZIP whose `DEPLOY_SHA.txt` matches the certified main revision. Preserve live `config.php`, `.env`, `uploads/`, and `storage/`. Avoid touching HomeServer. Cached outer page scripts may require host/CDN cache invalidation; the FM-G6 shared-loader and dedicated-stage script URLs are versioned to prevent stale nested assets.
