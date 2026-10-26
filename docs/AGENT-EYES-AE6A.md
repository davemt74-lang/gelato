# Agent Eyes AE6A — Proactive Enrollment Invitation

AE6A reuses Agent Eyes' existing opt-in room camera and AE2 enrollment form. A single, consistently tracked unknown participant can receive an optional enrollment invitation after remaining visible for three seconds. With the Super Admin's existing Agent Proactive Voice preference enabled, Gelato may offer a spoken invitation. It does not open Chat, create a new recording stream, or imply that the participant is identified.

The Agent Eyes panel provides **Start consented enrollment** and **Not now**. Opening enrollment reuses the existing active-member selector, three-sample face enrollment and unselected consent checkbox. A participant who declines is not prompted again for the same temporary track. Only one face in view may trigger an invitation, avoiding ambiguous shared-room consent. A global spoken-invitation cooldown prevents repeated announcements. Camera stop clears pending invitations and closes any unfinished enrollment form.

No voiceprint, photograph, descriptor, guest account, audio recording, or permission is created by an invitation. Face samples continue to require explicit consent and capture clicks. This section is **not** the attended voice/photo enrollment phase; that comes next and must reuse the existing voice identity system with an explicit participant-consent step.

## Acceptance

`tests/agent-eyes-ae6a-invitation-browser.test.cjs` covers stable presence, opt-in spoken invitations, no automatic capture, single-track gating, consent default, disabled speech, decline cooldown, reuse of the existing enrollment modal, and camera-stop cleanup. Existing AE2/AE5 browser tests must remain green.

Physical camera, speaker, and microphone behavior still require on-site validation. This section does not elevate visual identity to authentication or Agent tool authority.
