using System;
using System.Collections.Generic;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;

internal static class VisionModelRuntimePersistenceContract
{
    public static async Task RunAsync()
    {
        await SuccessfulActivationPersistsAndClearsPending();
        await InterruptedRestartRecoversKnownGood();
        await CorruptKnownGoodFailsClosed();
        await MissingRecoveryAdapterFailsClosed();
    }

    private static async Task SuccessfulActivationPersistsAndClearsPending()
    {
        var bytes = Encoding.UTF8.GetBytes("section-21-model");
        var assignment = Assignment(bytes);
        var persistence = new MemoryPersistence();
        var runtime = new RecoverableRuntime("scripted-test-detector", "onnx");
        var service = new VisionModelActivationService(
            new Fetcher(bytes),
            runtime,
            (report, token) => Task.FromResult(true),
            VisionModelActivationService.DefaultMaximumArtifactBytes,
            persistence);

        var result = await service.ApplyAsync(assignment);

        Assert(result.Activated, "persisted activation must succeed");
        Assert(persistence.State.Pending == null, "successful activation must clear the pending journal");
        Assert(persistence.State.ActivePackagePublicId == assignment.Package!.PublicId, "active package must persist");
        Assert(persistence.State.ActiveArtifactSha256 == assignment.Package.ArtifactSha256, "active checksum must persist");
        Assert(persistence.Artifacts.ContainsKey(assignment.Package.ArtifactSha256), "verified artifact must be staged durably");
    }

    private static async Task InterruptedRestartRecoversKnownGood()
    {
        var knownGood = Encoding.UTF8.GetBytes("known-good-model");
        var sha = VisionModelActivationService.ComputeSha256(knownGood);
        var persistence = new MemoryPersistence();
        persistence.State.DetectorName = "scripted-test-detector";
        persistence.State.RuntimeType = "onnx";
        persistence.State.ActivePackagePublicId = "vision-model-v1";
        persistence.State.ActiveArtifactSha256 = sha;
        persistence.State.KnownGoodPackagePublicId = "vision-model-v1";
        persistence.State.KnownGoodArtifactSha256 = sha;
        persistence.State.Pending = new VisionModelPendingActivation
        {
            AssignmentKey = "interrupted",
            PackagePublicId = "vision-model-v2",
            ArtifactSha256 = new string('a', 64),
            RuntimeType = "onnx",
            Stage = "prepared"
        };
        persistence.Artifacts[sha] = knownGood;

        var runtime = new RecoverableRuntime("scripted-test-detector", "onnx")
        {
            ActivePackagePublicId = string.Empty,
            ActiveArtifactSha256 = string.Empty
        };
        var recovery = new VisionModelRecoveryService(runtime, persistence);

        var result = await recovery.ReconcileAsync();

        Assert(result.Ready && result.Recovered, "restart must recover staged known-good state");
        Assert(result.InterruptedActivationFound, "restart must identify the interrupted transaction");
        Assert(runtime.ActivePackagePublicId == "vision-model-v1", "runtime must restore the durable known-good package");
        Assert(persistence.State.Pending == null, "successful restart recovery must clear interrupted pending state");
    }

    private static async Task CorruptKnownGoodFailsClosed()
    {
        var correct = Encoding.UTF8.GetBytes("known-good-model");
        var sha = VisionModelActivationService.ComputeSha256(correct);
        var persistence = new MemoryPersistence();
        persistence.State.DetectorName = "scripted-test-detector";
        persistence.State.RuntimeType = "onnx";
        persistence.State.ActivePackagePublicId = "vision-model-v1";
        persistence.State.ActiveArtifactSha256 = sha;
        persistence.Artifacts[sha] = Encoding.UTF8.GetBytes("corrupt-model");

        var runtime = new RecoverableRuntime("scripted-test-detector", "onnx")
        {
            ActivePackagePublicId = string.Empty,
            ActiveArtifactSha256 = string.Empty
        };

        var result = await new VisionModelRecoveryService(runtime, persistence).ReconcileAsync();

        Assert(!result.Ready && result.ErrorCode == "known_good_artifact_corrupt", "corrupt durable artifact must hold fail-closed");
        Assert(runtime.RecoverCalls == 0, "corrupt bytes must never reach runtime recovery");
    }

