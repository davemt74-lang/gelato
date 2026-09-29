# V10 Section 1 — Hardware Runtime Contract & AIR3 Simulator Adapter

V10 Section 1 isolates the missing AIR3 proprietary SDK behind a hardware-neutral adapter boundary.

The runtime contract covers four vendor-facing surfaces:

- camera frame ingestion;
- on-lens display projection;
- user/input events;
- model-runtime loading/inference capability negotiation.

The current `simulator.v1` adapter implements those contracts using the existing browser camera, projection HUD, deterministic fixture detector, and governed browser ONNX runtime.

The future `air3.vendor.v1` adapter must implement the same `GlassesHardwareRuntimeAdapter` interface. V9 kitchen intelligence, POS/KDS truth, model governance, and build authority do not depend on vendor-specific AIR3 calls.

Until the proprietary files arrive, the contract explicitly reports vendor native camera/display/input/inference as unavailable while browser camera, simulator, governed ONNX, live POS/KDS sync, and the full V9 pipeline remain available.
