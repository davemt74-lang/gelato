using System;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    /// <summary>
    /// The only Gelato client class that should directly bind to the vendor AIR3 SDK.
    ///
    /// The SDK package is not yet available in this repository. Once INMO supplies it,
    /// implement this adapter against the documented AIR3 hooks:
    /// - ArPoseManager.Start6Dof()
    /// - ArPoseManager.Stop6Dof()
    /// - ArPoseManager.GetAir3ImageData()
    /// - ArPoseManager.GetAir3CameraParams()
    ///
    /// No recipe, KDS, validation, or HUD code should depend on vendor SDK types.
    /// </summary>
    public sealed class InmoAir3Platform : MonoBehaviour, IGlassesPlatform
    {
        public bool IsInitialized { get; private set; }

        public PlatformCapabilities Capabilities { get; } = new PlatformCapabilities
        {
            Platform = "inmo_air3",
            Camera = true,
            Tracking3Dof = true,
            Tracking6Dof = true,
            BinocularDisplay = true,
            TouchInput = true
        };

        public Task InitializeAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            throw VendorSdkMissing();
        }

        public void StartTracking()
        {
            throw VendorSdkMissing();
        }

        public void StopTracking()
        {
            throw VendorSdkMissing();
        }

        public CameraFrame TryGetLatestFrame()
        {
            throw VendorSdkMissing();
        }

        public CameraCalibration TryGetCameraCalibration()
        {
            throw VendorSdkMissing();
        }

        public PoseState GetPose()
        {
            throw VendorSdkMissing();
        }

        private static NotSupportedException VendorSdkMissing()
        {
            return new NotSupportedException(
                "INMO AIR3 vendor SDK is not imported. Add InmoAir3SDK_0.7.3.unitypackage and implement only InmoAir3Platform."
            );
        }
    }
}
