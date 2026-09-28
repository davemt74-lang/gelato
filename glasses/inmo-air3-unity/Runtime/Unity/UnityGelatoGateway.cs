using System;
using System.Collections.Generic;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;
using UnityEngine.Networking;

namespace Gelato.Ar.Unity
{
    public sealed class UnityGelatoGateway : MonoBehaviour, IGelatoGateway
    {
        [SerializeField] private string baseUrl = "https://example.com";
        [SerializeField] private string deviceApiPath = "/api/glasses-device.php";

        private string _deviceToken;

        public void SetDeviceToken(string token)
        {
            _deviceToken = token == null ? string.Empty : token.Trim();
        }

        public async Task<PairResult> PairAsync(string pairingCode, DeviceDescriptor device, CancellationToken cancellationToken)
        {
            var response = await PostAsync<PairResponse>(new PairRequest
            {
                action = DeviceApiActions.Pair,
                pairingCode = pairingCode,
                hardwareIdentifier = device.HardwareIdentifier,
                displayName = device.DisplayName,
                platform = device.Platform,
                sdkVersion = device.SdkVersion,
                appVersion = device.AppVersion,
                systemVersion = device.SystemVersion,
                capabilities = new PairCapabilitiesRequest
                {
                    visionModelRuntimes = device.VisionModelRuntimes == null
                        ? Array.Empty<string>()
                        : new List<string>(device.VisionModelRuntimes).ToArray()
                }
            }, false, cancellationToken);

            return new PairResult
            {
                DeviceToken = response.deviceToken ?? string.Empty,
                DevicePublicId = response.device != null ? response.device.publicId ?? string.Empty : string.Empty
            };
        }

        public async Task<CurrentWork> GetCurrentWorkAsync(CancellationToken cancellationToken)
        {
            var response = await PostAsync<WorkResponse>(new ActionRequest
            {
                action = DeviceApiActions.CurrentWork
            }, true, cancellationToken);

            var work = response.work ?? new WorkDto();
            var items = new List<WorkItem>();
            if (work.items != null)
            {
                foreach (var item in work.items) items.Add(MapWorkItem(item));
            }

            return new CurrentWork
            {
                AssignmentRequired = work.assignmentRequired,
                Revision = work.revision ?? string.Empty,
                FocusItem = work.focusItem == null ? null : MapWorkItem(work.focusItem),
                Items = items
            };
        }

        public async Task<StationCalibration> GetStationCalibrationAsync(CameraFrame frame, CancellationToken cancellationToken)
        {
            if (frame == null) throw new ArgumentNullException(nameof(frame));

            var response = await PostAsync<CalibrationResponse>(new CalibrationRequest
            {
                action = DeviceApiActions.CalibrationGet,
                frameWidth = frame.Width,
                frameHeight = frame.Height,
                pixelFormat = frame.PixelFormat ?? string.Empty
            }, true, cancellationToken);

            if (response.calibration == null) return null;
            var dto = response.calibration;
            var zones = new List<IngredientZone>();
            if (dto.zones != null)
            {
                foreach (var zone in dto.zones)
                {
                    zones.Add(new IngredientZone
                    {
                        ZoneKey = zone.zoneKey ?? string.Empty,
                        IngredientId = zone.ingredientId,
                        CanonicalName = zone.canonicalName ?? string.Empty,
                        DisplayName = zone.displayName ?? string.Empty,
                        X = zone.x,
                        Y = zone.y,
                        Width = zone.width,
                        Height = zone.height,
                        Priority = zone.priority
                    });
                }
            }

            var regions = new List<StationRegion>();
            if (dto.regions != null)
            {
                foreach (var region in dto.regions)
                {
                    regions.Add(new StationRegion
                    {
                        RegionKey = region.regionKey ?? string.Empty,
                        RegionType = region.regionType ?? string.Empty,
                        DisplayName = region.displayName ?? string.Empty,
                        X = region.x,
                        Y = region.y,
                        Width = region.width,
                        Height = region.height,
                        Priority = region.priority
                    });
                }
            }

            var compatibility = dto.compatibility ?? new CalibrationCompatibilityDto();
            return new StationCalibration
            {
                PublicId = dto.publicId ?? string.Empty,
                StationPublicId = dto.stationPublicId ?? string.Empty,
                StationName = dto.stationName ?? string.Empty,
                Version = dto.version,
                Platform = dto.platform ?? string.Empty,
                FrameWidth = dto.frame != null ? dto.frame.width : 0,
                FrameHeight = dto.frame != null ? dto.frame.height : 0,
                PixelFormat = dto.frame != null ? dto.frame.pixelFormat ?? string.Empty : string.Empty,
                SourceHash = dto.sourceHash ?? string.Empty,
                Zones = zones,
                Regions = regions,
                Compatibility = new CalibrationCompatibility
                {
                    Compatible = compatibility.compatible,
                    Reasons = compatibility.reasons ?? Array.Empty<string>()
                }
            };
        }

