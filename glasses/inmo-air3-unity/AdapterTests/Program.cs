#if GELATO_UNITY_ADAPTER_CONTRACT
using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Reflection;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using Gelato.Ar.Unity;
using UnityEngine;
using UnityEngine.Networking;
using Debug = UnityEngine.Debug;

internal static class Program
{
    private const string HeartbeatOk = "{\"ok\":true,\"device\":{\"publicId\":\"test-device\"}}";
    private const string UnknownAssertion = "Before the first acknowledged heartbeat, actual Unity controller must block automated vision.";

    private static int Main(string[] args)
    {
        using var pump = new EngineContext();
        SynchronizationContext.SetSynchronizationContext(pump);
        UnityWebRequest.OwnerThread = Thread.CurrentThread.ManagedThreadId;
        try
        {
            var run = RunAsync(args.Contains("--baseline-heartbeat", StringComparer.Ordinal));
            pump.RunUntil(() => run.IsCompleted, TimeSpan.FromSeconds(20));
            run.GetAwaiter().GetResult();
            Console.WriteLine("air3-unity-adapter-contract-ok (engine facade; no Unity engine or AIR3 hardware certification)");
            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine(ex);
            return 1;
        }
        finally
        {
            SynchronizationContext.SetSynchronizationContext(null);
        }
    }

    private static async Task RunAsync(bool baselineOnly)
    {
        await ControllerStartsUnknownAsync();
        if (baselineOnly) return;
        await HeartbeatOutcomesAndStalenessAsync();
        await StalledHeartbeatFailureAndRecoveryAsync();
        await HeartbeatRejectsInvalidAcknowledgementsAsync();
        await GatewayDeadlineAndCancellationAsync();
        await ModelDownloadBoundariesAsync();
        await StalledProcessingReleasesAndRecoversAsync();
        await StalledModelDownloadReleasesProcessingAsync();
        await SceneShutdownCancelsProcessingAsync();
        await CredentialReplacementInvalidatesHeartbeatAsync();
        await CredentialReplacementCancelsOldWorkAsync();
        Assert(Debug.Errors.Count == 0, "Actual controller must not hide unexpected adapter exceptions: " + string.Join(" | ", Debug.Errors));
    }

    private static async Task ControllerStartsUnknownAsync()
    {
        await using var fixture = await Fixture.CreateAsync();
        fixture.Heartbeat = _ => { }; // An actual request remains pending.
        fixture.Update();
        Assert(fixture.Controller.DeviceHealth != null, "Actual controller must evaluate device health.");
        Assert(!fixture.Controller.DeviceHealth.CanProcessAutomatedVision, UnknownAssertion);
        Assert(fixture.Controller.DeviceHealth.LastHeartbeatAtMs < 0, "No heartbeat timestamp may exist before an acknowledgement.");
        Assert(fixture.Detector.Calls == 0, "Unknown heartbeat must block actual detector invocation.");
        Assert(fixture.HeartbeatRequests.Count == 1, "Controller must send one real authenticated probe.");
        Assert(fixture.HeartbeatRequests[0].Headers["Authorization"] == "Bearer fixture-token", "Heartbeat must use paired device authentication.");
        for (var i = 0; i < 5; i++) fixture.Update();
        Assert(fixture.HeartbeatRequests.Count == 1, "Pending heartbeats must not overlap across Update calls.");
    }

