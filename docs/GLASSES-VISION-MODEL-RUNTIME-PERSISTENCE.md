# Gelato AR Glasses Plugin — Section 21A Production Runtime Persistence, Recovery & Loader Harness

Section 21A prepares the production model runtime for AIR3 hardware while the proprietary model-loader SDK files are still pending.

## Goal

Section 20 can verify and atomically activate a model during one running app session. Section 21A makes that lifecycle durable across process crashes, device restarts, interrupted downloads, and interrupted activations without inventing vendor APIs.

## Durable state

Gelato stores per detector/runtime state under the Unity application persistent-data directory.

The durable state records:

- active package public ID + SHA-256;
- last known-good package public ID + SHA-256;
- pending assignment/package/checksum/runtime;
- pending lifecycle stage;
- last handled runtime error.

State writes are atomic through a temporary file and replacement.

A corrupt state file is quarantined instead of trusted.

## Verified artifact cache

Only SHA-256-verified bytes are admitted to the durable artifact cache.

Artifact file names are derived only from the validated 64-character SHA-256, preventing package names or remote paths from becoming filesystem paths.

The cache:

- removes interrupted temporary files;
- preserves active, known-good, and currently pending artifacts;
- limits non-protected cache retention to four artifacts;
- limits non-protected retained bytes to 1 GiB;
- re-verifies SHA-256 when reading an artifact for recovery;
- deletes a corrupt cached artifact instead of loading it.

## Activation journal

Before a non-no-op activation, Gelato writes a pending transaction.

Lifecycle stages are:

- assigned;
- verified;
- prepared.

Successful activation commits the new active package and clears pending state.

A handled failure records diagnostics and clears pending state.

Therefore a pending transaction after restart means the process/device was interrupted before it could finish or handle the transaction.

Already-active assignments do not create pending journal entries.

## Restart recovery

Before automated vision inference proceeds, `VisionRuntimeController` runs `VisionModelRecoveryService`.

Recovery behavior:

1. validate durable detector/runtime identity;
2. compare current runtime to durable active/known-good identity;
3. if already correct, clear interrupted state and clean cache;
4. otherwise require `IVisionModelRecoverableRuntimeHost`;
5. reload the SHA-verified known-good artifact;
6. require the runtime to confirm the recovered package/checksum;
7. atomically commit restored state and clean stale cache.

If recovery cannot be proven, automated vision is held fail-closed and retry occurs. The system does not guess or silently treat an unconfirmed model as active.

## AIR3 vendor boundary

`InmoAir3VisionModelRuntimeHost` is the intentionally unimplemented adapter seam for the pending proprietary AIR3 model-loader SDK.

When the files arrive, only this adapter should need vendor-specific code for:

- prepare/load;
- warm-up/self-test;
- atomic activation;
- restore;
- restart recovery.

It must advertise only the real runtime type supported by the supplied SDK.

No recipe, KDS, label registry, confidence, transfer evidence, product validation, rollout, persistence, or recovery policy belongs in the vendor adapter.

## Safety inheritance

Section 21A retains all Section 18–20 controls:

- governed label profiles;
- blocked-label fail-closed precedence;
- raw and per-label confidence floors;
- station calibration;
- transfer evidence;
- human correction;
- product validation;
- KDS authority;
- exact issued-assignment rollout binding;
- HTTPS artifact source;
- byte-size and SHA-256 verification;
- known-good rollback.

## Contract coverage

Hardware-neutral tests prove:

- a successful activation stages verified bytes and commits durable active state;
- successful activation clears the pending journal;
- interrupted prepared activation recovers the known-good model after restart;
- corrupt known-good bytes never reach runtime recovery;
- missing recovery adapter holds fail-closed;
- existing Section 18–20 behavior remains in the full AIR3 regression suite.
