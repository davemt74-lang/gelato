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
        HudContract();
        SimulatorSupportContract();
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
        Assert(platform.StartTrackingCalls == 1 && platform.Tracking, "build start must start platform tracking");

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

        var confirmedValidation = await coordinator.ConfirmComponentAsync("ingredient:1");
        Assert(confirmedValidation.Status == "pending", "manual Verify confirmation must re-evaluate validation");
        Assert(gateway.ConfirmCalls == 1, "manual confirmation must use the build.confirm gateway action");

        var resolvedValidation = await coordinator.ResolveUnexpectedAsync("sim:unexpected:cheese");
        Assert(resolvedValidation.Status == "pending", "unexpected resolution must re-evaluate validation");
        Assert(gateway.ResolveCalls == 1, "unexpected resolution must use the build.resolve_unexpected gateway action");

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
        Assert(platform.StopTrackingCalls == 1 && !platform.Tracking, "successful handoff must stop platform tracking");

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


    private static void HudContract()
    {
        Assert(HudLayoutPolicy.ReferenceWidth == 1920f && HudLayoutPolicy.ReferenceHeight == 1080f, "HUD reference resolution must match AIR3 layout target");
        Assert(HudLayoutPolicy.PersistentUiClearsCenter(), "persistent right rail must not overlap the center safe zone");
        Assert(HudLayoutPolicy.CenterSafeZone.Right + HudLayoutPolicy.RailGapFromCenter <= HudLayoutPolicy.RightRail.X, "center safe zone must retain a physical gap before the right rail");
        Assert(HudLayoutPolicy.TransientOutlineSeconds <= 1.5f, "ingredient outlines must remain short-lived");

        var work = new CurrentWork
        {
            FocusItem = new WorkItem
            {
                KdsItemPublicId = "kds-club",
                Status = "in_progress",
                Name = "Club Sandwich + Fries",
                SpecialInstructions = "NO TOMATO"
            }
        };
        var build = new BuildSession
        {
            PublicId = "build-club",
            Status = "active",
            Components = new[]
            {
                new BuildComponent
                {
                    ComponentKey = "ingredient:bread",
                    DisplayName = "Bread",
                    ExpectedQuantity = 3,
                    DetectedQuantity = 3,
                    Unit = "slices",
                    Status = "confirmed",
                    Confidence = 0.97f
                },
                new BuildComponent
                {
                    ComponentKey = "ingredient:turkey",
                    DisplayName = "Turkey",
                    ExpectedQuantity = 3,
                    DetectedQuantity = 2,
                    Unit = "slices",
                    Status = "detected",
                    Confidence = 0.96f
                }
            },
            BuildSteps = new[]
            {
                new BuildStep { StepKey = "step:1", Order = 1, Text = "Add Bread", ComponentKeys = new[] { "ingredient:bread" } },
                new BuildStep { StepKey = "step:2", Order = 2, Text = "Add Turkey", ComponentKeys = new[] { "ingredient:turkey" } },
                new BuildStep { StepKey = "step:3", Order = 3, Text = "Top and slice", ComponentKeys = Array.Empty<string>() }
            }
        };

        var pending = new ProductValidation
        {
            Status = "pending",
            NextStage = string.Empty,
            AllIngredientsAccountedFor = false,
            Next = new ValidationNext { Label = "Expo / Finishing", Available = false }
        };
        var pendingHud = HudViewModelFactory.Create(work, build, pending, null);
        Assert(pendingHud.ItemTitle == "Club Sandwich + Fries", "ITEM panel must show the focused product");
        Assert(pendingHud.ItemBody.Contains("NO TOMATO", StringComparison.Ordinal), "ITEM panel must surface POS instructions");
        Assert(pendingHud.BuildBody.Contains("[x] Add Bread", StringComparison.Ordinal), "completed build steps must be marked complete");
        Assert(pendingHud.BuildBody.Contains("> Add Turkey", StringComparison.Ordinal), "first incomplete ingredient step must become active");
        Assert(pendingHud.BuildBody.Contains("• Top and slice", StringComparison.Ordinal), "action-only recipe instruction must remain visible");
        Assert(pendingHud.ValidationBody.Contains("Turkey", StringComparison.Ordinal), "PRODUCT VALIDATION must expose component accounting");
        Assert(!pendingHud.ShowNext, "NEXT must stay hidden before validation is ready");

        var ready = new ProductValidation
        {
            PublicId = "validation-club",
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
        };
        var readyHud = HudViewModelFactory.Create(work, build, ready, null);
        Assert(readyHud.ShowNext, "NEXT must appear when product validation is ready");
        Assert(readyHud.NextTitle == "EXPO / FINISHING", "NEXT must identify Expo / Finishing");
        Assert(readyHud.NextBody == "All ingredients accounted for.", "NEXT must explain readiness");
        Assert(readyHud.ValidationBody.StartsWith("ALL INGREDIENTS ACCOUNTED FOR", StringComparison.Ordinal), "validation panel must visibly confirm full accounting");

        var handoffHud = HudViewModelFactory.Create(work, build, ready, new ExpoHandoff
        {
            PublicId = "handoff-club",
            Status = "completed",
            KdsStatus = "ready",
            Label = "Sent to Expo / Finishing"
        });
        Assert(handoffHud.ShowNext && handoffHud.NextTitle == "SENT TO EXPO / FINISHING", "terminal handoff must replace the NEXT action with sent state");
    }


    private static void SimulatorSupportContract()
    {
        var component = new BuildComponent
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            ExpectedQuantity = 3f,
            DetectedQuantity = 1f,
            Unit = "slices",
            Status = "detected"
        };

        var normal = SimulatorObservationFactory.Create(component, 1);
        Assert(normal.ObservationKey.Contains("ingredient-42", StringComparison.Ordinal), "simulator observation key must be deterministic and component-scoped");
        Assert(Math.Abs(normal.Quantity - 2f) < 0.0001f, "simulator must default to the remaining expected quantity");
        Assert(Math.Abs(normal.Confidence - 0.96f) < 0.0001f, "normal simulator observation must use high confidence");
        Assert(normal.BoundingBox.Length == 4, "simulator observation must provide a normalized bounding box");

        var low = SimulatorObservationFactory.Create(component, 2, true, 1f);
        Assert(Math.Abs(low.Quantity - 1f) < 0.0001f, "simulator quantity override must be preserved");
        Assert(Math.Abs(low.Confidence - 0.68f) < 0.0001f, "low-confidence simulator mode must exercise Verify behavior");

        var unexpected = SimulatorObservationFactory.CreateUnexpected("Swiss Cheese", 3);
        Assert(unexpected.ComponentKey.Contains("swiss-cheese", StringComparison.Ordinal), "unexpected simulator observation must expose an isolated component key");
        Assert(Math.Abs(unexpected.Confidence - 0.95f) < 0.0001f, "unexpected simulator event must still be high-confidence evidence");

        for (var i = 1; i <= 12; i++)
        {
            var box = SimulatorObservationFactory.DeterministicBox(i);
            Assert(box[0] >= 0f && box[1] >= 0f && box[2] > 0f && box[3] > 0f, "simulator box must be positive");
            Assert(box[0] + box[2] <= 0.75f, "simulator ingredient box must remain out of the persistent right rail");
            Assert(box[1] + box[3] <= 1f, "simulator ingredient box must stay within the display");
        }

        Assert(SimulatorHotkeys.ComponentRange == "1-9", "simulator component hotkey range must remain documented");
        Assert(SimulatorHotkeys.ConfirmVerify == "C" && SimulatorHotkeys.ResolveUnexpected == "X", "simulator exception hotkeys must remain stable");
    }

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }

    private sealed class FakePlatform : IGlassesPlatform
    {
        public bool IsInitialized { get; private set; }
        public bool Tracking { get; private set; }
        public int StartTrackingCalls { get; private set; }
        public int StopTrackingCalls { get; private set; }
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

        public void StartTracking() { StartTrackingCalls++; Tracking = true; }
        public void StopTracking() { StopTrackingCalls++; Tracking = false; }
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
        public int ConfirmCalls { get; private set; }
        public int ResolveCalls { get; private set; }

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

        public Task<BuildSession> ConfirmComponentAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken)
        {
            ConfirmCalls++;
            return Task.FromResult(new BuildSession
            {
                PublicId = buildSessionPublicId,
                Status = "active",
                KdsItemPublicId = "kds-item-1",
                Components = new[]
                {
                    new BuildComponent
                    {
                        ComponentKey = componentKey,
                        DisplayName = "Verified component",
                        ExpectedQuantity = 1f,
                        DetectedQuantity = 1f,
                        Status = "confirmed",
                        Confidence = 1f
                    }
                }
            });
        }

        public Task<BuildSession> ResolveUnexpectedAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken)
        {
            ResolveCalls++;
            return Task.FromResult(new BuildSession
            {
                PublicId = buildSessionPublicId,
                Status = "active",
                KdsItemPublicId = "kds-item-1",
                Components = Array.Empty<BuildComponent>()
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