    private static async Task HeartbeatOutcomesAndStalenessAsync()
    {
        await using var fixture = await Fixture.CreateAsync(interval: 60f, stale: 1f);
        fixture.Heartbeat = _ => { };
        fixture.Update();
        Time.unscaledTime = 0.25f;
        fixture.HeartbeatRequests.Single().Complete(HeartbeatOk);
        await UntilAsync(() => fixture.HeartbeatTask.IsCompleted);
        fixture.Update();
        await UntilAsync(() => !fixture.Processing);
        Assert(fixture.Controller.DeviceHealth.CanProcessAutomatedVision, "A validated actual heartbeat must restore readiness.");
        Assert(fixture.Detector.Calls > 0, "Successful connectivity must permit the actual pipeline to run.");
        var observed = fixture.Controller.DeviceHealth.LastHeartbeatAtMs;
        Assert(observed == 250d, "Heartbeat timestamp must be the actual acknowledgement time.");
        Time.unscaledTime = 0.75f;
        fixture.Update();
        await UntilAsync(() => !fixture.Processing);
        Assert(fixture.Controller.DeviceHealth.LastHeartbeatAtMs == observed, "Update cannot advance an unobserved heartbeat.");
        Time.unscaledTime = 1.25f;
        var calls = fixture.Detector.Calls;
        fixture.Update();
        Assert(fixture.Controller.DeviceHealth.PrimaryCode == "heartbeat_stale", "Heartbeat age must block at its configured stale boundary.");
        Assert(fixture.Detector.Calls == calls, "Stale connectivity must stop automated processing.");

        Time.unscaledTime = 61f;
        fixture.Heartbeat = r => r.Complete("{\"message\":\"offline\"}", UnityWebRequest.Result.ConnectionError, 0);
        fixture.Update();
        Assert(fixture.Controller.DeviceHealth.PrimaryCode == "heartbeat_failed", "Lost network must be represented by the controller's real probe result.");
        Assert(fixture.Controller.DeviceHealth.LastHeartbeatAtMs == observed, "Failed requests must not refresh heartbeat time.");
        var failures = fixture.Controller.DeviceHealth.Telemetry.HeartbeatFailures;
        fixture.Update();
        Assert(fixture.Controller.DeviceHealth.Telemetry.HeartbeatFailures == failures, "Repeated frames must not fabricate new failed probes.");

        Time.unscaledTime = 122f;
        fixture.Heartbeat = r => r.Complete(HeartbeatOk);
        fixture.Update();
        await UntilAsync(() => !fixture.Processing);
        Assert(fixture.Controller.DeviceHealth.CanProcessAutomatedVision, "Real successful probes must recover while processing is blocked.");
        Assert(fixture.Controller.DeviceHealth.LastHeartbeatAtMs == 122000d, "Recovery must retain the actual new success time.");
        Assert(ReadProperty(fixture.Controller.DeviceHealth, "ResourceConstrained") == null, "Unmeasured resource pressure must be unknown.");
        Assert((string)ReadProperty(fixture.Controller.DeviceHealth, "ThermalState") == "unknown", "Unmeasured thermal state must be unknown.");
    }

    private static async Task HeartbeatRejectsInvalidAcknowledgementsAsync()
    {
        foreach (var body in new[] { "{}", "null", "{\"ok\":false,\"device\":{\"publicId\":\"x\"}}", "{\"ok\":true,\"device\":{}}", "malformed" })
        {
            ResetTransport();
            var gateway = NewGateway();
            UnityWebRequest.OnSend = r => r.Complete(body);
            await ExpectFailureAsync(() => gateway.HeartbeatAsync(CancellationToken.None));
            Assert(UnityWebRequest.Requests.Single().Disposed, "Rejected heartbeat responses must dispose transport.");
        }
    }

    private static async Task StalledHeartbeatFailureAndRecoveryAsync()
    {
        await using var fixture = await Fixture.CreateAsync(interval: 0.1f);
        fixture.Heartbeat = _ => { };
        fixture.Update();
        await UntilAsync(() => fixture.HeartbeatTask.IsCompleted);
        fixture.Update();
        Assert(fixture.Controller.DeviceHealth.PrimaryCode == "heartbeat_failed", "Stalled real probes must terminate as a health failure.");
        AssertAbortedAndDisposed(fixture.HeartbeatRequests.Single());
        Assert(!fixture.Processing && fixture.Detector.Calls == 0, "A heartbeat timeout must release work and continue holding automation.");
        fixture.Heartbeat = r => r.Complete(HeartbeatOk);
        Time.unscaledTime = 0.2f;
        fixture.Update();
        await UntilAsync(() => !fixture.Processing);
        Assert(fixture.Controller.DeviceHealth.CanProcessAutomatedVision && fixture.Detector.Calls > 0,
            "After a stalled heartbeat, the next actual successful probe must restore processing.");
    }

