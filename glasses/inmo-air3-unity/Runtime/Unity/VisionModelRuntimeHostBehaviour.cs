using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public abstract class VisionModelRuntimeHostBehaviour : MonoBehaviour, IVisionModelRuntimeHost
    {
        public abstract string DetectorName { get; }
        public abstract string RuntimeType { get; }
        public abstract VisionModelRuntimeSnapshot CaptureActive();

        public abstract Task<VisionModelPreparedArtifact> PrepareAsync(
            VisionModelPackage package,
            byte[] verifiedArtifact,
            CancellationToken cancellationToken);

        public abstract Task SelfTestAsync(
            VisionModelPreparedArtifact prepared,
            CancellationToken cancellationToken);

        public abstract Task ActivateAsync(
            VisionModelPreparedArtifact prepared,
            CancellationToken cancellationToken);

        public abstract Task RestoreAsync(
            VisionModelRuntimeSnapshot previous,
            CancellationToken cancellationToken);
    }
}
