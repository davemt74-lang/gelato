using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class DesktopSimulatorVisionDetector : VisionDetectorBehaviour, IVisionDetectorRuntimeControl
    {
        private readonly Queue<IReadOnlyList<VisionDetection>> _frames = new Queue<IReadOnlyList<VisionDetection>>();
        private bool _ready;

        public override string DetectorName => "desktop-simulator-detector";

        public Task WarmupAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            _ready = true;
            return Task.CompletedTask;
        }

        public Task RestartAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            _ready = false;
            return Task.CompletedTask;
        }

        public void EnqueueFrame(params VisionDetection[] detections)
        {
            _frames.Enqueue(detections ?? Array.Empty<VisionDetection>());
        }

        public void ClearScript()
        {
            _frames.Clear();
        }

        public override Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (!_ready)
                throw new InvalidOperationException("Desktop simulator detector must be warmed up before inference.");

            IReadOnlyList<VisionDetection> detections = _frames.Count > 0
                ? _frames.Dequeue()
                : Array.Empty<VisionDetection>();
            return Task.FromResult(detections);
        }
    }
}
