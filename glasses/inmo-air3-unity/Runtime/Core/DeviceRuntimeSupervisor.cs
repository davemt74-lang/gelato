using System;
using System.Collections.Generic;

namespace Gelato.Ar.Core
{
    public enum DeviceRuntimeOperationalState
    {
        Starting,
        Ready,
        Degraded,
        Recovering,
        Failed
    }

    public enum DeviceRuntimeIncidentSeverity
    {
        Info,
        Warning,
        Critical
    }

    public sealed class DeviceRuntimeSignals
    {
        public bool PlatformInitialized { get; set; }
        public bool CameraAvailable { get; set; }
        public long FrameTimestampNanoseconds { get; set; }
        public VisionDetectorRuntimeState DetectorState { get; set; } = VisionDetectorRuntimeState.Starting;
        public bool ModelRuntimeReady { get; set; }
        public bool HeartbeatHealthy { get; set; } = true;
        public string HeartbeatError { get; set; } = string.Empty;
        public string ThermalState { get; set; } = "unknown";
        public bool ResourceConstrained { get; set; }
    }

    public sealed class DeviceRuntimeIncident
    {
        public long Sequence { get; internal set; }
        public DeviceRuntimeIncidentSeverity Severity { get; internal set; }
        public DeviceRuntimeOperationalState State { get; internal set; }
        public string Code { get; internal set; } = string.Empty;
        public string Message { get; internal set; } = string.Empty;
        public double OccurredAtMs { get; internal set; }
    }

    public sealed class DeviceRuntimeTelemetry
    {
        public long Evaluations { get; internal set; }
        public long ReadyEvaluations { get; internal set; }
        public long FailClosedEvaluations { get; internal set; }
        public long FrameSamples { get; internal set; }
        public long DistinctFrames { get; internal set; }
        public long StaleFrameBlocks { get; internal set; }
        public long HeartbeatFailures { get; internal set; }
        public long RecoveryAttempts { get; internal set; }
        public long RecoverySuccesses { get; internal set; }
        public long RecoveryFailures { get; internal set; }
        public long StateTransitions { get; internal set; }
    }

    public sealed class DeviceRuntimeHealthSnapshot
    {
        public DeviceRuntimeOperationalState State { get; internal set; }
        public bool CanProcessAutomatedVision { get; internal set; }
        public string PrimaryCode { get; internal set; } = string.Empty;
        public string Message { get; internal set; } = string.Empty;
        public long LastFrameTimestampNanoseconds { get; internal set; }
        public double LastDistinctFrameAtMs { get; internal set; }
        public double LastHeartbeatAtMs { get; internal set; }
        public int ConsecutiveRecoveryFailures { get; internal set; }
        public IReadOnlyList<DeviceRuntimeIncident> Incidents { get; internal set; } = Array.Empty<DeviceRuntimeIncident>();
        public DeviceRuntimeTelemetry Telemetry { get; internal set; } = new DeviceRuntimeTelemetry();
    }

    public sealed class DeviceRuntimeSupervisorOptions
    {
        public TimeSpan CameraStaleAfter { get; set; } = TimeSpan.FromSeconds(2);
        public TimeSpan HeartbeatStaleAfter { get; set; } = TimeSpan.FromSeconds(15);
        public TimeSpan RecoveryCooldown { get; set; } = TimeSpan.FromSeconds(5);
        public int MaxConsecutiveRecoveryFailures { get; set; } = 3;
        public int IncidentCapacity { get; set; } = 64;

        public void Validate()
        {
            if (CameraStaleAfter <= TimeSpan.Zero) throw new InvalidOperationException("Camera stale threshold must be positive.");
            if (HeartbeatStaleAfter <= TimeSpan.Zero) throw new InvalidOperationException("Heartbeat stale threshold must be positive.");
            if (RecoveryCooldown < TimeSpan.Zero) throw new InvalidOperationException("Recovery cooldown cannot be negative.");
            if (MaxConsecutiveRecoveryFailures < 1) throw new InvalidOperationException("Recovery failure budget must be at least one.");
            if (IncidentCapacity < 1) throw new InvalidOperationException("Incident capacity must be at least one.");
        }
    }

