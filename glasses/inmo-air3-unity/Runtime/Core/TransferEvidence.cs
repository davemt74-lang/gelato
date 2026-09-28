using System;
using System.Collections.Generic;

namespace Gelato.Ar.Core
{
    public enum TransferEvidenceKind
    {
        None,
        SourcePrimed,
        InTransit,
        TransferConfirmed,
        WorkSurfaceUnprimed,
        HeldOutsideWorkSurface,
        UnexpectedOutsideWorkSurface
    }

    public sealed class TransferEvidenceResult
    {
        public TransferEvidenceKind Kind { get; set; }
        public bool Applicable { get; set; }
        public bool Hold { get; set; }
        public bool TransferConfirmed { get; set; }
        public bool SequenceSupported { get; set; }
        public float EffectiveConfidence { get; set; }
        public string Action { get; set; } = "added";
        public string SourceZoneKey { get; set; } = string.Empty;
        public string DestinationRegionKey { get; set; } = string.Empty;
    }

    public sealed class TransferEvidenceTracker
    {
        private readonly Dictionary<string, TransferState> _states =
            new Dictionary<string, TransferState>(StringComparer.Ordinal);

        public int ActiveTransferCount => _states.Count;

        public void Reset()
        {
            _states.Clear();
        }

        public void MarkObservationEmitted(string componentKey, string instanceKey)
        {
            if (string.IsNullOrWhiteSpace(componentKey) || string.IsNullOrWhiteSpace(instanceKey)) return;
            var key = componentKey.Trim() + "|" + instanceKey.Trim();
            if (!_states.TryGetValue(key, out var state) || !state.Completed) return;
            state.Consumed = true;
            _states[key] = state;
        }

        public TransferEvidenceResult Evaluate(
            VisionDetection detection,
            VisionFrameContext context,
            long frameOrdinal,
            VisionPipelineOptions options)
        {
            if (detection == null) throw new ArgumentNullException(nameof(detection));
            if (context == null) throw new ArgumentNullException(nameof(context));
            if (options == null) throw new ArgumentNullException(nameof(options));

            var result = new TransferEvidenceResult
            {
                Kind = TransferEvidenceKind.None,
                Applicable = false,
                Hold = false,
                EffectiveConfidence = Clamp01(detection.Confidence),
                Action = NormalizeAction(detection.Action)
            };

            if (!options.EnableTransferEvidence) return result;

            var calibration = context.StationCalibration;
            if (calibration == null || !calibration.Compatibility.Compatible) return result;
            if (!StationCalibrationPolicy.HasRegionType(calibration, "build_surface")) return result;

            var box = VisionBoundingBox.FromArray(detection.BoundingBox);
            if (box.Area <= 0f) return result;

            var centerX = box.X + (box.Width * 0.5f);
            var centerY = box.Y + (box.Height * 0.5f);
            var buildRegion = StationCalibrationPolicy.HighestPriorityRegionAt(
                calibration,
                "build_surface",
                centerX,
                centerY
            );

            if (detection.IsUnexpected)
            {
                result.Applicable = true;
                if (buildRegion == null)
                {
                    result.Kind = TransferEvidenceKind.UnexpectedOutsideWorkSurface;
                    result.Hold = true;
                    return result;
                }

                result.DestinationRegionKey = buildRegion.RegionKey;
                return result;
            }

            var ingredientId = StationCalibrationPolicy.IngredientIdForComponent(detection.ComponentKey);
            if (ingredientId <= 0) return result;

            var sourceZones = ZonesForIngredient(calibration, ingredientId);
            if (sourceZones.Count == 0) return result;

            result.Applicable = true;
            var sourceZone = HighestPriorityIngredientZoneAt(sourceZones, centerX, centerY);
            var instanceKey = detection.InstanceKey?.Trim() ?? string.Empty;
            var stateKey = instanceKey.Length == 0
                ? string.Empty
                : detection.ComponentKey + "|" + instanceKey;

            ExpireOldStates(frameOrdinal, options.MaxTransferFrames);

            if (sourceZone != null)
            {
                result.Kind = TransferEvidenceKind.SourcePrimed;
                result.Hold = true;
                result.SourceZoneKey = sourceZone.ZoneKey;

                if (stateKey.Length > 0)
                {
                    _states[stateKey] = new TransferState
                    {
                        ComponentKey = detection.ComponentKey,
                        InstanceKey = instanceKey,
                        SourceZoneKey = sourceZone.ZoneKey,
                        SourceFrameOrdinal = frameOrdinal,
                        LastFrameOrdinal = frameOrdinal,
                        Completed = false,
                        Consumed = false
                    };
                }
                return result;
            }

            if (buildRegion != null)
            {
                result.DestinationRegionKey = buildRegion.RegionKey;

                if (stateKey.Length > 0
                    && _states.TryGetValue(stateKey, out var state)
                    && frameOrdinal >= state.SourceFrameOrdinal
                    && frameOrdinal - state.SourceFrameOrdinal <= options.MaxTransferFrames)
                {
                    state.LastFrameOrdinal = frameOrdinal;

                    if (!state.Completed)
                    {
                        state.Completed = true;
                        state.Consumed = false;
                        _states[stateKey] = state;
                    }

                    if (!state.Consumed)
                    {
                        state.LastFrameOrdinal = frameOrdinal;
                        _states[stateKey] = state;

                        result.Kind = TransferEvidenceKind.TransferConfirmed;
                        result.TransferConfirmed = true;
                        result.SourceZoneKey = state.SourceZoneKey;
                        result.SequenceSupported = IsCurrentRecipeStep(detection.ComponentKey, context);
                        result.Action = "added";
                        result.EffectiveConfidence = Clamp01(
                            detection.Confidence
                            + options.TransferSupportBoost
                            + (result.SequenceSupported ? options.SequenceSupportBoost : 0f)
                        );
                        return result;
                    }

                    // The same tracked physical instance already produced its durable observation.
                    // Do not create another additive event unless it returns to the source zone and
                    // a new source → build movement is established.
                    state.LastFrameOrdinal = frameOrdinal;
                    _states[stateKey] = state;
                    result.Kind = TransferEvidenceKind.WorkSurfaceUnprimed;
                    result.Action = "seen";
                    result.EffectiveConfidence = Math.Min(
                        Clamp01(detection.Confidence),
                        options.UnprimedWorkSurfaceConfidenceCap
                    );
                    return result;
                }

                // The ingredient is visibly on the build surface, but its move from the source pan
                // was not proven. Preserve it as evidence but keep it below auto-confirm confidence.
                result.Kind = TransferEvidenceKind.WorkSurfaceUnprimed;
                result.Action = "seen";
                result.EffectiveConfidence = Math.Min(
                    Clamp01(detection.Confidence),
                    options.UnprimedWorkSurfaceConfidenceCap
                );
                return result;
            }

            if (stateKey.Length > 0
                && _states.TryGetValue(stateKey, out var inTransit)
                && frameOrdinal - inTransit.SourceFrameOrdinal <= options.MaxTransferFrames)
            {
                inTransit.LastFrameOrdinal = frameOrdinal;
                _states[stateKey] = inTransit;
                result.Kind = TransferEvidenceKind.InTransit;
                result.Hold = true;
                result.SourceZoneKey = inTransit.SourceZoneKey;
                return result;
            }

            // With a calibrated source and build surface, presence elsewhere is not equivalent to
            // an ingredient being added to the product.
            result.Kind = TransferEvidenceKind.HeldOutsideWorkSurface;
            result.Hold = true;
            return result;
        }

