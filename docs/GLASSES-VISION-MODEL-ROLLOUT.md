# Gelato AR Glasses Plugin — Section 19 Vision Model Package Management & Governed Runtime Rollout

Section 19 governs which detector model package a paired glasses device is allowed to receive for an active kitchen build.

It does **not** pretend to hot-swap a model binary before the final glasses/model-loader SDK is available.

## Package registry

Model packages are immutable artifact identities.

A package records:

- detector name;
- model name and version;
- runtime type;
- target glasses platform;
- HTTPS artifact URL;
- required SHA-256 checksum;
- optional artifact byte size;
- minimum SDK version;
- minimum Gelato glasses app version;
- notes and metadata.

Supported runtime types:

- `onnx`
- `tflite`
- `unity_barracuda`
- `vendor`

The artifact URL must be HTTPS.

The checksum must be exactly 64 hexadecimal SHA-256 characters.

There is intentionally no artifact-edit action. To change a binary, URL, checksum, runtime, detector, or model version, register a new package.

Packages may be retired only when they are no longer required by:

- an active rollout;
- a paused rollout;
- a rolled-back rollout's baseline assignment.

## Rollout plan

Every rollout declares:

- target package;
- baseline package;
- detector;
- optional location scope;
- optional station scope;
- canary percentage;
- lifecycle status.

Target and baseline must use the same:

- detector;
- platform;
- runtime.

The baseline is mandatory. This makes rollback explicit and deterministic.

Statuses:

- `draft`
- `active`
- `paused`
- `rolled_back`

A draft never affects devices.

Activation is explicit.

Pause means Gelato returns `hold` and does not issue a new model action.

Rollback means Gelato explicitly selects the declared baseline package.

## Canary behavior

Canary membership is deterministic per rollout + device.

Gelato computes a stable 0.00–99.99 bucket from:

`SHA256(rolloutPublicId + "|" + devicePublicId)`

A device receives the target only when:

`bucket < canaryPercent`

Otherwise it receives the baseline.

Canary percentage may advance only upward through the explicit `rollout.advance` action.

Reducing a rollout requires pause or rollback; the normal advancement path cannot silently move backward.

## Scope precedence

Matching rollouts may exist at different scopes.

Precedence is:

1. station;
2. location;
3. organization.

Within the same specificity, the most recently updated matching rollout wins.

Only one active/paused rollout may own the same detector and exact scope at a time.

## Compatibility

Before Gelato returns `apply`, the selected package is checked against the paired device.

Compatibility includes:

- package status is ready;
- package platform equals device platform;
- SDK meets package minimum;
- glasses app meets package minimum;
- device explicitly advertises support for the package runtime in `capabilities.visionModelRuntimes`.

Missing runtime capability information fails closed.

An incompatible target does **not** automatically fall back to baseline. Gelato returns `hold` with compatibility reasons. This avoids hiding a broken target rollout behind an implicit server-side model switch.

## Device assignment API

Authenticated device action:

`vision.model_assignment`

requires:

- active build-session public ID;
- detector name.

Response schema:

`gelato.vision_model_assignment.v1`

includes:

- action: `apply` or `hold`;
- assignment key;
- rollout identity/status/canary percentage;
- deterministic device bucket;
- selection: `target`, `baseline`, `rollback`, or `hold`;
- selected package manifest;
- compatibility result and reasons.

The assignment is isolated to the device that owns the build session.

## Independent client policy

The Unity Core does not blindly trust `action=apply`.

`VisionModelAssignmentPolicy` independently requires:

- server action is `apply`;
- server compatibility is true;
- assignment detector matches the active detector;
- package detector matches the active detector;
- artifact URL is HTTPS;
- SHA-256 is syntactically valid;
- runtime type exists;
- platform exists;
- rollout identity exists.

This is defense in depth before any future model-loader touches the artifact.

## Runtime capability advertisement

`DeviceDescriptor.VisionModelRuntimes` is sent during pairing.

The desktop simulator currently advertises:

- `onnx`
- `tflite`
- `unity_barracuda`
- `vendor`

The real INMO adapter must advertise only runtimes actually supported by the final device/runtime package.

## Runtime boundary

`VisionRuntimeController` requests the governed assignment once per active build and records an idempotent `assignment_seen` event.

Section 19 remains the authority for desired model state, compatibility, canary selection, pause and rollback. Section 20 consumes only assignments that pass this contract and performs verified artifact installation/activation through the hardware-neutral runtime host.

Every issued assignment is persisted in `glasses_vision_model_assignments` against the exact device and build session. Assignment keys include build-session identity, so telemetry and activation history cannot collapse across separate kitchen builds.