    public sealed class DeviceRuntimeSupervisor
    {
        private readonly DeviceRuntimeSupervisorOptions _options;
        private readonly List<DeviceRuntimeIncident> _incidents = new List<DeviceRuntimeIncident>();
        private readonly DeviceRuntimeTelemetry _telemetry = new DeviceRuntimeTelemetry();
        private DeviceRuntimeOperationalState _state = DeviceRuntimeOperationalState.Starting;
        private long _lastFrameTimestamp;
        private double _lastDistinctFrameAtMs = -1d;
        private double _lastHeartbeatAtMs = -1d;
        private bool _heartbeatSeen;
        private double _lastRecoveryAttemptAtMs = double.NegativeInfinity;
        private int _consecutiveRecoveryFailures;
        private long _incidentSequence;
        private string _lastIncidentSignature = string.Empty;
        private string _primaryCode = "starting";
        private string _message = "Device runtime is starting.";

        public DeviceRuntimeSupervisor(DeviceRuntimeSupervisorOptions? options = null)
        {
            _options = options ?? new DeviceRuntimeSupervisorOptions();
            _options.Validate();
        }

        public DeviceRuntimeOperationalState State => _state;
        public bool CanProcessAutomatedVision => _state == DeviceRuntimeOperationalState.Ready;

        public DeviceRuntimeHealthSnapshot Evaluate(DeviceRuntimeSignals signals, TimeSpan monotonicNow)
        {
            if (signals == null) throw new ArgumentNullException(nameof(signals));
            var nowMs = MonotonicMilliseconds(monotonicNow);
            _telemetry.Evaluations++;

            ObserveFrame(signals, nowMs);
            ObserveHeartbeat(signals, nowMs);

            var state = DeviceRuntimeOperationalState.Ready;
            var code = "ready";
            var message = "Device runtime is healthy.";

            if (!signals.PlatformInitialized)
            {
                state = DeviceRuntimeOperationalState.Failed;
                code = "platform_unavailable";
                message = "Glasses platform is not initialized.";
            }
            else if (IsCriticalThermal(signals.ThermalState))
            {
                state = DeviceRuntimeOperationalState.Failed;
                code = "thermal_critical";
                message = "Device thermal state is critical.";
            }
            else if (_consecutiveRecoveryFailures >= _options.MaxConsecutiveRecoveryFailures)
            {
                state = DeviceRuntimeOperationalState.Failed;
                code = "recovery_budget_exhausted";
                message = "Device runtime recovery failure budget is exhausted.";
            }
            else if (signals.DetectorState == VisionDetectorRuntimeState.Failed)
            {
                state = DeviceRuntimeOperationalState.Failed;
                code = "detector_failed";
                message = "Detector runtime is failed.";
            }
            else if (!signals.CameraAvailable)
            {
                state = DeviceRuntimeOperationalState.Degraded;
                code = "camera_unavailable";
                message = "Camera frame is unavailable.";
            }
            else if (IsFrameStale(nowMs))
            {
                state = DeviceRuntimeOperationalState.Degraded;
                code = "camera_stale";
                message = "Camera is returning a stale frame.";
                _telemetry.StaleFrameBlocks++;
            }
            else if (!signals.ModelRuntimeReady)
            {
                state = DeviceRuntimeOperationalState.Recovering;
                code = "model_runtime_recovering";
                message = "Known-good model runtime is not ready.";
            }
            else if (signals.DetectorState == VisionDetectorRuntimeState.Recovering ||
                     signals.DetectorState == VisionDetectorRuntimeState.Starting)
            {
                state = DeviceRuntimeOperationalState.Recovering;
                code = "detector_recovering";
                message = "Detector runtime is starting or recovering.";
            }
            else if (signals.DetectorState == VisionDetectorRuntimeState.Degraded)
            {
                state = DeviceRuntimeOperationalState.Degraded;
                code = "detector_degraded";
                message = "Detector runtime is degraded.";
            }
            else if (!signals.HeartbeatHealthy || IsHeartbeatStale(nowMs))
            {
                state = DeviceRuntimeOperationalState.Degraded;
                code = !signals.HeartbeatHealthy ? "heartbeat_failed" : "heartbeat_stale";
                message = string.IsNullOrWhiteSpace(signals.HeartbeatError)
                    ? "Device heartbeat is unhealthy."
                    : signals.HeartbeatError;
            }
            else if (signals.ResourceConstrained)
            {
                state = DeviceRuntimeOperationalState.Degraded;
                code = "resource_constrained";
                message = "Device runtime is resource constrained.";
            }
            else if (IsHotThermal(signals.ThermalState))
            {
                state = DeviceRuntimeOperationalState.Degraded;
                code = "thermal_hot";
                message = "Device thermal state is hot.";
            }

            ApplyState(state, code, message, nowMs);
            if (state == DeviceRuntimeOperationalState.Ready) _telemetry.ReadyEvaluations++;
            else _telemetry.FailClosedEvaluations++;
            return Snapshot();
        }

