using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Core
{
    public interface IGlassesPlatform
    {
        bool IsInitialized { get; }
        PlatformCapabilities Capabilities { get; }
        Task InitializeAsync(CancellationToken cancellationToken);
        void StartTracking();
        void StopTracking();
        CameraFrame? TryGetLatestFrame();
        CameraCalibration? TryGetCameraCalibration();
        PoseState GetPose();
    }

    public interface IDeviceTokenStore
    {
        string? Load();
        void Save(string token);
        void Clear();
    }

    public interface IGelatoGateway
    {
        void SetDeviceToken(string token);
        Task<PairResult> PairAsync(string pairingCode, DeviceDescriptor device, CancellationToken cancellationToken);
        Task<CurrentWork> GetCurrentWorkAsync(CancellationToken cancellationToken);
        Task<StationCalibration?> GetStationCalibrationAsync(CameraFrame frame, CancellationToken cancellationToken);
        Task<VisionLabelProfile?> GetVisionLabelProfileAsync(string buildSessionPublicId, string detectorName, CancellationToken cancellationToken);
        Task<VisionModelAssignment?> GetVisionModelAssignmentAsync(string buildSessionPublicId, string detectorName, CancellationToken cancellationToken);
        Task ReportVisionModelAsync(VisionModelReport report, CancellationToken cancellationToken);
        Task<BuildSession> StartBuildAsync(string kdsItemPublicId, string? sourceRevision, CancellationToken cancellationToken);
        Task<BuildSession> SubmitObservationAsync(string buildSessionPublicId, IngredientObservation observation, CancellationToken cancellationToken);
        Task<BuildSession> ConfirmComponentAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken);
        Task<BuildSession> ResolveUnexpectedAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken);
        Task<BuildSession> CorrectObservationAsync(string buildSessionPublicId, ObservationCorrection correction, CancellationToken cancellationToken);
        Task<IReadOnlyList<ObservationEvidence>> GetEvidenceAsync(string buildSessionPublicId, int limit, CancellationToken cancellationToken);
        Task<ProductValidation> EvaluateAsync(string buildSessionPublicId, CancellationToken cancellationToken);
        Task<ExpoHandoff> HandoffExpoAsync(string buildSessionPublicId, CancellationToken cancellationToken);
    }
}
