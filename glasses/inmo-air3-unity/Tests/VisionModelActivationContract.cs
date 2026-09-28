using System;
using System.Collections.Generic;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;

internal static class VisionModelActivationContract
{
    public static async Task RunAsync()
    {
        await VerifiedActivationFlow();
        await ChecksumMismatchFailsClosed();
        await PrepareFailureKeepsKnownGood();
        await ActivationFailureRestoresKnownGood();
        await RollbackAssignmentReportsRollbackActivation();
        await RuntimeMismatchFailsBeforeDownload();
    }

    private static async Task VerifiedActivationFlow()
    {
        var bytes = Encoding.UTF8.GetBytes("gelato-model-v2");
        var assignment = Assignment(bytes, "target");
        var fetcher = new FakeFetcher(bytes);
        var runtime = new FakeRuntime("scripted-test-detector", "onnx");
        var reports = new List<VisionModelReport>();
        var service = new VisionModelActivationService(fetcher, runtime, (report, token) =>
        {
            reports.Add(report);
            return Task.FromResult(true);
        });

        var result = await service.ApplyAsync(assignment);

        Assert(result.Activated && result.State == "active", "verified artifact must activate");
        Assert(fetcher.Calls == 1, "artifact must download exactly once");
        Assert(runtime.PrepareCalls == 1 && runtime.SelfTestCalls == 1 && runtime.ActivateCalls == 1, "runtime must prepare, self-test, then activate once");
        Assert(runtime.RestoreCalls == 0, "successful activation must not restore the prior runtime");
        Assert(runtime.ActivePackagePublicId == "vision-model-v2", "atomic activation must make the verified target current");
        Assert(HasReport(reports, "download_started") && HasReport(reports, "downloaded") && HasReport(reports, "verified") && HasReport(reports, "activated"), "activation telemetry must cover download, verification and activation");
        Assert(!HasReport(reports, "failed"), "successful activation must not report failure");
    }

    private static async Task ChecksumMismatchFailsClosed()
    {
        var registered = Encoding.UTF8.GetBytes("registered-model");
        var downloaded = Encoding.UTF8.GetBytes("tampered-model!!");
        Assert(registered.Length == downloaded.Length, "test fixtures must have equal lengths so checksum is the rejecting control");

        var assignment = Assignment(registered, "target");
        var runtime = new FakeRuntime("scripted-test-detector", "onnx");
        var reports = new List<VisionModelReport>();
        var service = new VisionModelActivationService(new FakeFetcher(downloaded), runtime, (report, token) =>
        {
            reports.Add(report);
            return Task.FromResult(true);
        });

        var result = await service.ApplyAsync(assignment);

        Assert(!result.Activated && result.ErrorCode == "artifact_sha256_mismatch", "checksum mismatch must fail closed");
        Assert(runtime.PrepareCalls == 0 && runtime.ActivateCalls == 0, "unverified bytes must never reach the runtime");
        Assert(runtime.ActivePackagePublicId == "vision-model-v1", "checksum failure must preserve the known-good model");
        Assert(HasFailure(reports, "artifact_sha256_mismatch"), "checksum failure must be reported");
    }

    private static async Task PrepareFailureKeepsKnownGood()
    {
        var bytes = Encoding.UTF8.GetBytes("gelato-model-v2");
        var assignment = Assignment(bytes, "target");
        var runtime = new FakeRuntime("scripted-test-detector", "onnx") { FailPrepare = true };
        var service = new VisionModelActivationService(new FakeFetcher(bytes), runtime, (report, token) => Task.FromResult(true));

        var result = await service.ApplyAsync(assignment);

        Assert(!result.Activated && result.ErrorCode == "runtime_prepare_failed", "runtime prepare failure must fail closed");
        Assert(runtime.ActivateCalls == 0 && runtime.RestoreCalls == 0, "failed isolated preparation must never disturb the active runtime");
        Assert(runtime.ActivePackagePublicId == "vision-model-v1", "prepare failure must leave known-good active");
    }

