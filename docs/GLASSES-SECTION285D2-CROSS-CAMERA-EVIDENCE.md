# Gelato Section 285D2 — Shared Camera Evidence Fusion
Baseline: certified 285D1 main `ddd1f7eb7990b6d15326b342be4874e851410426`.

Cameras 1–5 share the approved ONNX recognition model. This read-only module correlates **class labels only** from distinct cameras when observations match the current device, station, location, model digest and a bounded 900ms time window (1400ms maximum source age). Confidence must be at least 0.85 and bounding boxes valid. Same-camera duplicates, stale/replayed frames, unscoped stationary sources, or changed configurations are rejected. Failed or disconnected feeds are excluded, and reset invalidates all evidence.

The module counts how many active tickets contain each recognized class; common ingredients are explicitly ambiguous. It never claims any physical object identity, ingredient transfer, placement, ticket selection, completed ingredient or KDS status. Agent context shows read-only summaries and camera provenance, not frames. It uses existing Camera 1 and auxiliary observation events; no additional camera permissions or inference sessions.

13 focused acceptance assertions plus existing MediaPipe, auto-focus, shared-camera, and full-deploy tests must pass on PR and merged main. Field recognition remains pending. Next 285D3: hand/ingredient trajectory and actual placement hypotheses with conservative operator verification; 285D4: governed automatic ingredient progress only after physical certification.
