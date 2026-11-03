using System;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class VisionRuntimeController : MonoBehaviour
    {
        [SerializeField] private GelatoArBootstrap bootstrap;
        [SerializeField] private VisionDetectorBehaviour detector;
        [SerializeField] private ArHudRuntimeBinder hudBinder;
        [SerializeField] private VisionModelRuntimeHostBehaviour modelRuntimeHost;

        [Header("Inference")]
        [SerializeField, Range(1f, 30f)] private float maximumInferenceFps = 6f;
        [SerializeField, Range(0f, 1f)] private float minimumConfidence = 0.50f;
        [SerializeField, Range(1, 10)] private int stableFramesRequired = 2;
        [SerializeField, Range(1, 30)] private int maxMissingFrames = 3;
        [SerializeField, Range(0f, 1f)] private float associationIouThreshold = 0.25f;
        [SerializeField, Range(100, 5000)] private int inferenceTimeoutMs = 750;
        [SerializeField, Range(1, 10)] private int failuresBeforeRestart = 3;
        [SerializeField, Range(2, 20)] private int failuresBeforeFailed = 6;

        [Header("Network health")]
        [SerializeField, Range(0.1f, 60f)] private float heartbeatIntervalSeconds = 5f;
        [SerializeField, Range(0.1f, 120f)] private float heartbeatStaleAfterSeconds = 15f;
        [SerializeField, Range(0.1f, 300f)] private float modelDownloadTimeoutSeconds = 60f;

        private Task _heartbeatTask = Task.CompletedTask;
        private CancellationTokenSource _heartbeatCancellation;
        private ArWorkflowCoordinator _heartbeatCoordinator;
        private long _heartbeatCredentialVersion = -1;
        private bool? _heartbeatHealthy;
        private double? _lastHeartbeatSucceededAtMs;
        private long _heartbeatObservationVersion;
        private string _heartbeatError = string.Empty;
        private float _nextHeartbeatAt;

        private VisionPipeline _pipeline;
        private VisionDetectorRuntimeHarness _detectorRuntime;
        private DeviceRuntimeSupervisor _deviceRuntimeSupervisor;
        private DeviceRuntimeHealthSnapshot _deviceRuntimeHealth;
        private bool _detectorWarmupReady;
        private float _nextDetectorRecoveryRetryAt;
        private CancellationTokenSource _lifetime;
        private CancellationTokenSource _processingCancellation;
        private float _nextInferenceAt;
        private bool _processing;
        private string _activeSession = string.Empty;
        private bool _stationCalibrationLoaded;
        private bool _visionProfileLoaded;
        private float _nextVisionProfileRetryAt;
        private bool _visionModelAssignmentLoaded;
        private VisionModelActivationService _modelActivationService;
        private VisionModelRecoveryService _modelRecoveryService;
        private bool _modelRecoveryReady;
        private float _nextModelRecoveryRetryAt;

        public string DetectorName => _pipeline == null ? string.Empty : _pipeline.DetectorName;
        public int ActiveTrackCount => _pipeline == null ? 0 : _pipeline.ActiveTrackCount;
        public VisionDetectorRuntimeHealth DetectorHealth => _detectorRuntime?.Health;
        public DeviceRuntimeHealthSnapshot DeviceHealth => _deviceRuntimeHealth;

        private void Awake()
        {
            _lifetime = new CancellationTokenSource();
            if (detector == null)
            {
                Debug.LogWarning("Gelato AR vision detector is not assigned. Vision processing is disabled.");
                return;
            }

            _deviceRuntimeSupervisor = new DeviceRuntimeSupervisor(new DeviceRuntimeSupervisorOptions
            {
                HeartbeatStaleAfter = TimeSpan.FromSeconds(Math.Max(0.1f, Math.Min(120f, heartbeatStaleAfterSeconds)))
            });

            _detectorRuntime = new VisionDetectorRuntimeHarness(detector, new VisionDetectorRuntimeOptions
            {
                InferenceTimeout = TimeSpan.FromMilliseconds(Math.Max(100, inferenceTimeoutMs)),
                ConsecutiveFailuresBeforeRestart = Math.Max(1, failuresBeforeRestart),
                ConsecutiveFailuresBeforeFailed = Math.Max(failuresBeforeRestart, failuresBeforeFailed)
            });

            _pipeline = new VisionPipeline(_detectorRuntime, new VisionPipelineOptions
            {
                MinimumConfidence = minimumConfidence,
                StableFramesRequired = stableFramesRequired,
                MaxMissingFrames = maxMissingFrames,
                AssociationIouThreshold = associationIouThreshold
            });

            if (modelRuntimeHost != null)
            {
                var persistence = new UnityVisionModelRuntimePersistence(
                    modelRuntimeHost.DetectorName,
                    modelRuntimeHost.RuntimeType);
                _modelActivationService = new VisionModelActivationService(
                    new UnityVisionModelArtifactFetcher(TimeSpan.FromSeconds(
                        Math.Max(0.1f, Math.Min(300f, modelDownloadTimeoutSeconds)))),
                    modelRuntimeHost,
                    async (report, token) =>
                    {
                        if (bootstrap == null || bootstrap.Coordinator == null) return false;
                        return await bootstrap.Coordinator.ReportVisionModelAsync(report, token);
                    },
                    VisionModelActivationService.DefaultMaximumArtifactBytes,
                    persistence
                );
                _modelRecoveryService = new VisionModelRecoveryService(modelRuntimeHost, persistence);
            }
        }

        private async void Update()
        {
            try
            {
                await UpdateRuntimeAsync();
            }
            catch (OperationCanceledException)
            {
                // Scene/application shutdown or a replaced device credential.
            }
            catch (Exception ex)
            {
                Debug.LogError("Gelato AR local vision pipeline failed: " + ex.Message);
            }
        }

        private async Task UpdateRuntimeAsync()
        {
            if (_lifetime == null || bootstrap == null || bootstrap.Coordinator == null) return;
            var lifetimeToken = _lifetime.Token;
            var coordinator = bootstrap.Coordinator;

            // Probes are independent of inference/configuration work and health gates.
            // A lost connection must not disable the very probe that can restore it.
            RefreshHeartbeat(coordinator, lifetimeToken);
            if (_pipeline == null) return;
            RefreshDeviceHealth(coordinator, coordinator.Platform.TryGetLatestFrame());
            if (_processing || Time.unscaledTime < _nextInferenceAt) return;

            var build = coordinator.BuildSession;
            if (build == null || !string.Equals(build.Status, "active", StringComparison.Ordinal)) return;
            var processingCancellation = coordinator.LinkDeviceOperation(lifetimeToken, out var credentialVersion);
            _processingCancellation = processingCancellation;
            var token = processingCancellation.Token;
            _processing = true;
            try
            {
                // Hold the single-flight guard across warmup and recovery too.
                if (_detectorRuntime != null && (!_detectorWarmupReady || _detectorRuntime.Health.State == VisionDetectorRuntimeState.Failed))
                {
                    if (Time.unscaledTime < _nextDetectorRecoveryRetryAt) return;
                    try
                    {
                        await _detectorRuntime.WarmupAsync(token);
                        _detectorWarmupReady = true;
                        _nextDetectorRecoveryRetryAt = 0f;
                    }
                    catch (OperationCanceledException)
                    {
                        throw;
                    }
                    catch (Exception ex)
                    {
                        _detectorWarmupReady = false;
                        _nextDetectorRecoveryRetryAt = Time.unscaledTime + 5f;
                        Debug.LogWarning("Gelato AR detector runtime warmup/recovery failed; automated vision is held fail-closed: " + ex.Message);
                        return;
                    }
                }

                token.ThrowIfCancellationRequested();
                if (!IsCurrentWork(coordinator, build.PublicId, credentialVersion)) return;
                if (_modelRecoveryService != null && !_modelRecoveryReady)
                {
                    if (Time.unscaledTime < _nextModelRecoveryRetryAt) return;
                    var recovery = await _modelRecoveryService.ReconcileAsync(token);
                    if (!recovery.Ready)
                    {
                        _nextModelRecoveryRetryAt = Time.unscaledTime + 5f;
                        Debug.LogWarning("Gelato AR model runtime recovery is holding automated vision fail-closed: "
                            + recovery.ErrorCode + " " + recovery.Message);
                        return;
                    }
                    _modelRecoveryReady = true;
                    _nextModelRecoveryRetryAt = 0f;
                    if (recovery.Recovered)
                        Debug.Log("Gelato AR recovered the durable known-good model runtime after restart.");
                }

                token.ThrowIfCancellationRequested();
                if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;
                if (!string.Equals(_activeSession, build.PublicId, StringComparison.Ordinal))
                {
                    _activeSession = build.PublicId;
                    _pipeline.Reset(_activeSession);
                    _stationCalibrationLoaded = false;
                    _visionProfileLoaded = false;
                    _nextVisionProfileRetryAt = 0f;
                    _visionModelAssignmentLoaded = false;
                }

                var frame = coordinator.Platform.TryGetLatestFrame();
                RefreshDeviceHealth(coordinator, frame);
                if (!_deviceRuntimeHealth.CanProcessAutomatedVision) return;
                _nextInferenceAt = Time.unscaledTime + (1f / Mathf.Max(1f, maximumInferenceFps));
                if (!_stationCalibrationLoaded)
                {
                    await coordinator.RefreshStationCalibrationAsync(frame, token);
                    token.ThrowIfCancellationRequested();
                    if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;
                    _stationCalibrationLoaded = true;
                    if (!string.IsNullOrWhiteSpace(coordinator.LastCalibrationError))
                        Debug.LogWarning("Gelato AR station calibration unavailable: " + coordinator.LastCalibrationError);
                }

                if (!_visionModelAssignmentLoaded)
                {
                    var assignment = await coordinator.RefreshVisionModelAssignmentAsync(_pipeline.DetectorName, token);
                    token.ThrowIfCancellationRequested();
                    if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;
                    _visionModelAssignmentLoaded = true;

                    if (!string.IsNullOrWhiteSpace(coordinator.LastVisionModelError))
                    {
                        Debug.LogWarning("Gelato AR vision model assignment unavailable; current detector remains active: " + coordinator.LastVisionModelError);
                    }
                    else if (assignment != null)
                    {
                        await coordinator.ReportVisionModelAsync(
                            VisionModelAssignmentPolicy.AssignmentSeenReport(assignment),
                            token
                        );
                        token.ThrowIfCancellationRequested();
                        if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;

                        if (string.Equals(assignment.Action, "apply", StringComparison.Ordinal))
                        {
                            if (!VisionModelAssignmentPolicy.CanApply(assignment, _pipeline.DetectorName, out var reasons))
                            {
                                Debug.LogWarning("Gelato AR governed model assignment failed client policy: " + string.Join(",", reasons));
                            }
                            else if (_modelActivationService == null)
                            {
                                Debug.LogWarning("Gelato AR governed model assignment is valid, but no runtime host is configured. The current known-good detector remains active.");
                            }
                            else if (!string.Equals(modelRuntimeHost.DetectorName, _pipeline.DetectorName, StringComparison.Ordinal))
                            {
                                Debug.LogWarning("Gelato AR model runtime host detector does not match the active vision pipeline. The current known-good detector remains active.");
                            }
                            else
                            {
                                var activation = await _modelActivationService.ApplyAsync(assignment, token);
                                token.ThrowIfCancellationRequested();
                                if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;
                                if (activation.Activated)
                                {
                                    Debug.Log("Gelato AR verified model activated atomically: "
                                        + (activation.Package?.ModelName ?? "unknown") + " "
                                        + (activation.Package?.ModelVersion ?? string.Empty));
                                }
                                else
                                {
                                    Debug.LogWarning("Gelato AR model activation failed closed: "
                                        + activation.ErrorCode + " " + activation.Message
                                        + (activation.RestoredPrevious ? " Previous known-good runtime restored." : string.Empty));
                                }
                            }
                        }
                    }
                }

                if (!_visionProfileLoaded)
                {
                    if (Time.unscaledTime < _nextVisionProfileRetryAt) return;

                    var profile = await coordinator.RefreshVisionLabelProfileAsync(_pipeline.DetectorName, token);
                    token.ThrowIfCancellationRequested();
                    if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;
                    if (profile == null || !string.IsNullOrWhiteSpace(coordinator.LastVisionProfileError))
                    {
                        _nextVisionProfileRetryAt = Time.unscaledTime + 5f;
                        Debug.LogWarning("Gelato AR vision label profile unavailable; automated vision evidence is held fail-closed while the kitchen workflow remains available: "
                            + (coordinator.LastVisionProfileError ?? "profile_missing"));
                        return;
                    }

                    _visionProfileLoaded = true;
                    _nextVisionProfileRetryAt = 0f;
                }

                token.ThrowIfCancellationRequested();
                if (!CanContinue(coordinator, build.PublicId, credentialVersion)) return;
                // Configuration/model downloads may take time; use a current frame.
                frame = coordinator.Platform.TryGetLatestFrame();
                RefreshDeviceHealth(coordinator, frame);
                if (!_deviceRuntimeHealth.CanProcessAutomatedVision) return;

                var stationCalibration = StationCalibrationPolicy.CanUse(coordinator.StationCalibration, frame)
                    ? coordinator.StationCalibration
                    : null;

                var context = new VisionFrameContext
                {
                    BuildSessionPublicId = build.PublicId,
                    ExpectedComponents = build.Components,
                    BuildSteps = build.BuildSteps,
                    StationCalibration = stationCalibration,
                    VisionProfile = coordinator.VisionLabelProfile,
                    Calibration = coordinator.Platform.TryGetCameraCalibration(),
                    Pose = coordinator.Platform.GetPose()
                };

                var observations = await _pipeline.ProcessAsync(frame, context, token);
                if (_detectorRuntime != null && _detectorRuntime.Health.State == VisionDetectorRuntimeState.Failed)
                {
                    _detectorWarmupReady = false;
                    _nextDetectorRecoveryRetryAt = Time.unscaledTime + 1f;
                    Debug.LogWarning("Gelato AR detector runtime entered failed state; automated evidence is held until recovery.");
                    return;
                }
                foreach (var observation in observations)
                {
                    token.ThrowIfCancellationRequested();
                    if (!CanContinue(coordinator, build.PublicId, credentialVersion)) break;
                    if (hudBinder != null) hudBinder.ShowIngredientObservation(observation);
                    await coordinator.SubmitObservationAsync(observation, token);
                }
            }
            finally
            {
                if (ReferenceEquals(_processingCancellation, processingCancellation)) _processingCancellation = null;
                processingCancellation.Dispose();
                _processing = false;
            }
        }

        private bool CanContinue(ArWorkflowCoordinator coordinator, string buildPublicId, long credentialVersion)
        {
            if (!IsCurrentWork(coordinator, buildPublicId, credentialVersion)) return false;
            RefreshDeviceHealth(coordinator, coordinator.Platform.TryGetLatestFrame());
            return _deviceRuntimeHealth.CanProcessAutomatedVision;
        }

        private bool IsCurrentWork(ArWorkflowCoordinator coordinator, string buildPublicId, long credentialVersion)
        {
            if (bootstrap == null || !ReferenceEquals(bootstrap.Coordinator, coordinator)
                || coordinator.DeviceCredentialVersion != credentialVersion
                || coordinator.BuildSession == null
                || !string.Equals(coordinator.BuildSession.PublicId, buildPublicId, StringComparison.Ordinal)
                || !string.Equals(coordinator.BuildSession.Status, "active", StringComparison.Ordinal)) return false;
            return true;
        }

        private void RefreshDeviceHealth(ArWorkflowCoordinator coordinator, CameraFrame frame)
        {
            var previousCode = _deviceRuntimeHealth?.PrimaryCode;
            _deviceRuntimeHealth = _deviceRuntimeSupervisor.Evaluate(new DeviceRuntimeSignals
            {
                PlatformInitialized = coordinator.Platform.IsInitialized,
                CameraAvailable = frame != null && frame.Data != null && frame.Data.Length > 0,
                FrameTimestampNanoseconds = frame?.TimestampNanoseconds ?? 0,
                DetectorState = _detectorRuntime?.Health.State ?? VisionDetectorRuntimeState.Failed,
                ModelRuntimeReady = _modelRecoveryService == null || _modelRecoveryReady,
                HeartbeatHealthy = _heartbeatHealthy,
                LastHeartbeatSucceededAtMs = _lastHeartbeatSucceededAtMs,
                HeartbeatObservationVersion = _heartbeatObservationVersion,
                HeartbeatError = _heartbeatError,
                // No vendor thermal/memory adapter is present. Unknown is not healthy.
                ThermalState = "unknown",
                ResourceConstrained = null
            }, TimeSpan.FromSeconds(Math.Max(0f, Time.unscaledTime)));
            if (!_deviceRuntimeHealth.CanProcessAutomatedVision
                && !string.Equals(previousCode, _deviceRuntimeHealth.PrimaryCode, StringComparison.Ordinal))
                Debug.LogWarning("Gelato AR device runtime health is holding automated vision fail-closed: "
                    + _deviceRuntimeHealth.PrimaryCode + " " + _deviceRuntimeHealth.Message);
        }

        private void RefreshHeartbeat(ArWorkflowCoordinator coordinator, CancellationToken token)
        {
            if (!ReferenceEquals(_heartbeatCoordinator, coordinator)
                || _heartbeatCredentialVersion != coordinator.DeviceCredentialVersion)
            {
                _heartbeatCancellation?.Cancel();
                _processingCancellation?.Cancel();
                _heartbeatCoordinator = coordinator;
                _heartbeatCredentialVersion = coordinator.DeviceCredentialVersion;
                _heartbeatHealthy = null;
                _lastHeartbeatSucceededAtMs = null;
                _heartbeatError = string.Empty;
                _heartbeatObservationVersion++;
                _nextHeartbeatAt = 0f;
                _activeSession = string.Empty;
            }
            if (!coordinator.IsPaired || !_heartbeatTask.IsCompleted || Time.unscaledTime < _nextHeartbeatAt) return;
            var cancellation = coordinator.LinkDeviceOperation(token, out var credentialVersion);
            _heartbeatCancellation = cancellation;
            _heartbeatTask = ProbeHeartbeatAsync(coordinator, credentialVersion, cancellation);
        }

        private async Task ProbeHeartbeatAsync(ArWorkflowCoordinator coordinator, long credentialVersion,
            CancellationTokenSource cancellation)
        {
            var token = cancellation.Token;
            try
            {
                await coordinator.HeartbeatAsync(token);
                token.ThrowIfCancellationRequested();
                if (!IsCurrentHeartbeat(coordinator, credentialVersion)) return;
                _heartbeatHealthy = true;
                _lastHeartbeatSucceededAtMs = TimeSpan.FromSeconds(Math.Max(0f, Time.unscaledTime)).TotalMilliseconds;
                _heartbeatError = string.Empty;
                _heartbeatObservationVersion++;
            }
            catch (OperationCanceledException) when (token.IsCancellationRequested)
            {
                // Shutdown/credential replacement is not an observed network failure.
            }
            catch (Exception ex)
            {
                if (!IsCurrentHeartbeat(coordinator, credentialVersion)) return;
                _heartbeatHealthy = false;
                _heartbeatError = "Authenticated device heartbeat failed: " + ex.Message;
                _heartbeatObservationVersion++;
            }
            finally
            {
                if (IsCurrentHeartbeat(coordinator, credentialVersion))
                    _nextHeartbeatAt = Time.unscaledTime + Math.Max(0.1f, Math.Min(60f, heartbeatIntervalSeconds));
                if (ReferenceEquals(_heartbeatCancellation, cancellation)) _heartbeatCancellation = null;
                cancellation.Dispose();
            }
        }

        private bool IsCurrentHeartbeat(ArWorkflowCoordinator coordinator, long credentialVersion) =>
            _lifetime != null && !_lifetime.IsCancellationRequested
            && ReferenceEquals(_heartbeatCoordinator, coordinator)
            && coordinator.DeviceCredentialVersion == credentialVersion
            && _heartbeatCredentialVersion == credentialVersion;

        private void OnDestroy()
        {
            if (_lifetime != null)
            {
                _lifetime.Cancel();
                _lifetime.Dispose();
                _lifetime = null;
            }
        }
    }
}
