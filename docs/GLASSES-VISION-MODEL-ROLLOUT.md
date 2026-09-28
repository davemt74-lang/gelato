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
