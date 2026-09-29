# V10 Section 6 — Cook Interaction & On-Lens UX Acceptance

Section 6 validates the real cook-facing projected workflow and tightens the HUD around one rule: **the center of the cook's field of view stays clear of persistent UI**.

Persistent projection zones:
- top status;
- left active-order rail;
- right workflow rail.

The right workflow rail contains:
- current item and modifiers;
- current/next recipe steps;
- ingredient warnings;
- portion correction;
- placement correction;
- rework prompts;
- final validation state;
- explicit human handoff state.

The center remains available for the kitchen scene. Only transient evidence overlays (for example, a detected ingredient outline) may cross it.

STOP/cancel disables automatic observations and clears transient vision work. Recovery states gate consequential controls. Handoff remains explicit and delegates to the existing governed confirmation/handoff flow; Section 6 does not create another completion path.
