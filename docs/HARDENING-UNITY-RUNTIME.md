# Unity runtime network boundaries — Release E / AI-05

## Original defect

The audit found a real adapter wiring defect: `VisionRuntimeController.Update` supplied `HeartbeatHealthy = true` on every frame, and the supervisor treated each evaluation as a successful heartbeat. A disconnected client therefore could not become stale. Thermal state was a placeholder and resource pressure was reported as false without measurements. API and model downloads had no finite application deadline, so an unanswered request could retain `_processing` indefinitely. Warmup and model recovery also ran before that guard was set.

## Implemented behavior

The controller now runs an independent authenticated `heartbeat` request through the existing device API. The optional `IGelatoHeartbeatGateway` capability keeps gateway implementations without a real heartbeat explicitly unsupported for automated connectivity health. Calling a heartbeat does not refresh work, clear a build, or change kitchen workflow state.

The actual gateway requires a successful transport result, parseable JSON, `ok: true`, and a nonempty device public ID. Only that acknowledgement records the monotonic success time. The supervisor consumes that observed timestamp; evaluating health cannot refresh it. Before the first acknowledgement the signal is unknown. A failed probe blocks immediately, and a success that reaches its stale threshold blocks even if no newer failure was received. A subsequent validated acknowledgement restores connectivity readiness, subject to camera, detector, model, and other existing runtime gates.

Probes run before the controller's inference-rate and `_processing` gates, including when no active build exists. They remain single-flight, and continue during stalled configuration/model requests and while health blocks automated processing. Probe failures are counted by observation version instead of once per frame. Scene reconstruction and device credential changes invalidate previous heartbeat state. A late response for a replaced credential cannot mark the current credential healthy.

The controller holds `_processing` across warmup, recovery, configuration, model activation, and inference, and releases it in `finally`. It rechecks current heartbeat health, build identity/status, and credential identity after configuration/model awaits, before inference, and before each observation submission. Credential replacement cancels in-flight processing as well as its old heartbeat. Continuation checks also reject obsolete assignments even if they complete before the next `Update` can cancel them. Credential versions are published with `Interlocked` after the gateway token update and read with `Volatile` across worker/engine threads. The controller also reacquires a camera frame after potentially slow configuration/download work.

Thermal state is exposed as `unknown` and resource pressure as nullable/unknown in the health snapshot. These are optional telemetry with no supplied vendor measurement source; readiness of the required runtime checks does not assert measured thermal or memory safety.

## Deadlines, cancellation, and Unity context

| Boundary | Default | Configuration |
| --- | --- | --- |
| Every gateway request, including heartbeat | 10 seconds | Gateway inspector, clamped to 0.1–120 seconds |
| Next heartbeat after a completed attempt | 5 seconds | Controller inspector, 0.1–60 seconds |
| Successful heartbeat age limit | 15 seconds | Controller inspector, 0.1–120 seconds |
| Model download | 60 seconds | Controller inspector, clamped to 0.1–300 seconds; standalone fetcher accepts a positive deadline up to one hour |

`UnityRequestDeadline` configures Unity's native timeout as a second safeguard and enforces its own elapsed-time deadline while polling the actual operation. It checks cancellation before sending, during the operation, and before accepting completion. A requested caller cancellation is preserved as `OperationCanceledException` with the original token; an elapsed application deadline is `TimeoutException`. Interrupted requests are aborted, and each caller owns a `using` scope that disposes the request and handlers. The download byte limit is checked during progress and after completion.

`UnityRequestContext` captures the engine synchronization context during gateway `Awake` and model-fetcher construction. Core code uses `ConfigureAwait(false)`, so later calls may arrive from a worker thread; those calls are explicitly posted back before transport creation. Timer callbacks only set cancellation state. They do not call Unity APIs. Creation, send, polling, abort, and disposal execute on the captured context.

The application deadline is observed when the engine next runs its context. A frozen/suspended main thread cannot be made to execute Unity cleanup by this code; that remains part of player/platform acceptance. A native transport error can retain its existing API/download error classification; the application deadline specifically raises `TimeoutException`.

## Executable verification

The existing core contract project remains separate:

```sh
dotnet run --project glasses/inmo-air3-unity/Tests/Gelato.Ar.Core.Tests.csproj -c Release
```

Core supervisor contracts cover unknown/missing observations, invalid future/nonfinite timestamps, the stale boundary, fresh success recovery, once-per-probe failure counting, and explicitly unknown optional telemetry, alongside the previous camera/detector/recovery contracts.

The new .NET 8 adapter project links production source files from `Runtime/Unity`; it does not copy controller or transport logic:

```sh
dotnet run --project glasses/inmo-air3-unity/AdapterTests/Gelato.Ar.Unity.Adapter.Tests.csproj -c Release
```