        public async Task<VisionLabelProfile?> GetVisionLabelProfileAsync(
            string buildSessionPublicId,
            string detectorName,
            CancellationToken cancellationToken)
        {
            var response = await PostAsync<VisionProfileResponse>(new VisionProfileRequest
            {
                action = DeviceApiActions.VisionProfile,
                buildSessionPublicId = buildSessionPublicId,
                detectorName = detectorName ?? string.Empty
            }, true, cancellationToken);

            if (response.visionProfile == null) return null;
            var dto = response.visionProfile;
            var mappings = new List<VisionLabelMapping>();
            if (dto.mappings != null)
            {
                foreach (var mapping in dto.mappings)
                {
                    mappings.Add(new VisionLabelMapping
                    {
                        ModelLabel = mapping.modelLabel ?? string.Empty,
                        NormalizedLabel = mapping.normalizedLabel ?? string.Empty,
                        ComponentKey = mapping.componentKey ?? string.Empty,
                        DisplayName = mapping.displayName ?? string.Empty,
                        IngredientId = mapping.ingredientId,
                        MinimumConfidence = mapping.hasMinimumConfidence ? mapping.minimumConfidence : (float?)null,
                        SourceDetector = mapping.sourceDetector ?? string.Empty
                    });
                }
            }

            return new VisionLabelProfile
            {
                Schema = dto.schema ?? string.Empty,
                DetectorName = dto.detectorName ?? string.Empty,
                BuildSessionPublicId = dto.buildSessionPublicId ?? string.Empty,
                ProfileHash = dto.profileHash ?? string.Empty,
                Mappings = mappings,
                BlockedLabels = dto.blockedLabels ?? Array.Empty<string>()
            };
        }

