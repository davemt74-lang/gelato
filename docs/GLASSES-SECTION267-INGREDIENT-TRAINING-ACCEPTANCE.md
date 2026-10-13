# Section 267 — Ingredient Training Studio & Guided Evidence (release acceptance)

Baseline: Section 266 merged `f025c4d11d8f268f8aa090313f5657edce5a6bc1`.

## Existing systems reused
- V11 supervised employee assignments and sessions: `glasses_training_programs`, `glasses_training_assignments`, `glasses_training_sessions`.
- Runtime/employee identity: `glasses_employee_runtimes` + canonical Vision Lab wearer assignments.
- V5 Vision Lab media: `glasses_vision_training_media`, consent, quality/fingerprint, annotation boxes, private storage.
- Vision Lab missions, pending sample human review, curated dataset, QA, qualification and model promotion unchanged.
- Section 258 configured camera slots, capture history, menu item directory and camera quality fingerprints.
- The existing configured menu-item assembly ingredient source; **never recipe steps**.

## Operator flow
1. Assign employee to physical glasses runtime, then start one of that employee's existing `training` or `shadow` sessions.
2. On Camera tab, open Ingredient Capture Studio. Select an active menu item and one of its configured ingredient labels.
3. Choose *isolated*, *empty base*, *before addition*, *after addition*, *mixed*, *occluded* or *negative/look-alike* training phase.
4. Select a locally captured camera frame (camera slots 1–5) or import an image file. File imports never impersonate live camera slots.
5. Positive phases require a normalized ingredient bounding box. Negative/before/base frames explicitly prohibit a positive box. Repeat with different light, ingredient arrangements, distances and hands/utensils as safe.
6. Optionally select an existing employee/device/location/station-compatible Vision Lab mission.
7. Explicitly opt in to private storage and human review, then submit. The server re-resolves identity, session, menu ingredient, mission and camera slot, derives the time using DB `NOW(6)`, and calls the existing governed image store.
8. Show sample ID, quality warnings, duplicate signals and **PENDING REVIEW**. A reviewer must approve/relabel/adjudicate samples within existing Vision Lab. Production model stays unchanged.

## UI refinement in Section 267
- Camera tab: one combined badge per camera (green background and white photo-count numeral when enabled, subdued gray when off); no separate status dot.
- Main Camera tab: one green-on-active badge with white active camera count.
- Six camera tabs fit their container without a horizontal scrollbar. Camera content remains keyboard/wheel accessible while visual scrollbar is hidden.
- Remove right sidebar `PREVIEW · HUD Only · Glasses Outline` and `Previous/Next Step/Item · Ingredient Added · Repeat` buttons. Existing left-column/on-lens and voice navigation remain.
- Camera studio is part of camera settings and does not cover the glasses projection.

## Safety invariants
- Runtime active + physical device + employee + authorized restaurant location + active V11 training/shadow session must agree.
- No cross-employee, cross-device, cross-station or cross-organization training attribution.
- Server-derived labels must match current menu-item ingredients; never guess canonical labels.
- Photo/file input is untrusted. Validate image bytes, dimensions, limits, annotations and capture phase.
- Positive/negative sampling is distinguished in sample annotation and metadata; false positives are not generated for empty/negative examples.
- No camera frame is uploaded before explicit opt-in.
- Every stored sample stays pending until the existing human-reviewed pipeline acts.
- Never auto-promote models, change live KDS status, or certify camera accuracy.
- Training session IDs cannot be borrowed from another employee runtime.

## Release evidence / 10-point acceptance rubric
| Gate | Pass criteria |
|---|---|
| 1. Existing training reuse | No alternate program/session schemas; existing Vision Lab table |
| 2. Runtime isolation | Every capture revalidates live runtime and exact session |
| 3. Ingredient truth | Only configured menu-item ingredient labels |
| 4. Guided phases | 7 phases with positive/negative distinction |
| 5. Box safety | Positive requires valid box, negative rejects positive box |
| 6. Media consent | Explicit opt-in and authenticated CSRF-guarded upload |
| 7. Provenance | Device/employee/session/mission/station/phase metadata |
| 8. Review | Sample pending, quality/duplicate signals, model unchanged |
| 9. Camera & sidebar UI | Combined badges/no tab-strip scrollbar, redundant right buttons removed |
| 10. Certification | JS/PHP static tests, prior regressions, 17 workflow PR gates, package archive integrity, merge head |

**Field acceptance remains open:** Real ingredient photographs, headset capture/voice acoustics, multi-employee kitchen trials and per-class model accuracy require onsite testing. A software release score of 10/10 is not evidence of vision model accuracy.
