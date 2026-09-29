# V10 Section 2 — Production Frame Pipeline & Backpressure

Section 2 adds a hardware-neutral frame-processing contract between camera capture and the V9 temporal vision pipeline.

The pipeline is deliberately bounded and conservative:
- single inference worker;
- bounded queue;
- configurable drop-oldest/drop-newest backpressure;
- stale-frame rejection before and after inference;
- inference timeout;
- cancellation when the runtime stops;
- monotonic sequence delivery;
- duplicate sequence rejection;
- explicit dropped/stale/timeout/cancel/failure metrics.

The browser simulator implements the same policy now and adds simulator-only fault injection for detector delay, forced timeout, and frame bursts. None of these controls mutate POS, KDS, build, or model-governance state.
