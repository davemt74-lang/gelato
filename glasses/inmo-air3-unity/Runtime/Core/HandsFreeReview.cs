using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public enum HandsFreeCommandKind
    {
        Unknown,
        RefreshWork,
        StartBuild,
        EvaluateProduct,
        ConfirmVerify,
        RejectLastObservation,
        ResolveUnexpected,
        SendToExpo
    }

    public sealed class ReviewFeedback
    {
        public HandsFreeCommandKind Command { get; set; }
        public bool Success { get; set; }
        public bool Attention { get; set; }
        public string Title { get; set; } = string.Empty;
        public string Message { get; set; } = string.Empty;
    }

    public sealed class HandsFreeCommand
    {
        public HandsFreeCommandKind Kind { get; set; }
        public string Raw { get; set; } = string.Empty;
        public string Target { get; set; } = string.Empty;
    }

    public sealed class HandsFreeCommandResult
    {
        public bool Handled { get; set; }
        public bool Succeeded { get; set; }
        public HandsFreeCommandKind Kind { get; set; }
        public string Message { get; set; } = string.Empty;
    }

    public static class HandsFreeCommandParser
    {
        public static HandsFreeCommand Parse(string raw)
        {
            var normalized = Normalize(raw);
            var result = new HandsFreeCommand { Raw = raw ?? string.Empty, Kind = HandsFreeCommandKind.Unknown };
            if (normalized.Length == 0) return result;

            if (Is(normalized, "refresh", "refresh work", "refresh order", "load work", "load order"))
            {
                result.Kind = HandsFreeCommandKind.RefreshWork;
                return result;
            }
            if (Is(normalized, "start", "start build", "begin build", "start order"))
            {
                result.Kind = HandsFreeCommandKind.StartBuild;
                return result;
            }
            if (Is(normalized, "check", "check product", "validate", "validate product", "evaluate", "evaluate product"))
            {
                result.Kind = HandsFreeCommandKind.EvaluateProduct;
                return result;
            }
            if (Is(normalized, "undo last", "reject last", "reject last observation", "undo last ingredient", "wrong ingredient"))
            {
                result.Kind = HandsFreeCommandKind.RejectLastObservation;
                return result;
            }
            if (Is(normalized, "send expo", "send to expo", "expo", "finish", "finish item"))
            {
                result.Kind = HandsFreeCommandKind.SendToExpo;
                return result;
            }

            if (TryPrefix(normalized, new[] { "confirm", "confirm ingredient", "accept", "accept ingredient" }, out var confirmTarget))
            {
                result.Kind = HandsFreeCommandKind.ConfirmVerify;
                result.Target = confirmTarget;
                return result;
            }

            if (TryPrefix(normalized, new[] { "resolve unexpected", "ignore unexpected", "clear unexpected" }, out var unexpectedTarget))
            {
                result.Kind = HandsFreeCommandKind.ResolveUnexpected;
                result.Target = unexpectedTarget;
                return result;
            }

            return result;
        }

        public static string Normalize(string value)
        {
            if (string.IsNullOrWhiteSpace(value)) return string.Empty;
            var builder = new StringBuilder(value.Length);
            var spacing = false;
            foreach (var ch in value.Trim().ToLowerInvariant())
            {
                if (char.IsLetterOrDigit(ch))
                {
                    builder.Append(ch);
                    spacing = false;
                }
                else if (!spacing && builder.Length > 0)
                {
                    builder.Append(' ');
                    spacing = true;
                }
            }
            return builder.ToString().Trim();
        }

        private static bool Is(string value, params string[] options)
        {
            foreach (var option in options)
                if (string.Equals(value, option, StringComparison.Ordinal)) return true;
            return false;
        }

        private static bool TryPrefix(string value, IReadOnlyList<string> prefixes, out string target)
        {
            foreach (var prefix in prefixes.OrderByDescending(x => x.Length))
            {
                if (string.Equals(value, prefix, StringComparison.Ordinal))
                {
                    target = string.Empty;
                    return true;
                }
                var token = prefix + " ";
                if (value.StartsWith(token, StringComparison.Ordinal))
                {
                    target = value.Substring(token.Length).Trim();
                    return true;
                }
            }
            target = string.Empty;
            return false;
        }
    }

    public sealed class HandsFreeCommandRouter
    {
        private readonly ArWorkflowCoordinator _coordinator;

        public HandsFreeCommandRouter(ArWorkflowCoordinator coordinator)
        {
            _coordinator = coordinator ?? throw new ArgumentNullException(nameof(coordinator));
        }

        public async Task<HandsFreeCommandResult> ExecuteAsync(string rawCommand, CancellationToken cancellationToken = default)
        {
            var command = HandsFreeCommandParser.Parse(rawCommand);
            if (command.Kind == HandsFreeCommandKind.Unknown)
                return Feedback(command.Kind, false, false, "COMMAND NOT RECOGNIZED", "Use a supported kitchen command.");

            try
            {
                switch (command.Kind)
                {
                    case HandsFreeCommandKind.RefreshWork:
                    {
                        var work = await _coordinator.RefreshWorkAsync(cancellationToken).ConfigureAwait(false);
                        var message = work.FocusItem == null ? "No focused kitchen work." : "Loaded " + work.FocusItem.Name + ".";
                        return Feedback(command.Kind, true, false, "WORK REFRESHED", message);
                    }
                    case HandsFreeCommandKind.StartBuild:
                    {
                        var build = await _coordinator.StartFocusBuildAsync(cancellationToken).ConfigureAwait(false);
                        return Feedback(command.Kind, true, false, "BUILD STARTED", build.Components.Count + " components loaded.");
                    }
                    case HandsFreeCommandKind.EvaluateProduct:
                    {
                        var validation = await _coordinator.EvaluateAsync(cancellationToken).ConfigureAwait(false);
                        var ready = ArWorkflowCoordinator.IsReadyForFinishing(validation);
                        return Feedback(command.Kind, true, !ready, ready ? "PRODUCT READY" : "PRODUCT CHECKED",
                            ready ? "All required validation is complete." : "Build still needs attention.");
                    }
                    case HandsFreeCommandKind.ConfirmVerify:
                    {
                        var target = SelectComponent("verify", command.Target);
                        if (target == null) return _lastSelectionFailure!;
                        var validation = await _coordinator.ConfirmComponentAsync(target.ComponentKey, cancellationToken).ConfigureAwait(false);
                        return Feedback(command.Kind, true, false, "CONFIRMED", target.DisplayName + " confirmed. " + ValidationSuffix(validation));
                    }
                    case HandsFreeCommandKind.RejectLastObservation:
                    {
                        if (string.IsNullOrWhiteSpace(_coordinator.LastSubmittedObservationKey))
                            return Feedback(command.Kind, false, true, "NOTHING TO UNDO", "No submitted vision observation is available.");
                        var validation = await _coordinator.RejectLastObservationAsync("Hands-free cook correction", cancellationToken).ConfigureAwait(false);
                        return Feedback(command.Kind, true, false, "LAST OBSERVATION REJECTED", ValidationSuffix(validation));
                    }
                    case HandsFreeCommandKind.ResolveUnexpected:
                    {
                        var target = SelectComponent("unexpected", command.Target);
                        if (target == null) return _lastSelectionFailure!;
                        var validation = await _coordinator.ResolveUnexpectedAsync(target.ComponentKey, cancellationToken).ConfigureAwait(false);
                        return Feedback(command.Kind, true, false, "UNEXPECTED ITEM RESOLVED", target.DisplayName + " cleared. " + ValidationSuffix(validation));
                    }
                    case HandsFreeCommandKind.SendToExpo:
                    {
                        if (!ArWorkflowCoordinator.IsReadyForFinishing(_coordinator.Validation))
                            return Feedback(command.Kind, false, true, "EXPO BLOCKED", "Product validation is not ready for Expo / Finishing.");
                        var handoff = await _coordinator.HandoffToExpoAsync(cancellationToken).ConfigureAwait(false);
                        return Feedback(command.Kind, true, false, "SENT TO EXPO", string.IsNullOrWhiteSpace(handoff.Label) ? "Handoff complete." : handoff.Label);
                    }
                    default:
                        return Feedback(command.Kind, false, false, "COMMAND NOT RECOGNIZED", "Use a supported kitchen command.");
                }
            }
            catch (OperationCanceledException) { throw; }
            catch (Exception ex)
            {
                return Feedback(command.Kind, false, true, "COMMAND FAILED", ex.Message);
            }
        }

        private HandsFreeCommandResult? _lastSelectionFailure;

        private BuildComponent? SelectComponent(string requiredStatus, string target)
        {
            _lastSelectionFailure = null;
            var build = _coordinator.BuildSession;
            if (build == null)
            {
                _lastSelectionFailure = Feedback(HandsFreeCommandKind.Unknown, false, true, "NO ACTIVE BUILD", "Start a build before reviewing ingredients.");
                return null;
            }

            var candidates = build.Components
                .Where(component => string.Equals(component.Status, requiredStatus, StringComparison.Ordinal))
                .ToList();

            if (candidates.Count == 0)
            {
                _lastSelectionFailure = Feedback(HandsFreeCommandKind.Unknown, false, false, "NOTHING TO REVIEW",
                    requiredStatus == "verify" ? "No ingredient is waiting for confirmation." : "No unexpected ingredient is waiting for resolution.");
                return null;
            }

            var normalizedTarget = HandsFreeCommandParser.Normalize(target);
            if (normalizedTarget.Length == 0)
            {
                if (candidates.Count == 1) return candidates[0];
                _lastSelectionFailure = Feedback(HandsFreeCommandKind.Unknown, false, true, "SAY THE INGREDIENT",
                    candidates.Count + " items need review. Name the ingredient.");
                return null;
            }

            var matches = candidates.Where(component =>
            {
                var name = HandsFreeCommandParser.Normalize(component.DisplayName);
                var key = HandsFreeCommandParser.Normalize(component.ComponentKey);
                return name == normalizedTarget || name.Contains(normalizedTarget) || normalizedTarget.Contains(name)
                    || key == normalizedTarget || key.Contains(normalizedTarget);
            }).ToList();

            if (matches.Count == 1) return matches[0];

            _lastSelectionFailure = Feedback(HandsFreeCommandKind.Unknown, false, true,
                matches.Count == 0 ? "INGREDIENT NOT FOUND" : "INGREDIENT AMBIGUOUS",
                matches.Count == 0 ? "No matching review item was found." : "More than one review item matches that name.");
            return null;
        }

        private static string ValidationSuffix(ProductValidation validation)
        {
            return ArWorkflowCoordinator.IsReadyForFinishing(validation)
                ? "Ready for Expo / Finishing."
                : "Validation still pending.";
        }

        private HandsFreeCommandResult Feedback(HandsFreeCommandKind kind, bool success, bool attention, string title, string message)
        {
            var feedback = new ReviewFeedback
            {
                Command = kind,
                Success = success,
                Attention = attention,
                Title = title,
                Message = message
            };
            _coordinator.SetReviewFeedback(feedback);
            return new HandsFreeCommandResult
            {
                Handled = kind != HandsFreeCommandKind.Unknown,
                Succeeded = success,
                Kind = kind,
                Message = message
            };
        }
    }
}