    private static async Task GatewayDeadlineAndCancellationAsync()
    {
        ResetTransport();
        var gateway = NewGateway();
        UnityWebRequest.OnSend = _ => { };
        var timer = Stopwatch.StartNew();
        // Core coordinator continuations use ConfigureAwait(false); this must
        // marshal back to the captured context before touching Unity transport.
        await ExpectAsync<TimeoutException>(() => Task.Run(() => gateway.GetCurrentWorkAsync(CancellationToken.None)));
        Assert(timer.Elapsed < TimeSpan.FromSeconds(2), "A stalled API call must have a finite end-to-end deadline.");
        AssertAbortedAndDisposed(UnityWebRequest.Requests.Single());
        Assert(UnityWebRequest.Requests.Single().timeout > 0, "The native Unity timeout must also be configured.");

        ResetTransport();
        using var caller = new CancellationTokenSource();
        UnityWebRequest.OnSend = _ => caller.CancelAfter(5);
        var cancelled = await ExpectAsync<OperationCanceledException>(() => gateway.HeartbeatAsync(caller.Token));
        Assert(cancelled.CancellationToken == caller.Token, "Caller cancellation must retain its token instead of becoming a timeout.");
        AssertAbortedAndDisposed(UnityWebRequest.Requests.Single());

        ResetTransport();
        using var alreadyCancelled = new CancellationTokenSource();
        alreadyCancelled.Cancel();
        await ExpectAsync<OperationCanceledException>(() => gateway.HeartbeatAsync(alreadyCancelled.Token));
        Assert(UnityWebRequest.Requests.Count == 0, "Pre-cancelled API requests must not create or send transport.");

        UnityWebRequest.OnSend = r => r.Complete(HeartbeatOk);
        await gateway.HeartbeatAsync(CancellationToken.None);
        Assert(UnityWebRequest.Requests.Single().Disposed && !UnityWebRequest.Requests.Single().Aborted,
            "A new request must succeed after timeout/cancellation without stale transport state.");
    }

    private static async Task ModelDownloadBoundariesAsync()
    {
        var uri = new Uri("https://fixture.invalid/model.bin");
        ResetTransport();
        var fetcher = new UnityVisionModelArtifactFetcher(TimeSpan.FromMilliseconds(40));
        UnityWebRequest.OnSend = _ => { };
        await ExpectAsync<TimeoutException>(() => Task.Run(() => fetcher.FetchAsync(uri, 16, CancellationToken.None)));
        AssertAbortedAndDisposed(UnityWebRequest.Requests.Single());

        ResetTransport();
        using var caller = new CancellationTokenSource();
        UnityWebRequest.OnSend = _ => caller.CancelAfter(5);
        var cancelled = await ExpectAsync<OperationCanceledException>(() => fetcher.FetchAsync(uri, 16, caller.Token));
        Assert(cancelled.CancellationToken == caller.Token, "Download cancellation must remain caller cancellation.");
        AssertAbortedAndDisposed(UnityWebRequest.Requests.Single());

        ResetTransport();
        UnityWebRequest.OnSend = r => r.downloadedBytes = 17;
        await ExpectAsync<InvalidOperationException>(() => fetcher.FetchAsync(uri, 16, CancellationToken.None));
        AssertAbortedAndDisposed(UnityWebRequest.Requests.Single());

        foreach (var status in new[] { UnityWebRequest.Result.Success, UnityWebRequest.Result.ProtocolError })
        {
            ResetTransport();
            UnityWebRequest.OnSend = r => r.Complete(Array.Empty<byte>(), status, status == UnityWebRequest.Result.Success ? 200 : 503);
            await ExpectAsync<InvalidOperationException>(() => fetcher.FetchAsync(uri, 16, CancellationToken.None));
            Assert(UnityWebRequest.Requests.Single().Disposed, "Empty/error model responses must be disposed.");
        }

        ResetTransport();
        UnityWebRequest.OnSend = r => r.Complete(new byte[] { 1, 2, 3 });
        var data = await Task.Run(() => fetcher.FetchAsync(uri, 16, CancellationToken.None));
        Assert(data.SequenceEqual(new byte[] { 1, 2, 3 }), "Successful bounded download must preserve actual received bytes.");
        Assert(UnityWebRequest.Requests.Single().Disposed && !UnityWebRequest.Requests.Single().Aborted, "Successful download must release its transport.");
        ResetTransport();
        await ExpectAsync<InvalidOperationException>(() => fetcher.FetchAsync(new Uri("http://fixture.invalid/model.bin"), 16, CancellationToken.None));
        Assert(UnityWebRequest.Requests.Count == 0, "Insecure model URLs must be rejected before creating transport.");
    }

