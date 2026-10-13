# Section 268 — Reuse Audit & Runtime Station Ownership

Status: development candidate. Baseline Section 267 merged `89ce16b78c69ab0d08c28a34bced5aff83f7fea2`.

## Audit: do not rebuild these systems

Gelato already includes:
- V3 active-learning candidate upsert, scan, mission generation and reviewed observation queue.
- V5 private training media, quality/duplicates, consent, bounding-box review.
- V7 production-error ledger, corrections sync, hard-example mining/retraining review.
- V11 annotation correction, training runs and governed continuous production learning.
- Browser camera low-confidence frame capture and local hard-example approval/reclassification.
- Section 267 guided ingredient photograph collection from actual menu-item ingredients, bound to employee V11 training session.
- Section 266 multi-employee runtime isolation and canonical physical glasses device assignment.

## Gap 1 — Each glasses runtime owns a station selector

The global device dropdown belongs under **Runtime**, next to employee and station. The runtime's device selects a permitted kitchen station from the existing `kds_stations` rows at the same restaurant location. Assignments persist in the existing `glasses_devices.station_id` field (no duplicate table). A newly assigned runtime must select a station. Existing runtime station changes require `glasses.manage` and location permission. Reject while training or a governed build is active; all runtime browser tabs detect a changed station at heartbeat and reload the canonical KDS work. No inherited global device or station context. The visible glasses HUD remains uncluttered.

## Gap 2 — Capture the missing bridge, not a second learning system

A voluntary operator **Flag for learning** event from the active menu-item ingredient should create one deduplicated V3 active-learning candidate. It is *not* evidence that the ingredient was placed or AI verified. The source must be the actual assigned KDS item, the configured menu ingredient, and the authenticated/runtime-bound device + location + employee identity. Surface existing Vision Lab pending-review status and let existing training missions collect photos. Never silently upload frames, create labels from recipes or start automatic retraining.

## Acceptance

- [ ] Runtime device+station+employee selection scoped per device.
- [ ] Valid station at device location, physical pairing, staff and management permissions.
- [ ] Station mutation blocked while live training/build; stale ticket views reload.
- [ ] No additional station, employee, training or retraining database tables.
- [ ] Optional operator feedback sent to existing active-learning candidate queue, not directly to a model.
- [ ] Actual KDS item and canonical configured menu ingredient validated server-side.
- [ ] Idempotent feedback under retries and concurrent runtime sessions.
- [ ] No automatic photos, model changes or KDS state writes.
- [ ] Existing Section 265–267 regressions and both deployment/simulator CI green.
- [ ] PR merged only after exact-head release certification, ZIP and SHA-256 delivery.

Software acceptance cannot certify physical camera recognition accuracy.