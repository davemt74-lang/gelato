using System;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public sealed class VisionModelPendingActivation
    {
        public string AssignmentKey { get; set; } = string.Empty;
        public string PackagePublicId { get; set; } = string.Empty;
        public string ArtifactSha256 { get; set; } = string.Empty;
        public string RuntimeType { get; set; } = string.Empty;
        public string Stage { get; set; } = string.Empty;
        public long ArtifactBytes { get; set; }
        public string ErrorCode { get; set; } = string.Empty;
        public string Message { get; set; } = string.Empty;
    }

    public sealed class VisionModelDurableState
    {
        public int SchemaVersion { get; set; } = 1;
        public string DetectorName { get; set; } = string.Empty;
        public string RuntimeType { get; set; } = string.Empty;
        public string ActivePackagePublicId { get; set; } = string.Empty;
        public string ActiveArtifactSha256 { get; set; } = string.Empty;
        public string KnownGoodPackagePublicId { get; set; } = string.Empty;
        public string KnownGoodArtifactSha256 { get; set; } = string.Empty;
        public string LastErrorCode { get; set; } = string.Empty;
        public string LastErrorMessage { get; set; } = string.Empty;
        public VisionModelPendingActivation? Pending { get; set; }
    }

    public interface IVisionModelRuntimePersistence
    {
        Task<VisionModelDurableState> LoadAsync(CancellationToken cancellationToken);
        Task BeginActivationAsync(
            VisionModelAssignment assignment,
            VisionModelRuntimeSnapshot current,
            CancellationToken cancellationToken);
        Task StageVerifiedAsync(
            VisionModelAssignment assignment,
            byte[] verifiedArtifact,
            string artifactSha256,
            CancellationToken cancellationToken);
        Task MarkPreparedAsync(
            VisionModelAssignment assignment,
            CancellationToken cancellationToken);
        Task CommitActiveAsync(
            VisionModelAssignment assignment,
            VisionModelRuntimeSnapshot previous,
            CancellationToken cancellationToken);
        Task CommitRestoredAsync(
            VisionModelRuntimeSnapshot restored,
            string errorCode,
            string message,
            CancellationToken cancellationToken);
        Task MarkFailedAsync(
            VisionModelAssignment assignment,
            string errorCode,
            string message,
            CancellationToken cancellationToken);
        Task<byte[]?> TryReadVerifiedArtifactAsync(
            string packagePublicId,
            string artifactSha256,
            CancellationToken cancellationToken);
        Task CleanupAsync(CancellationToken cancellationToken);
    }

    public interface IVisionModelRecoverableRuntimeHost
    {
        Task RecoverAsync(
            VisionModelRuntimeSnapshot durableKnownGood,
            byte[] verifiedArtifact,
            CancellationToken cancellationToken);
    }

    public sealed class VisionModelRecoveryResult
    {
        public bool Ready { get; set; }
        public bool Recovered { get; set; }
        public bool InterruptedActivationFound { get; set; }
        public string State { get; set; } = string.Empty;
        public string ErrorCode { get; set; } = string.Empty;
        public string Message { get; set; } = string.Empty;
    }

    public sealed class VisionModelRecoveryService
    {
        private readonly IVisionModelRuntimeHost _runtime;
        private readonly IVisionModelRuntimePersistence _persistence;

        public VisionModelRecoveryService(
            IVisionModelRuntimeHost runtime,
            IVisionModelRuntimePersistence persistence)
        {
            _runtime = runtime ?? throw new ArgumentNullException(nameof(runtime));
            _persistence = persistence ?? throw new ArgumentNullException(nameof(persistence));
        }

        public async Task<VisionModelRecoveryResult> ReconcileAsync(
            CancellationToken cancellationToken = default)
        {
            var state = await _persistence.LoadAsync(cancellationToken).ConfigureAwait(false)
                ?? new VisionModelDurableState();

            if (!string.IsNullOrWhiteSpace(state.DetectorName)
                && !string.Equals(state.DetectorName, _runtime.DetectorName, StringComparison.Ordinal))
            {
                return Fail("durable_detector_mismatch", "Durable model state belongs to another detector.", state.Pending != null);
            }

            if (!string.IsNullOrWhiteSpace(state.RuntimeType)
                && !string.Equals(state.RuntimeType, _runtime.RuntimeType, StringComparison.OrdinalIgnoreCase))
            {
                return Fail("durable_runtime_mismatch", "Durable model state belongs to another runtime type.", state.Pending != null);
            }

            var current = _runtime.CaptureActive();
            var expectedPackage = !string.IsNullOrWhiteSpace(state.ActivePackagePublicId)
                ? state.ActivePackagePublicId
                : state.KnownGoodPackagePublicId;
            var expectedSha = !string.IsNullOrWhiteSpace(state.ActiveArtifactSha256)
                ? state.ActiveArtifactSha256
                : state.KnownGoodArtifactSha256;

            if (string.IsNullOrWhiteSpace(expectedPackage) || string.IsNullOrWhiteSpace(expectedSha))
            {
                await _persistence.CleanupAsync(cancellationToken).ConfigureAwait(false);
                return new VisionModelRecoveryResult
                {
                    Ready = true,
                    Recovered = false,
                    InterruptedActivationFound = state.Pending != null,
                    State = state.Pending != null ? "interrupted_without_known_good" : "empty"
                };
            }

            if (Matches(current, expectedPackage, expectedSha))
            {
                if (state.Pending != null)
                {
                    await _persistence.CommitRestoredAsync(
                        current,
                        "restart_interrupted_activation",
                        "Recovered durable active runtime after interrupted activation.",
                        cancellationToken).ConfigureAwait(false);
                }
                await _persistence.CleanupAsync(cancellationToken).ConfigureAwait(false);
                return new VisionModelRecoveryResult
                {
                    Ready = true,
                    Recovered = state.Pending != null,
                    InterruptedActivationFound = state.Pending != null,
                    State = "current_matches_durable"
                };
            }

            if (!(_runtime is IVisionModelRecoverableRuntimeHost recoverable))
            {
                return Fail(
                    "runtime_recovery_adapter_required",
                    "Durable known-good model exists but the runtime host cannot reload it until the vendor loader adapter is installed.",
                    state.Pending != null);
            }

            var artifact = await _persistence.TryReadVerifiedArtifactAsync(
                expectedPackage,
                expectedSha,
                cancellationToken).ConfigureAwait(false);

            if (artifact == null || artifact.Length == 0)
                return Fail("known_good_artifact_missing", "Durable known-good model artifact is unavailable.", state.Pending != null);

            var actualSha = VisionModelActivationService.ComputeSha256(artifact);
            if (!string.Equals(actualSha, expectedSha.Trim().ToLowerInvariant(), StringComparison.Ordinal))
                return Fail("known_good_artifact_corrupt", "Durable known-good model artifact failed SHA-256 verification.", state.Pending != null);

            var snapshot = new VisionModelRuntimeSnapshot
            {
                PackagePublicId = expectedPackage,
                ArtifactSha256 = expectedSha
            };

            try
            {
                await recoverable.RecoverAsync(snapshot, artifact, cancellationToken).ConfigureAwait(false);
                var recovered = _runtime.CaptureActive();
                if (!Matches(recovered, expectedPackage, expectedSha))
                    return Fail("runtime_recovery_unconfirmed", "Runtime recovery did not confirm the durable known-good model.", state.Pending != null);

                await _persistence.CommitRestoredAsync(
                    recovered,
                    state.Pending != null ? "restart_interrupted_activation" : string.Empty,
                    state.Pending != null ? "Recovered known-good runtime after interrupted activation." : "Recovered known-good runtime after restart.",
                    cancellationToken).ConfigureAwait(false);
                await _persistence.CleanupAsync(cancellationToken).ConfigureAwait(false);

                return new VisionModelRecoveryResult
                {
                    Ready = true,
                    Recovered = true,
                    InterruptedActivationFound = state.Pending != null,
                    State = "known_good_recovered"
                };
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return Fail("runtime_recovery_failed", ex.Message, state.Pending != null);
            }
        }

        private static bool Matches(VisionModelRuntimeSnapshot snapshot, string packagePublicId, string artifactSha256)
        {
            return snapshot != null
                && string.Equals(snapshot.PackagePublicId, packagePublicId, StringComparison.Ordinal)
                && string.Equals(
                    (snapshot.ArtifactSha256 ?? string.Empty).Trim().ToLowerInvariant(),
                    (artifactSha256 ?? string.Empty).Trim().ToLowerInvariant(),
                    StringComparison.Ordinal);
        }

        private static VisionModelRecoveryResult Fail(string code, string message, bool interrupted)
        {
            return new VisionModelRecoveryResult
            {
                Ready = false,
                Recovered = false,
                InterruptedActivationFound = interrupted,
                State = "hold",
                ErrorCode = code,
                Message = message ?? string.Empty
            };
        }
    }
}
