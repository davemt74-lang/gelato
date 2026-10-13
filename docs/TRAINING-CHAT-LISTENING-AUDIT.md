# Training Workspace — Chat, Popup & Listening Audit

## Production symptoms and confirmed causes

The Training workspace already owns the inline messages, input field, send button and local guided quiz grader. The newer Admin theme overrides all bubble backgrounds to white, including user bubbles that retained white text. Thus the user's answers visually disappeared.

A second, shared global Agent composer was fixed at the bottom of the same page. Its Enter handler sent to the global Agent rather than the Training grader. The dynamic Agent canvas previously auto-opened on every global Agent response, obscuring the underlying guided-quiz answer and next question.

## Corrected interface

- In Training mode there is one visible composer: the page's own Training composer. Enter sends to the local Training question grader; Shift+Enter adds a newline.
- The global Agent bar remains available in Admin mode and on other pages. It is suppressed only while the Training workspace mode is active.
- Global Agent response events cannot automatically open the popup over a Training quiz. An explicitly invoked full Agent canvas remains available where appropriate.
- User answers have a dark background and white foreground; corrections and questions retain dark text on light backgrounds.
- Guided training scoring, hints, explanations and next-question progression are preserved.

## Microphone and listening controls

| Mode | Activation | Destination |
|---|---|---|
| Typing | Enter | Existing local Training quiz |
| Dictate one | Click microphone; stops after first final transcript | Existing local Training quiz |
| Hands-free | Click hands-free toggle; accepts final phrases while Training is visible | Existing local Training quiz |
| Global Agent | Separate permissions and voice-identity controls | Protected global Agent API |

Microphone access never starts automatically. The status reports off, starting, listening, heard, paused, blocked, error or unsupported. Hidden tabs pause the Training microphone, switching to Admin stops it, and permission denial stops it. When global Agent listening is active, Training dictation stops the global microphone before starting its own. Browser APIs may still suspend continuous listening, so no permanent unattended listening is promised.

Training voice does not grant protected Agent permissions, record audio files, modify POS/KDS, or redirect answers to a different conversation.

## Acceptance

Check at desktop and 390px: start guided Pizza Cook training, submit a correct and incorrect answer, use hint and skip, and confirm readable answers plus correction and subsequent question inline. No Agent popup should appear. Then test once-only and hands-free dictation, deny microphone access to confirm a visible status, switch to Admin, and confirm Training listening stops. Existing Admin Agent features should remain available.

No database migration required. Preserve config.php, uploads and storage during deployment.
