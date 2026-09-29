# Vision Lab V11 Section 9 — Shadow Deployment & Production Validation

Section 9 extends the existing shadow-run and rollout system. Approved V11 release candidates are executed beside the active champion without affecting kitchen decisions, KDS state, POS state, build confirmation, or model assignment.

V11 shadow evidence captures disagreement, critical mismatches, champion/challenger correction outcomes, inference latency, timeouts and runtime errors. Runs retain release-candidate, device, location, station and operator lineage.

An immutable aggregate validation applies explicit minimum evidence and coverage requirements plus critical-mismatch, disagreement, latency, timeout, runtime-error and correction-win exit criteria. Only a passing validation is canary eligible.

The existing rollout activation path remains the consequential boundary. V11 activation additionally requires a passing Section 9 validation. Section 9 itself never activates or advances a rollout.
