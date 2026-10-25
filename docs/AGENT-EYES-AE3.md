# Agent Eyes AE3 — Voice + Visual Participant Handoff

## Owner-facing experience

With the opt-in camera on, Agent Eyes labels one current speaker **only** when the existing server-issued, short-lived verified voice event independently correlates with exactly one locally enrolled and provisionally matched visual track. A blue highlight identifies the corresponding face. A verified change between two speakers displays a handoff notice. When voice is unknown, a face leaves view, the room session expires, the device is revoked, or the camera stops, Agent Eyes displays **Speaker not attributed** and no highlight.

The room-presence event key now includes the current server speaker correlation and verified handoff state, allowing Agent Chat/Brain subscribers to receive speaker changes without waiting solely for the prior 10-second context refresh interval.

## Safety boundaries

- No client-side speaker guess, lipreading, face-driven voice identity, or automatic login/tool authority.
- Correlation requires a still-valid server receipt with `correlated_voice_and_visual_observation` and explicit non-authority flags, one matching person ID and track, and one provisional visual label.
- The server voice receipt expires in five seconds. Camera-off immediately clears local speaker presentation and the server room session.
- No audio, image frames or biometric vectors are added to network calls by AE3.
- Only the Super Admin room camera sees the live panel; this does not enroll guests.
- Physical multi-person microphone/lighting, camera loss, and mixed-speaker field acceptance still require owner testing. Simulated Chromium checks do not establish facial or voice recognition accuracy.

## Upgrade

Deploy the Cloud ZIP over the existing installation, preserving `config.php`, uploads and storage, then hard-refresh the browser. No new AE3 database migration; run `upgrade.php` if upgrading from pre-AE2.
