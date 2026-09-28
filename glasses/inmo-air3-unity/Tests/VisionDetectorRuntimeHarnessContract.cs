using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;

internal static class VisionDetectorRuntimeHarnessContract
{
    public static async Task RunAsync()
    {
        await WarmupAndHealthyInference();
        await TimeoutFailsClosedAndRestarts();
        await BackpressureDropsConcurrentFrame();
        await TimedOutIgnoringCancellationDoesNotOverlap();
        await DetectorRestartDoesNotDuplicateObservation();
        await RepeatedRestartFailureTransitionsFailed();
    }

    private static async Task WarmupAndHealthyInference()
    {
        var detector = new ControlledDetector();
        var harness = new VisionDetectorRuntimeHarness(detector, Options());
        await harness.WarmupAsync();
        var result = await harness.DetectAsync(Frame(1), Context(), CancellationToken.None);

        Assert(detector.WarmupCalls == 1, "runtime warmup must delegate once");
        Assert(result.Count == 1, "healthy inference must return detections");
        Assert(harness.Health.State == VisionDetectorRuntimeState.Ready, "healthy inference must be ready");
        Assert(harness.Health.SuccessfulInferences == 1 && harness.Health.LastLatencyMs >= 0, "latency and success telemetry must be recorded");
    }

    private static async Task TimeoutFailsClosedAndRestarts()
    {
        var detector = new ControlledDetector { Hang = true };
        var options = Options();
        options.ConsecutiveFailuresBeforeRestart = 1;
        var harness = new VisionDetectorRuntimeHarness(detector, options);
        await harness.WarmupAsync();

        var result = await harness.DetectAsync(Frame(2), Context(), CancellationToken.None);

        Assert(result.Count == 0, "timed-out inference must produce no evidence");
        Assert(harness.Health.TimedOutInferences == 1, "timeout telemetry must increment");
        await WaitUntilAsync(() => harness.Health.RestartSuccesses == 1, "watchdog restart and warm-up must complete after the timed-out call exits");
        Assert(detector.RestartCalls == 1, "successful watchdog recovery must restart the detector exactly once");
        Assert(harness.Health.State == VisionDetectorRuntimeState.Ready, "successful watchdog recovery must return runtime health to ready");
    }

    private static async Task BackpressureDropsConcurrentFrame()
    {
        var detector = new ControlledDetector { Gate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously) };
        var harness = new VisionDetectorRuntimeHarness(detector, Options());
        await harness.WarmupAsync();

        var first = harness.DetectAsync(Frame(3), Context(), CancellationToken.None);
        await detector.Started.Task;
        var second = await harness.DetectAsync(Frame(4), Context(), CancellationToken.None);