        public bool ShouldAttemptRecovery(TimeSpan monotonicNow)
        {
            if (_state == DeviceRuntimeOperationalState.Ready) return false;
            if (_consecutiveRecoveryFailures >= _options.MaxConsecutiveRecoveryFailures) return false;
            return MonotonicMilliseconds(monotonicNow) - _lastRecoveryAttemptAtMs >= _options.RecoveryCooldown.TotalMilliseconds;
        }

        public void RecordRecoveryAttempt(TimeSpan monotonicNow, string reason)
        {
            _lastRecoveryAttemptAtMs = MonotonicMilliseconds(monotonicNow);
            _telemetry.RecoveryAttempts++;
            ApplyState(DeviceRuntimeOperationalState.Recovering, "recovery_attempt",
                string.IsNullOrWhiteSpace(reason) ? "Device runtime recovery started." : reason,
                _lastRecoveryAttemptAtMs);
        }

        public void RecordRecoveryResult(TimeSpan monotonicNow, bool success, string errorCode = "", string message = "")
        {
            var nowMs = MonotonicMilliseconds(monotonicNow);
            if (success)
            {
                _telemetry.RecoverySuccesses++;
                _consecutiveRecoveryFailures = 0;
                AddIncident(DeviceRuntimeIncidentSeverity.Info, DeviceRuntimeOperationalState.Recovering,
                    "recovery_succeeded", "Device runtime recovery completed.", nowMs);
                return;
            }

            _telemetry.RecoveryFailures++;
            _consecutiveRecoveryFailures++;
            var exhausted = _consecutiveRecoveryFailures >= _options.MaxConsecutiveRecoveryFailures;
            ApplyState(
                exhausted ? DeviceRuntimeOperationalState.Failed : DeviceRuntimeOperationalState.Degraded,
                exhausted ? "recovery_budget_exhausted" : (string.IsNullOrWhiteSpace(errorCode) ? "recovery_failed" : errorCode),
                string.IsNullOrWhiteSpace(message) ? "Device runtime recovery failed." : message,
                nowMs);
        }

        private void ObserveFrame(DeviceRuntimeSignals signals, double nowMs)
        {
            if (!signals.CameraAvailable) return;
            _telemetry.FrameSamples++;
            if (signals.FrameTimestampNanoseconds <= 0) return;
            if (signals.FrameTimestampNanoseconds == _lastFrameTimestamp) return;
            _lastFrameTimestamp = signals.FrameTimestampNanoseconds;
            _lastDistinctFrameAtMs = nowMs;
            _telemetry.DistinctFrames++;
        }

        private void ObserveHeartbeat(DeviceRuntimeSignals signals, double nowMs)
        {
            if (signals.HeartbeatHealthy)
            {
                _heartbeatSeen = true;
                _lastHeartbeatAtMs = nowMs;
                return;
            }
            _telemetry.HeartbeatFailures++;
        }

        private bool IsFrameStale(double nowMs)
        {
            return _lastDistinctFrameAtMs >= 0d &&
                   nowMs - _lastDistinctFrameAtMs > _options.CameraStaleAfter.TotalMilliseconds;
        }