    private static async Task StalledProcessingReleasesAndRecoversAsync()
    {
        await using var fixture = await Fixture.CreateAsync(interval: 0.1f);
        fixture.Profile = _ => { };
        fixture.Update();
        Assert(fixture.Processing, "Fixture must exercise a real pending controller configuration call.");
        Time.unscaledTime = 0.2f;
        fixture.Update();
        Assert(fixture.HeartbeatRequests.Count == 2, "Heartbeat probes must run while the processing guard is held.");
        await UntilAsync(() => !fixture.Processing);
        var stalled = UnityWebRequest.Requests.Single(r => r.Action == DeviceApiActions.VisionProfile);
        AssertAbortedAndDisposed(stalled);
        Assert(fixture.Detector.Calls == 0, "Timed-out configuration must not emit automated evidence.");
        fixture.Profile = CompleteProfile;
        Time.unscaledTime = 6f;
        fixture.Update();
        await UntilAsync(() => !fixture.Processing);
        Assert(fixture.Detector.Calls > 0, "The actual controller must process again after the bounded failure and retry.");
    }

    private static async Task SceneShutdownCancelsProcessingAsync()
    {
        await using (var fixture = await Fixture.CreateAsync())
        {
            fixture.Profile = _ => { };
            fixture.Update();
            Assert(fixture.Processing, "Shutdown fixture must hold actual in-flight work.");
            fixture.Destroy();
            await UntilAsync(() => !fixture.Processing);
            AssertAbortedAndDisposed(UnityWebRequest.Requests.Single(r => r.Action == DeviceApiActions.VisionProfile));
        }
        // A newly constructed scene starts with no inherited success, even after
        // a previous scene successfully reached its API.
        await using var restarted = await Fixture.CreateAsync();
        restarted.Heartbeat = _ => { };
        restarted.Update();
        Assert(!restarted.Controller.DeviceHealth.CanProcessAutomatedVision, "Scene/process reset must require a fresh heartbeat.");
        Assert(restarted.Controller.DeviceHealth.LastHeartbeatAtMs < 0, "Heartbeat success must not survive controller reconstruction.");
    }

    private static async Task StalledModelDownloadReleasesProcessingAsync()
    {
        await using var fixture = await Fixture.CreateAsync(interval: 0.1f);
        var host = new ModelHost();
        // Exercise the actual controller/service/fetcher path while the model
        // runtime and persistence are outside this network-boundary contract.
        SetField(fixture.Controller, "modelRuntimeHost", host);
        SetField(fixture.Controller, "_modelActivationService", new VisionModelActivationService(
            new UnityVisionModelArtifactFetcher(TimeSpan.FromMilliseconds(40)), host,
            (report, token) => fixture.Coordinator.ReportVisionModelAsync(report, token)));
        fixture.ModelAssignment = CompleteModelAssignment;
        fixture.Update();
        await UntilAsync(() => UnityWebRequest.Requests.Any(r => r.method == "GET"));
        Assert(fixture.Processing, "Model download must hold actual controller work until it completes or times out.");
        Time.unscaledTime = 0.2f;
        fixture.Update();
        Assert(fixture.HeartbeatRequests.Count == 2, "Pending model download must not suppress recovery probes.");
        await UntilAsync(() => !fixture.Processing);
        AssertAbortedAndDisposed(UnityWebRequest.Requests.Single(r => r.method == "GET"));
        Assert(host.PrepareCalls == 0 && host.ActivationCalls == 0, "Timed-out model bytes cannot reach prepare or activation.");
        Assert(host.CaptureActive().PackagePublicId == "known-good", "A stalled download must preserve the previous runtime.");
        Assert(fixture.Detector.Calls > 0, "After download failure, the existing ready detector can resume through the actual controller.");
    }