        private void ExpireOldStates(long frameOrdinal, int maxTransferFrames)
        {
            if (_states.Count == 0) return;
            var expired = new List<string>();
            foreach (var pair in _states)
            {
                if (frameOrdinal - pair.Value.LastFrameOrdinal > maxTransferFrames)
                    expired.Add(pair.Key);
            }
            foreach (var key in expired) _states.Remove(key);
        }

        private static IReadOnlyList<IngredientZone> ZonesForIngredient(StationCalibration calibration, int ingredientId)
        {
            var zones = new List<IngredientZone>();
            foreach (var zone in calibration.Zones)
                if (zone != null && zone.IngredientId == ingredientId) zones.Add(zone);
            zones.Sort((a,b) => b.Priority.CompareTo(a.Priority));
            return zones;
        }

        private static IngredientZone? HighestPriorityIngredientZoneAt(
            IReadOnlyList<IngredientZone> zones,
            float x,
            float y)
        {
            IngredientZone? best = null;
            foreach (var zone in zones)
            {
                if (zone == null) continue;
                if (x < zone.X || y < zone.Y || x > zone.X + zone.Width || y > zone.Y + zone.Height) continue;
                if (best == null || zone.Priority > best.Priority) best = zone;
            }
            return best;
        }

        private static bool IsCurrentRecipeStep(string componentKey, VisionFrameContext context)
        {
            if (string.IsNullOrWhiteSpace(componentKey)
                || context.BuildSteps == null
                || context.BuildSteps.Count == 0)
                return false;

            var componentStates = new Dictionary<string,string>(StringComparer.Ordinal);
            foreach (var component in context.ExpectedComponents)
            {
                if (component == null || string.IsNullOrWhiteSpace(component.ComponentKey)) continue;
                componentStates[component.ComponentKey] = component.Status ?? string.Empty;
            }

            var ordered = new List<BuildStep>(context.BuildSteps);
            ordered.Sort((a,b) => a.Order.CompareTo(b.Order));

            foreach (var step in ordered)
            {
                if (step == null || step.ComponentKeys == null || step.ComponentKeys.Count == 0) continue;

                var complete = true;
                foreach (var key in step.ComponentKeys)
                {
                    if (!componentStates.TryGetValue(key, out var status)
                        || (!string.Equals(status, "confirmed", StringComparison.Ordinal)
                            && !string.Equals(status, "ignored", StringComparison.Ordinal)))
                    {
                        complete = false;
                        break;
                    }
                }

                if (complete) continue;

                foreach (var key in step.ComponentKeys)
                    if (string.Equals(key, componentKey, StringComparison.Ordinal)) return true;
                return false;
            }

            return false;
        }

        private static string NormalizeAction(string action)
        {
            if (string.Equals(action, "removed", StringComparison.Ordinal)) return "removed";
            if (string.Equals(action, "seen", StringComparison.Ordinal)) return "seen";
            return "added";
        }

        private static float Clamp01(float value)
        {
            return Math.Max(0f, Math.Min(1f, value));
        }

        private sealed class TransferState
        {
            public string ComponentKey = string.Empty;
            public string InstanceKey = string.Empty;
            public string SourceZoneKey = string.Empty;
            public long SourceFrameOrdinal;
            public long LastFrameOrdinal;
            public bool Completed;
            public bool Consumed;
        }
    }
}
