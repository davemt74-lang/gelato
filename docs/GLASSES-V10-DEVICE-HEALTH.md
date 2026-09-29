# V10 Section 3 — Device Health, Telemetry & Diagnostics

Section 3 adds a hardware-neutral health snapshot over the V10 runtime.

Telemetry includes:
- camera state/source/resolution;
- frame FPS and inference latency;
- queue depth and accepted/processed/dropped/stale/timeout/cancel/failure counters;
- inference adapter/runtime/error state;
- active detector/model identity;
- runtime reconnect and sync-error counters;
- recent runtime errors;
- AIR3 battery and thermal fields with explicit vendor-SDK-pending provenance.

The simulator renders the health snapshot live and can export a diagnostic JSON bundle. The diagnostic layer is read-only and cannot mutate POS, KDS, build, handoff, rollout, or model state.