    private static async Task CredentialReplacementInvalidatesHeartbeatAsync()
    {
        await using var fixture = await Fixture.CreateAsync(interval: 60f);
        fixture.Update();
        await UntilAsync(() => !fixture.Processing);
        Assert(fixture.Controller.DeviceHealth.CanProcessAutomatedVision, "Credential replacement fixture must start ready.");
        await fixture.Coordinator.PairAsync("123456", new DeviceDescriptor());
        fixture.Heartbeat = _ => { };
        Time.unscaledTime = 1f;
        fixture.Update();
        Assert(!fixture.Controller.DeviceHealth.CanProcessAutomatedVision && fixture.Controller.DeviceHealth.LastHeartbeatAtMs < 0,
            "A replacement credential cannot inherit another credential's heartbeat success.");
        var pending = fixture.HeartbeatRequests.Last();
        await fixture.Coordinator.PairAsync("654321", new DeviceDescriptor());
        fixture.Update();
        pending.Complete(HeartbeatOk); // An obsolete response races credential replacement.
        await UntilAsync(() => pending.Disposed);
        fixture.Update();
        Assert(!fixture.Controller.DeviceHealth.CanProcessAutomatedVision, "Late success for a replaced credential must not authorize automation.");
    }

    private static async Task CredentialReplacementCancelsOldWorkAsync()
    {
        await using (var fixture = await Fixture.CreateAsync())
        {
            fixture.Profile = _ => { };
            fixture.Update();
            Assert(fixture.Processing, "Credential cancellation fixture must hold a real profile request.");
            await fixture.Coordinator.PairAsync("123456", new DeviceDescriptor());
            fixture.Heartbeat = _ => { };
            Time.unscaledTime = 0.2f;
            fixture.Update();
            await UntilAsync(() => !fixture.Processing);
            AssertAbortedAndDisposed(UnityWebRequest.Requests.Single(r => r.Action == DeviceApiActions.VisionProfile));
            Assert(fixture.Detector.Calls == 0, "Work started under a replaced credential must not reach the detector.");
        }

        await using var raced = await Fixture.CreateAsync();
        raced.ModelAssignment = _ => { };
        raced.Update();
        var assignment = UnityWebRequest.Requests.Single(r => r.Action == DeviceApiActions.VisionModelAssignment);
        await raced.Coordinator.PairAsync("654321", new DeviceDescriptor());
        // No intervening Update cancels the token: the continuation itself must
        // reject this old assignment before reporting, downloading or applying it.
        CompleteModelAssignment(assignment);
        await UntilAsync(() => !raced.Processing);
        Assert(!UnityWebRequest.Requests.Any(r => r.Action == DeviceApiActions.VisionModelReport || r.method == "GET"),
            "An assignment completed under an obsolete credential must not report or enter model activation.");
        Assert(raced.Detector.Calls == 0, "An obsolete configuration continuation cannot produce observations.");
    }

    private static void CompleteModelAssignment(UnityWebRequest request) => request.Complete(
        "{\"ok\":true,\"modelAssignment\":{\"action\":\"apply\",\"assignmentKey\":\"assignment-1\",\"detectorName\":\"adapter-test\","
        + "\"compatibility\":{\"compatible\":true},\"rollout\":{\"publicId\":\"rollout-1\"},\"package\":{\"publicId\":\"package-2\","
        + "\"detectorName\":\"adapter-test\",\"runtimeType\":\"fixture-runtime\",\"platform\":\"fixture\",\"artifactUrl\":\"https://fixture.invalid/model.bin\","
        + "\"artifactSha256\":\"" + new string('0', 64) + "\"}}}");

    private static void CompleteProfile(UnityWebRequest request) => request.Complete(
        "{\"ok\":true,\"visionProfile\":{\"schema\":\"gelato.vision-profile.v1\",\"detectorName\":\"adapter-test\",\"buildSessionPublicId\":\"build-1\",\"profileHash\":\"fixture\",\"mappings\":[],\"blockedLabels\":[]}}");

    private static UnityGelatoGateway NewGateway()
    {
        var gateway = new UnityGelatoGateway();
        Invoke(gateway, "Awake");
        SetField(gateway, "requestTimeoutSeconds", 0.1f);
        gateway.SetDeviceToken("fixture-token");
        return gateway;
    }

    private static void ResetTransport()
    {
        Assert(UnityWebRequest.Requests.All(r => r.Disposed), "A previous contract leaked a transport request.");
        UnityWebRequest.Requests.Clear();
        UnityWebRequest.OnSend = null;
        Time.unscaledTime = 0f;
    }

