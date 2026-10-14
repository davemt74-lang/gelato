# Gelato Glasses Section 285B — Visual KDS Focus from Real Hand Evidence

## Scope and guarantees
Section 285B **does not** mark an ingredient Added, start/bump an order, automatically
begin a governed build, or verify placement. It only selects a likely existing KDS
item for the employee's glasses HUD, using a qualified, already-active browser
ONNX ingredient model plus live MediaPipe Camera 1 hand tracking. This is **not**
a claim that any general-purpose camera or untrained model can recognize food.

Production mode is active when an authorized, assigned employee glasses runtime
is connected and the user has enabled the camera and loaded an approved
ingredient model. There is no mandatory "Start Ticket" operation and no
implicit camera/microphone permission request.

## Real input, strict evidence
- Camera 1 inference observes approved-model detections for **all eligible
  queued/in-progress KDS items** from the assigned station, not just the currently
  selected item. Existing read-only held-ingredient suggestions remain limited
  to the selected item's unaccounted ingredients.
- Only verified ONNX package data and current MediaPipe hand landmarks count.
  No simulated/fallback detections are eligible.
- A candidate ingredient must map uniquely across **all** station KDS items.
  Sauce, cheese, or another ingredient present in multiple items cannot select
  a ticket on its own. Ambiguity, overlapping hands, low confidence, stale
  or duplicate frames, invalid model checksum, missing device/station assignment,
  camera loss, or no recognized ingredient all fail closed.
- Strong candidate requires **four distinct** aligned frames at >=90%
  detector confidence spanning >=900 ms; object within 550 ms of a live
  >=50%-confidence left/right hand landmark; adjacent frames within 800 ms.
  All thresholds are conservative acceptance gates, not field-validated
  accuracy claims. True on-device accuracy must be measured with real footage.
- Only **Camera 1** feeds this feature in Section 285B. No false claim that
  stationary cameras already run the same live inference.
- Read-only UI selection happens on the existing KDS item and ticket. No POST
  to KDS or changes to restaurant workflow status.

## Operator override
- Voice Next Item, Previous Item, Next Ticket, Previous Ticket, and Go To Ticket
  continue to work without KDS writes.
- Employee selection via voice/HUD locks the chosen view against automatic
  camera-driven switching. "Follow Activity" releases the manual lock.
- An active governed build pins its original KDS item; auto-focus never
  reassigns its evidence. Per-item ingredient checklist history survives focus
  changes. Ended or revoked runtimes do not continue to select tickets.
- A new device, stale station assignment, hidden page, recovery block, removed
  KDS item or released model resets temporary candidate evidence.

## Acceptance matrix (10 gates)
1. Approved SHA-256 model and assigned glasses runtime required.
2. Four distinct handheld detections, stable for >=900 ms, trigger unique
   ticket selection; duplicates do not count.
3. Identical/shared ingredient across multiple tickets remains ambiguous.
4. Multiple incompatible detections or overlapping hands remain ambiguous.
5. Unrelated/countertop/non-hand object cannot auto-focus.
6. Stale/low-confidence/malformed detections fail closed.
7. Wrong station, missing assignment, inactive camera/recovery fail closed.
8. Manual voice/HUD focus override is never preempted by inference.
9. Governed active build is pinned; existing checklist remains intact.
10. No production camera result changes KDS ingredient or ticket status.

## Field qualification still needed
Physical SDK-connected glasses, ONNX models trained on representative actual
kitchen footage, model health, false-positive rates, latency under load,
multi-camera matching and actual placement verification remain separate
certification work. Section 285B should not be used as proof of ingredient
application or automatic food completion.
