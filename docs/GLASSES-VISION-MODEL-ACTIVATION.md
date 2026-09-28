# Gelato AR Glasses Plugin — Section 20 Verified Model Artifact Installation & Atomic Runtime Activation

Section 20 turns the governed Section 19 assignment into a fail-closed activation transaction without weakening any restaurant-truth or vision-safety layer.

## Runtime transaction

A valid `apply` assignment now follows this order:

1. independent Section 19 client-policy validation;
2. runtime-host type validation;
3. HTTPS-only artifact download with a hard byte ceiling;
4. optional immutable manifest byte-size verification;
5. SHA-256 verification against the registered package;
6. isolated runtime preparation;
7. runtime self-test before activation;
8. atomic activation;
9. governed activation telemetry.

Unverified bytes never reach the runtime host.

## Known-good protection

Before preparing a candidate, Gelato captures the currently active runtime snapshot.

If the active package ID and verified SHA-256 already match the governed assignment, Gelato returns `already_active`, emits assignment-specific activation telemetry, and skips download, preparation, self-test, and swap.

Otherwise, preparation and self-test happen before the active model is changed.

If the runtime throws during activation, Gelato calls `RestoreAsync` with the captured known-good snapshot using a non-cancelled restoration token. A failed activation therefore attempts restoration even when the original operation was cancelled or faulted during the swap.

A preparation/self-test failure does not require restoration because the active runtime was never changed.

## Checksum and size rules

The package SHA-256 remains the immutable artifact identity from Section 19.

Gelato computes SHA-256 over the actual downloaded bytes and requires an exact lowercase-normalized match.

If `artifactBytes` is present, the downloaded byte count must also match exactly.

The default client-side artifact ceiling is 512 MiB. A host may configure a smaller ceiling.

## Reports

The activation transaction emits idempotent assignment-derived reports. Each report carries the exact Section 19 assignment key, and the server accepts it only when that assignment was actually issued to the reporting device/build:

- `download_started`
- `downloaded`
- `verified`
- `activated`
- `rollback_activated`
- `failed`

Normal target/baseline activation uses `activated`.

A Section 19 rollback assignment uses `rollback_activated`.

Telemetry remains best-effort. Reporting failure never changes the selected runtime and never stops kitchen workflow.

## Unity integration

`VisionRuntimeController` now accepts a `VisionModelRuntimeHostBehaviour`.

When a valid assignment arrives and a matching runtime host is configured, the controller executes `VisionModelActivationService` instead of only logging eligibility.

`UnityVisionModelArtifactFetcher` performs the HTTPS download and enforces the byte ceiling while the request is in progress and again after completion.

## Runtime host boundary

`IVisionModelRuntimeHost` is intentionally hardware/runtime specific and exposes only:

- current-runtime snapshot;
- isolated prepare;
- self-test;
- activate;
- restore.

The AIR3 vendor SDK/model-loader package is still not present in this repository, so Gelato does **not** invent vendor APIs.

When INMO supplies the model-loader SDK, the adapter should subclass `VisionModelRuntimeHostBehaviour` and implement those five operations. Recipe, KDS, product validation, label profiles, and confidence policy remain outside that adapter.

## Safety layers retained

Section 20 does not bypass or replace:

- Section 18 detector-label profiles;
- global raw detector confidence floors;
- per-label confidence floors;
- spatial calibration;
- transfer evidence;
- human correction;
- product validation;
- KDS lifecycle authority.

The activated runtime only changes the detector implementation feeding the existing `VisionPipeline`; all downstream safety and restaurant truth remains authoritative.

## Contract coverage

The hardware-neutral contract proves:

- verified bytes activate exactly once;
- an already-active package/checksum performs no download or runtime swap;
- equal-length tampered bytes fail on SHA-256 before runtime preparation;
- preparation failure preserves the known-good runtime;
- activation failure restores the captured known-good runtime;
- rollback assignments emit `rollback_activated`;
- runtime-type mismatch is rejected before download;
- download, verify, activation and failure reports use deterministic assignment-derived keys.