    private static void AssertAbortedAndDisposed(UnityWebRequest request)
    {
        Assert(request.Aborted && request.Disposed, "Interrupted transport must be both aborted and disposed.");
        Assert(request.AbortThread == UnityWebRequest.OwnerThread && request.DisposeThread == UnityWebRequest.OwnerThread,
            "Abort/dispose must execute on Unity's context, never a timer/worker thread.");
        Assert(request.downloadHandler.Disposed && (request.uploadHandler == null || request.uploadHandler.Disposed),
            "Request disposal must release its owned handlers.");
    }

    private static async Task<T> ExpectAsync<T>(Func<Task> operation) where T : Exception
    {
        try { await operation(); }
        catch (T ex) { return ex; }
        throw new InvalidOperationException("Expected " + typeof(T).Name + " from actual adapter source.");
    }
    private static async Task ExpectFailureAsync(Func<Task> operation)
    {
        try { await operation(); }
        catch (Exception) { return; }
        throw new InvalidOperationException("An unacknowledged heartbeat was incorrectly accepted.");
    }
    private static async Task UntilAsync(Func<bool> condition)
    {
        var deadline = Stopwatch.StartNew();
        while (!condition())
        {
            if (deadline.Elapsed > TimeSpan.FromSeconds(3)) throw new TimeoutException("Adapter contract exceeded its bounded wait.");
            await Task.Delay(1);
        }
    }
    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }
    private static void Invoke(object value, string method) => value.GetType().GetMethod(method, BindingFlags.Instance | BindingFlags.NonPublic).Invoke(value, null);
    private static void SetField(object value, string field, object item, bool optional = false)
    {
        var info = value.GetType().GetField(field, BindingFlags.Instance | BindingFlags.NonPublic);
        if (info == null && optional) return;
        if (info == null) throw new InvalidOperationException("Missing adapter field: " + field);
        info.SetValue(value, item);
    }
    private static object ReadProperty(object value, string name) => value.GetType().GetProperty(name).GetValue(value);

    private sealed class Fixture : IAsyncDisposable
    {
        public readonly VisionRuntimeController Controller = new VisionRuntimeController();
        public readonly CountingDetector Detector = new CountingDetector();
        public ArWorkflowCoordinator Coordinator;
        public Action<UnityWebRequest> Heartbeat = r => r.Complete(HeartbeatOk);
        public Action<UnityWebRequest> Profile = CompleteProfile;
        public Action<UnityWebRequest> ModelAssignment = r => r.Complete("{\"ok\":true}");
        private bool _destroyed;
        public bool Processing => (bool)typeof(VisionRuntimeController).GetField("_processing", BindingFlags.Instance | BindingFlags.NonPublic).GetValue(Controller);
        public Task HeartbeatTask => (Task)typeof(VisionRuntimeController).GetField("_heartbeatTask", BindingFlags.Instance | BindingFlags.NonPublic).GetValue(Controller);
        public List<UnityWebRequest> HeartbeatRequests => UnityWebRequest.Requests.Where(r => r.Action == DeviceApiActions.Heartbeat).ToList();

        public static async Task<Fixture> CreateAsync(float interval = 5f, float stale = 15f)
        {
            ResetTransport();
            var fixture = new Fixture();
            var gateway = NewGateway();
            fixture.Coordinator = new ArWorkflowCoordinator(new CameraPlatform(), gateway, new TokenStore());
            await fixture.Coordinator.InitializeAsync();
            typeof(ArWorkflowCoordinator).GetProperty("BuildSession").SetValue(fixture.Coordinator,
                new BuildSession { PublicId = "build-1", Status = "active" });
            var bootstrap = new GelatoArBootstrap();
            typeof(GelatoArBootstrap).GetProperty("Coordinator").SetValue(bootstrap, fixture.Coordinator);
            SetField(fixture.Controller, "bootstrap", bootstrap);
            SetField(fixture.Controller, "detector", fixture.Detector);
            SetField(fixture.Controller, "heartbeatIntervalSeconds", interval, optional: true);
            SetField(fixture.Controller, "heartbeatStaleAfterSeconds", stale, optional: true);
            UnityWebRequest.OnSend = request =>
            {
                if (request.method == "GET") return; // Deliberately stalled model transport.
                switch (request.Action)
                {
                    case DeviceApiActions.Heartbeat: fixture.Heartbeat(request); break;
                    case DeviceApiActions.VisionProfile: fixture.Profile(request); break;
                    case DeviceApiActions.VisionModelAssignment: fixture.ModelAssignment(request); break;
                    case DeviceApiActions.Pair: request.Complete("{\"ok\":true,\"deviceToken\":\"new-token\",\"device\":{\"publicId\":\"new-device\"}}"); break;
                    default: request.Complete("{\"ok\":true}"); break;
                }
            };
            Invoke(fixture.Controller, "Awake");
            return fixture;
        }
        public void Update() => Invoke(Controller, "Update");
        public void Destroy()
        {
            if (_destroyed) return;
            _destroyed = true;
            Invoke(Controller, "OnDestroy");
        }
        public async ValueTask DisposeAsync()
        {
            Destroy();
            await UntilAsync(() => !Processing && UnityWebRequest.Requests.All(r => r.Disposed));
        }
    }

    private sealed class CountingDetector : VisionDetectorBehaviour
    {
        public int Calls;
        public override string DetectorName => "adapter-test";
        public override Task<IReadOnlyList<VisionDetection>> DetectAsync(CameraFrame frame, VisionFrameContext context, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            Calls++;
            return Task.FromResult<IReadOnlyList<VisionDetection>>(Array.Empty<VisionDetection>());
        }
    }
    private sealed class TokenStore : IDeviceTokenStore
    {
        private string _token = "fixture-token";
        public string Load() => _token;
        public void Save(string token) { _token = token; }
        public void Clear() { _token = null; }
    }
    private sealed class ModelHost : VisionModelRuntimeHostBehaviour
    {
        public int PrepareCalls;
        public int ActivationCalls;
        public override string DetectorName => "adapter-test";
        public override string RuntimeType => "fixture-runtime";
        public override VisionModelRuntimeSnapshot CaptureActive() => new VisionModelRuntimeSnapshot { PackagePublicId = "known-good" };
        public override Task<VisionModelPreparedArtifact> PrepareAsync(VisionModelPackage package, byte[] bytes, CancellationToken token)
        {
            PrepareCalls++;
            throw new InvalidOperationException("Timed-out model must never be prepared.");
        }
        public override Task SelfTestAsync(VisionModelPreparedArtifact prepared, CancellationToken token) => Task.CompletedTask;
        public override Task ActivateAsync(VisionModelPreparedArtifact prepared, CancellationToken token)
        {
            ActivationCalls++;
            return Task.CompletedTask;
        }
        public override Task RestoreAsync(VisionModelRuntimeSnapshot previous, CancellationToken token) => Task.CompletedTask;
    }
    private sealed class CameraPlatform : IGlassesPlatform
    {
        private long _frame;
        public bool IsInitialized { get; private set; }
        public PlatformCapabilities Capabilities { get; } = new PlatformCapabilities { Camera = true };
        public Task InitializeAsync(CancellationToken cancellationToken) { IsInitialized = true; return Task.CompletedTask; }
        public void StartTracking() { }
        public void StopTracking() { }
        public CameraFrame TryGetLatestFrame() => new CameraFrame { Data = new byte[] { 1 }, Width = 1, Height = 1, TimestampNanoseconds = ++_frame };
        public CameraCalibration TryGetCameraCalibration() => null;
        public PoseState GetPose() => new PoseState();
    }

    private sealed class EngineContext : SynchronizationContext, IDisposable
    {
        private readonly ConcurrentQueue<(SendOrPostCallback callback, object state)> _queue = new ConcurrentQueue<(SendOrPostCallback, object)>();
        public override void Post(SendOrPostCallback callback, object state) => _queue.Enqueue((callback, state));
        public void RunUntil(Func<bool> done, TimeSpan timeout)
        {
            var clock = Stopwatch.StartNew();
            while (!done())
            {
                if (clock.Elapsed > timeout) throw new TimeoutException("Engine facade pump exceeded its bounded run.");
                if (_queue.TryDequeue(out var entry)) entry.callback(entry.state);
                else Thread.Sleep(1);
            }
        }
        public void Dispose() { }
    }
}
#endif
