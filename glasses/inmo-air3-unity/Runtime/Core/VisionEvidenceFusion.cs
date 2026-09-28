using System;

namespace Gelato.Ar.Core
{
    public enum SpatialEvidenceKind
    {
        None,
        Support,
        Conflict
    }

    public sealed class SpatialEvidenceResult
    {
        public SpatialEvidenceKind Kind { get; set; }
        public float RawConfidence { get; set; }
        public float EffectiveConfidence { get; set; }
        public string ZoneKey { get; set; } = string.Empty;
        public int ZoneIngredientId { get; set; }
    }

    public static class VisionEvidenceFusion
    {
        public static SpatialEvidenceResult Evaluate(
            VisionDetection detection,
            StationCalibration? calibration,
            CameraFrame frame,
            float supportBoost,
            float conflictPenalty)
        {
            if (detection == null) throw new ArgumentNullException(nameof(detection));
            if (frame == null) throw new ArgumentNullException(nameof(frame));

            var raw = Clamp01(detection.Confidence);
            var result = new SpatialEvidenceResult
            {
                Kind = SpatialEvidenceKind.None,
                RawConfidence = raw,
                EffectiveConfidence = raw
            };

            if (detection.IsUnexpected
                || calibration == null
                || !StationCalibrationPolicy.CanUse(calibration, frame))
                return result;

            var ingredientId = StationCalibrationPolicy.IngredientIdForComponent(detection.ComponentKey);
            if (ingredientId <= 0 || calibration.Zones == null || calibration.Zones.Count == 0)
                return result;

            var box = VisionBoundingBox.FromArray(detection.BoundingBox);
            if (box.Area <= 0f) return result;

            var centerX = box.X + (box.Width * 0.5f);
            var centerY = box.Y + (box.Height * 0.5f);

            IngredientZone? bestSupport = null;
            IngredientZone? bestConflict = null;

            foreach (var zone in calibration.Zones)
            {
                if (zone == null || zone.Width <= 0f || zone.Height <= 0f) continue;
                if (!Contains(zone, centerX, centerY)) continue;

                if (zone.IngredientId == ingredientId)
                {
                    if (bestSupport == null || zone.Priority > bestSupport.Priority)
                        bestSupport = zone;
                }
                else
                {
                    if (bestConflict == null || zone.Priority > bestConflict.Priority)
                        bestConflict = zone;
                }
            }

            // Matching spatial evidence wins over an overlapping conflicting zone. This lets
            // a deliberately nested/overlapping station layout remain deterministic.
            if (bestSupport != null)
            {
                result.Kind = SpatialEvidenceKind.Support;
                result.ZoneKey = bestSupport.ZoneKey;
                result.ZoneIngredientId = bestSupport.IngredientId;
                result.EffectiveConfidence = Clamp01(raw + Math.Max(0f, supportBoost));
                return result;
            }

            if (bestConflict != null)
            {
                result.Kind = SpatialEvidenceKind.Conflict;
                result.ZoneKey = bestConflict.ZoneKey;
                result.ZoneIngredientId = bestConflict.IngredientId;
                result.EffectiveConfidence = Clamp01(raw - Math.Max(0f, conflictPenalty));
            }

            return result;
        }

        private static bool Contains(IngredientZone zone, float x, float y)
        {
            return x >= zone.X
                && y >= zone.Y
                && x <= zone.X + zone.Width
                && y <= zone.Y + zone.Height;
        }

        private static float Clamp01(float value)
        {
            return Math.Max(0f, Math.Min(1f, value));
        }
    }
}