Its bounded single-thread engine facade provides only the Unity symbols needed by the linked sources and a programmable in-memory `UnityWebRequest` transport. It asserts that transport operations run on the engine thread, including calls originating from worker-thread core continuations and cancellation set by timers. JSON support models the public-field DTO contract. There are no real HTTP, paid AI, or production API calls.

The adapter contracts exercise:

- Actual controller startup with a pending authenticated request: unknown health and no detector invocation.
- Valid success, unchanged timestamp across `Update` calls, stale blocking, lost-network failure, and successful recovery while blocked.
- Malformed, empty, unsuccessful, and identity-less heartbeat acknowledgements.
- Single-flight probes and independent heartbeat progress while `_processing` is held.
- Gateway timeout, caller cancellation, pre-cancelled requests, handler disposal, and a subsequent successful request.
- Model download timeout, cancellation, progress byte limit, empty/error responses, successful bytes, and HTTPS validation.
- Actual controller/service/model-fetcher integration during a stalled model download: release of `_processing`, no preparation or activation of incomplete bytes, and continued use of the existing detector.
- Actual controller configuration timeout, retry and processing recovery, scene shutdown, reconstruction, and credential replacement. The replacement cancels the prior heartbeat and automated vision work before publishing its bearer. The tests deliver an obsolete assignment and valid model bytes before the next `Update`, then verify the old response cannot publish configuration, prepare or activate a model, or send a model report under the replacement bearer.

The source-only environment used for this change did not include `dotnet` or Unity. Local review and diff checks are not substitutes for compilation. The Release E CI job is the required executor for these two .NET commands and the behavioral baseline proof; consult its actual result before reporting them as passed.

## Behavioral baseline proof

The adapter runner accepts a narrow baseline mode:

```sh
dotnet run --project glasses/inmo-air3-unity/AdapterTests/Gelato.Ar.Unity.Adapter.Tests.csproj -c Release -- --baseline-heartbeat
```

In a disposable copy of the candidate tree, restore these two files from Release D base `82eda028f40af0c849b403ee037740f96bc3173b`:

- `glasses/inmo-air3-unity/Runtime/Unity/VisionRuntimeController.cs`
- `glasses/inmo-air3-unity/Runtime/Core/DeviceRuntimeSupervisor.cs`

Run only the adapter project in baseline mode, retaining the candidate test harness and gateway implementation. The old controller/supervisor compile against this harness because the baseline assertion uses their existing public health API and new optional fields are inspected reflectively only by the full suite. The baseline must fail with exactly this behavioral assertion:

> Before the first acknowledged heartbeat, actual Unity controller must block automated vision.

A build failure, missing SDK, unexpected exception, or timeout is not accepted as baseline proof. The candidate must pass that same startup contract and the full adapter suite. The separate core test project uses the new signal API and is run against the candidate, not the deliberately restored baseline.

## Acceptance limits

Passing the .NET facade suite verifies the linked C# adapter control flow and a bounded, thread-affine transport contract. It does not verify Unity's native networking, serializer implementation, player-loop timing, IL2CPP stripping/AOT, Android lifecycle/suspension behavior, native camera delivery, thermal sensors, memory-pressure callbacks, or physical AIR3 operation. The context dispatcher specifically protects network transport. Existing core continuations can also reach synchronous token-store/platform adapters and model-host callbacks; their real Unity/Android execution context and cancellation behavior require separate integration acceptance. In particular, this change does not certify `DevelopmentPlayerPrefsTokenStore` or vendor model-host callbacks as safe from worker threads.

`InmoAir3Platform` remains the documented vendor adapter stub. `InmoAir3SDK_0.7.3.unitypackage` has not been supplied. No vendor capability or sensor measurement is invented, and this release does not certify a physical AIR3 deployment. A real Unity 2021 LTS editor/player build and vendor-device acceptance remain necessary, including disconnect/reconnect, stalled endpoint, scene exit, background/resume, model download interruption, and measured thermal/resource tests once those adapters exist.

## Official API references

- [Unity 2021.3: UnityWebRequest.timeout](https://docs.unity3d.com/2021.3/Documentation/ScriptReference/Networking.UnityWebRequest-timeout.html) — native request timeout defaults to zero; set it explicitly.
- [Unity 2021.3: UnityWebRequest.Abort](https://docs.unity3d.com/2021.3/Documentation/ScriptReference/Networking.UnityWebRequest.Abort.html) — abort requests cancellation of an in-progress transfer.
- [Unity 2021.3: Overview of .NET in Unity](https://docs.unity3d.com/2021.3/Documentation/Manual/overview-of-dot-net-in-unity.html) — Unity API threading limitations and the engine synchronization context.