    private static async Task ActivationFailureRestoresKnownGood()
    {
        var bytes = Encoding.UTF8.GetBytes("gelato-model-v2");
        var assignment = Assignment(bytes, "target");
        var runtime = new FakeRuntime("scripted-test-detector", "onnx") { FailActivateAfterSwap = true };
        var service = new VisionModelActivationService(new FakeFetcher(bytes), runtime, (report, token) => Task.FromResult(true));

        var result = await service.ApplyAsync(assignment);

        Assert(!result.Activated && result.ErrorCode == "runtime_activation_failed", "activation failure must be surfaced");
        Assert(result.RestoredPrevious, "activation failure must restore the captured known-good runtime");
        Assert(runtime.RestoreCalls == 1, "runtime restoration must execute exactly once");
        Assert(runtime.ActivePackagePublicId == "vision-model-v1", "restoration must return to the previous package");
    }

    private static async Task RollbackAssignmentReportsRollbackActivation()
    {
        var bytes = Encoding.UTF8.GetBytes("gelato-baseline-v1");
        var assignment = Assignment(bytes, "rollback");
        assignment.Package!.PublicId = "vision-model-v1";
        assignment.Package.ModelVersion = "1.0.0";
        assignment.Rollout!.Status = "rolled_back";

        var reports = new List<VisionModelReport>();
        var runtime = new FakeRuntime("scripted-test-detector", "onnx") { ActivePackagePublicId = "vision-model-v2" };
        var service = new VisionModelActivationService(new FakeFetcher(bytes), runtime, (report, token) =>
        {
            reports.Add(report);
            return Task.FromResult(true);
        });

        var result = await service.ApplyAsync(assignment);

        Assert(result.Activated && runtime.ActivePackagePublicId == "vision-model-v1", "rollback assignment must activate the declared verified baseline");
        Assert(HasReport(reports, "rollback_activated"), "rollback activation must use the governed rollback telemetry type");
        Assert(!HasReport(reports, "activated"), "rollback must not be misreported as normal target activation");
    }

    private static async Task RuntimeMismatchFailsBeforeDownload()
    {
        var bytes = Encoding.UTF8.GetBytes("gelato-model-v2");
        var assignment = Assignment(bytes, "target");
        var fetcher = new FakeFetcher(bytes);
        var runtime = new FakeRuntime("scripted-test-detector", "tflite");
        var service = new VisionModelActivationService(fetcher, runtime, (report, token) => Task.FromResult(true));

        var result = await service.ApplyAsync(assignment);

        Assert(!result.Activated && result.ErrorCode == "runtime_type_mismatch", "runtime mismatch must fail closed");
        Assert(fetcher.Calls == 0 && runtime.PrepareCalls == 0, "runtime mismatch must be rejected before artifact download");
    }

    private static VisionModelAssignment Assignment(byte[] artifact, string selection)
    {
        return new VisionModelAssignment
        {
            Schema = "gelato.vision_model_assignment.v1",
            DetectorName = "scripted-test-detector",
            BuildSessionPublicId = "build-1",
            Action = "apply",
            AssignmentKey = "assignment-section-20",
            Selection = selection,
            Rollout = new VisionModelRolloutAssignment
            {
                PublicId = "vision-rollout-20",
                Status = selection == "rollback" ? "rolled_back" : "active",
                CanaryPercent = 100f
            },
            Package = new VisionModelPackage
            {
                PublicId = "vision-model-v2",
                DetectorName = "scripted-test-detector",
                ModelName = "sandwich-detector",
                ModelVersion = "2.0.0",
                RuntimeType = "onnx",
                Platform = "inmo_air3",
                ArtifactUrl = "https://models.example.test/sandwich-detector.onnx",
                ArtifactSha256 = VisionModelActivationService.ComputeSha256(artifact),
                ArtifactBytes = artifact.LongLength
            },
            Compatibility = new VisionModelCompatibility { Compatible = true }
        };
    }

