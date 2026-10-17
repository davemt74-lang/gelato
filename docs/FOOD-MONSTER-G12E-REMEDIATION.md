# FM-G12E — G12 Remediation and Certification Gate

## Status and claim boundary
This change repairs defects found in G12A–G12D and adds a CI release gate.
Green automated tests prove only the documented contract under simulated inputs.
**No physical camera recognition, acoustic diarization accuracy, device-level microphone recovery, or unattended restaurant autonomy is claimed certified.**

## Remediated behavior
- **G12A**: only a server-verified same-owner room transition sets the short-lived speaker handoff flag; the Agent Chat emits boolean-only notifications and Food Monster shows a neutral acknowledgement. Unknown/typed turns invalidate continuity. The stage never authenticates or grants permissions based on visual identity.
- **G12B**: fatal microphone/service errors and restart exhaustion cancel queued automatic sends, clear voice draft ownership, stop recognition and polling, and turn off Conversation Mode. Repeated onend restarts are capped; the microphone is never automatically reauthorized.
- **G12C**: optional learning recaps require two distinct prior canonical message IDs and distinct meaningful keyword signatures, rejecting exact and filler-word paraphrase duplicates. These are topic reminders, not mastery assessments.
- **G12D**: session-only, employee-scoped hint deduplication preserves source order independence, cooldown and 32-person / 16-hint bounds, with tests of real decision logic.
- **G12E isolation**: the owner's mixed-speaker room transcript is not used as model context for voice turns. Server-confirmed employee turns use their canonical person thread; unknown voice receives only the current utterance. Typed authenticated owner chat retains its shared-room history. Existing tool-authorization remains server-side.
- **Deployment**: cache tokens changed in Food Monster and Agent Canvas so updated client code is actually loaded.

## Automated acceptance
The FM-G12E workflow runs PHP/JS lint, existing voice and memory regressions, browser microphone failure tests, handoff events, room tracking under eight-person/reordered frames, high-impact approval controls, and voice LLM history isolation. All changes to those paths must trigger certification. Exact final commit, not earlier workflow success, is the merge criterion.

## Required field certification (not met by CI)
1. Opt-in camera / mic with consenting staff; verify at least two verified speakers and one unknown; record false-attribution count and never authorize an action from a face.
2. Test employee A -> employee B -> unknown -> A in a real noisy kitchen, including TV/TTS echo and a five-second speaker correlation timeout. Verify person B cannot inherit A's private context.
3. Deny and revoke microphone permission during queued dictation, unplug microphone, hide tab, and force repeated speech-service restarts. Require no phantom send and a visible off state.
4. Exercise permitted routine operational task and high-impact proposal/approval on a test tenant. Verify durable action receipts, replay prevention, tenant separation and no action from ordinary unverified voice.
5. Validate full post-merge deployment archive and SHA-256 with private config/uploads excluded. Install on a staging HostGator-like environment and compare after upgrade.

**Release rule:** Do not score 10/10 for physical accuracy or end-to-end autonomy without field evidence. Do not move to FM-G13 until G12E exact-head CI passes, is merged, and deployment packaging is checked.
