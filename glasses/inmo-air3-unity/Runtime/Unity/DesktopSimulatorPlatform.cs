using System;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;
using UnityEngine.UI;

namespace Gelato.Ar.Unity
{
    public sealed class DesktopSimulatorPlatform : MonoBehaviour, IGlassesPlatform
    {
        [Header("Camera")]
        [SerializeField] private bool useWebcam = true;
        [SerializeField] private int requestedWidth = 640;
        [SerializeField] private int requestedHeight = 480;
        [SerializeField] private int requestedFps = 30;
        [SerializeField] private RawImage preview;

        [Header("Pose simulation")]
        [SerializeField] private float movementSpeed = 0.6f;
        [SerializeField] private float rotationSpeed = 45f;

        private WebCamTexture _webcam;
        private bool _tracking;
        private long _lastFrameTimestamp;

        public bool IsInitialized { get; private set; }

        public PlatformCapabilities Capabilities { get; } = new PlatformCapabilities
        {
            Platform = "desktop_simulator",
            Camera = true,
            Tracking3Dof = true,
            Tracking6Dof = true,
            BinocularDisplay = false,
            TouchInput = true
        };

        public Task InitializeAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();

            if (useWebcam)
            {
                _webcam = new WebCamTexture(requestedWidth, requestedHeight, requestedFps);
                _webcam.Play();
                if (preview != null) preview.texture = _webcam;
            }

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
            if (!IsInitialized || !_tracking || _webcam == null || !_webcam.isPlaying || !_webcam.didUpdateThisFrame)
                return null;

            var timestamp = (long)(Time.realtimeSinceStartupAsDouble * 1_000_000_000d);
            if (timestamp == _lastFrameTimestamp) return null;
            _lastFrameTimestamp = timestamp;

            var pixels = _webcam.GetPixels32();
            var grayscale = new byte[pixels.Length];
            for (var i = 0; i < pixels.Length; i++)
            {
                var pixel = pixels[i];
                grayscale[i] = (byte)Mathf.Clamp(
                    Mathf.RoundToInt((0.2126f * pixel.r) + (0.7152f * pixel.g) + (0.0722f * pixel.b)),
                    0,
                    255
                );
            }

            return new CameraFrame
            {
                Data = grayscale,
                Width = _webcam.width,
                Height = _webcam.height,
                TimestampNanoseconds = timestamp,
                PixelFormat = "grayscale8"
            };
        }

        public CameraCalibration TryGetCameraCalibration()
        {
            if (_webcam == null || _webcam.width <= 16 || _webcam.height <= 16) return null;

            // Approximate desktop calibration for simulator-only projection tests.
            return new CameraCalibration
            {
                Fx = _webcam.width,
                Fy = _webcam.width,
                Cx = _webcam.width * 0.5f,
                Cy = _webcam.height * 0.5f,
                Distortion = Array.Empty<float>()
            };
        }

        public PoseState GetPose()
        {
            EnsureInitialized();
            var t = transform;
            return new PoseState
            {
                X = t.position.x,
                Y = t.position.y,
                Z = t.position.z,
                Qx = t.rotation.x,
                Qy = t.rotation.y,
                Qz = t.rotation.z,
                Qw = t.rotation.w,
                TimestampNanoseconds = (long)(Time.realtimeSinceStartupAsDouble * 1_000_000_000d)
            };
        }

        public bool Tracking => _tracking;

        private void Update()
        {
            if (!_tracking) return;

            var move = new Vector3(
                Input.GetAxisRaw("Horizontal"),
                (Input.GetKey(KeyCode.PageUp) ? 1f : 0f) - (Input.GetKey(KeyCode.PageDown) ? 1f : 0f),
                Input.GetAxisRaw("Vertical")
            );
            transform.Translate(move.normalized * (movementSpeed * Time.unscaledDeltaTime), Space.Self);

            var yaw = 0f;
            var pitch = 0f;
            if (Input.GetKey(KeyCode.LeftArrow)) yaw -= 1f;
            if (Input.GetKey(KeyCode.RightArrow)) yaw += 1f;
            if (Input.GetKey(KeyCode.UpArrow)) pitch -= 1f;
            if (Input.GetKey(KeyCode.DownArrow)) pitch += 1f;

            transform.Rotate(
                pitch * rotationSpeed * Time.unscaledDeltaTime,
                yaw * rotationSpeed * Time.unscaledDeltaTime,
                0f,
                Space.Self
            );
        }

        private void OnDestroy()
        {
            if (_webcam != null && _webcam.isPlaying) _webcam.Stop();
        }

        private void EnsureInitialized()
        {
            if (!IsInitialized) throw new InvalidOperationException("Desktop simulator platform is not initialized.");
        }
    }
}