        Assert(second.Count == 0, "concurrent frame must be dropped rather than queued");
        Assert(harness.Health.BackpressureDrops == 1, "backpressure drop must be observable");
        detector.Gate.SetResult(true);
        await first;
    }

    private static async Task TimedOutIgnoringCancellationDoesNotOverlap()
    {
        var detector = new ControlledDetector
        {
            IgnoreCancellationGate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously)
        };
        var options = Options();
        options.ConsecutiveFailuresBeforeRestart = 1;
        options.ConsecutiveFailuresBeforeFailed = 4;
        var harness = new VisionDetectorRuntimeHarness(detector, options);
        await harness.WarmupAsync();

        var timedOut = await harness.DetectAsync(Frame(40), Context(), CancellationToken.None);
        Assert(timedOut.Count == 0 && harness.Health.TimedOutInferences == 1, "hung inference must time out fail-closed");

        var second = await harness.DetectAsync(Frame(41), Context(), CancellationToken.None);
        Assert(second.Count == 0 && harness.Health.BackpressureDrops == 1, "timed-out underlying call must continue holding the inference gate until it exits");
        Assert(detector.RestartCalls == 0, "watchdog must not restart while the timed-out detector call is still executing");

        detector.IgnoreCancellationGate.SetResult(true);
        await WaitUntilAsync(() => detector.RestartCalls == 1, "watchdog must restart only after the timed-out call exits");
        var third = await harness.DetectAsync(Frame(42), Context(), CancellationToken.None);
        Assert(third.Count == 1, "new inference may resume only after the timed-out underlying call exits");
    }

    private static async Task DetectorRestartDoesNotDuplicateObservation()
    {
        var detector = new RestartDuplicateDetector();
        var options = Options();
        options.ConsecutiveFailuresBeforeRestart = 1;
        var harness = new VisionDetectorRuntimeHarness(detector, options);
        await harness.WarmupAsync();

        var pipeline = new VisionPipeline(harness, new VisionPipelineOptions
        {
            MinimumConfidence = 0.5f,
            StableFramesRequired = 1,
            MaxMissingFrames = 3
        });
        var bread = new BuildComponent
        {
            ComponentKey = "ingredient:bread",
            DisplayName = "Bread",
            ExpectedQuantity = 1f
        };
        var context = new VisionFrameContext
        {
            BuildSessionPublicId = "build-restart-no-duplicate",
            ExpectedComponents = new[] { bread }
        };

        var first = await pipeline.ProcessAsync(Frame(50), context);
        Assert(first.Count == 1, "first stable detection must emit once");

        detector.FailNext = true;
        var failed = await pipeline.ProcessAsync(Frame(51), context);
        Assert(failed.Count == 0 && detector.RestartCalls == 1, "detector failure must restart without emitting evidence");

        var afterRestart = await pipeline.ProcessAsync(Frame(52), context);
        Assert(afterRestart.Count == 0, "same tracked ingredient must not re-emit after detector restart");
        Assert(pipeline.Diagnostics.ObservationsEmitted == 1, "detector restart must preserve exactly-once observation emission within the build");
    }

    private static async Task RepeatedRestartFailureTransitionsFailed()
    {
        var detector = new ControlledDetector { Throw = true, FailRestart = true };
        var options = Options();
        options.ConsecutiveFailuresBeforeRestart = 1;
        options.ConsecutiveFailuresBeforeFailed = 2;
        var harness = new VisionDetectorRuntimeHarness(detector, options);
        await harness.WarmupAsync();

        await harness.DetectAsync(Frame(5), Context(), CancellationToken.None);

        Assert(harness.Health.State == VisionDetectorRuntimeState.Failed, "failed watchdog restart must transition detector to failed");
        Assert(harness.Health.LastErrorCode == "detector_restart_failed", "failed restart must expose explicit health code");
    }

    private static VisionDetectorRuntimeOptions Options() => new VisionDetectorRuntimeOptions
    {
        InferenceTimeout = TimeSpan.FromMilliseconds(40),
        ConsecutiveFailuresBeforeRestart = 2,
        ConsecutiveFailuresBeforeFailed = 4
    };

    private static CameraFrame Frame(long timestamp) => new CameraFrame
    {
        Data = new byte[] { 1, 2, 3, 4 },
        Width = 2,
        Height = 2,
        TimestampNanoseconds = timestamp,
        PixelFormat = "grayscale8"
    };

    private static VisionFrameContext Context() => new VisionFrameContext
    {
        BuildSessionPublicId = "build-21b",
        ExpectedComponents = Array.Empty<BuildComponent>()
    };

    private static async Task WaitUntilAsync(Func<bool> condition, string message)
    {
        for (var i = 0; i < 100; i++)
        {
            if (condition()) return;
            await Task.Delay(5);
        }
        throw new InvalidOperationException(message);
    }

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }

    private sealed class RestartDuplicateDetector : IVisionDetector, IVisionDetectorRuntimeControl
    {
        public string DetectorName => "restart-duplicate-detector";
        public bool FailNext { get; set; }
        public int RestartCalls { get; private set; }

        public Task WarmupAsync(CancellationToken cancellationToken) => Task.CompletedTask;

        public Task RestartAsync(CancellationToken cancellationToken)
        {
            RestartCalls++;
            return Task.CompletedTask;
        }

        public Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (FailNext)
            {
                FailNext = false;
                throw new InvalidOperationException("simulated detector restart");
            }

            IReadOnlyList<VisionDetection> result = new[]
            {
                new VisionDetection
                {
                    ComponentKey = "ingredient:bread",
                    Label = "Bread",
                    DisplayName = "Bread",
                    InstanceKey = "bread-instance-1",
                    Action = "added",
                    Quantity = 1f,
                    Confidence = 0.95f,
                    BoundingBox = new[] { 0.1f, 0.1f, 0.2f, 0.2f }
                }
            };
            return Task.FromResult(result);
        }
    }

    private sealed class ControlledDetector : IVisionDetector, IVisionDetectorRuntimeControl
    {
        public string DetectorName => "controlled-detector";
        public bool Hang { get; set; }
        public bool Throw { get; set; }
        public bool FailRestart { get; set; }
        public TaskCompletionSource<bool>? IgnoreCancellationGate { get; set; }
        public int WarmupCalls { get; private set; }
        public int RestartCalls { get; private set; }
        public TaskCompletionSource<bool>? Gate { get; set; }
        public TaskCompletionSource<bool> Started { get; } = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);

        public Task WarmupAsync(CancellationToken cancellationToken)
        {
            WarmupCalls++;
            return Task.CompletedTask;
        }

        public Task RestartAsync(CancellationToken cancellationToken)
        {
            RestartCalls++;
            if (FailRestart) throw new InvalidOperationException("simulated restart failure");
            Hang = false;
            Throw = false;
            return Task.CompletedTask;
        }

        public async Task<IReadOnlyList<VisionDetection>> DetectAsync(CameraFrame frame, VisionFrameContext context, CancellationToken cancellationToken)
        {
            Started.TrySetResult(true);
            if (Throw) throw new InvalidOperationException("simulated inference failure");
            if (IgnoreCancellationGate != null)
            {
                await IgnoreCancellationGate.Task;
                IgnoreCancellationGate = null;
            }
            if (Hang) await Task.Delay(TimeSpan.FromSeconds(5), cancellationToken);
            if (Gate != null) await Gate.Task;
            return new[]
            {
                new VisionDetection
                {
                    Label = "test",
                    DisplayName = "test",
                    Confidence = 0.9f,
                    Quantity = 1f,
                    BoundingBox = new[] { 0.1f, 0.1f, 0.2f, 0.2f },
                    IsUnexpected = true
                }
            };
        }
    }
}
