using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public readonly struct VisionBoundingBox
    {
        public VisionBoundingBox(float x, float y, float width, float height)
        {
            X = Clamp01(x);
            Y = Clamp01(y);
            Width = Math.Max(0f, Math.Min(width, 1f - X));
            Height = Math.Max(0f, Math.Min(height, 1f - Y));
        }

        public float X { get; }
        public float Y { get; }
        public float Width { get; }
        public float Height { get; }
        public float Right => X + Width;
        public float Bottom => Y + Height;
        public float Area => Width * Height;

        public float[] ToArray() => new[] { X, Y, Width, Height };

        public static VisionBoundingBox FromArray(float[]? values)
        {
            if (values == null || values.Length < 4) return new VisionBoundingBox(0f, 0f, 0f, 0f);
            return new VisionBoundingBox(values[0], values[1], values[2], values[3]);
        }

        public static float IntersectionOverUnion(VisionBoundingBox a, VisionBoundingBox b)
        {
            var left = Math.Max(a.X, b.X);
            var top = Math.Max(a.Y, b.Y);
            var right = Math.Min(a.Right, b.Right);
            var bottom = Math.Min(a.Bottom, b.Bottom);
            var width = Math.Max(0f, right - left);
            var height = Math.Max(0f, bottom - top);
            var intersection = width * height;
            var union = a.Area + b.Area - intersection;
            return union <= 0f ? 0f : intersection / union;
        }

        private static float Clamp01(float value) => Math.Max(0f, Math.Min(1f, value));
    }

    public sealed class VisionDetection
    {
        // Detector-facing class/label. The model does not need to know Gelato database/component IDs.
        public string Label { get; set; } = string.Empty;
        // Optional direct component key for deterministic/test detectors. Production models may leave this empty.
        public string ComponentKey { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public string InstanceKey { get; set; } = string.Empty;
        public string Action { get; set; } = "added";
        public float Quantity { get; set; } = 1f;
        public float Confidence { get; set; }
        public float[] BoundingBox { get; set; } = Array.Empty<float>();
        public bool IsUnexpected { get; set; }

        // Filled by the hardware-neutral evidence pipeline after detector output.
        public string EvidenceKind { get; set; } = string.Empty;
        public string EvidenceSourceZoneKey { get; set; } = string.Empty;
        public string EvidenceDestinationRegionKey { get; set; } = string.Empty;
        public bool EvidenceSequenceSupported { get; set; }
        public bool ProfileMatched { get; set; }
        public float? ProfileMinimumConfidence { get; set; }
    }

    public sealed class VisionFrameContext
    {
        public string BuildSessionPublicId { get; set; } = string.Empty;
        public IReadOnlyList<BuildComponent> ExpectedComponents { get; set; } = Array.Empty<BuildComponent>();
        public IReadOnlyList<BuildStep> BuildSteps { get; set; } = Array.Empty<BuildStep>();
        public StationCalibration? StationCalibration { get; set; }
        public VisionLabelProfile? VisionProfile { get; set; }
        public CameraCalibration? Calibration { get; set; }
        public PoseState? Pose { get; set; }
    }

    public interface IVisionDetector
    {
        string DetectorName { get; }
        Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken);
    }

    public sealed class VisionPipelineOptions
    {
        public float MinimumConfidence { get; set; } = 0.50f;
        public int StableFramesRequired { get; set; } = 2;
        public int MaxMissingFrames { get; set; } = 3;
        public float AssociationIouThreshold { get; set; } = 0.25f;
        public int MaxDetectionsPerFrame { get; set; } = 64;
        public int MaxActiveTracks { get; set; } = 128;
        public bool RequireMonotonicTimestamps { get; set; } = true;
        public float SpatialSupportBoost { get; set; } = 0.06f;
        public float SpatialConflictPenalty { get; set; } = 0.20f;
        public bool EnableTransferEvidence { get; set; } = true;
        public int MaxTransferFrames { get; set; } = 60;
        public float TransferSupportBoost { get; set; } = 0.08f;
        public float SequenceSupportBoost { get; set; } = 0.03f;
        public float UnprimedWorkSurfaceConfidenceCap { get; set; } = 0.74f;

        public void Validate()
        {
            if (MinimumConfidence < 0f || MinimumConfidence > 1f)
                throw new InvalidOperationException("Vision minimum confidence must be between 0 and 1.");
            if (StableFramesRequired < 1 || StableFramesRequired > 30)
                throw new InvalidOperationException("Vision stable-frame requirement is invalid.");
            if (MaxMissingFrames < 1 || MaxMissingFrames > 120)
                throw new InvalidOperationException("Vision missing-frame limit is invalid.");
            if (AssociationIouThreshold < 0f || AssociationIouThreshold > 1f)
                throw new InvalidOperationException("Vision IoU threshold must be between 0 and 1.");
            if (MaxDetectionsPerFrame < 1 || MaxDetectionsPerFrame > 512)
                throw new InvalidOperationException("Vision per-frame detection limit is invalid.");
            if (MaxActiveTracks < 1 || MaxActiveTracks > 2048)
                throw new InvalidOperationException("Vision active-track limit is invalid.");
            if (SpatialSupportBoost < 0f || SpatialSupportBoost > 0.25f)
                throw new InvalidOperationException("Vision spatial support boost is invalid.");
            if (SpatialConflictPenalty < 0f || SpatialConflictPenalty > 0.50f)
                throw new InvalidOperationException("Vision spatial conflict penalty is invalid.");
            if (MaxTransferFrames < 1 || MaxTransferFrames > 1800)
                throw new InvalidOperationException("Vision transfer-frame window is invalid.");
            if (TransferSupportBoost < 0f || TransferSupportBoost > 0.25f)
                throw new InvalidOperationException("Vision transfer support boost is invalid.");
            if (SequenceSupportBoost < 0f || SequenceSupportBoost > 0.15f)
                throw new InvalidOperationException("Vision sequence support boost is invalid.");
            if (UnprimedWorkSurfaceConfidenceCap < 0.50f || UnprimedWorkSurfaceConfidenceCap >= 0.85f)
                throw new InvalidOperationException("Vision unprimed work-surface confidence cap must remain below auto-confirm confidence.");
        }
    }

    public sealed class VisionPipelineDiagnostics
    {
        public long FramesProcessed { get; internal set; }
        public long DuplicateOrStaleFramesSkipped { get; internal set; }
        public long DetectionsReceived { get; internal set; }
        public long DetectionsAccepted { get; internal set; }
        public long DetectionsRejected { get; internal set; }
        public long ObservationsEmitted { get; internal set; }
        public long SpatialSupports { get; internal set; }
        public long SpatialConflicts { get; internal set; }
        public long TransferSourcesPrimed { get; internal set; }
        public long TransfersConfirmed { get; internal set; }
        public long SequenceSupports { get; internal set; }
        public long UnprimedWorkSurfaceDetections { get; internal set; }
        public long TransferHeldDetections { get; internal set; }
        public long ProfileLabelMatches { get; internal set; }
        public long ProfileThresholdRejects { get; internal set; }
        public long DisplayNameFallbackMatches { get; internal set; }

        internal void Reset()
        {
            FramesProcessed = 0;
            DuplicateOrStaleFramesSkipped = 0;
            DetectionsReceived = 0;
            DetectionsAccepted = 0;
            DetectionsRejected = 0;
            ObservationsEmitted = 0;
            SpatialSupports = 0;
            SpatialConflicts = 0;
            TransferSourcesPrimed = 0;
            TransfersConfirmed = 0;
            SequenceSupports = 0;
            UnprimedWorkSurfaceDetections = 0;
            TransferHeldDetections = 0;
            ProfileLabelMatches = 0;
            ProfileThresholdRejects = 0;
            DisplayNameFallbackMatches = 0;
        }
    }
}
