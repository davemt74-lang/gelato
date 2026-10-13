# Gelato — Revised Kitchen Assembly Roadmap (Sections 266–270)

Baseline: Section 265, PR #265, merged at a4bdc84b14ab4b75b8ea5c793ffe29dbe62fcef3.

## Architecture rules
1. Ticket and menu-item ingredient data are authoritative; assembly lists never derive from recipe instructions.
2. Preserve separately scoped identities for KDS ticket, KDS item, governed build session, device/station, model and camera.
3. Manual, Assisted and Automatic use one checklist and one transition function. Never create divergent shadow checklists.
4. Camera observation != ingredient addition. Model output requires identity mapping, temporal persistence, source freshness, bounded confidence, and an evidence review/gate.
5. A fixture detector is for tests only. Do not mark ingredient completion from fixture, ungoverned model, stale frame, mismatched item, or an untrained label.
6. Do not automatically issue POS/KDS ready, Expo, payment, or order lifecycle writes when an ingredient checklist completes.
7. User overrides and skips are authoritative and auditable. Only approved samples enter a new training dataset; model promotion is separately governed.
8. Preserve a stationary HUD work area with compact ticket tabs (# only), small Added/Skip buttons and readable accessibility labels.
9. Exact release evidence (tests, gates, hashes, branch/main parity) is required before reporting 10/10 or merging.

## Section 266 — Assembly HUD, modes and recognition adapter
- Compact # ticket tabs for only occupied tickets; selected ticket lists its items.
- Manual, Assisted, Automatic control existing vision settings, no second mode switch.
- Menu ingredient assembly list is one source of truth; keep special instructions/modifiers visible but separate.
- Map canonical configured ingredient to a uniquely matched governed build component and qualified track for selected KDS item.
- Manual: explicit Added/Skip. Assisted: proposed match + Confirm/Reject, never auto-advance. Automatic: require governed model, fresh matching selected build, stable profile-matched track, policy confidence and confirmed backend observation.
- Hold automation if no configured ingredient, ambiguous label, stale/disconnected camera, model not ready, item switch, active recovery, or missing source.
- Preserve ticket-local navigation, operator override, and no automatic KDS transitions.
- Acceptance: deterministic multi-item navigation, wrong-ticket exclusion, duplicate/idempotent correction, manual fallback, consistent voice/manual controls, and CI green.

## Section 267 — Ingredient training studio
- Reuse existing camera capture, Vision Lab, annotation/workbench, dataset curation, training runs, model packages and label profiles.
- Guided ingredient images and clips: empty base, before/after placement, different distances, positions, lighting, mixtures, hands/utensils, occlusions and similar ingredients.
- Data labels carry ingredient ID, source image, menu item context, station/camera, human review, and consent/privacy policy.
- Use reviewed positive, negative and hard-example datasets with split leakage protections; qualification and human approval before release.
- Acceptance: traceable train/evaluation runs with no implied production accuracy.

## Section 268 — Kitchen learning and feedback
- Capture proposed versus operator-confirmed ingredient events, false positives, skips, undos, and contextual scene changes.
- Capture hard examples for review only; no online weights update or automatic self-certification.
- Track per ingredient, camera, station, model version; confidence drift and disagreements.
- Acceptance: corrections reproducible and traceable with safe retention and no silent production-model change.

## Section 269 — Governed automatic assembly
- Temporal ingredient addition, removal/occlusion, duplicates and sequence checks, ingredient quantities when supported.
- Gate automatic progress on governed backend confirmation and current item binding; safe undo and reversibility.
- Distinguish assembly-complete from official KDS-ready. Maintain auditable event histories and idempotent resume.
- Acceptance: no premature, duplicate, cross-item or fixture-based completion even under reconnect/reorder.

## Section 270 — Real kitchen pilot and certification
- Agree per-ingredient thresholds and evaluate on held-out live-kitchen examples (speed, occlusions, lighting, mixtures).
- Report sensitivity, precision/false positive rate, latency, review burden, confidence calibration, drift and operator corrections.
- Shadow -> assisted -> limited auto with explicit rollout/rollback, permissions and human override.
- Score 10/10 for release criteria only after evidence supports each gate; distinguish system readiness from recognition accuracy.

## Current limitations
- Section 265 uses local browser checklist acknowledgments; they are not canonical KDS readiness.
- Existing governed ONNX model assignment requires a build session. Browser preview may be read-only and cannot fabricate one.
- The deterministic fixture is not real ingredient recognition.
- Photographic training, kitchen calibration and production reliability are not certified by Section 266.
