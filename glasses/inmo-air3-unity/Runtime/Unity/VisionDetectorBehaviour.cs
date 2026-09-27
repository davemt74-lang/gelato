using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public abstract class VisionDetectorBehaviour : MonoBehaviour, IVisionDetector
    {
        public abstract string DetectorName { get; }

        public abstract Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken);
    }

    public sealed class NoOpVisionDetector : VisionDetectorBehaviour
    {
        private static readonly IReadOnlyList<VisionDetection> Empty = new VisionDetection[0];

        public override string DetectorName => "no-op";

        public override Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            return Task.FromResult(Empty);
        }
    }
}
