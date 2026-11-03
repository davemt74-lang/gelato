using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public sealed class ArWorkflowCoordinator
    {
        private readonly IGlassesPlatform _platform;
        private readonly IGelatoGateway _gateway;
        private readonly IDeviceTokenStore _tokenStore;
        private readonly object _deviceCredentialGate = new object();
        private CancellationTokenSource _deviceCredentialLifetime = new CancellationTokenSource();
        private string? _deviceToken;
        private long _deviceCredentialVersion;

        public IGlassesPlatform Platform => _platform;
        public bool IsPaired => !string.IsNullOrWhiteSpace(_deviceToken);
        public long DeviceCredentialVersion => Volatile.Read(ref _deviceCredentialVersion);
        public WorkflowState State { get; private set; } = WorkflowState.Uninitialized;
        public CurrentWork? CurrentWork { get; private set; }
        public StationCalibration? StationCalibration { get; private set; }
        public VisionLabelProfile? VisionLabelProfile { get; private set; }
        public VisionModelAssignment? VisionModelAssignment { get; private set; }
        public BuildSession? BuildSession { get; private set; }
        public ProductValidation? Validation { get; private set; }
        public ExpoHandoff? Handoff { get; private set; }
        public string? LastError { get; private set; }
        public string? LastCalibrationError { get; private set; }
        public string? LastVisionProfileError { get; private set; }
        public string? LastVisionModelError { get; private set; }
        public string? LastSubmittedObservationKey { get; private set; }
        public ReviewFeedback? ReviewFeedback { get; private set; }

        public ArWorkflowCoordinator(IGlassesPlatform platform, IGelatoGateway gateway, IDeviceTokenStore tokenStore)
        {
            _platform = platform ?? throw new ArgumentNullException(nameof(platform));
            _gateway = gateway ?? throw new ArgumentNullException(nameof(gateway));
            _tokenStore = tokenStore ?? throw new ArgumentNullException(nameof(tokenStore));
        }

        public async Task InitializeAsync(CancellationToken cancellationToken = default)
        {
            await GuardAsync(async () =>
            {
                await _platform.InitializeAsync(cancellationToken).ConfigureAwait(false);
                SetDeviceCredential(_tokenStore.Load());
                State = IsPaired ? WorkflowState.Idle : WorkflowState.Unpaired;
            }).ConfigureAwait(false);
        }

        public async Task PairAsync(string pairingCode, DeviceDescriptor device, CancellationToken cancellationToken = default)
        {
            if (string.IsNullOrWhiteSpace(pairingCode)) throw new ArgumentException("Pairing code is required.", nameof(pairingCode));
            if (device == null) throw new ArgumentNullException(nameof(device));

            State = WorkflowState.Pairing;
            await GuardAsync(async () =>
            {
                var result = await _gateway.PairAsync(pairingCode.Trim(), device, cancellationToken).ConfigureAwait(false);
                if (string.IsNullOrWhiteSpace(result.DeviceToken)) throw new InvalidOperationException("Gelato did not return a device token.");

                SetDeviceCredential(result.DeviceToken);
                _tokenStore.Save(result.DeviceToken);
                State = WorkflowState.Idle;
            }).ConfigureAwait(false);
        }

        public CancellationTokenSource LinkDeviceOperation(CancellationToken lifetime, out long credentialVersion)
        {
            lock (_deviceCredentialGate)
            {
                credentialVersion = DeviceCredentialVersion;
                return CancellationTokenSource.CreateLinkedTokenSource(lifetime, _deviceCredentialLifetime.Token);
            }
        }

        private void SetDeviceCredential(string? token)
        {
            lock (_deviceCredentialGate)
            {
                // Stop previous work before making the new bearer available to it.
                _deviceCredentialLifetime.Cancel();
                _deviceCredentialLifetime.Dispose();
                _deviceCredentialLifetime = new CancellationTokenSource();
                _deviceToken = token;
                _gateway.SetDeviceToken(token ?? string.Empty);
                CurrentWork = null;
                ResetBuild();
                ReviewFeedback = null;
                Interlocked.Increment(ref _deviceCredentialVersion);
            }
        }

        private void ResetBuild()
        {
            BuildSession = null;
            StationCalibration = null;
            VisionLabelProfile = null;
            VisionModelAssignment = null;
            LastCalibrationError = null;
            LastVisionProfileError = null;
            LastVisionModelError = null;
            LastSubmittedObservationKey = null;
            Validation = null;
            Handoff = null;
        }

        private T PublishDeviceConfiguration<T>(long credentialVersion, CancellationToken token, Func<T> publish)
        {
            lock (_deviceCredentialGate)
            {
                token.ThrowIfCancellationRequested();
                if (credentialVersion != DeviceCredentialVersion)
                    throw new OperationCanceledException("The device credential changed.");
                return publish();
            }
        }

        public Task HeartbeatAsync(CancellationToken cancellationToken = default)
        {
            EnsurePaired();
            cancellationToken.ThrowIfCancellationRequested();
            if (!(_gateway is IGelatoHeartbeatGateway heartbeat))
                throw new NotSupportedException("The configured gateway has no authenticated heartbeat implementation.");
            return heartbeat.HeartbeatAsync(cancellationToken);
        }

        public async Task<CurrentWork> RefreshWorkAsync(CancellationToken cancellationToken = default)
        {
            EnsurePaired();
            State = WorkflowState.LoadingWork;
            ReviewFeedback = null;

            return await GuardAsync(async () =>
            {
                CurrentWork = await _gateway.GetCurrentWorkAsync(cancellationToken).ConfigureAwait(false);
                ResetBuild();

                State = CurrentWork.FocusItem != null && !CurrentWork.AssignmentRequired
                    ? WorkflowState.WorkReady
                    : WorkflowState.Idle;

                return CurrentWork;
            }).ConfigureAwait(false);
        }

        public async Task<StationCalibration?> RefreshStationCalibrationAsync(CameraFrame frame, CancellationToken cancellationToken = default)
        {
            EnsurePaired();
            if (frame == null) throw new ArgumentNullException(nameof(frame));
            if (frame.Width <= 0 || frame.Height <= 0 || string.IsNullOrWhiteSpace(frame.PixelFormat))
                throw new InvalidOperationException("A valid camera frame is required to load station calibration.");

            var credentialVersion = DeviceCredentialVersion;
            try
            {
                var calibration = await _gateway.GetStationCalibrationAsync(frame, cancellationToken).ConfigureAwait(false);
                return PublishDeviceConfiguration(credentialVersion, cancellationToken, () =>
                {
                    LastCalibrationError = null;
                    return StationCalibration = calibration;
                });
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return PublishDeviceConfiguration(credentialVersion, cancellationToken, () =>
                {
                    LastCalibrationError = ex.Message;
                    return StationCalibration = null;
                });
            }
        }

        public async Task<VisionLabelProfile?> RefreshVisionLabelProfileAsync(
            string detectorName,
            CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            if (string.IsNullOrWhiteSpace(detectorName))
                throw new ArgumentException("Vision detector name is required.", nameof(detectorName));

            var credentialVersion = DeviceCredentialVersion;
            try
            {
                var profile = await _gateway.GetVisionLabelProfileAsync(
                    BuildSession!.PublicId,
                    detectorName.Trim(),
                    cancellationToken
                ).ConfigureAwait(false);
                return PublishDeviceConfiguration(credentialVersion, cancellationToken, () =>
                {
                    LastVisionProfileError = null;
                    return VisionLabelProfile = profile;
                });
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return PublishDeviceConfiguration(credentialVersion, cancellationToken, () =>
                {
                    LastVisionProfileError = ex.Message;
                    return VisionLabelProfile = null;
                });
            }
        }

        public async Task<VisionModelAssignment?> RefreshVisionModelAssignmentAsync(
            string detectorName,
            CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            if (string.IsNullOrWhiteSpace(detectorName))
                throw new ArgumentException("Vision detector name is required.", nameof(detectorName));

            var credentialVersion = DeviceCredentialVersion;
            try
            {
                var assignment = await _gateway.GetVisionModelAssignmentAsync(
                    BuildSession!.PublicId,
                    detectorName.Trim(),
                    cancellationToken
                ).ConfigureAwait(false);
                return PublishDeviceConfiguration(credentialVersion, cancellationToken, () =>
                {
                    LastVisionModelError = null;
                    return VisionModelAssignment = assignment;
                });
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return PublishDeviceConfiguration(credentialVersion, cancellationToken, () =>
                {
                    LastVisionModelError = ex.Message;
                    return VisionModelAssignment = null;
                });
            }
        }

        public async Task<bool> ReportVisionModelAsync(
            VisionModelReport report,
            CancellationToken cancellationToken = default)
        {
            EnsurePaired();
            if (report == null) throw new ArgumentNullException(nameof(report));
            if (string.IsNullOrWhiteSpace(report.ReportKey))
                throw new ArgumentException("Vision model report key is required.", nameof(report));

            try
            {
                LastVisionModelError = null;
                await _gateway.ReportVisionModelAsync(report, cancellationToken).ConfigureAwait(false);
                return true;
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                LastVisionModelError = ex.Message;
                return false;
            }
        }

        public async Task<BuildSession> StartFocusBuildAsync(CancellationToken cancellationToken = default)
        {
            EnsurePaired();
            if (CurrentWork?.FocusItem == null) throw new InvalidOperationException("There is no focused KDS item to build.");

            State = WorkflowState.StartingBuild;
            ReviewFeedback = null;
            return await GuardAsync(async () =>
            {
                BuildSession = await _gateway.StartBuildAsync(
                    CurrentWork.FocusItem.KdsItemPublicId,
                    string.IsNullOrWhiteSpace(CurrentWork.Revision) ? null : CurrentWork.Revision,
                    cancellationToken
                ).ConfigureAwait(false);

                Validation = null;
                Handoff = null;
                LastSubmittedObservationKey = null;
                VisionLabelProfile = null;
                VisionModelAssignment = null;
                LastVisionProfileError = null;
                LastVisionModelError = null;
                _platform.StartTracking();
                State = WorkflowState.Building;
                return BuildSession;
            }).ConfigureAwait(false);
        }

        public async Task<ProductValidation> SubmitObservationAsync(IngredientObservation observation, CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            if (observation == null) throw new ArgumentNullException(nameof(observation));
            if (string.IsNullOrWhiteSpace(observation.ObservationKey)) throw new ArgumentException("Observation key is required.", nameof(observation));
            if (string.IsNullOrWhiteSpace(observation.ComponentKey)) throw new ArgumentException("Component key is required.", nameof(observation));
            ReviewFeedback = null;

            return await GuardAsync(async () =>
            {
                BuildSession = await _gateway.SubmitObservationAsync(BuildSession!.PublicId, observation, cancellationToken).ConfigureAwait(false);
                LastSubmittedObservationKey = observation.ObservationKey;
                Validation = await _gateway.EvaluateAsync(BuildSession.PublicId, cancellationToken).ConfigureAwait(false);
                State = IsReadyForFinishing(Validation) ? WorkflowState.ReadyForFinishing : WorkflowState.Building;
                return Validation;
            }).ConfigureAwait(false);
        }

        public async Task<ProductValidation> ConfirmComponentAsync(string componentKey, CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            ReviewFeedback = null;
            if (string.IsNullOrWhiteSpace(componentKey)) throw new ArgumentException("Component key is required.", nameof(componentKey));

            return await GuardAsync(async () =>
            {
                BuildSession = await _gateway.ConfirmComponentAsync(BuildSession!.PublicId, componentKey, cancellationToken).ConfigureAwait(false);
                Validation = await _gateway.EvaluateAsync(BuildSession.PublicId, cancellationToken).ConfigureAwait(false);
                State = IsReadyForFinishing(Validation) ? WorkflowState.ReadyForFinishing : WorkflowState.Building;
                return Validation;
            }).ConfigureAwait(false);
        }

        public async Task<ProductValidation> ResolveUnexpectedAsync(string componentKey, CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            ReviewFeedback = null;
            if (string.IsNullOrWhiteSpace(componentKey)) throw new ArgumentException("Component key is required.", nameof(componentKey));

            return await GuardAsync(async () =>
            {
                BuildSession = await _gateway.ResolveUnexpectedAsync(BuildSession!.PublicId, componentKey, cancellationToken).ConfigureAwait(false);
                Validation = await _gateway.EvaluateAsync(BuildSession.PublicId, cancellationToken).ConfigureAwait(false);
                State = IsReadyForFinishing(Validation) ? WorkflowState.ReadyForFinishing : WorkflowState.Building;
                return Validation;
            }).ConfigureAwait(false);
        }

        public async Task<ProductValidation> CorrectObservationAsync(ObservationCorrection correction, CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            ReviewFeedback = null;
            if (correction == null) throw new ArgumentNullException(nameof(correction));
            if (string.IsNullOrWhiteSpace(correction.ObservationKey)) throw new ArgumentException("Observation key is required.", nameof(correction));
            if (string.IsNullOrWhiteSpace(correction.CorrectionKey)) throw new ArgumentException("Correction key is required.", nameof(correction));
            if (!string.Equals(correction.Resolution, "reject", StringComparison.Ordinal)
                && !string.Equals(correction.Resolution, "replace", StringComparison.Ordinal))
                throw new ArgumentException("Correction resolution is invalid.", nameof(correction));

            return await GuardAsync(async () =>
            {
                BuildSession = await _gateway.CorrectObservationAsync(
                    BuildSession!.PublicId,
                    correction,
                    cancellationToken
                ).ConfigureAwait(false);
                Validation = await _gateway.EvaluateAsync(BuildSession.PublicId, cancellationToken).ConfigureAwait(false);
                State = IsReadyForFinishing(Validation) ? WorkflowState.ReadyForFinishing : WorkflowState.Building;
                return Validation;
            }).ConfigureAwait(false);
        }

        public Task<ProductValidation> RejectObservationAsync(
            string observationKey,
            string reason = "",
            CancellationToken cancellationToken = default)
        {
            if (string.IsNullOrWhiteSpace(observationKey))
                throw new ArgumentException("Observation key is required.", nameof(observationKey));

            return CorrectObservationAsync(new ObservationCorrection
            {
                CorrectionKey = "reject:" + observationKey.Trim(),
                ObservationKey = observationKey.Trim(),
                Resolution = "reject",
                Reason = reason ?? string.Empty
            }, cancellationToken);
        }

        public Task<ProductValidation> RejectLastObservationAsync(
            string reason = "",
            CancellationToken cancellationToken = default)
        {
            if (string.IsNullOrWhiteSpace(LastSubmittedObservationKey))
                throw new InvalidOperationException("There is no submitted observation to reject.");

            return RejectObservationAsync(LastSubmittedObservationKey, reason, cancellationToken);
        }

        public Task<IReadOnlyList<ObservationEvidence>> GetEvidenceAsync(
            int limit = 20,
            CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            return _gateway.GetEvidenceAsync(BuildSession!.PublicId, limit, cancellationToken);
        }

        public async Task<ProductValidation> EvaluateAsync(CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            ReviewFeedback = null;
            return await GuardAsync(async () =>
            {
                Validation = await _gateway.EvaluateAsync(BuildSession!.PublicId, cancellationToken).ConfigureAwait(false);
                State = IsReadyForFinishing(Validation) ? WorkflowState.ReadyForFinishing : WorkflowState.Building;
                return Validation;
            }).ConfigureAwait(false);
        }

        public async Task<ExpoHandoff> HandoffToExpoAsync(CancellationToken cancellationToken = default)
        {
            EnsureActiveBuild();
            ReviewFeedback = null;
            if (!IsReadyForFinishing(Validation))
                throw new InvalidOperationException("Product validation is not ready for Expo / Finishing.");

            State = WorkflowState.HandingOff;
            return await GuardAsync(async () =>
            {
                Handoff = await _gateway.HandoffExpoAsync(BuildSession!.PublicId, cancellationToken).ConfigureAwait(false);
                _platform.StopTracking();
                State = WorkflowState.HandedOff;
                return Handoff;
            }).ConfigureAwait(false);
        }

        public void SetReviewFeedback(ReviewFeedback? feedback)
        {
            ReviewFeedback = feedback;
        }

        public void ClearReviewFeedback()
        {
            ReviewFeedback = null;
        }

        public void ResetForNextWork()
        {
            CurrentWork = null;
            StationCalibration = null;
            VisionLabelProfile = null;
            VisionModelAssignment = null;
            BuildSession = null;
            Validation = null;
            Handoff = null;
            LastError = null;
            LastCalibrationError = null;
            LastVisionProfileError = null;
            LastVisionModelError = null;
            LastSubmittedObservationKey = null;
            ReviewFeedback = null;
            State = string.IsNullOrWhiteSpace(_deviceToken) ? WorkflowState.Unpaired : WorkflowState.Idle;
        }

        public static bool IsReadyForFinishing(ProductValidation? validation)
        {
            return validation != null
                && string.Equals(validation.Status, "ready_for_finishing", StringComparison.Ordinal)
                && string.Equals(validation.NextStage, "expo_finishing", StringComparison.Ordinal)
                && validation.Next.Available;
        }

        private void EnsurePaired()
        {
            if (string.IsNullOrWhiteSpace(_deviceToken))
                throw new InvalidOperationException("Glasses must be paired with Gelato first.");
        }

        private void EnsureActiveBuild()
        {
            EnsurePaired();
            if (BuildSession == null || !string.Equals(BuildSession.Status, "active", StringComparison.Ordinal))
                throw new InvalidOperationException("There is no active AR build session.");
        }

        private async Task GuardAsync(Func<Task> action)
        {
            try
            {
                LastError = null;
                await action().ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                LastError = ex.Message;
                State = WorkflowState.Error;
                throw;
            }
        }

        private async Task<T> GuardAsync<T>(Func<Task<T>> action)
        {
            try
            {
                LastError = null;
                return await action().ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                LastError = ex.Message;
                State = WorkflowState.Error;
                throw;
            }
        }
    }
}