        public async Task<VisionModelAssignment?> GetVisionModelAssignmentAsync(
            string buildSessionPublicId,
            string detectorName,
            CancellationToken cancellationToken)
        {
            var response = await PostAsync<VisionModelAssignmentResponse>(new VisionModelAssignmentRequest
            {
                action = DeviceApiActions.VisionModelAssignment,
                buildSessionPublicId = buildSessionPublicId,
                detectorName = detectorName ?? string.Empty
            }, true, cancellationToken);

            if (response.modelAssignment == null) return null;
            var dto = response.modelAssignment;
            return new VisionModelAssignment
            {
                Schema = dto.schema ?? string.Empty,
                DetectorName = dto.detectorName ?? string.Empty,
                BuildSessionPublicId = dto.buildSessionPublicId ?? string.Empty,
                Action = dto.action ?? "hold",
                Reason = dto.reason ?? string.Empty,
                AssignmentKey = dto.assignmentKey ?? string.Empty,
                Selection = dto.selection ?? string.Empty,
                CanaryBucket = dto.canaryBucket,
                Rollout = dto.rollout == null ? null : new VisionModelRolloutAssignment
                {
                    PublicId = dto.rollout.publicId ?? string.Empty,
                    Status = dto.rollout.status ?? string.Empty,
                    CanaryPercent = dto.rollout.canaryPercent
                },
                Package = dto.package == null ? null : new VisionModelPackage
                {
                    PublicId = dto.package.publicId ?? string.Empty,
                    DetectorName = dto.package.detectorName ?? string.Empty,
                    ModelName = dto.package.modelName ?? string.Empty,
                    ModelVersion = dto.package.modelVersion ?? string.Empty,
                    RuntimeType = dto.package.runtimeType ?? string.Empty,
                    Platform = dto.package.platform ?? string.Empty,
                    ArtifactUrl = dto.package.artifactUrl ?? string.Empty,
                    ArtifactSha256 = dto.package.artifactSha256 ?? string.Empty,
                    ArtifactBytes = dto.package.hasArtifactBytes ? dto.package.artifactBytes : (long?)null,
                    MinimumSdkVersion = dto.package.minimumSdkVersion ?? string.Empty,
                    MinimumAppVersion = dto.package.minimumAppVersion ?? string.Empty
                },
                Compatibility = dto.compatibility == null ? new VisionModelCompatibility() : new VisionModelCompatibility
                {
                    Compatible = dto.compatibility.compatible,
                    Reasons = dto.compatibility.reasons ?? Array.Empty<string>()
                }
            };
        }

        public async Task ReportVisionModelAsync(VisionModelReport report, CancellationToken cancellationToken)
        {
            if (report == null) throw new ArgumentNullException(nameof(report));
            await PostAsync<VisionModelReportResponse>(new VisionModelReportRequest
            {
                action = DeviceApiActions.VisionModelReport,
                reportKey = report.ReportKey ?? string.Empty,
                reportType = report.ReportType ?? string.Empty,
                rolloutPublicId = report.RolloutPublicId ?? string.Empty,
                packagePublicId = report.PackagePublicId ?? string.Empty,
                runtimeState = report.RuntimeState ?? string.Empty,
                artifactSha256 = report.ArtifactSha256 ?? string.Empty,
                errorCode = report.ErrorCode ?? string.Empty,
                message = report.Message ?? string.Empty
            }, true, cancellationToken);
        }

        public async Task<BuildSession> StartBuildAsync(string kdsItemPublicId, string sourceRevision, CancellationToken cancellationToken)
        {
            var response = await PostAsync<BuildResponse>(new BuildStartRequest
            {
                action = DeviceApiActions.BuildStart,
                kdsItemPublicId = kdsItemPublicId,
                sourceRevision = sourceRevision ?? string.Empty
            }, true, cancellationToken);

            return MapBuild(response.buildSession);
        }

        public async Task<BuildSession> SubmitObservationAsync(string buildSessionPublicId, IngredientObservation observation, CancellationToken cancellationToken)
        {
            var response = await PostAsync<BuildResponse>(new ObservationRequest
            {
                action = DeviceApiActions.BuildObserve,
                buildSessionPublicId = buildSessionPublicId,
                observationKey = observation.ObservationKey,
                componentKey = observation.ComponentKey,
                displayName = observation.DisplayName,
                observationAction = observation.Action,
                quantity = observation.Quantity,
                confidence = observation.Confidence,
                trackingId = observation.TrackingId,
                bbox = observation.BoundingBox ?? Array.Empty<float>(),
                metadata = new ObservationMetadataRequest
                {
                    evidenceKind = observation.EvidenceKind ?? string.Empty,
                    sourceZoneKey = observation.EvidenceSourceZoneKey ?? string.Empty,
                    destinationRegionKey = observation.EvidenceDestinationRegionKey ?? string.Empty,
                    sequenceSupported = observation.EvidenceSequenceSupported,
                    detectorLabel = observation.DetectorLabel ?? string.Empty,
                    profileMatched = observation.VisionProfileMatched,
                    profileMinimumConfidence = observation.VisionProfileMinimumConfidence ?? 0f,
                    hasProfileMinimumConfidence = observation.VisionProfileMinimumConfidence.HasValue
                }
            }, true, cancellationToken);

            return MapBuild(response.buildSession);
        }