        private bool IsHeartbeatStale(double nowMs)
        {
            return _heartbeatSeen &&
                   nowMs - _lastHeartbeatAtMs > _options.HeartbeatStaleAfter.TotalMilliseconds;
        }

        private void ApplyState(DeviceRuntimeOperationalState state, string code, string message, double nowMs)
        {
            var changed = state != _state || !string.Equals(code, _primaryCode, StringComparison.Ordinal);
            if (state != _state) _telemetry.StateTransitions++;
            _state = state;
            _primaryCode = code ?? string.Empty;
            _message = message ?? string.Empty;
            if (!changed) return;

            var severity = state == DeviceRuntimeOperationalState.Failed
                ? DeviceRuntimeIncidentSeverity.Critical
                : state == DeviceRuntimeOperationalState.Ready
                    ? DeviceRuntimeIncidentSeverity.Info
                    : DeviceRuntimeIncidentSeverity.Warning;
            AddIncident(severity, state, _primaryCode, _message, nowMs);
        }

        private void AddIncident(DeviceRuntimeIncidentSeverity severity, DeviceRuntimeOperationalState state, string code, string message, double nowMs)
        {
            var signature = state + "|" + code + "|" + message;
            if (string.Equals(signature, _lastIncidentSignature, StringComparison.Ordinal)) return;
            _lastIncidentSignature = signature;
            _incidents.Add(new DeviceRuntimeIncident
            {
                Sequence = ++_incidentSequence,
                Severity = severity,
                State = state,
                Code = code ?? string.Empty,
                Message = message ?? string.Empty,
                OccurredAtMs = nowMs
            });
            while (_incidents.Count > _options.IncidentCapacity) _incidents.RemoveAt(0);
        }

        private DeviceRuntimeHealthSnapshot Snapshot()
        {
            var incidents = new List<DeviceRuntimeIncident>(_incidents.Count);
            foreach (var incident in _incidents)
            {
                incidents.Add(new DeviceRuntimeIncident
                {
                    Sequence = incident.Sequence,
                    Severity = incident.Severity,
                    State = incident.State,
                    Code = incident.Code,
                    Message = incident.Message,
                    OccurredAtMs = incident.OccurredAtMs
                });
            }

            return new DeviceRuntimeHealthSnapshot
            {
                State = _state,
                CanProcessAutomatedVision = CanProcessAutomatedVision,
                PrimaryCode = _primaryCode,
                Message = _message,
                LastFrameTimestampNanoseconds = _lastFrameTimestamp,
                LastDistinctFrameAtMs = _lastDistinctFrameAtMs,
                LastHeartbeatAtMs = _lastHeartbeatAtMs,
                ConsecutiveRecoveryFailures = _consecutiveRecoveryFailures,
                Incidents = incidents,
                Telemetry = new DeviceRuntimeTelemetry
                {
                    Evaluations = _telemetry.Evaluations,
                    ReadyEvaluations = _telemetry.ReadyEvaluations,
                    FailClosedEvaluations = _telemetry.FailClosedEvaluations,
                    FrameSamples = _telemetry.FrameSamples,
                    DistinctFrames = _telemetry.DistinctFrames,
                    StaleFrameBlocks = _telemetry.StaleFrameBlocks,
                    HeartbeatFailures = _telemetry.HeartbeatFailures,
                    RecoveryAttempts = _telemetry.RecoveryAttempts,
                    RecoverySuccesses = _telemetry.RecoverySuccesses,
                    RecoveryFailures = _telemetry.RecoveryFailures,
                    StateTransitions = _telemetry.StateTransitions
                }
            };
        }

        private static bool IsCriticalThermal(string value) =>
            string.Equals(value, "critical", StringComparison.OrdinalIgnoreCase);

        private static bool IsHotThermal(string value) =>
            string.Equals(value, "hot", StringComparison.OrdinalIgnoreCase);

        private static double MonotonicMilliseconds(TimeSpan value) => Math.Max(0d, value.TotalMilliseconds);
    }
}
