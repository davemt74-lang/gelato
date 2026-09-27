using System;
using System.Collections.Generic;

namespace Gelato.Ar.Core
{
    public enum WorkflowState
    {
        Uninitialized,
        Unpaired,
        Pairing,
        Idle,
        LoadingWork,
        WorkReady,
        StartingBuild,
        Building,
        ReadyForFinishing,
        HandingOff,
        HandedOff,
        Error
    }

    public sealed class PlatformCapabilities
    {
        public string Platform { get; set; } = "unknown";
        public bool Camera { get; set; }
        public bool Tracking3Dof { get; set; }
        public bool Tracking6Dof { get; set; }
        public bool BinocularDisplay { get; set; }
        public bool TouchInput { get; set; }
    }

    public sealed class CameraFrame
    {
        public byte[] Data { get; set; } = Array.Empty<byte>();
        public int Width { get; set; }
        public int Height { get; set; }
        public long TimestampNanoseconds { get; set; }
        public string PixelFormat { get; set; } = "grayscale8";
    }

    public sealed class CameraCalibration
    {
        public float Fx { get; set; }
        public float Fy { get; set; }
        public float Cx { get; set; }
        public float Cy { get; set; }
        public float[] Distortion { get; set; } = Array.Empty<float>();
    }

    public sealed class PoseState
    {
        public float X { get; set; }
        public float Y { get; set; }
        public float Z { get; set; }
        public float Qx { get; set; }
        public float Qy { get; set; }
        public float Qz { get; set; }
        public float Qw { get; set; } = 1f;
        public long TimestampNanoseconds { get; set; }
    }

    public sealed class DeviceDescriptor
    {
        public string HardwareIdentifier { get; set; } = string.Empty;
        public string DisplayName { get; set; } = "INMO AIR3";
        public string Platform { get; set; } = "inmo_air3";
        public string SdkVersion { get; set; } = string.Empty;
        public string AppVersion { get; set; } = string.Empty;
        public string SystemVersion { get; set; } = string.Empty;
    }

    public sealed class PairResult
    {
        public string DeviceToken { get; set; } = string.Empty;
        public string DevicePublicId { get; set; } = string.Empty;
    }

    public sealed class WorkItem
    {
        public string KdsItemPublicId { get; set; } = string.Empty;
        public string Status { get; set; } = string.Empty;
        public string Name { get; set; } = string.Empty;
        public string SpecialInstructions { get; set; } = string.Empty;
    }

    public sealed class CurrentWork
    {
        public bool AssignmentRequired { get; set; }
        public string Revision { get; set; } = string.Empty;
        public WorkItem? FocusItem { get; set; }
        public IReadOnlyList<WorkItem> Items { get; set; } = Array.Empty<WorkItem>();
    }

    public sealed class BuildComponent
    {
        public string ComponentKey { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public float ExpectedQuantity { get; set; }
        public float DetectedQuantity { get; set; }
        public string Unit { get; set; } = string.Empty;
        public string Status { get; set; } = string.Empty;
        public float? Confidence { get; set; }
    }

    public sealed class BuildSession
    {
        public string PublicId { get; set; } = string.Empty;
        public string Status { get; set; } = string.Empty;
        public string KdsItemPublicId { get; set; } = string.Empty;
        public string SourceRevision { get; set; } = string.Empty;
        public IReadOnlyList<BuildComponent> Components { get; set; } = Array.Empty<BuildComponent>();
    }

    public sealed class IngredientObservation
    {
        public string ObservationKey { get; set; } = string.Empty;
        public string ComponentKey { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public string Action { get; set; } = "seen";
        public float Quantity { get; set; } = 1f;
        public float Confidence { get; set; }
        public string TrackingId { get; set; } = string.Empty;
        public float[] BoundingBox { get; set; } = Array.Empty<float>();
    }

    public sealed class ValidationNext
    {
        public string Stage { get; set; } = string.Empty;
        public string Label { get; set; } = string.Empty;
        public bool Available { get; set; }
        public string Message { get; set; } = string.Empty;
    }

    public sealed class ProductValidation
    {
        public string PublicId { get; set; } = string.Empty;
        public string Status { get; set; } = string.Empty;
        public string NextStage { get; set; } = string.Empty;
        public bool AllIngredientsAccountedFor { get; set; }
        public ValidationNext Next { get; set; } = new ValidationNext();
    }

    public sealed class ExpoHandoff
    {
        public string PublicId { get; set; } = string.Empty;
        public string Status { get; set; } = string.Empty;
        public string KdsStatus { get; set; } = string.Empty;
        public string Label { get; set; } = string.Empty;
    }

    public static class DeviceApiActions
    {
        public const string Pair = "pair";
        public const string CurrentWork = "current_work";
        public const string BuildStart = "build.start";
        public const string BuildObserve = "build.observe";
        public const string BuildConfirm = "build.confirm";
        public const string ResolveUnexpected = "build.resolve_unexpected";
        public const string ValidationEvaluate = "validation.evaluate";
        public const string ValidationGet = "validation.get";
        public const string HandoffExpo = "handoff.expo";
        public const string Heartbeat = "heartbeat";
    }
}
