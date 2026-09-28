using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class UnityVisionModelRuntimePersistence : IVisionModelRuntimePersistence
    {
        private const int MaxCachedArtifacts = 4;
        private const long MaxCachedArtifactBytes = 1024L * 1024L * 1024L;

        private readonly string _root;
        private readonly string _statePath;
        private readonly string _artifactDirectory;
        private readonly string _detectorName;
        private readonly string _runtimeType;

        public UnityVisionModelRuntimePersistence(string detectorName, string runtimeType)
        {
            _detectorName = NormalizeIdentity(detectorName, "detector");
            _runtimeType = NormalizeIdentity(runtimeType, "runtime");
            _root = Path.Combine(
                Application.persistentDataPath,
                "gelato",
                "vision-model-runtime",
                SafeSegment(_detectorName),
                SafeSegment(_runtimeType));
            _statePath = Path.Combine(_root, "state.json");
            _artifactDirectory = Path.Combine(_root, "artifacts");
        }

        public Task<VisionModelDurableState> LoadAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            EnsureDirectories();
            if (!File.Exists(_statePath))
                return Task.FromResult(NewState());

            try
            {
                var json = File.ReadAllText(_statePath);
                var dto = JsonUtility.FromJson<StateDto>(json);
                if (dto == null || dto.schemaVersion != 1)
                    return Task.FromResult(NewState());

                return Task.FromResult(new VisionModelDurableState
                {
                    SchemaVersion = dto.schemaVersion,
                    DetectorName = dto.detectorName ?? string.Empty,
                    RuntimeType = dto.runtimeType ?? string.Empty,
                    ActivePackagePublicId = dto.activePackagePublicId ?? string.Empty,
                    ActiveArtifactSha256 = dto.activeArtifactSha256 ?? string.Empty,
                    KnownGoodPackagePublicId = dto.knownGoodPackagePublicId ?? string.Empty,
                    KnownGoodArtifactSha256 = dto.knownGoodArtifactSha256 ?? string.Empty,
                    LastErrorCode = dto.lastErrorCode ?? string.Empty,
                    LastErrorMessage = dto.lastErrorMessage ?? string.Empty,
                    Pending = dto.pending == null ? null : new VisionModelPendingActivation
                    {
                        AssignmentKey = dto.pending.assignmentKey ?? string.Empty,
                        PackagePublicId = dto.pending.packagePublicId ?? string.Empty,
                        ArtifactSha256 = dto.pending.artifactSha256 ?? string.Empty,
                        RuntimeType = dto.pending.runtimeType ?? string.Empty,
                        Stage = dto.pending.stage ?? string.Empty,
                        ArtifactBytes = dto.pending.artifactBytes,
                        ErrorCode = dto.pending.errorCode ?? string.Empty,
                        Message = dto.pending.message ?? string.Empty
                    }
                });
            }
            catch
            {
                QuarantineStateFile();
                return Task.FromResult(NewState());
            }
        }

        public async Task BeginActivationAsync(
            VisionModelAssignment assignment,
            VisionModelRuntimeSnapshot current,
            CancellationToken cancellationToken)
        {
            if (assignment == null) throw new ArgumentNullException(nameof(assignment));
            var package = assignment.Package ?? throw new InvalidOperationException("Model assignment has no package.");
            var state = await LoadAsync(cancellationToken);
            state.DetectorName = _detectorName;
            state.RuntimeType = _runtimeType;

            if (!string.IsNullOrWhiteSpace(current?.PackagePublicId)
                && !string.IsNullOrWhiteSpace(current?.ArtifactSha256))
            {
                state.ActivePackagePublicId = current.PackagePublicId;
                state.ActiveArtifactSha256 = NormalizeSha(current.ArtifactSha256);
                if (string.IsNullOrWhiteSpace(state.KnownGoodPackagePublicId))
                {
                    state.KnownGoodPackagePublicId = current.PackagePublicId;
                    state.KnownGoodArtifactSha256 = NormalizeSha(current.ArtifactSha256);
                }
            }

            state.LastErrorCode = string.Empty;
            state.LastErrorMessage = string.Empty;
            state.Pending = new VisionModelPendingActivation
            {
                AssignmentKey = assignment.AssignmentKey ?? string.Empty,
                PackagePublicId = package.PublicId ?? string.Empty,
                ArtifactSha256 = NormalizeSha(package.ArtifactSha256),
                RuntimeType = package.RuntimeType ?? string.Empty,
                Stage = "assigned",
                ArtifactBytes = package.ArtifactBytes ?? 0
            };
            Save(state);
        }

        public async Task StageVerifiedAsync(
            VisionModelAssignment assignment,
            byte[] verifiedArtifact,
            string artifactSha256,
            CancellationToken cancellationToken)
        {
            if (verifiedArtifact == null || verifiedArtifact.Length == 0)
                throw new ArgumentException("Verified artifact is required.", nameof(verifiedArtifact));

            var sha = NormalizeSha(artifactSha256);
            if (sha.Length != 64 || !sha.All(IsHex))
                throw new InvalidOperationException("Verified artifact SHA-256 is invalid.");
            if (!string.Equals(VisionModelActivationService.ComputeSha256(verifiedArtifact), sha, StringComparison.Ordinal))
                throw new InvalidOperationException("Verified artifact bytes do not match their SHA-256.");

            cancellationToken.ThrowIfCancellationRequested();
            EnsureDirectories();

            var finalPath = ArtifactPath(sha);
            if (!File.Exists(finalPath))
                AtomicWriteBytes(finalPath, verifiedArtifact);

            var state = await LoadAsync(cancellationToken);
            AssertPendingAssignment(state, assignment);
            state.Pending!.Stage = "verified";
            state.Pending.ArtifactSha256 = sha;
            state.Pending.ArtifactBytes = verifiedArtifact.LongLength;
            Save(state);
        }

        public async Task MarkPreparedAsync(VisionModelAssignment assignment, CancellationToken cancellationToken)
        {
            var state = await LoadAsync(cancellationToken);
            AssertPendingAssignment(state, assignment);
            state.Pending!.Stage = "prepared";
            Save(state);
        }

        public async Task CommitActiveAsync(
            VisionModelAssignment assignment,
            VisionModelRuntimeSnapshot previous,
            CancellationToken cancellationToken)
        {
            var state = await LoadAsync(cancellationToken);
            AssertPendingAssignment(state, assignment);
            var package = assignment.Package ?? throw new InvalidOperationException("Model assignment has no package.");
            var newSha = NormalizeSha(package.ArtifactSha256);

            if (!string.IsNullOrWhiteSpace(previous?.PackagePublicId)
                && !string.IsNullOrWhiteSpace(previous?.ArtifactSha256)
                && !string.Equals(previous.PackagePublicId, package.PublicId, StringComparison.Ordinal))
            {
                state.KnownGoodPackagePublicId = previous.PackagePublicId;
                state.KnownGoodArtifactSha256 = NormalizeSha(previous.ArtifactSha256);
            }
            else if (string.IsNullOrWhiteSpace(state.KnownGoodPackagePublicId))
            {
                state.KnownGoodPackagePublicId = package.PublicId;
                state.KnownGoodArtifactSha256 = newSha;
            }

            state.ActivePackagePublicId = package.PublicId ?? string.Empty;
            state.ActiveArtifactSha256 = newSha;
            state.LastErrorCode = string.Empty;
            state.LastErrorMessage = string.Empty;
            state.Pending = null;
            Save(state);
        }

        public async Task CommitRestoredAsync(
            VisionModelRuntimeSnapshot restored,
            string errorCode,
            string message,
            CancellationToken cancellationToken)
        {
            if (restored == null) throw new ArgumentNullException(nameof(restored));
            var state = await LoadAsync(cancellationToken);
            state.DetectorName = _detectorName;
            state.RuntimeType = _runtimeType;
            state.ActivePackagePublicId = restored.PackagePublicId ?? string.Empty;
            state.ActiveArtifactSha256 = NormalizeSha(restored.ArtifactSha256);
            state.KnownGoodPackagePublicId = restored.PackagePublicId ?? string.Empty;
            state.KnownGoodArtifactSha256 = NormalizeSha(restored.ArtifactSha256);
            state.LastErrorCode = errorCode ?? string.Empty;
            state.LastErrorMessage = message ?? string.Empty;
            state.Pending = null;
            Save(state);
        }

        public async Task MarkFailedAsync(
            VisionModelAssignment assignment,
            string errorCode,
            string message,
            CancellationToken cancellationToken)
        {
            var state = await LoadAsync(cancellationToken);
            if (state.Pending != null
                && string.Equals(state.Pending.AssignmentKey, assignment?.AssignmentKey, StringComparison.Ordinal))
            {
                state.Pending = null;
            }
            state.LastErrorCode = errorCode ?? string.Empty;
            state.LastErrorMessage = message ?? string.Empty;
            Save(state);
        }

        public Task<byte[]?> TryReadVerifiedArtifactAsync(
            string packagePublicId,
            string artifactSha256,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var sha = NormalizeSha(artifactSha256);
            if (sha.Length != 64 || !sha.All(IsHex))
                return Task.FromResult<byte[]?>(null);

            var path = ArtifactPath(sha);
            if (!File.Exists(path))
                return Task.FromResult<byte[]?>(null);

            var bytes = File.ReadAllBytes(path);
            if (!string.Equals(VisionModelActivationService.ComputeSha256(bytes), sha, StringComparison.Ordinal))
            {
                TryDelete(path);
                return Task.FromResult<byte[]?>(null);
            }
            return Task.FromResult<byte[]?>(bytes);
        }

        public async Task CleanupAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            EnsureDirectories();
            CleanupTemporaryFiles();

            var state = await LoadAsync(cancellationToken);
            var protectedShas = new HashSet<string>(StringComparer.Ordinal);
            AddProtected(protectedShas, state.ActiveArtifactSha256);
            AddProtected(protectedShas, state.KnownGoodArtifactSha256);
            if (state.Pending != null) AddProtected(protectedShas, state.Pending.ArtifactSha256);

            var files = new DirectoryInfo(_artifactDirectory)
                .GetFiles("*.bin", SearchOption.TopDirectoryOnly)
                .OrderByDescending(x => x.LastWriteTimeUtc)
                .ToList();

            long keptBytes = 0;
            var keptCount = 0;
            foreach (var file in files)
            {
                cancellationToken.ThrowIfCancellationRequested();
                var sha = Path.GetFileNameWithoutExtension(file.Name).ToLowerInvariant();
                var mustKeep = protectedShas.Contains(sha);
                if (mustKeep || (keptCount < MaxCachedArtifacts && keptBytes + file.Length <= MaxCachedArtifactBytes))
                {
                    keptCount++;
                    keptBytes += file.Length;
                    continue;
                }
                TryDelete(file.FullName);
            }
        }

        private VisionModelDurableState NewState()
        {
            return new VisionModelDurableState
            {
                SchemaVersion = 1,
                DetectorName = _detectorName,
                RuntimeType = _runtimeType
            };
        }

        private void Save(VisionModelDurableState state)
        {
            EnsureDirectories();
            var dto = new StateDto
            {
                schemaVersion = 1,
                detectorName = _detectorName,
                runtimeType = _runtimeType,
                activePackagePublicId = state.ActivePackagePublicId ?? string.Empty,
                activeArtifactSha256 = NormalizeSha(state.ActiveArtifactSha256),
                knownGoodPackagePublicId = state.KnownGoodPackagePublicId ?? string.Empty,
                knownGoodArtifactSha256 = NormalizeSha(state.KnownGoodArtifactSha256),
                lastErrorCode = state.LastErrorCode ?? string.Empty,
                lastErrorMessage = state.LastErrorMessage ?? string.Empty,
                pending = state.Pending == null ? null : new PendingDto
                {
                    assignmentKey = state.Pending.AssignmentKey ?? string.Empty,
                    packagePublicId = state.Pending.PackagePublicId ?? string.Empty,
                    artifactSha256 = NormalizeSha(state.Pending.ArtifactSha256),
                    runtimeType = state.Pending.RuntimeType ?? string.Empty,
                    stage = state.Pending.Stage ?? string.Empty,
                    artifactBytes = state.Pending.ArtifactBytes,
                    errorCode = state.Pending.ErrorCode ?? string.Empty,
                    message = state.Pending.Message ?? string.Empty
                }
            };
            AtomicWriteText(_statePath, JsonUtility.ToJson(dto, true));
        }

        private void AssertPendingAssignment(VisionModelDurableState state, VisionModelAssignment assignment)
        {
            if (state.Pending == null
                || !string.Equals(state.Pending.AssignmentKey, assignment?.AssignmentKey, StringComparison.Ordinal))
                throw new InvalidOperationException("Durable model transaction does not match the active assignment.");
        }

        private void EnsureDirectories()
        {
            Directory.CreateDirectory(_root);
            Directory.CreateDirectory(_artifactDirectory);
        }

        private string ArtifactPath(string sha)
        {
            var normalized = NormalizeSha(sha);
            if (normalized.Length != 64 || !normalized.All(IsHex))
                throw new InvalidOperationException("Artifact SHA-256 is invalid.");
            return Path.Combine(_artifactDirectory, normalized + ".bin");
        }

        private void CleanupTemporaryFiles()
        {
            foreach (var path in Directory.GetFiles(_root, "*.tmp", SearchOption.AllDirectories))
                TryDelete(path);
        }

        private void QuarantineStateFile()
        {
            try
            {
                if (!File.Exists(_statePath)) return;
                var quarantine = _statePath + ".corrupt";
                TryDelete(quarantine);
                File.Move(_statePath, quarantine);
            }
            catch { }
        }

        private static void AtomicWriteText(string path, string content)
        {
            var tmp = path + ".tmp";
            File.WriteAllText(tmp, content ?? string.Empty);
            Replace(tmp, path);
        }

        private static void AtomicWriteBytes(string path, byte[] content)
        {
            var tmp = path + ".tmp";
            File.WriteAllBytes(tmp, content);
            Replace(tmp, path);
        }

        private static void Replace(string tmp, string final)
        {
            if (File.Exists(final))
            {
                var backup = final + ".bak";
                TryDelete(backup);
                File.Replace(tmp, final, backup);
                TryDelete(backup);
            }
            else
            {
                File.Move(tmp, final);
            }
        }

        private static string NormalizeIdentity(string value, string field)
        {
            var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
            if (normalized.Length == 0) throw new ArgumentException(field + " is required.");
            return normalized;
        }

        private static string SafeSegment(string value)
        {
            var chars = value.Select(ch => char.IsLetterOrDigit(ch) || ch == '.' || ch == '_' || ch == '-' ? ch : '-').ToArray();
            return new string(chars).Trim('-');
        }

        private static string NormalizeSha(string value)
        {
            return (value ?? string.Empty).Trim().ToLowerInvariant();
        }

        private static bool IsHex(char ch)
        {
            return (ch >= '0' && ch <= '9') || (ch >= 'a' && ch <= 'f');
        }

        private static void AddProtected(HashSet<string> set, string sha)
        {
            var normalized = NormalizeSha(sha);
            if (normalized.Length == 64 && normalized.All(IsHex)) set.Add(normalized);
        }

        private static void TryDelete(string path)
        {
            try { if (File.Exists(path)) File.Delete(path); } catch { }
        }

        [Serializable]
        private sealed class StateDto
        {
            public int schemaVersion;
            public string detectorName;
            public string runtimeType;
            public string activePackagePublicId;
            public string activeArtifactSha256;
            public string knownGoodPackagePublicId;
            public string knownGoodArtifactSha256;
            public string lastErrorCode;
            public string lastErrorMessage;
            public PendingDto pending;
        }

        [Serializable]
        private sealed class PendingDto
        {
            public string assignmentKey;
            public string packagePublicId;
            public string artifactSha256;
            public string runtimeType;
            public string stage;
            public long artifactBytes;
            public string errorCode;
            public string message;
        }
    }
}
