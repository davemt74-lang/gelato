using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public sealed class VisionPipeline
    {
        private readonly IVisionDetector _detector;
        private readonly VisionPipelineOptions _options;
        private readonly List<TrackState> _tracks = new List<TrackState>();
        private string _sessionPublicId = string.Empty;
        private int _nextTrackId = 1;

        public VisionPipeline(IVisionDetector detector, VisionPipelineOptions? options = null)
        {
            _detector = detector ?? throw new ArgumentNullException(nameof(detector));
            _options = options ?? new VisionPipelineOptions();
            _options.Validate();
        }

        public string DetectorName => _detector.DetectorName;
        public int ActiveTrackCount => _tracks.Count;

        public void Reset(string? buildSessionPublicId = null)
        {
            _tracks.Clear();
            _nextTrackId = 1;
            _sessionPublicId = buildSessionPublicId?.Trim() ?? string.Empty;
        }

        public async Task<IReadOnlyList<IngredientObservation>> ProcessAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken = default)
        {
            if (frame == null) throw new ArgumentNullException(nameof(frame));
            if (context == null) throw new ArgumentNullException(nameof(context));
            if (string.IsNullOrWhiteSpace(context.BuildSessionPublicId))
                throw new InvalidOperationException("Vision processing requires an active build-session identity.");

            if (!string.Equals(_sessionPublicId, context.BuildSessionPublicId, StringComparison.Ordinal))
                Reset(context.BuildSessionPublicId);

            cancellationToken.ThrowIfCancellationRequested();
            var detections = await _detector.DetectAsync(frame, context, cancellationToken).ConfigureAwait(false)
                ?? Array.Empty<VisionDetection>();

            var expected = new HashSet<string>(
                context.ExpectedComponents
                    .Where(c => !string.IsNullOrWhiteSpace(c.ComponentKey))
                    .Select(c => c.ComponentKey),
                StringComparer.Ordinal
            );

            var accepted = new List<VisionDetection>();
            foreach (var detection in detections)
            {
                if (detection == null) continue;
                if (detection.Confidence < _options.MinimumConfidence) continue;
                if (string.IsNullOrWhiteSpace(detection.ComponentKey)) continue;
                if (!detection.IsUnexpected && !expected.Contains(detection.ComponentKey)) continue;
                if (detection.Quantity <= 0f) continue;

                var box = VisionBoundingBox.FromArray(detection.BoundingBox);
                if (box.Area <= 0f) continue;

                detection.BoundingBox = box.ToArray();
                detection.Confidence = Math.Max(0f, Math.Min(1f, detection.Confidence));
                accepted.Add(detection);
            }

            var matched = new HashSet<int>();
            var observations = new List<IngredientObservation>();

            foreach (var detection in accepted)
            {
                var track = FindTrack(detection, matched);
                if (track == null)
                {
                    track = new TrackState
                    {
                        TrackId = _nextTrackId++,
                        ComponentKey = detection.ComponentKey,
                        DisplayName = detection.DisplayName,
                        InstanceKey = detection.InstanceKey,
                        Box = VisionBoundingBox.FromArray(detection.BoundingBox),
                        StableFrames = 1,
                        MissedFrames = 0,
                        Confidence = detection.Confidence,
                        Quantity = detection.Quantity,
                        Action = NormalizeAction(detection.Action),
                        IsUnexpected = detection.IsUnexpected
                    };
                    _tracks.Add(track);
                }
                else
                {
                    track.StableFrames = track.MissedFrames > 0 ? 1 : track.StableFrames + 1;
                    track.MissedFrames = 0;
                    track.Box = VisionBoundingBox.FromArray(detection.BoundingBox);
                    track.Confidence = detection.Confidence;
                    track.Quantity = detection.Quantity;
                    track.Action = NormalizeAction(detection.Action);
                    track.DisplayName = string.IsNullOrWhiteSpace(detection.DisplayName)
                        ? track.DisplayName
                        : detection.DisplayName;
                    track.IsUnexpected = detection.IsUnexpected;
                    if (!string.IsNullOrWhiteSpace(detection.InstanceKey)) track.InstanceKey = detection.InstanceKey;
                }

                matched.Add(track.TrackId);

                if (track.Emitted || track.StableFrames < _options.StableFramesRequired) continue;

                track.Emitted = true;
                observations.Add(new IngredientObservation
                {
                    ObservationKey = BuildObservationKey(_sessionPublicId, track.TrackId),
                    ComponentKey = track.ComponentKey,
                    DisplayName = string.IsNullOrWhiteSpace(track.DisplayName) ? track.ComponentKey : track.DisplayName,
                    Action = track.Action,
                    Quantity = track.Quantity,
                    Confidence = track.Confidence,
                    TrackingId = "vision-track-" + track.TrackId,
                    BoundingBox = track.Box.ToArray()
                });
            }

            for (var i = _tracks.Count - 1; i >= 0; i--)
            {
                var track = _tracks[i];
                if (matched.Contains(track.TrackId)) continue;
                track.MissedFrames++;
                track.StableFrames = 0;
                if (track.MissedFrames >= _options.MaxMissingFrames) _tracks.RemoveAt(i);
            }

            return observations;
        }

        private TrackState? FindTrack(VisionDetection detection, HashSet<int> alreadyMatched)
        {
            if (!string.IsNullOrWhiteSpace(detection.InstanceKey))
            {
                foreach (var track in _tracks)
                {
                    if (alreadyMatched.Contains(track.TrackId)) continue;
                    if (!string.Equals(track.ComponentKey, detection.ComponentKey, StringComparison.Ordinal)) continue;
                    if (string.Equals(track.InstanceKey, detection.InstanceKey, StringComparison.Ordinal)) return track;
                }
            }

            TrackState? best = null;
            var bestIou = _options.AssociationIouThreshold;
            var box = VisionBoundingBox.FromArray(detection.BoundingBox);

            foreach (var track in _tracks)
            {
                if (alreadyMatched.Contains(track.TrackId)) continue;
                if (!string.Equals(track.ComponentKey, detection.ComponentKey, StringComparison.Ordinal)) continue;
                if (!string.IsNullOrWhiteSpace(detection.InstanceKey) && !string.IsNullOrWhiteSpace(track.InstanceKey)) continue;

                var iou = VisionBoundingBox.IntersectionOverUnion(track.Box, box);
                if (iou < bestIou) continue;
                bestIou = iou;
                best = track;
            }

            return best;
        }

        private static string NormalizeAction(string action)
        {
            if (string.Equals(action, "removed", StringComparison.Ordinal)) return "removed";
            if (string.Equals(action, "seen", StringComparison.Ordinal)) return "seen";
            return "added";
        }

        private static string BuildObservationKey(string session, int trackId)
        {
            var builder = new StringBuilder(session.Length + 32);
            foreach (var ch in session)
                builder.Append(char.IsLetterOrDigit(ch) ? char.ToLowerInvariant(ch) : '-');

            return "vision-" + builder.ToString().Trim('-') + "-track-" + trackId;
        }

        private sealed class TrackState
        {
            public int TrackId;
            public string ComponentKey = string.Empty;
            public string DisplayName = string.Empty;
            public string InstanceKey = string.Empty;
            public string Action = "added";
            public VisionBoundingBox Box;
            public int StableFrames;
            public int MissedFrames;
            public float Confidence;
            public float Quantity;
            public bool IsUnexpected;
            public bool Emitted;
        }
    }
}
