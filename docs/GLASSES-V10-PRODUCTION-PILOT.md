# V10 Section 7 — Production Pilot Governance

Section 7 governs which sites and devices may enter production mode.

A pilot defines a cohort, release label, optional location/station scope, optional governed model rollout, and a required hardware adapter. Real AIR3 pilots default to `air3.vendor.v1` and therefore cannot become production-ready until the proprietary adapter actually exists.

Controls:
- draft / active / paused / stopped pilot lifecycle;
- per-device enroll / enable / disable / remove;
- readiness evaluation with device, scope, runtime health, recovery state, rollout, and hardware-adapter checks;
- cohort/site/station scoping;
- kill switch immediately disables enabled devices;
- pause/stop disables enabled devices;
- explicit reasons for disable/remove/pause/stop/kill-switch actions;
- durable pilot event audit trail;
- device-facing production-status query;
- model assignment may request `productionMode=true`, which is rejected unless the device has an enabled, active, ready pilot.

The pilot layer does not replace pairing, KDS/build authority, model-rollout governance, or the AIR3 hardware adapter.
