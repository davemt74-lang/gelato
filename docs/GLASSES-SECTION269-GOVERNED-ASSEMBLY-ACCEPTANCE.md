# Section 269 — Governed Assembly Recognition Integration

Baseline: Section 268 merged at `38e0b2e00442c1e817c20c18e5ae55f905656f76`.

## Prior-system audit (reuse; do not rebuild)
- Vision Lab calibration profiles and station/device context selection.
- Vision Lab confidence policies, per-class mapping floors, model QA and candidate promotion.
- Existing governed model assignment bound to an active build and SHA-256-verified ONNX artifact.
- Existing temporal tracking, frame backpressure, recovery freshness barrier, signed-off canonical build observations and audit.
- Existing Manual, Assisted, Automatic recognition selector, camera confidence slider, employee runtime and KDS item checklist.
- Section 267 supervised training capture; Section 268 operator review and correction feedback.
- Section 266's read-only assembly recognition decision existed but was not wired to the HUD.

## The missing integration built
- Real ONNX detections pass through existing governed profile mapping; temporal tracks retain whether they matched an approved profile, class-specific minimum confidence, frame persistence and timestamps.
- The recognition decision now reads the **next configured assembly ingredient** for the selected KDS item (never preparation recipe instructions).
- **Manual** preserves operator Added/Skip and does not generate suggestions.
- **Assisted** displays a compact suggestion with the actual model confidence, then requires operator **Confirm** or **Reject**. Confirmation updates the local checklist only. No KDS or model write.
- **Automatic** is additionally gated by a saved per-employee-runtime opt-in (OFF by default). In live kitchen use, an automatic backend observation requires a physical paired runtime, station binding, authorized active build, verified ONNX/model assignment, mapped profile label, stable fresh track, a valid configured menu ingredient, no sequence violation and at least 90% confidence plus the configured per-label threshold.
- After the backend confirms a build component, the policy rechecks the current selected KDS item, model checksum, track freshness and opt-in before marking the local checklist ingredient accounted for.
- The checklist tracks source metadata (operator versus governed recognition) independently of POS/KDS readiness and cannot silently move to another item while an authorized build is active.
- An unrelated fixture, untrained label, stale frame, wrong ticket, mismatched component, unapproved model, denied observation or missing calibration cannot advance a live kitchen checklist.

## Acceptance requirements
- [ ] Existing 265–268 regression suites green.
- [ ] Manual and Assisted preserve operator authority.
- [ ] Automatic requires explicit runtime opt-in; untrusted fixture cannot submit observation.
- [ ] Exact governed build, ticket, menu ingredient and approved mapping are required.
- [ ] 4+ fresh frames, profile threshold, >=90% confidence and backend component confirmation for Auto.
- [ ] Sequence violation, low confidence, disconnected camera, stale worker, recovery, model unload and station change fail closed.
- [ ] Source metadata and duplicate prevention retained; automatic checklist never sets KDS ready.
- [ ] App UI small enough for glasses and the existing runtime settings remain authoritative.
- [ ] CI exact PR head green, merged main same tree, verified deployment package.

## Explicit limits / field calibration
This release wires **software integration** only. It does not certify trained model accuracy on actual ingredients. Real-world sensitivity, false positives, lighting, occlusion, and human-review burden remain onsite Section 270 pilot requirements. A model must already be trained, approved, and assigned by existing Vision Lab governance.

**Package security:** The earlier Section 268 deployment ZIP reportedly triggered Windows malware detection. SHA-256 and CI integrity checks are not antivirus clearance. Preserve the warning, add malicious-file scanning before claiming the new package is safe, and do not advise bypassing security tools.
