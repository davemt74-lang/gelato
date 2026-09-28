using System;
using System.Collections.Generic;
using System.Text.RegularExpressions;

namespace Gelato.Ar.Core
{
    public static class VisionModelAssignmentPolicy
    {
        private static readonly Regex Sha256 = new Regex("^[a-f0-9]{64}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);

        public static bool CanApply(VisionModelAssignment? assignment, string detectorName, out IReadOnlyList<string> reasons)
        {
            var failures = new List<string>();

            if (assignment == null)
            {
                failures.Add("assignment_missing");
                reasons = failures;
                return false;
            }

            if (!string.Equals(assignment.Action, "apply", StringComparison.Ordinal))
                failures.Add("assignment_action_not_apply");

            if (!assignment.Compatibility.Compatible)
                failures.Add("server_compatibility_failed");

            if (!string.Equals(
                NormalizeDetector(assignment.DetectorName),
                NormalizeDetector(detectorName),
                StringComparison.Ordinal))
                failures.Add("detector_mismatch");

            var package = assignment.Package;
            if (package == null)
            {
                failures.Add("package_missing");
            }
            else
            {
                if (!string.Equals(
                    NormalizeDetector(package.DetectorName),
                    NormalizeDetector(detectorName),
                    StringComparison.Ordinal))
                    failures.Add("package_detector_mismatch");

                if (!Uri.TryCreate(package.ArtifactUrl, UriKind.Absolute, out var uri)
                    || !string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
                    failures.Add("artifact_url_not_https");

                var sha = (package.ArtifactSha256 ?? string.Empty).Trim().ToLowerInvariant();
                if (!Sha256.IsMatch(sha))
                    failures.Add("artifact_sha256_invalid");

                if (string.IsNullOrWhiteSpace(package.RuntimeType))
                    failures.Add("runtime_type_missing");

                if (string.IsNullOrWhiteSpace(package.Platform))
                    failures.Add("platform_missing");
            }

            if (assignment.Rollout == null)
                failures.Add("rollout_missing");

            reasons = failures;
            return failures.Count == 0;
        }

        public static VisionModelReport AssignmentSeenReport(VisionModelAssignment assignment)
        {
            if (assignment == null) throw new ArgumentNullException(nameof(assignment));
            return new VisionModelReport
            {
                AssignmentKey = assignment.AssignmentKey,
                ReportKey = assignment.AssignmentKey + ":seen",
                ReportType = "assignment_seen",
                RolloutPublicId = assignment.Rollout?.PublicId ?? string.Empty,
                PackagePublicId = assignment.Package?.PublicId ?? string.Empty,
                RuntimeState = "assignment_received",
                ArtifactSha256 = assignment.Package?.ArtifactSha256 ?? string.Empty,
                Message = assignment.Action == "apply"
                    ? "Governed model assignment received; runtime activation requires a verified model-loader implementation."
                    : "Governed model assignment received with hold action."
            };
        }

        private static string NormalizeDetector(string value)
        {
            if (string.IsNullOrWhiteSpace(value)) return string.Empty;
            var chars = new List<char>(value.Length);
            var dash = false;
            foreach (var ch in value.Trim().ToLowerInvariant())
            {
                if (char.IsLetterOrDigit(ch) || ch == '.' || ch == '_' || ch == '-')
                {
                    if (dash && chars.Count > 0 && chars[chars.Count - 1] != '-') chars.Add('-');
                    chars.Add(ch);
                    dash = false;
                }
                else
                {
                    dash = true;
                }
            }
            return new string(chars.ToArray()).Trim('-');
        }
    }
}