    private static bool HasReport(List<VisionModelReport> reports, string reportType)
    {
        foreach (var report in reports)
            if (string.Equals(report.ReportType, reportType, StringComparison.Ordinal)) return true;
        return false;
    }

    private static bool HasFailure(List<VisionModelReport> reports, string errorCode)
    {
        foreach (var report in reports)
            if (string.Equals(report.ReportType, "failed", StringComparison.Ordinal)
                && string.Equals(report.ErrorCode, errorCode, StringComparison.Ordinal)) return true;
        return false;
    }

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }

    private sealed class FakeFetcher : IVisionModelArtifactFetcher
    {
        private readonly byte[] _bytes;
        public int Calls { get; private set; }

        public FakeFetcher(byte[] bytes)
        {
            _bytes = bytes ?? throw new ArgumentNullException(nameof(bytes));
        }

        public Task<byte[]> FetchAsync(Uri artifactUri, long maximumBytes, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            Calls++;
            if (!string.Equals(artifactUri.Scheme, "https", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("insecure artifact");
            if (_bytes.LongLength > maximumBytes) throw new InvalidOperationException("artifact exceeds cap");
            var copy = new byte[_bytes.Length];
            Buffer.BlockCopy(_bytes, 0, copy, 0, _bytes.Length);
            return Task.FromResult(copy);
        }
    }

    private sealed class FakeRuntime : IVisionModelRuntimeHost
    {
        public string DetectorName { get; }
        public string RuntimeType { get; }
        public string ActivePackagePublicId { get; set; } = "vision-model-v1";
        public string ActiveArtifactSha256 { get; set; } = "known-good";
        public bool FailPrepare { get; set; }
        public bool FailActivateAfterSwap { get; set; }
        public int PrepareCalls { get; private set; }
        public int SelfTestCalls { get; private set; }
        public int ActivateCalls { get; private set; }
        public int RestoreCalls { get; private set; }

        public FakeRuntime(string detectorName, string runtimeType)
        {
            DetectorName = detectorName;
            RuntimeType = runtimeType;
        }

        public VisionModelRuntimeSnapshot CaptureActive()
        {
            return new VisionModelRuntimeSnapshot
            {
                PackagePublicId = ActivePackagePublicId,
                ArtifactSha256 = ActiveArtifactSha256,
                RuntimeHandle = ActivePackagePublicId
            };
        }

        public Task<VisionModelPreparedArtifact> PrepareAsync(VisionModelPackage package, byte[] verifiedArtifact, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            PrepareCalls++;
            if (FailPrepare) throw new InvalidOperationException("simulated prepare failure");
            return Task.FromResult(new VisionModelPreparedArtifact
            {
                PackagePublicId = package.PublicId,
                ArtifactSha256 = VisionModelActivationService.ComputeSha256(verifiedArtifact),
                RuntimeHandle = package.PublicId
            });
        }

        public Task SelfTestAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            SelfTestCalls++;
            if (prepared.RuntimeHandle == null) throw new InvalidOperationException("prepared runtime handle missing");
            return Task.CompletedTask;
        }

        public Task ActivateAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            ActivateCalls++;
            ActivePackagePublicId = prepared.PackagePublicId;
            ActiveArtifactSha256 = prepared.ArtifactSha256;
            if (FailActivateAfterSwap) throw new InvalidOperationException("simulated activation failure after swap");
            return Task.CompletedTask;
        }

        public Task RestoreAsync(VisionModelRuntimeSnapshot previous, CancellationToken cancellationToken)
        {
            RestoreCalls++;
            ActivePackagePublicId = previous.PackagePublicId;
            ActiveArtifactSha256 = previous.ArtifactSha256;
            return Task.CompletedTask;
        }
    }
}
