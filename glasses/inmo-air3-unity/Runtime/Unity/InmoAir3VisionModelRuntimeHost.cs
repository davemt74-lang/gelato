using System;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;

namespace Gelato.Ar.Unity
{
    /// <summary>
    /// Section 21A vendor boundary for AIR3 model loading.
    ///
    /// The proprietary AIR3 model-loader package is not present yet. This class deliberately
    /// exposes the exact persistence/recovery seam the vendor adapter must implement without
    /// inventing SDK calls. Do not attach this stub as the active runtime host until the vendor
    /// package is imported and these methods are implemented against the real loader.
    /// </summary>
    public sealed class InmoAir3VisionModelRuntimeHost : VisionModelRuntimeHostBehaviour, IVisionModelRecoverableRuntimeHost
    {
        public override string DetectorName => "air3-production-detector";
        public override string RuntimeType => "vendor";

        public override VisionModelRuntimeSnapshot CaptureActive()
        {
            return new VisionModelRuntimeSnapshot();
        }

        public override Task<VisionModelPreparedArtifact> PrepareAsync(
            VisionModelPackage package,
            byte[] verifiedArtifact,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            throw VendorSdkMissing();
        }

        public override Task SelfTestAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            throw VendorSdkMissing();
        }

        public override Task ActivateAsync(VisionModelPreparedArtifact prepared, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            throw VendorSdkMissing();
        }

        public override Task RestoreAsync(VisionModelRuntimeSnapshot previous, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            throw VendorSdkMissing();
        }

        public Task RecoverAsync(
            VisionModelRuntimeSnapshot durableKnownGood,
            byte[] verifiedArtifact,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            throw VendorSdkMissing();
        }

        private static NotSupportedException VendorSdkMissing()
        {
            return new NotSupportedException(
                "INMO AIR3 model-loader SDK is not imported. Section 21A persistence/recovery is ready; implement only InmoAir3VisionModelRuntimeHost when the vendor loader files arrive."
            );
        }
    }
}
