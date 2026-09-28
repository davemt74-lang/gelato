# Gelato AR Glasses Plugin — Section 21B Production Detector Runtime Harness & Simulator Parity

Section 21B governs the detector execution path that sits between camera frames and the existing Gelato VisionPipeline.

## Goals

- make detector inference bounded and observable;
- prevent unbounded frame queues;
- reject overlapping inference under backpressure;
- fail closed on timeout/runtime errors;
- recover controlled runtimes through restart + warm-up;
- expose health/latency/drop telemetry;
- keep simulator and production detectors behind the same runtime-control contract;
- preserve the Section 18–21A safety chain.

## Runtime harness

`VisionDetectorRuntimeHarness` wraps any `IVisionDetector`.

The harness provides:

- one active inference at a time;
- zero-length backlog: a concurrent frame is dropped instead of queued;
- configurable inference deadline;
- timeout/error accounting;
- latency accounting;
- runtime states: starting, ready, degraded, recovering, failed;
- watchdog thresholds;
- optional warm-up/restart through `IVisionDetectorRuntimeControl`.

A timed-out inference is cancelled. If the detector ignores cancellation, the harness continues holding the inference gate until that underlying call exits, preventing a second detector call from overlapping it.

## Controller behavior

`VisionRuntimeController` constructs the production pipeline around the runtime harness.

Before automated vision starts, detector warm-up must succeed.

If the detector enters `Failed`, automated evidence is held and the controller retries recovery. Kitchen/manual/KDS workflow remains outside this hold.

Existing camera timestamp monotonicity remains enforced by `VisionPipeline`, so stale or duplicate frames cannot satisfy multi-frame stability.

## Health telemetry

The runtime health surface records:

- inference calls;
- successful inferences;
- timed-out inferences;
- failed inferences;
- backpressure drops;
- restart attempts/successes;
- last inference latency;
- average inference latency;
- last error code/message;
- current runtime state.

This is runtime telemetry only; it does not alter recipe or KDS truth.

## Simulator parity

`DesktopSimulatorVisionDetector` implements both `VisionDetectorBehaviour` and `IVisionDetectorRuntimeControl`.

It supports:

- warm-up;
- restart;
- scripted per-frame detections;
- empty frames when the script is exhausted.

The same production harness therefore exercises simulator detections rather than relying on a separate detector execution model.

## AIR3 boundary

No proprietary AIR3 APIs are invented in Section 21B.

When the SDK arrives, the AIR3 production detector should implement the same:

- `IVisionDetector`;
- optionally `IVisionDetectorRuntimeControl` for warm-up/restart.

The Section 21A model-loader adapter and Section 21B detector adapter remain separate seams.

## Safety inheritance

Section 21B does not bypass:

- governed label profiles and blocked labels;
- raw/per-label confidence floors;
- spatial calibration;
- transfer evidence;
- human corrections;
- product validation;
- KDS lifecycle authority;
- exact model assignment binding;
- verified model activation;
- durable model recovery.

## Contract coverage

The hardware-neutral contract proves:

- warm-up before healthy inference;
- inference timeout returns no evidence;
- watchdog restart after configured failures;
- one-inference backpressure;
- timed-out detector calls that ignore cancellation cannot overlap subsequent inference;
- restart failure enters explicit failed state;
- health/latency counters are updated;
- prior AIR3 behavior remains green.
