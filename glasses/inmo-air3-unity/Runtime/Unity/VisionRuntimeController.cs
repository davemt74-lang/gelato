using System;
using System.Threading;
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

        private VisionPipeline _pipeline;
        private CancellationTokenSource _lifetime;
        private float _nextInferenceAt;
        private bool _processing;
        private string _activeSession = string.Empty;
        private bool _stationCalibrationLoaded;
        private bool _visionProfileLoaded;
        private bool _visionModelAssignmentLoaded;
        private VisionModelActivationService _modelActivationService;

        public string DetectorName => _pipeline == null ? string.Empty : _pipeline.DetectorName;
        public int ActiveTrackCount => _pipeline == null ? 0 : _pipeline.ActiveTrackCount;

        private void Awake()
        {
            _lifetime = new CancellationTokenSource();
            if (detector == null)
            {
                Debug.LogWarning("Gelato AR vision detector is not assigned. Vision processing is disabled.");
                return;
            }

            _pipeline = new VisionPipeline(detector, new VisionPipelineOptions
            {
                MinimumConfidence = minimumConfidence,
                StableFramesRequired = stableFramesRequired,
                MaxMissingFrames = maxMissingFrames,
                AssociationIouThreshold = associationIouThreshold
            });

            if (modelRuntimeHost != null)
            {
                _modelActivationService = new VisionModelActivationService(
                    new UnityVisionModelArtifactFetcher(),
                    modelRuntimeHost,
                    async (report, token) =>
                    {
                        if (bootstrap == null || bootstrap.Coordinator == null) return false;
                        return await bootstrap.Coordinator.ReportVisionModelAsync(report, token);
                    }
                );
            }
        }

        private async void Update()
        {
            if (_processing || _pipeline == null || bootstrap == null || bootstrap.Coordinator == null) return;
            if (Time.unscaledTime < _nextInferenceAt) return;

            var coordinator = bootstrap.Coordinator;
            var build = coordinator.BuildSession;
            if (build == null || !string.Equals(build.Status, "active", StringComparison.Ordinal)) return;

            if (!string.Equals(_activeSession, build.PublicId, StringComparison.Ordinal))
            {
                _activeSession = build.PublicId;
                _pipeline.Reset(_activeSession);
                _stationCalibrationLoaded = false;
                _visionProfileLoaded = false;
                _visionModelAssignmentLoaded = false;
            }

            var frame = coordinator.Platform.TryGetLatestFrame();
            if (frame == null || frame.Data == null || frame.Data.Length == 0) return;

            _nextInferenceAt = Time.unscaledTime + (1f / Mathf.Max(1f, maximumInferenceFps));
            _processing = true;

            try
            {
                if (!_stationCalibrationLoaded)
                {
                    await coordinator.RefreshStationCalibrationAsync(frame, _lifetime.Token);
                    _stationCalibrationLoaded = true;
                    if (!string.IsNullOrWhiteSpace(coordinator.LastCalibrationError))
                        Debug.LogWarning("Gelato AR station calibration unavailable: " + coordinator.LastCalibrationError);
                }

                if (!_visionModelAssignmentLoaded)
                {
                    var assignment = await coordinator.RefreshVisionModelAssignmentAsync(_pipeline.DetectorName, _lifetime.Token);
                    _visionModelAssignmentLoaded = true;

                    if (!string.IsNullOrWhiteSpace(coordinator.LastVisionModelError))
                    {
                        Debug.LogWarning("Gelato AR vision model assignment unavailable; current detector remains active: " + coordinator.LastVisionModelError);
                    }
                    else if (assignment != null)
                    {
                        await coordinator.ReportVisionModelAsync(
                            VisionModelAssignmentPolicy.AssignmentSeenReport(assignment),
                            _lifetime.Token
                        );

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
                                var activation = await _modelActivationService.ApplyAsync(assignment, _lifetime.Token);
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
                    await coordinator.RefreshVisionLabelProfileAsync(_pipeline.DetectorName, _lifetime.Token);
                    _visionProfileLoaded = true;
                    if (!string.IsNullOrWhiteSpace(coordinator.LastVisionProfileError))
                        Debug.LogWarning("Gelato AR vision label profile unavailable; using recipe-name fallback: " + coordinator.LastVisionProfileError);
                }

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

                var observations = await _pipeline.ProcessAsync(frame, context, _lifetime.Token);
                foreach (var observation in observations)
                {
                    if (_lifetime.IsCancellationRequested) break;
                    if (hudBinder != null) hudBinder.ShowIngredientObservation(observation);
                    await coordinator.SubmitObservationAsync(observation, _lifetime.Token);
                }
            }
            catch (OperationCanceledException)
            {
                // Scene/application shutdown.
            }
            catch (Exception ex)
            {
                Debug.LogError("Gelato AR local vision pipeline failed: " + ex.Message);
            }
            finally
            {
                _processing = false;
            }
        }

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