    private static async Task MissingRecoveryAdapterFailsClosed()
    {
        var bytes = Encoding.UTF8.GetBytes("known-good-model");
        var sha = VisionModelActivationService.ComputeSha256(bytes);
        var persistence = new MemoryPersistence();
        persistence.State.DetectorName = "scripted-test-detector";
        persistence.State.RuntimeType = "onnx";
        persistence.State.ActivePackagePublicId = "vision-model-v1";
        persistence.State.ActiveArtifactSha256 = sha;
        persistence.Artifacts[sha] = bytes;

        var runtime = new NonRecoverableRuntime("scripted-test-detector", "onnx");
        var result = await new VisionModelRecoveryService(runtime, persistence).ReconcileAsync();

        Assert(!result.Ready && result.ErrorCode == "runtime_recovery_adapter_required", "missing vendor recovery adapter must hold fail-closed");
    }

    private static VisionModelAssignment Assignment(byte[] bytes)
    {
        return new VisionModelAssignment
        {
            Schema = "gelato.vision_model_assignment.v1",
            DetectorName = "scripted-test-detector",
            BuildSessionPublicId = "build-21",
            Action = "apply",
            AssignmentKey = new string('2', 64),
            Selection = "target",
            Rollout = new VisionModelRolloutAssignment { PublicId = "rollout-21", Status = "active", CanaryPercent = 100f },
            Package = new VisionModelPackage
            {
                PublicId = "vision-model-v2",
                DetectorName = "scripted-test-detector",
                ModelName = "sandwich-detector",
                ModelVersion = "2.1.0",
                RuntimeType = "onnx",
                Platform = "inmo_air3",
                ArtifactUrl = "https://models.example.test/model.onnx",
                ArtifactSha256 = VisionModelActivationService.ComputeSha256(bytes),
                ArtifactBytes = bytes.LongLength
            },
            Compatibility = new VisionModelCompatibility { Compatible = true }
        };
    }

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }

    private sealed class Fetcher : IVisionModelArtifactFetcher
    {
        private readonly byte[] _bytes;
        public Fetcher(byte[] bytes) { _bytes = bytes; }
        public Task<byte[]> FetchAsync(Uri artifactUri, long maximumBytes, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            return Task.FromResult(_bytes);
        }
    }

    private sealed class MemoryPersistence : IVisionModelRuntimePersistence
    {
        public VisionModelDurableState State { get; } = new VisionModelDurableState();
        public Dictionary<string, byte[]> Artifacts { get; } = new Dictionary<string, byte[]>(StringComparer.Ordinal);

        public Task<VisionModelDurableState> LoadAsync(CancellationToken cancellationToken) => Task.FromResult(State);

        public Task BeginActivationAsync(VisionModelAssignment assignment, VisionModelRuntimeSnapshot current, CancellationToken cancellationToken)
        {
            State.DetectorName = assignment.DetectorName;
            State.RuntimeType = assignment.Package!.RuntimeType;
            State.ActivePackagePublicId = current.PackagePublicId;
            State.ActiveArtifactSha256 = current.ArtifactSha256;
            State.KnownGoodPackagePublicId = current.PackagePublicId;
            State.KnownGoodArtifactSha256 = current.ArtifactSha256;
            State.Pending = new VisionModelPendingActivation
            {
                AssignmentKey = assignment.AssignmentKey,
                PackagePublicId = assignment.Package.PublicId,
                ArtifactSha256 = assignment.Package.ArtifactSha256,
                RuntimeType = assignment.Package.RuntimeType,
                Stage = "assigned"
            };
            return Task.CompletedTask;
        }

        public Task StageVerifiedAsync(VisionModelAssignment assignment, byte[] verifiedArtifact, string artifactSha256, CancellationToken cancellationToken)
        {
            Artifacts[artifactSha256] = verifiedArtifact;
            State.Pending!.Stage = "verified";
            return Task.CompletedTask;
        }

        public Task MarkPreparedAsync(VisionModelAssignment assignment, CancellationToken cancellationToken)
        {
            State.Pending!.Stage = "prepared";
            return Task.CompletedTask;
        }

        public Task CommitActiveAsync(VisionModelAssignment assignment, VisionModelRuntimeSnapshot previous, CancellationToken cancellationToken)
        {
            State.KnownGoodPackagePublicId = previous.PackagePublicId;
            State.KnownGoodArtifactSha256 = previous.ArtifactSha256;
            State.ActivePackagePublicId = assignment.Package!.PublicId;
            State.ActiveArtifactSha256 = assignment.Package.ArtifactSha256;
            State.Pending = null;
            return Task.CompletedTask;
        }

        public Task CommitRestoredAsync(VisionModelRuntimeSnapshot restored, string errorCode, string message, CancellationToken cancellationToken)
        {
            State.ActivePackagePublicId = restored.PackagePublicId;
            State.ActiveArtifactSha256 = restored.ArtifactSha256;
            State.KnownGoodPackagePublicId = restored.PackagePublicId;
            State.KnownGoodArtifactSha256 = restored.ArtifactSha256;
            State.Pending = null;
            return Task.CompletedTask;
        }

        public Task MarkFailedAsync(VisionModelAssignment assignment, string errorCode, string message, CancellationToken cancellationToken)
        {
            State.Pending = null;
            State.LastErrorCode = errorCode;
            State.LastErrorMessage = message;
            return Task.CompletedTask;
        }

        public Task<byte[]?> TryReadVerifiedArtifactAsync(string packagePublicId, string artifactSha256, CancellationToken cancellationToken)
        {
            return Task.FromResult(Artifacts.TryGetValue(artifactSha256, out var bytes) ? bytes : null);
        }

        public Task CleanupAsync(CancellationToken cancellationToken) => Task.CompletedTask;
    }

    private sealed class RecoverableRuntime : IVisionModelRuntimeHost, IVisionModelRecoverableRuntimeHost
    {
        public string DetectorName { get; }
        public string RuntimeType { get; }
        public string ActivePackagePublicId { get; set; } = "vision-model-v1";
        public string ActiveArtifactSha256 { get; set; } = new string('1', 64);
        public int RecoverCalls { get; private set; }

        public RecoverableRuntime(string detectorName, string runtimeType)
        {
            DetectorName = detectorName;
            RuntimeType = runtimeType;
        }

        public VisionModelRuntimeSnapshot CaptureActive() => new VisionModelRuntimeSnapshot
        {
            PackagePublicId = ActivePackagePublicId,
            ArtifactSha256 = ActiveArtifactSha256
        };

        public Task<VisionModelPreparedArtifact> PrepareAsync(VisionModelPackage package, byte[] verifiedArtifact, CancellationToken cancellationToken)
            => Task.FromResult(new VisionModelPreparedArtifact
            {
                PackagePublicId = package.PublicId,
                ArtifactSha256 = VisionModelActivationService.ComputeSha256(verifiedArtifact),
                RuntimeHandle = package.PublicId
            });

        public Task SelfTestAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken) => Task.CompletedTask;

        public Task ActivateAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken)
        {
            ActivePackagePublicId = prepared.PackagePublicId;
            ActiveArtifactSha256 = prepared.ArtifactSha256;
            return Task.CompletedTask;
        }

        public Task RestoreAsync(VisionModelRuntimeSnapshot previous, CancellationToken cancellationToken)
        {
            ActivePackagePublicId = previous.PackagePublicId;
            ActiveArtifactSha256 = previous.ArtifactSha256;
            return Task.CompletedTask;
        }

        public Task RecoverAsync(VisionModelRuntimeSnapshot durableKnownGood, byte[] verifiedArtifact, CancellationToken cancellationToken)
        {
            RecoverCalls++;
            ActivePackagePublicId = durableKnownGood.PackagePublicId;
            ActiveArtifactSha256 = VisionModelActivationService.ComputeSha256(verifiedArtifact);
            return Task.CompletedTask;
        }
    }

    private sealed class NonRecoverableRuntime : IVisionModelRuntimeHost
    {
        public string DetectorName { get; }
        public string RuntimeType { get; }
        public NonRecoverableRuntime(string detectorName, string runtimeType) { DetectorName = detectorName; RuntimeType = runtimeType; }
        public VisionModelRuntimeSnapshot CaptureActive() => new VisionModelRuntimeSnapshot();
        public Task<VisionModelPreparedArtifact> PrepareAsync(VisionModelPackage package, byte[] verifiedArtifact, CancellationToken cancellationToken) => throw new NotSupportedException();
        public Task SelfTestAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken) => throw new NotSupportedException();
        public Task ActivateAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken) => throw new NotSupportedException();
        public Task RestoreAsync(VisionModelRuntimeSnapshot previous, CancellationToken cancellationToken) => throw new NotSupportedException();
    }
}
