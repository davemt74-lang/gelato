# Agent Eyes AE4 — Movement, Crossing and Brief Occlusion

## Camera view
The opt-in Agent Eyes live camera retains the AE1–AE3 boxes: green around a provisionally recognized face, amber around an unknown participant and blue around the independently voice-correlated current speaker. Face bounding boxes update with incoming camera observations and use light presentation-only smoothing so routine detector jitter is less visible.

## Tracking reliability
- Tracks use bounded motion prediction plus already-available local descriptors to retain stable anonymous track IDs as participants move and cross one another.
- Appearance disagreement cannot steal another person's track even if the faces cross or overlap spatially.
- A brief occlusion can retain an ephemeral track ID; a gap exceeding 3.5 seconds discards provisional identity streaks and requires three fresh consistent descriptors before a person's label reappears.
- Descriptor-less observations use spatial tracking only for short gaps, and never inherit a person's identity.
- Tracks disappear after the existing 5.5-second TTL; camera stop still clears all tracks and closes the stream.

## Certification limits
Automated tests simulate crossings, disappearances, jitter, return after occlusion, and real Chromium camera UI. They do not certify face recognition accuracy with actual staff, lighting changes, masks, PPE or multiple real webcams. The existing server-side tenant, consent and no-authentication boundaries remain unchanged. No migration is needed.