        public async Task<BuildSession> ConfirmComponentAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken)
        {
            var response = await PostAsync<BuildResponse>(new ComponentActionRequest
            {
                action = DeviceApiActions.BuildConfirm,
                buildSessionPublicId = buildSessionPublicId,
                componentKey = componentKey
            }, true, cancellationToken);

            return MapBuild(response.buildSession);
        }

        public async Task<BuildSession> ResolveUnexpectedAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken)
        {
            var response = await PostAsync<BuildResponse>(new ComponentActionRequest
            {
                action = DeviceApiActions.ResolveUnexpected,
                buildSessionPublicId = buildSessionPublicId,
                componentKey = componentKey
            }, true, cancellationToken);

            return MapBuild(response.buildSession);
        }

        public async Task<BuildSession> CorrectObservationAsync(
            string buildSessionPublicId,
            ObservationCorrection correction,
            CancellationToken cancellationToken)
        {
            if (correction == null) throw new ArgumentNullException(nameof(correction));

            var response = await PostAsync<CorrectionResponse>(new CorrectionRequest
            {
                action = DeviceApiActions.CorrectObservation,
                buildSessionPublicId = buildSessionPublicId,
                correctionKey = correction.CorrectionKey ?? string.Empty,
                observationKey = correction.ObservationKey ?? string.Empty,
                resolution = correction.Resolution ?? string.Empty,
                targetComponentKey = correction.TargetComponentKey ?? string.Empty,
                correctedQuantity = correction.CorrectedQuantity ?? 0f,
                hasCorrectedQuantity = correction.CorrectedQuantity.HasValue,
                reason = correction.Reason ?? string.Empty
            }, true, cancellationToken);

            return MapBuild(response.buildSession);
        }

        public async Task<IReadOnlyList<ObservationEvidence>> GetEvidenceAsync(
            string buildSessionPublicId,
            int limit,
            CancellationToken cancellationToken)
        {
            var response = await PostAsync<EvidenceResponse>(new EvidenceRequest
            {
                action = DeviceApiActions.BuildEvidence,
                buildSessionPublicId = buildSessionPublicId,
                limit = Math.Max(1, Math.Min(100, limit))
            }, true, cancellationToken);

            var items = new List<ObservationEvidence>();
            if (response.evidence == null) return items;

            foreach (var dto in response.evidence)
            {
                var correction = dto.latestCorrection;
                items.Add(new ObservationEvidence
                {
                    ObservationKey = dto.observationKey ?? string.Empty,
                    ComponentKey = dto.componentKey ?? string.Empty,
                    Action = dto.action ?? string.Empty,
                    Quantity = dto.quantity,
                    Confidence = dto.confidence,
                    TrackingId = dto.trackingId ?? string.Empty,
                    LatestCorrection = correction == null ? null : new ObservationCorrection
                    {
                        CorrectionKey = correction.correctionKey ?? string.Empty,
                        ObservationKey = correction.observationKey ?? string.Empty,
                        Resolution = correction.resolution ?? string.Empty,
                        TargetComponentKey = correction.targetComponentKey ?? string.Empty,
                        CorrectedQuantity = correction.hasCorrectedQuantity ? correction.correctedQuantity : (float?)null,
                        Reason = correction.reason ?? string.Empty
                    }
                });
            }

            return items;
        }

        public async Task<ProductValidation> EvaluateAsync(string buildSessionPublicId, CancellationToken cancellationToken)
        {
            var response = await PostAsync<ValidationResponse>(new BuildSessionRequest
            {
                action = DeviceApiActions.ValidationEvaluate,
                buildSessionPublicId = buildSessionPublicId
            }, true, cancellationToken);

            var validation = response.validation ?? new ValidationDto();
            var summary = validation.summary ?? new ValidationSummaryDto();
            var next = summary.next ?? new ValidationNextDto();

            return new ProductValidation
            {
                PublicId = validation.publicId ?? string.Empty,
                Status = validation.status ?? string.Empty,
                NextStage = validation.nextStage ?? string.Empty,
                AllIngredientsAccountedFor = summary.allIngredientsAccountedFor,
                Next = new ValidationNext
                {
                    Stage = next.stage ?? string.Empty,
                    Label = next.label ?? string.Empty,
                    Available = next.available,
                    Message = next.message ?? string.Empty
                }
            };
        }

        public async Task<ExpoHandoff> HandoffExpoAsync(string buildSessionPublicId, CancellationToken cancellationToken)
        {
            var response = await PostAsync<HandoffResponse>(new BuildSessionRequest
            {
                action = DeviceApiActions.HandoffExpo,
                buildSessionPublicId = buildSessionPublicId
            }, true, cancellationToken);

            var handoff = response.handoff ?? new HandoffDto();
            return new ExpoHandoff
            {
                PublicId = handoff.publicId ?? string.Empty,
                Status = handoff.status ?? string.Empty,
                KdsStatus = handoff.kdsStatus ?? string.Empty,
                Label = handoff.next != null ? handoff.next.label ?? string.Empty : string.Empty
            };
        }

        private async Task<T> PostAsync<T>(object payload, bool authenticated, CancellationToken cancellationToken) where T : class
        {
            if (authenticated && string.IsNullOrWhiteSpace(_deviceToken))
                throw new InvalidOperationException("Gelato device token is not configured.");

            var json = JsonUtility.ToJson(payload);
            var url = BuildDeviceApiUrl();

            using (var request = new UnityWebRequest(url, UnityWebRequest.kHttpVerbPOST))
            {
                request.uploadHandler = new UploadHandlerRaw(Encoding.UTF8.GetBytes(json));
                request.downloadHandler = new DownloadHandlerBuffer();
                request.SetRequestHeader("Content-Type", "application/json");
                request.SetRequestHeader("Accept", "application/json");
                if (authenticated) request.SetRequestHeader("Authorization", "Bearer " + _deviceToken);

                var operation = request.SendWebRequest();
                while (!operation.isDone)
                {
                    if (cancellationToken.IsCancellationRequested)
                    {
                        request.Abort();
                        cancellationToken.ThrowIfCancellationRequested();
                    }
                    await Task.Yield();
                }

                cancellationToken.ThrowIfCancellationRequested();

                var body = request.downloadHandler != null ? request.downloadHandler.text : string.Empty;
                if (request.result != UnityWebRequest.Result.Success)
                {
                    var message = "Gelato device API request failed (" + request.responseCode + ").";
                    if (!string.IsNullOrWhiteSpace(body))
                    {
                        try
                        {
                            var error = JsonUtility.FromJson<ErrorResponse>(body);
                            if (error != null && !string.IsNullOrWhiteSpace(error.message)) message = error.message;
                        }
                        catch
                        {
                            // Preserve the safe generic message when the response is not JSON.
                        }
                    }
                    throw new InvalidOperationException(message);
                }

                var response = JsonUtility.FromJson<T>(body);
                if (response == null) throw new InvalidOperationException("Gelato returned an empty device API response.");
                return response;
            }
        }

        private string BuildDeviceApiUrl()
        {
            var root = (baseUrl ?? string.Empty).Trim().TrimEnd('/');
            if (string.IsNullOrWhiteSpace(root)) throw new InvalidOperationException("Gelato base URL is required.");
            if (!root.StartsWith("https://", StringComparison.OrdinalIgnoreCase)
                && !root.StartsWith("http://localhost", StringComparison.OrdinalIgnoreCase)
                && !root.StartsWith("http://127.0.0.1", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Gelato glasses runtime requires HTTPS outside local development.");

            var path = string.IsNullOrWhiteSpace(deviceApiPath) ? "/api/glasses-device.php" : deviceApiPath.Trim();
            if (!path.StartsWith("/", StringComparison.Ordinal)) path = "/" + path;
            return root + path;
        }

        private static WorkItem MapWorkItem(WorkItemDto item)
        {
            var posLine = item.posLine ?? new PosLineDto();
            return new WorkItem
            {
                KdsItemPublicId = item.kdsItemPublicId ?? string.Empty,
                Status = item.status ?? string.Empty,
                Name = posLine.name ?? string.Empty,
                SpecialInstructions = posLine.specialInstructions ?? string.Empty
            };
        }

        private static BuildSession MapBuild(BuildSessionDto dto)
        {
            dto = dto ?? new BuildSessionDto();
            var components = new List<BuildComponent>();
            if (dto.components != null)
            {
                foreach (var component in dto.components)
                {
                    components.Add(new BuildComponent
                    {
                        ComponentKey = component.componentKey ?? string.Empty,
                        DisplayName = component.displayName ?? string.Empty,
                        ExpectedQuantity = component.expectedQuantity,
                        DetectedQuantity = component.detectedQuantity,
                        Unit = component.unit ?? string.Empty,
                        Status = component.status ?? string.Empty,
                        Confidence = component.hasConfidence ? component.confidence : (float?)null
                    });
                }
            }

            var steps = new List<BuildStep>();
            var stepDtos = dto.context != null && dto.context.buildDefinition != null
                ? dto.context.buildDefinition.steps
                : null;
            if (stepDtos != null)
            {
                foreach (var step in stepDtos)
                {
                    steps.Add(new BuildStep
                    {
                        StepKey = step.stepKey ?? string.Empty,
                        Order = step.order,
                        Text = step.text ?? string.Empty,
                        ComponentKeys = step.componentKeys ?? Array.Empty<string>()
                    });
                }
            }

            return new BuildSession
            {
                PublicId = dto.publicId ?? string.Empty,
                Status = dto.status ?? string.Empty,
                KdsItemPublicId = dto.kdsItemPublicId ?? string.Empty,
                SourceRevision = dto.sourceRevision ?? string.Empty,
                Components = components,
                BuildSteps = steps
            };
        }

        [Serializable] private class ActionRequest { public string action; }
        [Serializable] private class BuildSessionRequest : ActionRequest { public string buildSessionPublicId; }
        [Serializable] private sealed class BuildStartRequest : ActionRequest { public string kdsItemPublicId; public string sourceRevision; }
        [Serializable] private sealed class VisionModelAssignmentRequest : BuildSessionRequest
        {
            public string detectorName;
        }
        [Serializable] private sealed class VisionModelReportRequest : ActionRequest
        {
            public string reportKey;
            public string reportType;
            public string rolloutPublicId;
            public string packagePublicId;
            public string runtimeState;
            public string artifactSha256;
            public string errorCode;
            public string message;
        }
        [Serializable] private sealed class VisionProfileRequest : BuildSessionRequest
        {
            public string detectorName;
        }
        [Serializable] private sealed class CalibrationRequest : ActionRequest
        {
            public int frameWidth;
            public int frameHeight;
            public string pixelFormat;
        }
        [Serializable] private sealed class ComponentActionRequest : BuildSessionRequest { public string componentKey; }
        [Serializable] private sealed class PairRequest : ActionRequest
        {
            public string pairingCode;
            public string hardwareIdentifier;
            public string displayName;
            public string platform;
            public string sdkVersion;
            public string appVersion;
            public string systemVersion;
            public PairCapabilitiesRequest capabilities;
        }
        [Serializable] private sealed class PairCapabilitiesRequest
        {
            public string[] visionModelRuntimes;
        }
        [Serializable] private sealed class ObservationRequest : BuildSessionRequest
        {
            public string observationKey;
            public string componentKey;
            public string displayName;
            public string observationAction;
            public float quantity;
            public float confidence;
            public string trackingId;
            public float[] bbox;
            public ObservationMetadataRequest metadata;
        }
        [Serializable] private sealed class CorrectionRequest : BuildSessionRequest
        {
            public string correctionKey;
            public string observationKey;
            public string resolution;
            public string targetComponentKey;
            public float correctedQuantity;
            public bool hasCorrectedQuantity;
            public string reason;
        }
        [Serializable] private sealed class EvidenceRequest : BuildSessionRequest
        {
            public int limit;
        }
        [Serializable] private sealed class ObservationMetadataRequest
        {
            public string evidenceKind;
            public string sourceZoneKey;
            public string destinationRegionKey;
            public bool sequenceSupported;
            public string detectorLabel;
            public bool profileMatched;
            public float profileMinimumConfidence;
            public bool hasProfileMinimumConfidence;
        }

        [Serializable] private sealed class ErrorResponse { public bool ok; public string message; }
        [Serializable] private sealed class DeviceDto { public string publicId; }
        [Serializable] private sealed class PairResponse { public bool ok; public DeviceDto device; public string deviceToken; }
        [Serializable] private sealed class WorkResponse { public bool ok; public WorkDto work; }
        [Serializable] private sealed class WorkDto
        {
            public bool assignmentRequired;
            public string revision;
            public WorkItemDto focusItem;
            public WorkItemDto[] items;
        }
        [Serializable] private sealed class WorkItemDto
        {
            public string kdsItemPublicId;
            public string status;
            public PosLineDto posLine;
        }
        [Serializable] private sealed class PosLineDto { public string name; public string specialInstructions; }

        [Serializable] private sealed class VisionModelAssignmentResponse
        {
            public bool ok;
            public VisionModelAssignmentDto modelAssignment;
        }
        [Serializable] private sealed class VisionModelAssignmentDto
        {
            public string schema;
            public string detectorName;
            public string buildSessionPublicId;
            public string action;
            public string reason;
            public string assignmentKey;
            public string selection;
            public float canaryBucket;
            public VisionModelRolloutDto rollout;
            public VisionModelPackageDto package;
            public VisionModelCompatibilityDto compatibility;
        }
        [Serializable] private sealed class VisionModelRolloutDto
        {
            public string publicId;
            public string status;
            public float canaryPercent;
        }
        [Serializable] private sealed class VisionModelPackageDto
        {
            public string publicId;
            public string detectorName;
            public string modelName;
            public string modelVersion;
            public string runtimeType;
            public string platform;
            public string artifactUrl;
            public string artifactSha256;
            public long artifactBytes;
            public bool hasArtifactBytes;
            public string minimumSdkVersion;
            public string minimumAppVersion;
        }
        [Serializable] private sealed class VisionModelCompatibilityDto
        {
            public bool compatible;
            public string[] reasons;
        }
        [Serializable] private sealed class VisionModelReportResponse
        {
            public bool ok;
            public VisionModelReportAckDto modelReport;
        }
        [Serializable] private sealed class VisionModelReportAckDto
        {
            public string reportKey;
            public string reportType;
            public string createdAt;
            public bool idempotent;
        }

        [Serializable] private sealed class VisionProfileResponse
        {
            public bool ok;
            public VisionLabelProfileDto visionProfile;
        }
        [Serializable] private sealed class VisionLabelProfileDto
        {
            public string schema;
            public string detectorName;
            public string buildSessionPublicId;
            public string profileHash;
            public VisionLabelMappingDto[] mappings;
            public string[] blockedLabels;
        }
        [Serializable] private sealed class VisionLabelMappingDto
        {
            public string modelLabel;
            public string normalizedLabel;
            public string componentKey;
            public string displayName;
            public int ingredientId;
            public float minimumConfidence;
            public bool hasMinimumConfidence;
            public string sourceDetector;
        }

        [Serializable] private sealed class CalibrationResponse
        {
            public bool ok;
            public bool assignmentRequired;
            public StationCalibrationDto calibration;
        }
        [Serializable] private sealed class StationCalibrationDto
        {
            public string publicId;
            public string stationPublicId;
            public string stationName;
            public int version;
            public string platform;
            public CalibrationFrameDto frame;
            public string sourceHash;
            public IngredientZoneDto[] zones;
            public StationRegionDto[] regions;
            public CalibrationCompatibilityDto compatibility;
        }
        [Serializable] private sealed class CalibrationFrameDto
        {
            public int width;
            public int height;
            public string pixelFormat;
        }
        [Serializable] private sealed class IngredientZoneDto
        {
            public string zoneKey;
            public int ingredientId;
            public string canonicalName;
            public string displayName;
            public float x;
            public float y;
            public float width;
            public float height;
            public int priority;
        }
        [Serializable] private sealed class StationRegionDto
        {
            public string regionKey;
            public string regionType;
            public string displayName;
            public float x;
            public float y;
            public float width;
            public float height;
            public int priority;
        }
        [Serializable] private sealed class CalibrationCompatibilityDto
        {
            public bool compatible;
            public string[] reasons;
        }

        [Serializable] private sealed class CorrectionResponse
        {
            public bool ok;
            public BuildSessionDto buildSession;
            public CorrectionDto correction;
        }
        [Serializable] private sealed class EvidenceResponse
        {
            public bool ok;
            public EvidenceDto[] evidence;
        }
        [Serializable] private sealed class EvidenceDto
        {
            public string observationKey;
            public string componentKey;
            public string action;
            public float quantity;
            public float confidence;
            public string trackingId;
            public CorrectionDto latestCorrection;
        }
        [Serializable] private sealed class CorrectionDto
        {
            public string correctionKey;
            public string observationKey;
            public string resolution;
            public string targetComponentKey;
            public float correctedQuantity;
            public bool hasCorrectedQuantity;
            public string reason;
        }

        [Serializable] private sealed class BuildResponse { public bool ok; public BuildSessionDto buildSession; }
        [Serializable] private sealed class BuildSessionDto
        {
            public string publicId;
            public string status;
            public string kdsItemPublicId;
            public string sourceRevision;
            public BuildComponentDto[] components;
            public BuildContextDto context;
        }
        [Serializable] private sealed class BuildContextDto
        {
            public BuildDefinitionContextDto buildDefinition;
        }
        [Serializable] private sealed class BuildDefinitionContextDto
        {
            public BuildStepDto[] steps;
        }
        [Serializable] private sealed class BuildStepDto
        {
            public string stepKey;
            public int order;
            public string text;
            public string[] componentKeys;
        }
        [Serializable] private sealed class BuildComponentDto
        {
            public string componentKey;
            public string displayName;
            public float expectedQuantity;
            public float detectedQuantity;
            public string unit;
            public string status;
            public float confidence;
            public bool hasConfidence = true;
        }

        [Serializable] private sealed class ValidationResponse { public bool ok; public ValidationDto validation; }
        [Serializable] private sealed class ValidationDto
        {
            public string publicId;
            public string status;
            public string nextStage;
            public ValidationSummaryDto summary;
        }
        [Serializable] private sealed class ValidationSummaryDto
        {
            public bool allIngredientsAccountedFor;
            public ValidationNextDto next;
        }
        [Serializable] private sealed class ValidationNextDto
        {
            public string stage;
            public string label;
            public bool available;
            public string message;
        }

        [Serializable] private sealed class HandoffResponse { public bool ok; public HandoffDto handoff; }
        [Serializable] private sealed class HandoffDto
        {
            public string publicId;
            public string status;
            public string kdsStatus;
            public HandoffNextDto next;
        }
        [Serializable] private sealed class HandoffNextDto
        {
            public string stage;
            public string label;
            public bool available;
        }
    }
}
