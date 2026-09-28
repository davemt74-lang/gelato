# Section 21C — Device Runtime Telemetry, Health & Recovery Operations

Section 21C adds a hardware-neutral operational supervisor above the Section 21B detector harness. It does not replace detector safety, model governance, label profiles, build-session authority, or KDS authority.

## Runtime health contract

The supervisor aggregates:

- platform initialization
- camera availability and distinct-frame freshness
- detector state from the 21B runtime harness
- known-good model runtime readiness
- heartbeat health
- thermal state when a platform can supply it
- resource-pressure state when a platform can supply it

Aggregate states are `Starting`, `Ready`, `Degraded`, `Recovering`, and `Failed`. Automated vision evidence is permitted only in `Ready`.

## Fail-closed behavior

Camera loss, stale frames, detector degradation/failure, model recovery, heartbeat failure, critical thermal conditions, and resource pressure cannot manufacture ingredient observations. The kitchen workflow and current build session remain intact; only automated evidence is held.

## Telemetry

The supervisor exposes bounded counters for evaluations, ready/fail-closed evaluations, frame samples, distinct frames, stale-frame blocks, heartbeat failures, recovery attempts/successes/failures, and state transitions.

The bounded incident ledger records only health transitions/reason changes, preventing duplicate incident spam during a persistent fault.

## Recovery operations

Recovery is rate-limited by a monotonic cooldown and a consecutive failure budget. Exhausting the budget fails closed rather than creating an infinite restart loop. Successful recovery clears the failure budget but does not reset the active build session or vision pipeline tracking state.

## AIR3 boundary

Thermal/resource telemetry is represented as a hardware-neutral contract. Section 21C does not invent proprietary AIR3 APIs. The later Section 21D adapter can populate those signals from vendor SDK capabilities when available.

## Safety invariants

Sections 18–21B remain authoritative for:

- governed label mappings / blocked labels
- confidence thresholds
- stale and duplicate frame rejection in the vision pipeline
- calibration / spatial evidence
- correction and learning history
- verified model activation and durable recovery
- inference timeout, backpressure, and watchdog restart
- exactly-once ingredient observation emission
- product validation and KDS/expo authority
