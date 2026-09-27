using System;
using System.Globalization;

namespace Gelato.Ar.Core
{
    public static class SimulatorObservationFactory
    {
        public static IngredientObservation Create(
            BuildComponent component,
            int sequence,
            bool lowConfidence = false,
            float? quantityOverride = null)
        {
            if (component == null) throw new ArgumentNullException(nameof(component));
            if (string.IsNullOrWhiteSpace(component.ComponentKey))
                throw new ArgumentException("Simulator component key is required.", nameof(component));
            if (sequence < 1) throw new ArgumentOutOfRangeException(nameof(sequence));

            var remaining = Math.Max(0.001f, component.ExpectedQuantity - component.DetectedQuantity);
            var quantity = quantityOverride.HasValue
                ? Math.Max(0.001f, quantityOverride.Value)
                : remaining;

            var box = DeterministicBox(sequence);

            return new IngredientObservation
            {
                ObservationKey = "sim-" + sequence.ToString(CultureInfo.InvariantCulture) + "-" + Sanitize(component.ComponentKey),
                ComponentKey = component.ComponentKey,
                DisplayName = component.DisplayName,
                Action = "added",
                Quantity = quantity,
                Confidence = lowConfidence ? 0.68f : 0.96f,
                TrackingId = "sim-track-" + sequence.ToString(CultureInfo.InvariantCulture),
                BoundingBox = box
            };
        }

        public static IngredientObservation CreateUnexpected(string displayName, int sequence)
        {
            if (sequence < 1) throw new ArgumentOutOfRangeException(nameof(sequence));
            var name = string.IsNullOrWhiteSpace(displayName) ? "Unexpected Ingredient" : displayName.Trim();
            return new IngredientObservation
            {
                ObservationKey = "sim-unexpected-" + sequence.ToString(CultureInfo.InvariantCulture),
                ComponentKey = "sim:unexpected:" + Sanitize(name),
                DisplayName = name,
                Action = "added",
                Quantity = 1f,
                Confidence = 0.95f,
                TrackingId = "sim-unexpected-track-" + sequence.ToString(CultureInfo.InvariantCulture),
                BoundingBox = DeterministicBox(sequence + 17)
            };
        }

        public static float[] DeterministicBox(int sequence)
        {
            if (sequence < 1) throw new ArgumentOutOfRangeException(nameof(sequence));

            // Keep simulated ingredient boxes in the central/lower work area, away from the persistent right rail.
            var slot = (sequence - 1) % 6;
            var column = slot % 3;
            var row = slot / 3;

            return new[]
            {
                0.16f + (column * 0.14f),
                0.38f + (row * 0.18f),
                0.12f,
                0.12f
            };
        }

        private static string Sanitize(string value)
        {
            var chars = value.Trim().ToLowerInvariant().ToCharArray();
            for (var i = 0; i < chars.Length; i++)
            {
                if (!char.IsLetterOrDigit(chars[i])) chars[i] = '-';
            }
            return new string(chars).Trim('-');
        }
    }

    public static class SimulatorHotkeys
    {
        public const string Pair = "P";
        public const string RefreshWork = "F1";
        public const string StartBuild = "F2";
        public const string Evaluate = "V";
        public const string HandoffExpo = "E";
        public const string Reset = "R";
        public const string Unexpected = "U";
        public const string LowConfidenceModifier = "Left Shift";
        public const string ComponentRange = "1-9";
    }
}
