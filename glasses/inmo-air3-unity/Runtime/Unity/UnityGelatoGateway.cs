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
                systemVersion = device.SystemVersion
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
                    sequenceSupported = observation.EvidenceSequenceSupported
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
        [Serializable] private sealed class ObservationMetadataRequest
        {
            public string evidenceKind;
            public string sourceZoneKey;
            public string destinationRegionKey;
            public bool sequenceSupported;
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
