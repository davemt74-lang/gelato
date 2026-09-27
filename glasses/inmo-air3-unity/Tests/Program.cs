using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;

internal static class Program
{
    private static async Task Main()
    {
        await PairingAndBuildFlow();
        await ExistingCredentialFlow();
        await ErrorStateFlow();
        Console.WriteLine("air3-unity-shell-ok");
    }

    private static async Task PairingAndBuildFlow()
    {
        var platform = new FakePlatform();
        var gateway = new FakeGateway();
        var store = new FakeTokenStore();
        var coordinator = new ArWorkflowCoordinator(platform, gateway, store);

        await coordinator.InitializeAsync();
        Assert(platform.IsInitialized, "platform must initialize");
        Assert(coordinator.State == WorkflowState.Unpaired, "missing token must produce Unpaired state");

        await coordinator.PairAsync("ABCDEF123456", new DeviceDescriptor
        {
            HardwareIdentifier = "AIR3-TEST-1",
            DisplayName = "Test AIR3",
            Platform = "inmo_air3",
            SdkVersion = "0.7.3",
            AppVersion = "0.1.0",
            SystemVersion = "3.4.test"
        });

        Assert(coordinator.State == WorkflowState.Idle, "pairing must end Idle");
        Assert(store.Load() == "device-token", "device token must persist");
        Assert(gateway.DeviceToken == "device-token", "gateway must receive paired token");

        var work = await coordinator.RefreshWorkAsync();
        Assert(coordinator.State == WorkflowState.WorkReady, "focused KDS work must produce WorkReady");
        Assert(work.FocusItem?.KdsItemPublicId == "kds-item-1", "focus item must come from Gelato");

        var premature = false;
        try { await coordinator.HandoffToExpoAsync(); }
        catch (InvalidOperationException) { premature = true; }
        Assert(premature, "handoff before a build must be rejected");

        coordinator.ResetForNextWork();
        await coordinator.RefreshWorkAsync();
        var build = await coordinator.StartFocusBuildAsync();
        Assert(coordinator.State == WorkflowState.Building, "start build must enter Building");
        Assert(build.PublicId == "build-1", "build session identity must be preserved");

        var firstValidation = await coordinator.SubmitObservationAsync(new IngredientObservation
        {
            ObservationKey = "obs-1",
            ComponentKey = "ingredient:1",
            DisplayName = "Bread",
            Action = "added",
            Quantity = 2,
            Confidence = 0.96f
        });
        Assert(firstValidation.Status == "pending", "partial build must remain pending");
        Assert(coordinator.State == WorkflowState.Building, "pending validation must remain Building");

        var secondValidation = await coordinator.SubmitObservationAsync(new IngredientObservation
        {
            ObservationKey = "obs-2",
            ComponentKey = "ingredient:1",
            DisplayName = "Bread",
            Action = "added",
            Quantity = 1,
            Confidence = 0.97f
        });
        Assert(ArWorkflowCoordinator.IsReadyForFinishing(secondValidation), "ready validation contract must be recognized");
        Assert(coordinator.State == WorkflowState.ReadyForFinishing, "ready validation must expose ReadyForFinishing");

        var handoff = await coordinator.HandoffToExpoAsync();
        Assert(coordinator.State == WorkflowState.HandedOff, "successful handoff must terminalize client workflow");
        Assert(handoff.KdsStatus == "ready", "handoff must preserve server KDS Ready state");
        Assert(gateway.HandoffCalls == 1, "handoff must use the Gelato handoff endpoint once");

        coordinator.ResetForNextWork();
        Assert(coordinator.State == WorkflowState.Idle, "paired reset must return to Idle");
        Assert(coordinator.BuildSession == null && coordinator.Validation == null && coordinator.Handoff == null, "reset must clear per-item runtime state");
    }

    private static async Task ExistingCredentialFlow()
    {
        var platform = new FakePlatform();
        var gateway = new FakeGateway();
        var store = new FakeTokenStore("existing-token");
        var coordinator = new ArWorkflowCoordinator(platform, gateway, store);

        await coordinator.InitializeAsync();

        Assert(coordinator.State == WorkflowState.Idle, "stored credential must restore Idle state");
        Assert(gateway.DeviceToken == "existing-token", "stored token must be installed into gateway");
    }

