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
        Assert(detector.RestartCalls == 1, "watchdog threshold must restart detector");
        Assert(harness.Health.RestartSuccesses == 1, "successful restart must be recorded");
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
        options.ConsecutiveFailuresBeforeRestart = 9;
        var harness = new VisionDetectorRuntimeHarness(detector, options);
        await harness.WarmupAsync();

        var timedOut = await harness.DetectAsync(Frame(40), Context(), CancellationToken.None);
        Assert(timedOut.Count == 0 && harness.Health.TimedOutInferences == 1, "hung inference must time out fail-closed");

        var second = await harness.DetectAsync(Frame(41), Context(), CancellationToken.None);
        Assert(second.Count == 0 && harness.Health.BackpressureDrops == 1, "timed-out underlying call must continue holding the inference gate until it exits");

        detector.IgnoreCancellationGate.SetResult(true);
        await Task.Delay(5);
        var third = await harness.DetectAsync(Frame(42), Context(), CancellationToken.None);
        Assert(third.Count == 1, "new inference may resume only after the timed-out underlying call exits");
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

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
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
