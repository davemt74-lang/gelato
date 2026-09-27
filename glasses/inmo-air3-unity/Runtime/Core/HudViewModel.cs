using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text;

namespace Gelato.Ar.Core
{
    public readonly struct HudRect
    {
        public HudRect(float x, float y, float width, float height)
        {
            X = x;
            Y = y;
            Width = width;
            Height = height;
        }

        public float X { get; }
        public float Y { get; }
        public float Width { get; }
        public float Height { get; }
        public float Right => X + Width;
        public float Bottom => Y + Height;
    }

    public static class HudLayoutPolicy
    {
        public const float ReferenceWidth = 1920f;
        public const float ReferenceHeight = 1080f;
        public const float RightRailWidth = 520f;
        public const float RightMargin = 48f;
        public const float RailGapFromCenter = 72f;
        public const float TransientOutlineSeconds = 1.25f;

        public static HudRect RightRail => new HudRect(
            ReferenceWidth - RightMargin - RightRailWidth,
            48f,
            RightRailWidth,
            ReferenceHeight - 96f
        );

        public static HudRect CenterSafeZone => new HudRect(
            80f,
            70f,
            RightRail.X - RailGapFromCenter - 80f,
            ReferenceHeight - 140f
        );

        public static bool PersistentUiClearsCenter()
        {
            return CenterSafeZone.Right + RailGapFromCenter <= RightRail.X;
        }
    }

    public sealed class HudViewModel
    {
        public string ItemTitle { get; set; } = string.Empty;
        public string ItemBody { get; set; } = string.Empty;
        public string BuildBody { get; set; } = string.Empty;
        public string ValidationBody { get; set; } = string.Empty;
        public bool ShowNext { get; set; }
        public string NextTitle { get; set; } = string.Empty;
        public string NextBody { get; set; } = string.Empty;
    }

    public static class HudViewModelFactory
    {
        public static HudViewModel Create(
            CurrentWork? work,
            BuildSession? build,
            ProductValidation? validation,
            ExpoHandoff? handoff)
        {
            var focus = work?.FocusItem;
            var model = new HudViewModel
            {
                ItemTitle = string.IsNullOrWhiteSpace(focus?.Name) ? "NO ACTIVE ITEM" : focus!.Name,
                ItemBody = FormatItemBody(focus),
                BuildBody = FormatBuild(build),
                ValidationBody = FormatValidation(build, validation)
            };

            if (handoff != null)
            {
                model.ShowNext = true;
                model.NextTitle = "SENT TO EXPO / FINISHING";
                model.NextBody = string.IsNullOrWhiteSpace(handoff.KdsStatus)
                    ? "Handoff complete."
                    : "KDS: " + handoff.KdsStatus.ToUpperInvariant();
            }
            else if (validation?.Next != null && validation.Next.Available)
            {
                model.ShowNext = true;
                model.NextTitle = string.IsNullOrWhiteSpace(validation.Next.Label)
                    ? "EXPO / FINISHING"
                    : validation.Next.Label.ToUpperInvariant();
                model.NextBody = string.IsNullOrWhiteSpace(validation.Next.Message)
                    ? "All ingredients accounted for."
                    : validation.Next.Message;
            }

            return model;
        }

        private static string FormatItemBody(WorkItem? focus)
        {
            if (focus == null) return "Waiting for station work.";
            var status = string.IsNullOrWhiteSpace(focus.Status) ? string.Empty : focus.Status.ToUpperInvariant();
            if (string.IsNullOrWhiteSpace(focus.SpecialInstructions)) return status;
            if (string.IsNullOrWhiteSpace(status)) return focus.SpecialInstructions;
            return status + Environment.NewLine + focus.SpecialInstructions;
        }

        private static string FormatBuild(BuildSession? build)
        {
            if (build == null) return "Start the focused KDS item to load build steps.";

            var components = build.Components.ToDictionary(c => c.ComponentKey, StringComparer.Ordinal);
            if (build.BuildSteps.Count > 0)
            {
                var builder = new StringBuilder();
                var activeAssigned = false;
                foreach (var step in build.BuildSteps.OrderBy(s => s.Order))
                {
                    var keys = step.ComponentKeys ?? Array.Empty<string>();
                    var referenced = keys.Where(components.ContainsKey).Select(k => components[k]).ToArray();
                    var complete = referenced.Length > 0 && referenced.All(IsComplete);
                    var verify = referenced.Any(c => string.Equals(c.Status, "verify", StringComparison.Ordinal));

                    string prefix;
                    if (keys.Count == 0)
                    {
                        prefix = "•";
                    }
                    else if (complete)
                    {
                        prefix = "[x]";
                    }
                    else if (verify)
                    {
                        prefix = "[?]";
                    }
                    else if (!activeAssigned)
                    {
                        prefix = ">";
                        activeAssigned = true;
                    }
                    else
                    {
                        prefix = "[ ]";
                    }

                    if (builder.Length > 0) builder.AppendLine();
                    builder.Append(prefix).Append(' ').Append(step.Text);
                }
                return builder.ToString();
            }

            var fallback = new StringBuilder();
            foreach (var component in build.Components)
            {
                if (fallback.Length > 0) fallback.AppendLine();
                fallback.Append(IsComplete(component) ? "[x] " : "[ ] ")
                    .Append(component.DisplayName)
                    .Append(' ')
                    .Append(FormatQuantity(component.DetectedQuantity))
                    .Append('/')
                    .Append(FormatQuantity(component.ExpectedQuantity));
            }
            return fallback.Length == 0 ? "No build definition is available." : fallback.ToString();
        }

        private static string FormatValidation(BuildSession? build, ProductValidation? validation)
        {
            var builder = new StringBuilder();
            if (validation == null)
            {
                builder.Append("Waiting for product validation.");
            }
            else if (validation.AllIngredientsAccountedFor)
            {
                builder.Append("ALL INGREDIENTS ACCOUNTED FOR");
            }
            else if (string.Equals(validation.Status, "blocked", StringComparison.Ordinal))
            {
                builder.Append("VALIDATION BLOCKED");
            }
            else
            {
                builder.Append("VALIDATING PRODUCT");
            }

            if (build == null || build.Components.Count == 0) return builder.ToString();

            foreach (var component in build.Components)
            {
                builder.AppendLine();
                builder.Append(StatusMark(component.Status))
                    .Append(' ')
                    .Append(component.DisplayName)
                    .Append("  ")
                    .Append(FormatQuantity(component.DetectedQuantity))
                    .Append('/')
                    .Append(FormatQuantity(component.ExpectedQuantity));

                if (!string.IsNullOrWhiteSpace(component.Unit))
                    builder.Append(' ').Append(component.Unit);
            }

            return builder.ToString();
        }

        private static bool IsComplete(BuildComponent component)
        {
            return string.Equals(component.Status, "confirmed", StringComparison.Ordinal)
                || string.Equals(component.Status, "ignored", StringComparison.Ordinal);
        }

        private static string StatusMark(string status)
        {
            if (string.Equals(status, "confirmed", StringComparison.Ordinal)) return "[x]";
            if (string.Equals(status, "verify", StringComparison.Ordinal)) return "[?]";
            if (string.Equals(status, "unexpected", StringComparison.Ordinal)) return "[!]";
            return "[ ]";
        }

        private static string FormatQuantity(float value)
        {
            return value.ToString("0.###", CultureInfo.InvariantCulture);
        }
    }
}
