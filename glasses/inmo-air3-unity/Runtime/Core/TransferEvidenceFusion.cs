using System;

namespace Gelato.Ar.Core
{
    public sealed class TransferEvidenceState
    {
        public bool SourceSeen { get; internal set; }
        public string SourceZoneKey { get; internal set; } = string.Empty;
        public float SourceCenterX { get; internal set; }
        public float SourceCenterY { get; internal set; }
        public int ContinuousFrames { get; internal set; }
        public bool Completed { get; internal set; }

        public void Reset()
        {
            SourceSeen = false;
            SourceZoneKey = string.Empty;
            SourceCenterX = 0f;
            SourceCenterY = 0f;
            ContinuousFrames = 0;
            Completed = false;
        }
    }

    public sealed class TransferEvidenceResult
    {
        public bool Started { get; set; }
        public bool Completed { get; set; }
        public string SourceZoneKey { get; set; } = string.Empty;
        public string WorkAreaKey { get; set; } = string.Empty;
        public float BaseConfidence { get; set; }
        public float EffectiveConfidence { get; set; }
        public float Distance { get; set; }
    }

    public static class TransferEvidenceFusion
    {
        public static TransferEvidenceResult Advance(
            TransferEvidenceState state,
            VisionDetection detection,
            StationCalibration? calibration,
            CameraFrame frame,
            int minimumFrames,
            float minimumDistance,
            float supportBoost,
            bool requireStableInstance)
        {
            if (state == null) throw new ArgumentNullException(nameof(state));
            if (detection == null) throw new ArgumentNullException(nameof(detection));
            if (frame == null) throw new ArgumentNullException(nameof(frame));

            var result = new TransferEvidenceResult
            {
                BaseConfidence = Clamp01(detection.Confidence),
                EffectiveConfidence = Clamp01(detection.Confidence)
            };

            if (state.Completed
                || detection.IsUnexpected
                || string.Equals(detection.Action, "removed", StringComparison.Ordinal)
                || calibration == null
                || !StationCalibrationPolicy.CanUse(calibration, frame))
                return result;

            if (requireStableInstance && string.IsNullOrWhiteSpace(detection.InstanceKey))
                return result;

            var ingredientId = StationCalibrationPolicy.IngredientIdForComponent(detection.ComponentKey);
            if (ingredientId <= 0) return result;

            var box = VisionBoundingBox.FromArray(detection.BoundingBox);
            if (box.Area <= 0f) return result;

            var centerX = box.X + (box.Width * 0.5f);
            var centerY = box.Y + (box.Height * 0.5f);
            var source = HighestPriorityIngredientZoneAt(calibration, ingredientId, centerX, centerY);

            if (!state.SourceSeen)
            {
                if (source == null) return result;

                state.SourceSeen = true;
                state.SourceZoneKey = source.ZoneKey;
                state.SourceCenterX = centerX;
                state.SourceCenterY = centerY;
                state.ContinuousFrames = 1;

                result.Started = true;
                result.SourceZoneKey = state.SourceZoneKey;
                return result;
            }

            state.ContinuousFrames++;
            result.SourceZoneKey = state.SourceZoneKey;

            // A move into a different ingredient bin invalidates the trajectory.
            var anyIngredientZone = StationCalibrationPolicy.HighestPriorityZoneAt(calibration, centerX, centerY);
            if (anyIngredientZone != null && anyIngredientZone.IngredientId != ingredientId)
            {
                state.Reset();
                return result;
            }

            var workArea = StationCalibrationPolicy.HighestPriorityWorkAreaAt(calibration, centerX, centerY, "assembly");
            if (workArea == null) return result;

            var dx = centerX - state.SourceCenterX;
            var dy = centerY - state.SourceCenterY;
            var distance = (float)Math.Sqrt((dx * dx) + (dy * dy));
            result.Distance = distance;

            if (state.ContinuousFrames < Math.Max(2, minimumFrames)
                || distance < Math.Max(0f, minimumDistance))
                return result;

            state.Completed = true;
            result.Completed = true;
            result.WorkAreaKey = workArea.AreaKey;
            result.EffectiveConfidence = Clamp01(result.BaseConfidence + Math.Max(0f, supportBoost));
            return result;
        }

        private static IngredientZone? HighestPriorityIngredientZoneAt(
            StationCalibration calibration,
            int ingredientId,
            float x,
            float y)
        {
            IngredientZone? best = null;
            foreach (var zone in calibration.Zones)
            {
                if (zone == null || zone.IngredientId != ingredientId) continue;
                if (x < zone.X || y < zone.Y || x > zone.X + zone.Width || y > zone.Y + zone.Height) continue;
                if (best == null || zone.Priority > best.Priority) best = zone;
            }
            return best;
        }

        private static float Clamp01(float value)
        {
            return Math.Max(0f, Math.Min(1f, value));
        }
    }
}
