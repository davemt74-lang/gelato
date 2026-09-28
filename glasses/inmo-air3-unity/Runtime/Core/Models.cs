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

    public sealed class BuildStep
    {
        public string StepKey { get; set; } = string.Empty;
        public int Order { get; set; }
        public string Text { get; set; } = string.Empty;
        public IReadOnlyList<string> ComponentKeys { get; set; } = Array.Empty<string>();
    }

    public sealed class BuildSession
    {
        public string PublicId { get; set; } = string.Empty;
        public string Status { get; set; } = string.Empty;
        public string KdsItemPublicId { get; set; } = string.Empty;
        public string SourceRevision { get; set; } = string.Empty;
        public IReadOnlyList<BuildComponent> Components { get; set; } = Array.Empty<BuildComponent>();
        public IReadOnlyList<BuildStep> BuildSteps { get; set; } = Array.Empty<BuildStep>();
    }

    public sealed class IngredientZone
    {
        public string ZoneKey { get; set; } = string.Empty;
        public int IngredientId { get; set; }
        public string CanonicalName { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public float X { get; set; }
        public float Y { get; set; }
        public float Width { get; set; }
        public float Height { get; set; }
        public int Priority { get; set; }
    }

    public sealed class StationRegion
    {
        public string RegionKey { get; set; } = string.Empty;
        public string RegionType { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public float X { get; set; }
        public float Y { get; set; }
        public float Width { get; set; }
        public float Height { get; set; }
        public int Priority { get; set; }

        public bool Contains(float x, float y)
        {
            return x >= X && y >= Y && x <= X + Width && y <= Y + Height;
        }
    }

    public sealed class CalibrationCompatibility
    {
        public bool Compatible { get; set; }
        public IReadOnlyList<string> Reasons { get; set; } = Array.Empty<string>();
    }

    public sealed class StationCalibration
    {
        public string PublicId { get; set; } = string.Empty;
        public string StationPublicId { get; set; } = string.Empty;
        public string StationName { get; set; } = string.Empty;
        public int Version { get; set; }
        public string Platform { get; set; } = string.Empty;
        public int FrameWidth { get; set; }
        public int FrameHeight { get; set; }
        public string PixelFormat { get; set; } = string.Empty;
        public string SourceHash { get; set; } = string.Empty;
        public IReadOnlyList<IngredientZone> Zones { get; set; } = Array.Empty<IngredientZone>();
        public IReadOnlyList<StationRegion> Regions { get; set; } = Array.Empty<StationRegion>();
        public CalibrationCompatibility Compatibility { get; set; } = new CalibrationCompatibility();
    }

    public static class StationCalibrationPolicy
    {
        public static bool CanUse(StationCalibration? calibration, CameraFrame frame)
        {
            if (calibration == null || frame == null || !calibration.Compatibility.Compatible) return false;
            return calibration.FrameWidth == frame.Width
                && calibration.FrameHeight == frame.Height
                && string.Equals(calibration.PixelFormat, frame.PixelFormat, StringComparison.OrdinalIgnoreCase);
        }

        public static IReadOnlyList<IngredientZone> ZonesForComponent(StationCalibration? calibration, BuildComponent component)
        {
            if (calibration == null || component == null || calibration.Zones == null) return Array.Empty<IngredientZone>();

            var ingredientId = IngredientIdForComponent(component.ComponentKey);
            if (ingredientId <= 0) return Array.Empty<IngredientZone>();

            var zones = new List<IngredientZone>();
            foreach (var zone in calibration.Zones)
                if (zone != null && zone.IngredientId == ingredientId) zones.Add(zone);

            zones.Sort((a,b) => b.Priority.CompareTo(a.Priority));
            return zones;
        }

        public static IngredientZone? HighestPriorityZoneAt(StationCalibration? calibration, float x, float y)
        {
            if (calibration == null || calibration.Zones == null) return null;
            IngredientZone? best = null;
            foreach (var zone in calibration.Zones)
            {
                if (zone == null) continue;
                if (x < zone.X || y < zone.Y || x > zone.X + zone.Width || y > zone.Y + zone.Height) continue;
                if (best == null || zone.Priority > best.Priority) best = zone;
            }
            return best;
        }

        public static StationRegion? HighestPriorityRegionAt(StationCalibration? calibration, string regionType, float x, float y)
        {
            if (calibration == null || calibration.Regions == null || string.IsNullOrWhiteSpace(regionType)) return null;
            StationRegion? best = null;
            foreach (var region in calibration.Regions)
            {
                if (region == null || !string.Equals(region.RegionType, regionType, StringComparison.Ordinal)) continue;
                if (!region.Contains(x, y)) continue;
                if (best == null || region.Priority > best.Priority) best = region;
            }
            return best;
        }

        public static bool HasRegionType(StationCalibration? calibration, string regionType)
        {
            if (calibration == null || calibration.Regions == null || string.IsNullOrWhiteSpace(regionType)) return false;
            foreach (var region in calibration.Regions)
                if (region != null && string.Equals(region.RegionType, regionType, StringComparison.Ordinal)) return true;
            return false;
        }

        public static int IngredientIdForComponent(string componentKey)
        {
            if (string.IsNullOrWhiteSpace(componentKey)) return 0;
            const string prefix = "ingredient:";
            if (!componentKey.StartsWith(prefix, StringComparison.Ordinal)) return 0;
            return int.TryParse(componentKey.Substring(prefix.Length), out var id) ? id : 0;
        }
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
        public const string CalibrationGet = "calibration.get";
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