    private static async Task ErrorStateFlow()
    {
        var platform = new FakePlatform();
        var gateway = new FakeGateway { FailCurrentWork = true };
        var store = new FakeTokenStore("existing-token");
        var coordinator = new ArWorkflowCoordinator(platform, gateway, store);

        await coordinator.InitializeAsync();
        var failed = false;
        try { await coordinator.RefreshWorkAsync(); }
        catch (InvalidOperationException) { failed = true; }

        Assert(failed, "gateway failure must propagate");
        Assert(coordinator.State == WorkflowState.Error, "gateway failure must put coordinator in Error");
        Assert(coordinator.LastError == "simulated work failure", "coordinator must preserve a safe error message");
    }

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }

    private sealed class FakePlatform : IGlassesPlatform
    {
        public bool IsInitialized { get; private set; }
        public PlatformCapabilities Capabilities { get; } = new PlatformCapabilities
        {
            Platform = "fake",
            Camera = true,
            Tracking3Dof = true,
            Tracking6Dof = true,
            BinocularDisplay = true,
            TouchInput = true
        };

        public Task InitializeAsync(CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            IsInitialized = true;
            return Task.CompletedTask;
        }

        public void StartTracking() { }
        public void StopTracking() { }
        public CameraFrame? TryGetLatestFrame() => null;
        public CameraCalibration? TryGetCameraCalibration() => null;
        public PoseState GetPose() => new PoseState();
    }

    private sealed class FakeTokenStore : IDeviceTokenStore
    {
        private string? _token;
        public FakeTokenStore(string? token = null) { _token = token; }
        public string? Load() => _token;
        public void Save(string token) { _token = token; }
        public void Clear() { _token = null; }
    }

    private sealed class FakeGateway : IGelatoGateway
    {
        private int _observations;

        public string DeviceToken { get; private set; } = string.Empty;
        public bool FailCurrentWork { get; set; }
        public int HandoffCalls { get; private set; }

        public void SetDeviceToken(string token) { DeviceToken = token; }

        public Task<PairResult> PairAsync(string pairingCode, DeviceDescriptor device, CancellationToken cancellationToken)
        {
            Assert(pairingCode == "ABCDEF123456", "pairing code must reach gateway");
            return Task.FromResult(new PairResult { DeviceToken = "device-token", DevicePublicId = "glasses-1" });
        }

        public Task<CurrentWork> GetCurrentWorkAsync(CancellationToken cancellationToken)
        {
            if (FailCurrentWork) throw new InvalidOperationException("simulated work failure");
            return Task.FromResult(new CurrentWork
            {
                AssignmentRequired = false,
                Revision = "revision-1",
                FocusItem = new WorkItem
                {
                    KdsItemPublicId = "kds-item-1",
                    Status = "queued",
                    Name = "Club Sandwich + Fries"
                },
                Items = new List<WorkItem>()
            });
        }

        public Task<BuildSession> StartBuildAsync(string kdsItemPublicId, string? sourceRevision, CancellationToken cancellationToken)
        {
            Assert(kdsItemPublicId == "kds-item-1", "focused KDS ID must start build");
            Assert(sourceRevision == "revision-1", "work revision must be forwarded");
            return Task.FromResult(new BuildSession
            {
                PublicId = "build-1",
                Status = "active",
                KdsItemPublicId = kdsItemPublicId,
                SourceRevision = sourceRevision ?? string.Empty
            });
        }

        public Task<BuildSession> SubmitObservationAsync(string buildSessionPublicId, IngredientObservation observation, CancellationToken cancellationToken)
        {
            Assert(buildSessionPublicId == "build-1", "observation must target active build");
            _observations++;
            return Task.FromResult(new BuildSession
            {
                PublicId = buildSessionPublicId,
                Status = "active",
                KdsItemPublicId = "kds-item-1"
            });
        }

        public Task<ProductValidation> EvaluateAsync(string buildSessionPublicId, CancellationToken cancellationToken)
        {
            if (_observations < 2)
            {
                return Task.FromResult(new ProductValidation
                {
                    PublicId = "validation-1",
                    Status = "pending",
                    NextStage = string.Empty,
                    AllIngredientsAccountedFor = false,
                    Next = new ValidationNext { Label = "Expo / Finishing", Available = false }
                });
            }

            return Task.FromResult(new ProductValidation
            {
                PublicId = "validation-1",
                Status = "ready_for_finishing",
                NextStage = "expo_finishing",
                AllIngredientsAccountedFor = true,
                Next = new ValidationNext
                {
                    Stage = "expo_finishing",
                    Label = "Expo / Finishing",
                    Available = true,
                    Message = "All ingredients accounted for."
                }
            });
        }

        public Task<ExpoHandoff> HandoffExpoAsync(string buildSessionPublicId, CancellationToken cancellationToken)
        {
            HandoffCalls++;
            return Task.FromResult(new ExpoHandoff
            {
                PublicId = "handoff-1",
                Status = "completed",
                KdsStatus = "ready",
                Label = "Sent to Expo / Finishing"
            });
        }
    }
}
