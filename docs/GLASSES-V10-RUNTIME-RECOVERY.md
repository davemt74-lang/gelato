# V10 Section 4 — Disconnect / Reconnect Recovery

Section 4 adds a governed runtime recovery state machine without creating a second source of kitchen truth.

Recovery rules:
- network, camera, inference, and visibility/app-resume interruptions immediately stop automatic observations;
- the V10 frame queue, active inference, temporal tracks, and readiness timers are cleared on disconnect;
- reconnect first reloads canonical station work, then rehydrates the existing active build;
- when a build session ID is known, recovery uses build.get;
- when only the KDS item is known after app restart, build.start safely returns the existing active session for the same device/item;
- stale pre-disconnect frames are never replayed;
- automatic vision remains gated until a fresh-frame barrier completes;
- completed/cancelled/otherwise non-resumable canonical work is blocked rather than recreated;
- no recovery path performs Expo/KDS handoff automatically.

The simulator persists only resumable identifiers locally (device, KDS item, build session). Canonical build contents always come back from the server after reconnect.
