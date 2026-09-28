using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public enum VisionDetectorRuntimeState
    {
        Starting,
        Ready,
        Degraded,
        Recovering,
        Failed
    }

    public sealed class VisionDetectorRuntimeHealth
    {
        public VisionDetectorRuntimeState State { get; internal set; } = VisionDetectorRuntimeState.Starting;
        public long InferenceCalls { get; internal set; }
        public long SuccessfulInferences { get; internal set; }
        public long TimedOutInferences { get; internal set; }
        public long FailedInferences { get; internal set; }
        public long BackpressureDrops { get; internal set; }
        public long RestartAttempts { get; internal set; }
        public long RestartSuccesses { get; internal set; }
        public double LastLatencyMs { get; internal set; }
        public double AverageLatencyMs { get; internal set; }
        public double LastInferenceFps { get; internal set; }
        public string LastErrorCode { get; internal set; } = string.Empty;
        public string LastErrorMessage { get; internal set; } = string.Empty;
    }

    public sealed class VisionDetectorRuntimeOptions
    {
        public TimeSpan InferenceTimeout { get; set; } = TimeSpan.FromMilliseconds(750);
        public int ConsecutiveFailuresBeforeRestart { get; set; } = 3;
        public int ConsecutiveFailuresBeforeFailed { get; set; } = 6;

        public void Validate()
        {
            if (InferenceTimeout <= TimeSpan.Zero) throw new InvalidOperationException("Inference timeout must be positive.");
            if (ConsecutiveFailuresBeforeRestart < 1) throw new InvalidOperationException("Restart failure threshold must be at least one.");
            if (ConsecutiveFailuresBeforeFailed < ConsecutiveFailuresBeforeRestart)
                throw new InvalidOperationException("Failed threshold cannot be lower than restart threshold.");
        }
    }

    public interface IVisionDetectorRuntimeControl
    {
        Task WarmupAsync(CancellationToken cancellationToken);
        Task RestartAsync(CancellationToken cancellationToken);
    }

    public sealed class VisionDetectorRuntimeHarness : IVisionDetector
    {
        private readonly IVisionDetector _inner;
        private readonly VisionDetectorRuntimeOptions _options;
        private readonly SemaphoreSlim _inferenceGate = new SemaphoreSlim(1, 1);
        private int _consecutiveFailures;
        private double _totalLatencyMs;
        private long _lastSuccessTimestamp;

        public VisionDetectorRuntimeHarness(IVisionDetector inner, VisionDetectorRuntimeOptions? options = null)
        {
            _inner = inner ?? throw new ArgumentNullException(nameof(inner));
            _options = options ?? new VisionDetectorRuntimeOptions();
            _options.Validate();
        }

        public string DetectorName => _inner.DetectorName;
        public VisionDetectorRuntimeHealth Health { get; } = new VisionDetectorRuntimeHealth();

        public async Task WarmupAsync(CancellationToken cancellationToken = default)
        {
            var wasFailed = Health.State == VisionDetectorRuntimeState.Failed;
            Health.State = VisionDetectorRuntimeState.Starting;
            if (_inner is IVisionDetectorRuntimeControl control)
            {
                if (wasFailed)
                {
                    Health.State = VisionDetectorRuntimeState.Recovering;
                    Health.RestartAttempts++;
                    await control.RestartAsync(cancellationToken).ConfigureAwait(false);
                    Health.RestartSuccesses++;
                }
                await control.WarmupAsync(cancellationToken).ConfigureAwait(false);
            }
            Health.State = VisionDetectorRuntimeState.Ready;
            Health.LastErrorCode = string.Empty;
            Health.LastErrorMessage = string.Empty;
        }

        public async Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken)
        {
            if (!_inferenceGate.Wait(0))
            {
                Health.BackpressureDrops++;
                return Array.Empty<VisionDetection>();
            }

            var releaseGate = true;
            try
            {
                Health.InferenceCalls++;
                var sw = Stopwatch.StartNew();
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                var detectTask = _inner.DetectAsync(frame, context, timeout.Token);
                var timeoutTask = Task.Delay(_options.InferenceTimeout, cancellationToken);
                var completed = await Task.WhenAny(detectTask, timeoutTask).ConfigureAwait(false);

                if (completed != detectTask)
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    timeout.Cancel();
                    releaseGate = false;
                    Health.TimedOutInferences++;
                    RegisterFailure("inference_timeout", "Detector inference exceeded its deadline.");
                    Health.State = VisionDetectorRuntimeState.Degraded;
                    _ = DrainTimedOutInferenceAndRecoverAsync(detectTask);
                    return Array.Empty<VisionDetection>();
                }

                var result = await detectTask.ConfigureAwait(false) ?? Array.Empty<VisionDetection>();
                sw.Stop();
                Health.LastLatencyMs = sw.Elapsed.TotalMilliseconds;
                _totalLatencyMs += Health.LastLatencyMs;
                Health.SuccessfulInferences++;
                Health.AverageLatencyMs = _totalLatencyMs / Math.Max(1, Health.SuccessfulInferences);
                var successTimestamp = Stopwatch.GetTimestamp();
                if (_lastSuccessTimestamp > 0 && successTimestamp > _lastSuccessTimestamp)
                {
                    Health.LastInferenceFps = (double)Stopwatch.Frequency / (successTimestamp - _lastSuccessTimestamp);
                }
                _lastSuccessTimestamp = successTimestamp;
                _consecutiveFailures = 0;
                Health.State = VisionDetectorRuntimeState.Ready;
                Health.LastErrorCode = string.Empty;
                Health.LastErrorMessage = string.Empty;
                return result;
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                Health.TimedOutInferences++;
                RegisterFailure("inference_timeout", "Detector inference was cancelled by the runtime deadline.");
                await TryRecoverAsync(cancellationToken).ConfigureAwait(false);
                return Array.Empty<VisionDetection>();
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                Health.FailedInferences++;
                RegisterFailure("inference_failed", ex.Message);
                await TryRecoverAsync(cancellationToken).ConfigureAwait(false);
                return Array.Empty<VisionDetection>();
            }
            finally
            {
                if (releaseGate) _inferenceGate.Release();
            }
        }

        private async Task DrainTimedOutInferenceAndRecoverAsync(Task<IReadOnlyList<VisionDetection>> detectTask)
        {
            try
            {
                await detectTask.ConfigureAwait(false);
            }
            catch
            {
                // The timed-out result is intentionally discarded. Its only purpose here is to
                // ensure the underlying detector call has physically exited before restart.
            }
            finally
            {
                _inferenceGate.Release();
            }

            try
            {
                await TryRecoverAsync(CancellationToken.None).ConfigureAwait(false);
            }
            catch
            {
                // TryRecoverAsync records explicit runtime health on restart failures.
            }
        }

        private void RegisterFailure(string code, string message)
        {
            _consecutiveFailures++;
            Health.LastErrorCode = code;
            Health.LastErrorMessage = message ?? string.Empty;
            Health.State = _consecutiveFailures >= _options.ConsecutiveFailuresBeforeFailed
                ? VisionDetectorRuntimeState.Failed
                : VisionDetectorRuntimeState.Degraded;
        }

        private async Task TryRecoverAsync(CancellationToken cancellationToken)
        {
            if (_consecutiveFailures < _options.ConsecutiveFailuresBeforeRestart) return;
            if (!(_inner is IVisionDetectorRuntimeControl control)) return;

            Health.State = VisionDetectorRuntimeState.Recovering;
            Health.RestartAttempts++;
            try
            {
                await control.RestartAsync(cancellationToken).ConfigureAwait(false);
                await control.WarmupAsync(cancellationToken).ConfigureAwait(false);
                Health.RestartSuccesses++;
                _consecutiveFailures = 0;
                Health.State = VisionDetectorRuntimeState.Ready;
                Health.LastErrorCode = string.Empty;
                Health.LastErrorMessage = string.Empty;
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                Health.FailedInferences++;
                Health.LastErrorCode = "detector_restart_failed";
                Health.LastErrorMessage = ex.Message;
                Health.State = VisionDetectorRuntimeState.Failed;
            }
        }
    }
}
