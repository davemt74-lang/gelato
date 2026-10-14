# Gelato — Section 285C: shared recognition across Cameras 1–5

## Build status

**Offline review candidate based on the certified Section 285B main revision
`aaf4c60b9568ae3a1a6c50b7e4ae80b1e4b477cb`.** The change is **not**
represented as a merged GitHub release. Publication requires an authorized
GitHub branch, full PR CI, merge, and release certification.

## Architecture

- **One approved model, five possible feeds:** The existing SHA-256 verified
  read-only browser ONNX/Yolo model is loaded once via the existing approved
  rollout and explicitly initiated Load Read-Only Model action. The Camera 1
  first-person recognition loop remains independent and unchanged in its
  source policy. A new Camera 2–5 scheduler uses the **same** existing
  `inferOnnxModel()` preprocessor, decoder, and `readonlyIngredient.session`.
- **Serialized model runs:** The shared `readonlyIngredient.busy` guard prevents
  overlapping ONNX session calls. Stationary cameras are checked in cyclic
  order and scheduled conservatively at 460ms between attempts to avoid
  overwhelming a browser running primary glasses hand/vision tracking.
  Effective frames per stationary camera depend on the number of active
  cameras, inference duration, and hardware performance. This is not
  guaranteed live real-time detection throughput.
- **Independent, stationary source identity:** Each auxiliary observation
  includes slot 2–5, assigned camera station, configured role, source type,
  model digest, camera frame time and strictly sanitized detection labels,
  confidence and bounding boxes. The camera location must equal the current
  restaurant location. Sources must be enabled, their streams active, and
  their saved camera configuration available.
- **Station isolation:** A fixed camera can still recognize objects when its
  configured station does not match the employee's station, but that event is
  marked `unscoped`; it cannot claim KDS ticket identity. Unassigned cameras
  are also unscoped. No cross-station event authorizes a kitchen transition.
- **Camera independence:** Camera 1 uses the selected browser/HDMI or future
  vendor-SDK stream; Cameras 2–5 remain separately configured and explicitly
  started stationary browser video inputs. No camera is opened on page load
  or merely because an ONNX model is loaded.
- **Failure and privacy isolation:** If a camera stream stops, a camera is
  disabled, the saved config changes, a station/location changes, an approved
  model unloads, or an inference result exceeds the freshness threshold,
  buffered observations are dropped. Raw frames never enter Agent context
  or an automatic training upload; the existing opt-in reviewed Vision Lab
  capture pipeline remains separate.
- **Agent awareness:** Read-only per-camera counts and station scope are
  surfaced in the existing glasses Agent Navigation context and the inference
  status panel. Stationary inference does **not** send MediaPipe hands,
  auto-focus tickets, mark ingredients Added, or mutate KDS/POS/build state.
  Camera fusion and verified hand-to-food **placement** require future work.

## Section 285C software acceptance score

| Gate | Software acceptance test | Result |
|---|---|---|
| 1 | Approved ONNX package reused; no new unqualified model loading | PASS (code + legacy model tests) |
| 2 | Camera 1 remains independent of Cameras 2–5 | PASS (legacy inference regression) |
| 3 | Four active stationary sources are polled fairly | PASS (round-robin test) |
| 4 | Camera disabling/disconnect clears stale source recognition | PASS (unit + in-flight integration test) |
| 5 | Cross-station and unassigned sources cannot claim KDS scope | PASS (unit + integration) |
| 6 | Invalid/old data and model SHA are rejected | PASS (validation test) |
| 7 | No concurrent ONNX session runs between sources | PASS (exclusive busy test) |
| 8 | In-flight model result blocked after camera config revision change | PASS (integration test) |
| 9 | No source independently marks ingredient added, KDS changed, or starts another camera | PASS (static/VM safety contract) |
| 10 | Existing glasses, hand, voice, KDS and camera regression paths retained | PASS (available local Node/PHP subset) |

**Score: 10/10 for these local software gates.** This score is **not**
10/10 for deployment readiness, field recognition accuracy, live latency,
AR glasses hardware support, or GitHub CI certification.

### Local limitations and future certification

The available test environment lacks PHP `mbstring`, preventing the existing
camera-location PHP contract suite from completing locally. The repository's
GitHub publication action was blocked; no new PR, PR CI, merge, or Section
285C release was created. Physical Camera 2–5 tests, actual recognized food
samples, device disconnect under actual streaming load, and verified full
GitHub CI remain outstanding. Use representative, consented kitchen footage
before enabling any future automatic KDS actions. Training improvements and
cross-camera placement fusion are **not** claimed as completed in 285C.

