using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public sealed class VisionModelPreparedArtifact
    {
        public string PackagePublicId { get; set; } = string.Empty;
        public string ArtifactSha256 { get; set; } = string.Empty;
        public object? RuntimeHandle { get; set; }
    }

    public sealed class VisionModelRuntimeSnapshot
    {
        public string PackagePublicId { get; set; } = string.Empty;
        public string ArtifactSha256 { get; set; } = string.Empty;
        public object? RuntimeHandle { get; set; }
    }

    public sealed class VisionModelActivationResult
    {
        public bool Activated { get; set; }
        public bool RestoredPrevious { get; set; }
        public string State { get; set; } = string.Empty;
        public string ErrorCode { get; set; } = string.Empty;
        public string Message { get; set; } = string.Empty;
        public VisionModelPackage? Package { get; set; }
    }

    public interface IVisionModelArtifactFetcher
    {
        Task<byte[]> FetchAsync(Uri artifactUri, long maximumBytes, CancellationToken cancellationToken);
    }

    public interface IVisionModelRuntimeHost
    {
        string DetectorName { get; }
        string RuntimeType { get; }
        VisionModelRuntimeSnapshot CaptureActive();
        Task<VisionModelPreparedArtifact> PrepareAsync(
            VisionModelPackage package,
            byte[] verifiedArtifact,
            CancellationToken cancellationToken);
        Task SelfTestAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken);
        Task ActivateAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken);
        Task RestoreAsync(VisionModelRuntimeSnapshot previous, CancellationToken cancellationToken);
    }

    public sealed class VisionModelActivationService
    {
        public const long DefaultMaximumArtifactBytes = 512L * 1024L * 1024L;

        private readonly IVisionModelArtifactFetcher _fetcher;
        private readonly IVisionModelRuntimeHost _runtime;
        private readonly Func<VisionModelReport, CancellationToken, Task<bool>> _report;
        private readonly IVisionModelRuntimePersistence? _persistence;
        private readonly long _maximumArtifactBytes;

        public VisionModelActivationService(
            IVisionModelArtifactFetcher fetcher,
            IVisionModelRuntimeHost runtime,
            Func<VisionModelReport, CancellationToken, Task<bool>> report,
            long maximumArtifactBytes = DefaultMaximumArtifactBytes,
            IVisionModelRuntimePersistence? persistence = null)
        {
            _fetcher = fetcher ?? throw new ArgumentNullException(nameof(fetcher));
            _runtime = runtime ?? throw new ArgumentNullException(nameof(runtime));
            _report = report ?? throw new ArgumentNullException(nameof(report));
            _persistence = persistence;
            if (maximumArtifactBytes <= 0) throw new ArgumentOutOfRangeException(nameof(maximumArtifactBytes));
            _maximumArtifactBytes = maximumArtifactBytes;
        }

        public async Task<VisionModelActivationResult> ApplyAsync(
            VisionModelAssignment assignment,
            CancellationToken cancellationToken = default)
        {
            if (assignment == null) throw new ArgumentNullException(nameof(assignment));

            if (!VisionModelAssignmentPolicy.CanApply(assignment, _runtime.DetectorName, out var policyReasons))
                return await FailAsync(assignment, "assignment_policy_rejected", string.Join(",", policyReasons), cancellationToken).ConfigureAwait(false);

            var package = assignment.Package!;
            if (!string.Equals(package.RuntimeType, _runtime.RuntimeType, StringComparison.OrdinalIgnoreCase))
                return await FailAsync(assignment, "runtime_type_mismatch", "Assigned package runtime does not match the configured runtime host.", cancellationToken).ConfigureAwait(false);

            if (package.ArtifactBytes.HasValue && package.ArtifactBytes.Value > _maximumArtifactBytes)
                return await FailAsync(assignment, "artifact_too_large", "Registered artifact exceeds the runtime download limit.", cancellationToken).ConfigureAwait(false);

            if (!Uri.TryCreate(package.ArtifactUrl, UriKind.Absolute, out var artifactUri)
                || !string.Equals(artifactUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
                return await FailAsync(assignment, "artifact_url_not_https", "Artifact URL must be HTTPS.", cancellationToken).ConfigureAwait(false);

            var previous = _runtime.CaptureActive();
            var expectedSha = (package.ArtifactSha256 ?? string.Empty).Trim().ToLowerInvariant();

            if (string.Equals(previous.PackagePublicId, package.PublicId, StringComparison.Ordinal)
                && string.Equals((previous.ArtifactSha256 ?? string.Empty).Trim().ToLowerInvariant(), expectedSha, StringComparison.Ordinal))
            {
                var alreadyType = string.Equals(assignment.Selection, "rollback", StringComparison.Ordinal)
                    ? "rollback_activated"
                    : "activated";
                await ReportBestEffortAsync(CreateReport(assignment, alreadyType, "already_active", expectedSha), cancellationToken).ConfigureAwait(false);
                return new VisionModelActivationResult
                {
                    Activated = true,
                    RestoredPrevious = false,
                    State = "already_active",
                    Package = package,
                    Message = "Assigned verified model is already active."
                };
            }

            if (_persistence != null)
            {
                await _persistence.BeginActivationAsync(assignment, previous, cancellationToken).ConfigureAwait(false);
            }

            byte[] artifact;

            try
            {
                await ReportBestEffortAsync(CreateReport(assignment, "download_started", "download_started"), cancellationToken).ConfigureAwait(false);
                artifact = await _fetcher.FetchAsync(artifactUri, _maximumArtifactBytes, cancellationToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return await FailAsync(assignment, "artifact_download_failed", ex.Message, cancellationToken).ConfigureAwait(false);
            }

            if (artifact == null || artifact.LongLength == 0)
                return await FailAsync(assignment, "artifact_empty", "Downloaded artifact is empty.", cancellationToken).ConfigureAwait(false);

            if (artifact.LongLength > _maximumArtifactBytes)
                return await FailAsync(assignment, "artifact_too_large", "Downloaded artifact exceeds the runtime download limit.", cancellationToken).ConfigureAwait(false);

            if (package.ArtifactBytes.HasValue && artifact.LongLength != package.ArtifactBytes.Value)
                return await FailAsync(assignment, "artifact_size_mismatch", "Downloaded artifact byte count does not match the package manifest.", cancellationToken).ConfigureAwait(false);

            await ReportBestEffortAsync(CreateReport(assignment, "downloaded", "downloaded"), cancellationToken).ConfigureAwait(false);

            var actualSha = ComputeSha256(artifact);
            if (!string.Equals(actualSha, expectedSha, StringComparison.Ordinal))
                return await FailAsync(assignment, "artifact_sha256_mismatch", "Downloaded artifact checksum does not match the immutable package manifest.", cancellationToken).ConfigureAwait(false);

            await ReportBestEffortAsync(CreateReport(assignment, "verified", "verified", actualSha), cancellationToken).ConfigureAwait(false);
            if (_persistence != null)
            {
                await _persistence.StageVerifiedAsync(assignment, artifact, actualSha, cancellationToken).ConfigureAwait(false);
            }

            VisionModelPreparedArtifact prepared;
            try
            {
                prepared = await _runtime.PrepareAsync(package, artifact, cancellationToken).ConfigureAwait(false);
                if (prepared == null) throw new InvalidOperationException("Runtime returned no prepared model.");
                if (!string.Equals(prepared.PackagePublicId, package.PublicId, StringComparison.Ordinal))
                    throw new InvalidOperationException("Prepared model package identity does not match the assignment.");
                if (!string.Equals((prepared.ArtifactSha256 ?? string.Empty).Trim().ToLowerInvariant(), actualSha, StringComparison.Ordinal))
                    throw new InvalidOperationException("Prepared model checksum does not match the verified artifact.");
                await _runtime.SelfTestAsync(prepared, cancellationToken).ConfigureAwait(false);
                if (_persistence != null)
                {
                    await _persistence.MarkPreparedAsync(assignment, cancellationToken).ConfigureAwait(false);
                }
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return await FailAsync(assignment, "runtime_prepare_failed", ex.Message, cancellationToken).ConfigureAwait(false);
            }

            try
            {
                await _runtime.ActivateAsync(prepared, cancellationToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                await RestoreAfterFailureAsync(previous).ConfigureAwait(false);
                throw;
            }
            catch (Exception ex)
            {
                var restored = await RestoreAfterFailureAsync(previous).ConfigureAwait(false);
                if (_persistence != null && restored)
                {
                    await _persistence.CommitRestoredAsync(previous, "runtime_activation_failed", ex.Message, CancellationToken.None).ConfigureAwait(false);
                }
                var result = await FailAsync(assignment, "runtime_activation_failed", ex.Message, cancellationToken).ConfigureAwait(false);
                result.RestoredPrevious = restored;
                return result;
            }

            if (_persistence != null)
            {
                await _persistence.CommitActiveAsync(assignment, previous, cancellationToken).ConfigureAwait(false);
                await _persistence.CleanupAsync(cancellationToken).ConfigureAwait(false);
            }

            var reportType = string.Equals(assignment.Selection, "rollback", StringComparison.Ordinal)
                ? "rollback_activated"
                : "activated";
            await ReportBestEffortAsync(CreateReport(assignment, reportType, "active", actualSha), cancellationToken).ConfigureAwait(false);

            return new VisionModelActivationResult
            {
                Activated = true,
                RestoredPrevious = false,
                State = "active",
                Package = package,
                Message = reportType == "rollback_activated"
                    ? "Verified rollback model activated."
                    : "Verified model activated."
            };
        }

        private async Task<bool> RestoreAfterFailureAsync(VisionModelRuntimeSnapshot previous)
        {
            try
            {
                await _runtime.RestoreAsync(previous, CancellationToken.None).ConfigureAwait(false);
                return true;
            }
            catch
            {
                return false;
            }
        }

        private async Task<VisionModelActivationResult> FailAsync(
            VisionModelAssignment assignment,
            string code,
            string message,
            CancellationToken cancellationToken)
        {
            if (_persistence != null)
            {
                try
                {
                    await _persistence.MarkFailedAsync(assignment, code, message, cancellationToken).ConfigureAwait(false);
                }
                catch (OperationCanceledException)
                {
                    throw;
                }
                catch
                {
                    // Runtime persistence telemetry must not hide the primary activation failure.
                }
            }
            await ReportBestEffortAsync(CreateReport(assignment, "failed", "failed", assignment.Package?.ArtifactSha256 ?? string.Empty, code, message), cancellationToken).ConfigureAwait(false);
            return new VisionModelActivationResult
            {
                Activated = false,
                State = "failed",
                ErrorCode = code,
                Message = message ?? string.Empty,
                Package = assignment.Package
            };
        }

        private async Task ReportBestEffortAsync(VisionModelReport report, CancellationToken cancellationToken)
        {
            try
            {
                await _report(report, cancellationToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch
            {
                // Telemetry failure must never mutate the selected runtime or stop kitchen work.
            }
        }

        private static VisionModelReport CreateReport(
            VisionModelAssignment assignment,
            string reportType,
            string runtimeState,
            string artifactSha256 = "",
            string errorCode = "",
            string message = "")
        {
            var package = assignment.Package;
            var rollout = assignment.Rollout;
            return new VisionModelReport
            {
                AssignmentKey = assignment.AssignmentKey,
                ReportKey = assignment.AssignmentKey + ":" + reportType,
                ReportType = reportType,
                RolloutPublicId = rollout?.PublicId ?? string.Empty,
                PackagePublicId = package?.PublicId ?? string.Empty,
                RuntimeState = runtimeState,
                ArtifactSha256 = string.IsNullOrWhiteSpace(artifactSha256)
                    ? package?.ArtifactSha256 ?? string.Empty
                    : artifactSha256,
                ErrorCode = errorCode ?? string.Empty,
                Message = message ?? string.Empty
            };
        }

        public static string ComputeSha256(byte[] data)
        {
            if (data == null) throw new ArgumentNullException(nameof(data));
            using (var sha = SHA256.Create())
            {
                var hash = sha.ComputeHash(data);
                return BitConverter.ToString(hash).Replace("-", string.Empty).ToLowerInvariant();
            }
        }
    }
}