## Device rollout ledger

Device action:

`vision.model_report`

supports:

- `assignment_seen`
- `download_started`
- `downloaded`
- `verified`
- `activated`
- `failed`
- `rollback_activated`

Reports use idempotent assignment-derived keys and must include the exact assignment key originally issued to that device/build.

The server binds every report to the immutable assignment ledger before accepting it. A device cannot claim a target package when its issued assignment selected baseline, and a rollback activation cannot be reported from a non-rollback assignment.

Verified/activated reports must additionally:

- reference the rollout recorded on the issued assignment;
- reference the exact package recorded on the issued assignment;
- provide the registered artifact SHA-256;
- pass current device/package compatibility.

`rollback_activated` must reference the rollout's declared baseline package.

Every accepted report is also mirrored to the existing glasses device-event stream.

## Admin

`glasses-vision-models.php` provides:

- package registration;
- package retirement;
- draft rollout creation;
- explicit activation;
- canary advancement;
- pause;
- resume;
- rollback with reason;
- per-rollout assignment/verification/activation/failure telemetry.

Writes require `glasses.manage` and CSRF validation.

Read access requires `glasses.view`.

## Audit model

Rollout lifecycle transitions are append-only in `glasses_vision_model_rollout_events`.

Creation and its first audit event are committed atomically.

Transitions record:

- prior state;
- next state;
- prior canary percentage;
- next canary percentage;
- actor;
- timestamp;
- optional reason/metadata.

## Scope boundary

Section 19 manages **desired model state** and rollout governance.

It does not bypass:

- Section 18 label mapping;
- per-label confidence floors;
- global confidence policy;
- station calibration;
- transfer evidence;
- product validation;
- KDS lifecycle authority.

Actual binary model installation/activation remains a separate hardware-dependent section.


## Browser ONNX package onboarding and preflight

The model registry now supports the browser inference metadata consumed by the Web Glasses Simulator.

For ONNX packages, the admin registration form captures:

- input tensor name;
- output tensor name;
- input width and height;
- NCHW or NHWC input layout;
- channel-first or row-style YOLOv8 output layout;
- pixel or normalized bounding-box scale;
- ordered detector labels;
- non-maximum-suppression IoU;
- maximum detections per frame.

The server validates and normalizes this as immutable package metadata under:

`metadata.browserInference.schema = gelato.browser_onnx_detector.v1`

Malformed tensor dimensions, layouts, labels, NMS values, or unsupported decoder contracts fail closed during registration.

### Verify browser artifact

Before registering an ONNX package, an administrator can run **Verify browser artifact**.

The preflight happens entirely in the administrator's browser and does not upload model bytes to Gelato. It performs:

1. cross-origin HTTPS artifact fetch using browser CORS;
2. 512 MiB browser safety ceiling enforcement;
3. optional registered byte-count comparison;
4. SHA-256 calculation with Web Crypto and exact comparison to the package form;
5. version-pinned ONNX Runtime Web load;
6. creation of an ONNX inference session from the downloaded bytes;
7. validation that the declared input tensor name exists in the model;
8. validation that the declared output tensor name exists in the model;
9. session release after the check.

A successful preflight reports **Browser-ready** and the registry marks packages carrying the supported metadata contract with a Browser ONNX capability badge.

Preflight does not replace rollout governance or runtime verification. Registration remains immutable; canary assignment, compatibility, download verification, activation and rollback continue to use the existing model-governance system.


## Live shadow model evaluation

Comparison-aware challengers now have a non-authoritative production-evidence stage between offline champion/challenger evaluation and canary rollout.

### Shadow assignment

For a live device/build, Gelato can select the most specific eligible **draft** rollout whose target already passed the offline champion/challenger gate.

The rollout baseline is the shadow champion and the target is the shadow challenger.

Both packages must independently pass normal device/runtime compatibility checks.

The Web Glasses Simulator additionally verifies that the shadow champion package exactly matches the currently loaded authoritative browser model before it loads the challenger.

### Same-frame execution

The browser runs champion and challenger inference over the same camera frame.

Only champion detections continue into:

- temporal tracking;
- canonical build observations;
- validation;
- KDS/Expo authority.

The challenger result is compared only for evaluation.

Shadow telemetry explicitly declares `authoritative=false`.

No shadow endpoint calls `glasses_build_observe`, product validation, KDS transitions, or rollout assignment mutation.

### Durable shadow ledger

Migration `20261022_glasses_vision_shadow_evaluation.sql` adds:

