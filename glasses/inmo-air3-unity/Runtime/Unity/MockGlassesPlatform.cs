using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class MockGlassesPlatform : MonoBehaviour, IGlassesPlatform
    {
        private bool _tracking;

        public bool IsInitialized { get; private set; }

        public PlatformCapabilities Capabilities { get; } = new PlatformCapabilities
        {
            Platform = "mock",
            Camera = false,
            Tracking3Dof = true,
            Tracking6Dof = true,
            BinocularDisplay = false,
            TouchInput = true
        };

        public Task InitializeAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            IsInitialized = true;
            return Task.CompletedTask;
        }

        public void StartTracking()
        {
            EnsureInitialized();
            _tracking = true;
        }

        public void StopTracking()
        {
            _tracking = false;
        }

        public CameraFrame TryGetLatestFrame()
        {
            return null;
        }

        public CameraCalibration TryGetCameraCalibration()
        {
            return null;
        }

        public PoseState GetPose()
        {
            EnsureInitialized();
            var transformRef = transform;
            return new PoseState
            {
                X = transformRef.position.x,
                Y = transformRef.position.y,
                Z = transformRef.position.z,
                Qx = transformRef.rotation.x,
                Qy = transformRef.rotation.y,
                Qz = transformRef.rotation.z,
                Qw = transformRef.rotation.w,
                TimestampNanoseconds = (long)(Time.realtimeSinceStartupAsDouble * 1_000_000_000d)
            };
        }

        public bool Tracking => _tracking;

        private void EnsureInitialized()
        {
            if (!IsInitialized) throw new System.InvalidOperationException("Mock glasses platform is not initialized.");
        }
    }
}
