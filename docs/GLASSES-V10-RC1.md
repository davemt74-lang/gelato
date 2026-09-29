# Gelato V10 RC1 — Production Runtime & AIR3 SDK Integration Readiness

V10 RC1 locks Sections 1–7 as one production-ready runtime around the missing proprietary AIR3 SDK.

## What is complete without the SDK

- V9 kitchen intelligence remains intact.
- Hardware-neutral camera/display/input/model-load adapter contract.
- Browser simulator adapter.
- Bounded frame pipeline and backpressure.
- Device health, diagnostics, and explicit SDK-pending battery/thermal fields.
- Disconnect/reconnect/app-resume recovery.
- Deterministic long-running station soak and fault injection.
- Clear-center cook HUD and explicit STOP/resume/handoff UX.
- Production pilot cohorts, readiness, kill switch, rollback, and audit trail.

## What remains SDK-dependent

Only the physical AIR3 adapter and physical-hardware acceptance remain blocked:
- native AIR3 camera calls;
- native display/projection calls;
- native glasses input;
- vendor inference/model-loader integration;
- battery/thermal/native device health;
- final physical latency/FOV/thermal/battery acceptance.

The vendor implementation must implement `GlassesHardwareRuntimeAdapter` as `air3.vendor.v1`. It must not replace V9 kitchen intelligence, the V10 frame pipeline, recovery, diagnostics, soak, cook UX, pilot governance, POS/KDS/build authority, or model rollout governance.

## RC1 acceptance

RC1 requires all seven V10 section contracts, deterministic soak, deterministic cook UX, existing Web Simulator regression, V9 RC1 regression, clean install, migration exhaustion, checksum integrity, upgrade idempotence, adapter-boundary checks, and production deploy packaging from the exact green head.