- `glasses_vision_shadow_runs`;
- `glasses_vision_shadow_events`.

The ledger stores metadata only, not kitchen images.

Per-frame evidence includes:

- champion/challenger detection counts;
- matched detections;
- champion-only / challenger-only counts;
- mean matched IoU;
- mean confidence;
- optional human-correction alignment;
- critical-mismatch flag.

Frame keys are idempotent for a shadow run.

### Default canary gate

A completed shadow run is eligible for canary when:

- at least 30 evaluated frames were persisted;
- critical mismatch rate is at most 10%;
- challenger correction wins are greater than or equal to champion correction wins.

The offline model-comparison gate still applies first.

A comparison-aware target cannot be activated from draft to canary until at least one completed shadow run for that rollout passes this live gate.

Legacy packages without comparison metadata retain their existing rollout behavior.

The intended progression is:

Offline golden-set comparison → draft rollout → live shadow evaluation → explicit completion → shadow gate → explicit canary activation.


## Canary production evaluation and automatic rollback

After a comparison-aware challenger passes offline evaluation and live shadow mode, its authoritative rollout enters a governed production canary.

### Governed stages

Comparison-aware production rollouts must begin at **5%** and advance only through:

`5% → 10% → 25% → 50% → 100%`

Advancement is explicit; Gelato never advances a canary automatically.

Before normal advancement, the current production health window must contain at least:

- 20 baseline samples;
- 20 target/canary samples;
- 2 baseline devices;
- 2 target devices.

Legacy packages without champion/challenger metadata retain their previous manual rollout behavior.

### Production health telemetry

Authenticated glasses runtimes can submit `vision.canary_sample` against an issued model assignment and build session.

Each idempotent sample can report:

- observation count;
- correction count;
- low-confidence count;
- unexpected-ingredient count;
- validation failure;
- build duration;
- inference count;
- total inference latency;
- timeout count;
- runtime error count.

The server derives comparable rates for the baseline and target cohorts inside the same rollout operating window.

### Health classification

The production evaluator classifies a rollout as:

- **collecting** — minimum evidence has not been reached;
- **healthy** — sufficient evidence and no promotion-blocking regression;
- **warning** — sufficient evidence but one or more target regressions exceed the normal promotion limits;
- **rollback_required** — severe target regression after the minimum rollback evidence threshold.

Normal promotion limits include correction rate, validation-failure rate, low-confidence rate, unexpected rate, timeout rate, runtime-error rate, inference latency, and build-duration regression.

### Automatic rollback

After at least 10 samples in both target and baseline cohorts, severe regression can trigger an automatic rollback.

Automatic rollback:

1. changes the rollout to `rolled_back`;
2. immediately restores baseline assignment behavior for subsequent device assignments;
3. appends an immutable `auto_rolled_back` rollout event containing the health evidence and reasons;
4. stores a health snapshot;
5. places the target package on a 24-hour cooldown hold.

A package under automatic rollback cooldown cannot be activated in another rollout until the hold expires.

### Operator override

If a canary is collecting or warning, an authorized operator may advance **only to the next governed stage** with an explicit rationale.

The override is recorded as `advanced_override` with:

- operator identity;
- reason;
- current health state;
- baseline and target metrics.

A `rollback_required` state cannot be overridden forward.

### Persistence

Migration `20261023_glasses_vision_canary_production.sql` adds:

- `glasses_vision_canary_samples`;
- `glasses_vision_canary_health_snapshots`;
- `glasses_vision_canary_package_holds`.

The canary ledger stores production metrics and governance evidence, not kitchen camera frames.

The complete promotion path is now:

Offline quality gate → golden-set champion comparison → live shadow → 5% canary → health-gated 10% → 25% → 50% → 100%, with automatic rollback available at every production stage.


## Continuous production drift detection

Gelato now monitors whether a previously healthy model/environment combination changes materially after deployment.

### Baseline scope

A production drift baseline is scoped by:

- organization;
- model package;
- location;
- station;
- detector.

The baseline is established from the first 20 stable production samples for that package/station combination. It stores only numerical/environment metadata, never kitchen images.

Baseline signals include:

- active station-calibration `sourceHash`;
- server-derived menu/build-definition signature;
- server-derived expected-ingredient signature;
- frame width / height / pixel format;
- brightness and contrast;
- camera pitch / yaw / roll;
- mean detector confidence;
- mean inference latency;
- correction rate;
- low-confidence rate.

Menu and ingredient signatures are derived server-side from the active build session so a device cannot redefine those identities.

### Drift states

