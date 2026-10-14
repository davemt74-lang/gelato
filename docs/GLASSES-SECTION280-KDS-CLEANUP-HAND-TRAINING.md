# Section 280 — Test ticket cleanup and two-hand camera observation

## Test-ticket cleanup
Kitchen Dashboard now has **Remove old test tickets**, available only to authorized POS/KDS
administrators. It lists active **fully unrouted** KDS tickets at the selected restaurant.
You explicitly select check numbers, review the count, and type REMOVE TEST TICKETS.
Gelato invokes the existing POS \`check.cancel\` service once per selected ticket, which
cancels the linked KDS items and removes them from the active kitchen/glasses queue.
POS checks remain as **cancelled audit records**, not hard-deleted rows. Captured
payment prevents cancellation. An authorized owner can use this without a shell or
manually manipulating foreign-key relationships. All writes occur only after clicking.
There is no auto-delete-on-age rule or global delete-all button.

## Dual hands in Runtime camera 1
Two hand states start **READY**. A verified real ONNX detection labelled
\`hand\`, \`left hand\`, or \`right hand\` enables independent green TRACKING boxes
on the camera scene. Green IDENTIFYING means a separate nearby detected object
matches one of the selected active KDS item's configured **unaccounted**
ingredients; the shown label is a suggestion, not a verified placement.
The maximum candidate focus is the first three (five nearest remaining may be
matched), never the entire restaurant ingredient catalog. Hand observations
are processed in normalized coordinates with global two-track matching,
brief-occlusion and lost states, up to 2.2 seconds reacquisition by location
and handedness when supplied, followed by fresh registration. Track IDs
are **short-lived browser-session identifiers**, not identity verification.

Hand objects are excluded from the ingredient/assembly observation pipeline.
Neither hands nor the held object invoke a KDS write or automatically mark
ingredient progress. Real hand detection requires a compatible governed ONNX
model to be active. The existing browser model workflow currently requires
a governed physical build; until a hand-capable model is deployed, the UI
truthfully stays READY. USB-connected iPhone webcams remain ordinary browser
cameras; camera permissions are never started automatically.

## Acceptance
\`node tests/glasses-dual-hand-tracking.test.js\`
\`node tests/kds-test-ticket-cleanup.test.js\`
Plus the original KDS, POS, device, camera, assembly, voice and release suites.
Field gates: test-ticket selection on actual data and a genuine hand-capable
detector on the user's Windows webcam/camera preview. Native AIR3 remains separate.