Each subsequent authenticated `vision.drift_sample` is classified as:

- **calibrating** — baseline has not yet reached 20 samples;
- **stable** — current environment/model behavior remains near baseline;
- **watch** — small but meaningful change;
- **drifted** — promotion-blocking change;
- **critical** — severe change requiring production safety action.

Examples include:

- lighting/contrast shift;
- camera-pose movement;
- frame geometry or pixel-format change;
- station calibration replacement;
- menu/build-definition change;
- expected-ingredient set change;
- detector confidence degradation;
- inference-latency spike;
- correction-rate or low-confidence-rate growth.

### Environment vs data-domain awareness

Drift reasons carry categories so operators can distinguish:

- `lighting`;
- `camera_pose`;
- `camera_config`;
- `environment` / calibration change;
- `data_domain` / menu or ingredient change;
- `model_quality`;
- `runtime`.

This prevents a station move or new recipe from being mislabeled simply as “the model got worse.”

### Durable incidents

Migration `20261024_glasses_vision_production_drift.sql` adds:

- `glasses_vision_drift_baselines`;
- `glasses_vision_drift_samples`;
- `glasses_vision_drift_incidents`.

Drift incidents are deduplicated by package/scope/category/reason signature and retain first/last-seen timestamps.

### Rollout safety integration

A `drifted` or `critical` target blocks normal canary advancement.

A **critical** drift sample on an active rollout:

1. changes the rollout to `rolled_back`;
2. restores subsequent assignments to the declared baseline package;
3. writes an immutable `drift_auto_rolled_back` rollout event;
4. places the target package on the existing 24-hour production cooldown when canary governance is installed;
5. leaves a durable drift incident explaining whether the likely cause was environment, data-domain, model-quality, or runtime change.

No drift path mutates POS, KDS, build observations, validation, or Expo state.

The production safety loop is now:

Train → golden test → shadow → canary → production → continuous drift awareness → hold/rollback when the deployed environment or data distribution materially changes.


## Drift recovery, recalibration and guided remediation

Drift detection now continues into a governed recovery workflow instead of ending at an alert or rollback.

### Incident lifecycle

Each durable drift incident has a stable public ID and recovery state:

- `open`
- `diagnosing`
- `remediation_required`
- `validating`
- `resolved`
- `reopened`

Every recovery transition is appended to `glasses_vision_drift_recovery_events` with actor, notes, remediation type and supporting evidence.

A resolved incident is not permanently dismissed. If the same drift signature returns, Gelato reopens the same incident, clears its validation count and increments its reopen counter.

### Guided diagnosis

Admin now shows:

- severity;
- drift category;
- concrete reasons / what changed;
- recommended remediation;
- current recovery state;
- stable validation progress;
- reopen count.

Default recommendations are cause-specific:

- lighting → restore lighting / recalibrate environment;
- camera pose/config/environment → restore camera/station calibration;
- menu/ingredient domain → review recipe/label coverage and collect examples;
- model quality → active learning + retraining;
- runtime → repair device/runtime path;
- mixed → diagnose before choosing remediation.

### Controlled baseline reset

A baseline reset is permitted only for environment/data-domain categories:

- lighting;
- camera pose;
- camera configuration;
- station calibration/environment;
- menu/ingredient data-domain change.

The previous baseline is retained as `superseded` evidence.

After reset, only production samples captured **after the reset timestamp** can establish the replacement 20-sample baseline.

Baseline reset is explicitly forbidden for:

- model-quality drift;
- runtime drift.

This prevents degraded model/runtime performance from being normalized away.

### Recovery evidence links

A remediation can retain references to:

- station calibration public ID;
- replacement model package;
- active-learning / dataset reference;
- operator notes.

These references remain attached to the incident audit.

### Post-remediation validation

Starting validation does not resolve the incident.

Gelato requires 10 stable post-remediation samples. Once the tenth stable sample is recorded, the incident automatically becomes `resolved`.

If `watch`, `drifted` or `critical` behavior returns while validating—or after resolution—the incident becomes `reopened` and the stable-sample counter resets.

### Persistence

Migration `20261025_glasses_vision_drift_recovery.sql`:

- extends `glasses_vision_drift_incidents` with recovery state and evidence links;
- preserves historical baselines by allowing superseded versions;
- adds `glasses_vision_drift_recovery_events` for immutable recovery history.

The full operational loop is now:

Detect → classify → rollback if necessary → diagnose → remediate/recalibrate/retrain → re-baseline when appropriate → validate 10 stable samples → resolve → reopen automatically if drift returns.
