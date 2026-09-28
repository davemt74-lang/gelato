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
        await ObservationCorrectionFlow();
        await HandsFreeReviewFlow();
        HudContract();
        SimulatorSupportContract();
        await StationCalibrationContract();
        await VisionLabelProfileContract();
        await VisionModelRolloutContract();
        await VisionModelActivationContract.RunAsync();
        await SpatialEvidenceFusionContract();
        await TransferSequenceEvidenceContract();
        await VisionPipelineContract();
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

    private static async Task ObservationCorrectionFlow()
    {
        var platform = new FakePlatform();
        var gateway = new FakeGateway();
        var coordinator = new ArWorkflowCoordinator(platform, gateway, new FakeTokenStore("existing-token"));

        await coordinator.InitializeAsync();
        await coordinator.RefreshWorkAsync();
        await coordinator.StartFocusBuildAsync();

        await coordinator.SubmitObservationAsync(new IngredientObservation
        {
            ObservationKey = "vision-build-1-track-7",
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            Action = "added",
            Quantity = 1,
            Confidence = 0.93f
        });

        Assert(coordinator.LastSubmittedObservationKey == "vision-build-1-track-7", "coordinator must retain the last submitted observation key for hands-free correction");

        var corrected = await coordinator.RejectLastObservationAsync("Wrong transfer");
        Assert(corrected.Status == "pending", "rejecting one observation must re-evaluate product validation");
        Assert(gateway.CorrectionCalls == 1, "reject-last must call the correction endpoint once");
        Assert(gateway.LastCorrection != null && gateway.LastCorrection.Resolution == "reject", "reject-last must send an immutable rejection correction");
        Assert(gateway.LastCorrection!.CorrectionKey == "reject:vision-build-1-track-7", "reject-last must use a deterministic idempotency key");

        var evidence = await coordinator.GetEvidenceAsync();
        Assert(gateway.EvidenceCalls == 1, "evidence review must use the build.evidence endpoint");
        Assert(evidence.Count == 1 && evidence[0].ObservationKey == "vision-build-1-track-7", "evidence review must preserve the original vision observation");
        Assert(evidence[0].LatestCorrection?.Resolution == "reject", "evidence review must expose the latest human correction");

        var replace = await coordinator.CorrectObservationAsync(new ObservationCorrection
        {
            CorrectionKey = "replace:vision-build-1-track-7:1",
            ObservationKey = "vision-build-1-track-7",
            Resolution = "replace",
            TargetComponentKey = "ingredient:84",
            CorrectedQuantity = 2,
            Reason = "This was Bacon, not Turkey"
        });
        Assert(replace.Status == "pending", "replacement correction must re-evaluate validation");
        Assert(gateway.CorrectionCalls == 2, "explicit replacement must call the correction endpoint");
        Assert(gateway.LastCorrection?.TargetComponentKey == "ingredient:84" && gateway.LastCorrection.CorrectedQuantity == 2, "replacement component and quantity must reach the gateway");

        coordinator.ResetForNextWork();
        Assert(coordinator.LastSubmittedObservationKey == null, "reset must clear last-observation correction state");
    }

    private static async Task HandsFreeReviewFlow()
    {
        Assert(HandsFreeCommandParser.Parse("confirm turkey").Kind == HandsFreeCommandKind.ConfirmVerify, "confirm ingredient speech must parse deterministically");
        Assert(HandsFreeCommandParser.Parse("UNDO last").Kind == HandsFreeCommandKind.RejectLastObservation, "undo-last alias must normalize case");
        Assert(HandsFreeCommandParser.Parse("send to expo").Kind == HandsFreeCommandKind.SendToExpo, "explicit Expo command must parse");
        Assert(HandsFreeCommandParser.Parse("finish").Kind == HandsFreeCommandKind.Unknown, "ambiguous finish command must fail closed");

        var platform = new FakePlatform();
        var gateway = new FakeGateway
        {
            StartComponents = new[]
            {
                new BuildComponent
                {
                    ComponentKey = "ingredient:turkey",
                    DisplayName = "Turkey",
                    ExpectedQuantity = 1,
                    DetectedQuantity = 1,
                    Status = "verify",
                    Confidence = 0.74f
                },
                new BuildComponent
                {
                    ComponentKey = "ingredient:bacon",
                    DisplayName = "Bacon",
                    ExpectedQuantity = 1,
                    DetectedQuantity = 1,
                    Status = "verify",
                    Confidence = 0.73f
                },
                new BuildComponent
                {
                    ComponentKey = "vision:unexpected:cheese",
                    DisplayName = "Swiss Cheese",
                    ExpectedQuantity = 0,
                    DetectedQuantity = 1,
                    Status = "unexpected",
                    Confidence = 0.95f
                }
            }
        };
        var coordinator = new ArWorkflowCoordinator(platform, gateway, new FakeTokenStore("existing-token"));
        var router = new HandsFreeCommandRouter(coordinator);

        await coordinator.InitializeAsync();
        var refresh = await router.ExecuteAsync("refresh work");
        Assert(refresh.Handled && refresh.Succeeded && coordinator.State == WorkflowState.WorkReady, "hands-free refresh must load focused KDS work");

        var start = await router.ExecuteAsync("start build");
        Assert(start.Succeeded && coordinator.State == WorkflowState.Building, "hands-free start must create the active build");

        var ambiguous = await router.ExecuteAsync("confirm");
        Assert(ambiguous.Handled && !ambiguous.Succeeded, "confirm without a target must fail closed when multiple Verify items exist");
        Assert(gateway.ConfirmCalls == 0, "ambiguous hands-free confirm must not mutate build state");
        Assert(coordinator.ReviewFeedback?.Attention == true && coordinator.ReviewFeedback?.Title == "SAY THE INGREDIENT", "ambiguous review must create visible HUD guidance");

        var confirm = await router.ExecuteAsync("confirm turkey");
        Assert(confirm.Succeeded && gateway.ConfirmCalls == 1, "targeted hands-free confirmation must call build.confirm once");
        Assert(coordinator.ReviewFeedback?.Title == "CONFIRMED", "successful confirm must be visible in HUD feedback");

        var resolve = await router.ExecuteAsync("ignore unexpected swiss cheese");
        Assert(resolve.Succeeded && gateway.ResolveCalls == 1, "targeted unexpected resolution must call the resolution endpoint");

        var blockedExpo = await router.ExecuteAsync("send expo");
        Assert(blockedExpo.Handled && !blockedExpo.Succeeded && gateway.HandoffCalls == 0, "Expo command must fail closed before validation readiness");
        Assert(coordinator.ReviewFeedback?.Title == "EXPO BLOCKED", "blocked Expo must explain itself in the HUD rail");

        await coordinator.SubmitObservationAsync(new IngredientObservation
        {
            ObservationKey = "handsfree-obs-1",
            ComponentKey = "ingredient:turkey",
            DisplayName = "Turkey",
            Action = "added",
            Quantity = 1,
            Confidence = 0.96f
        });
        await coordinator.SubmitObservationAsync(new IngredientObservation
        {
            ObservationKey = "handsfree-obs-2",
            ComponentKey = "ingredient:bacon",
            DisplayName = "Bacon",
            Action = "added",
            Quantity = 1,
            Confidence = 0.97f
        });

        var check = await router.ExecuteAsync("check product");
        Assert(check.Succeeded && coordinator.State == WorkflowState.ReadyForFinishing, "hands-free product check must surface readiness");

        var undo = await router.ExecuteAsync("undo last");
        Assert(undo.Succeeded && gateway.CorrectionCalls == 1, "undo last must use the immutable observation-correction path");
        Assert(gateway.LastCorrection?.ObservationKey == "handsfree-obs-2", "undo last must target the most recent submitted observation");
        Assert(gateway.LastCorrection?.CorrectionKey == "reject:handsfree-obs-2", "undo last must remain idempotent");

        var send = await router.ExecuteAsync("send to expo");
        Assert(send.Succeeded && gateway.HandoffCalls == 1 && coordinator.State == WorkflowState.HandedOff, "ready hands-free Expo command must hand off exactly once");

        var duplicateSend = await router.ExecuteAsync("expo");
        Assert(duplicateSend.Succeeded && gateway.HandoffCalls == 1, "duplicate Expo speech/event must not create a second handoff");
        Assert(coordinator.ReviewFeedback?.Title == "ALREADY SENT TO EXPO", "duplicate Expo must return deterministic feedback");

        var unknown = await router.ExecuteAsync("make it perfect");
        Assert(!unknown.Handled && !unknown.Succeeded, "unknown speech must not mutate the workflow");
        Assert(gateway.HandoffCalls == 1 && gateway.ConfirmCalls == 1 && gateway.ResolveCalls == 1, "unknown speech must have zero kitchen side effects");

        var feedbackHud = HudViewModelFactory.Create(
            coordinator.CurrentWork,
            coordinator.BuildSession,
            coordinator.Validation,
            coordinator.Handoff,
            coordinator.ReviewFeedback
        );
        Assert(feedbackHud.ShowReview, "hands-free result must render in the right-side HUD rail");
        Assert(feedbackHud.ReviewTitle == "COMMAND NOT RECOGNIZED", "HUD must display the latest command result without covering the center view");
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


    private static async Task StationCalibrationContract()
    {
        var frame = new CameraFrame
        {
            Data = new byte[640 * 480],
            Width = 640,
            Height = 480,
            TimestampNanoseconds = 101,
            PixelFormat = "grayscale8"
        };

        var calibration = new StationCalibration
        {
            PublicId = "station-cal-1",
            StationPublicId = "station-sandwich",
            StationName = "Sandwich",
            Version = 2,
            Platform = "inmo_air3",
            FrameWidth = 640,
            FrameHeight = 480,
            PixelFormat = "grayscale8",
            SourceHash = new string('a', 64),
            Compatibility = new CalibrationCompatibility { Compatible = true },
            Zones = new[]
            {
                new IngredientZone
                {
                    ZoneKey = "turkey-primary",
                    IngredientId = 42,
                    CanonicalName = "Turkey",
                    DisplayName = "Turkey Pan",
                    X = 0.10f,
                    Y = 0.20f,
                    Width = 0.20f,
                    Height = 0.20f,
                    Priority = 10
                },
                new IngredientZone
                {
                    ZoneKey = "turkey-backup",
                    IngredientId = 42,
                    CanonicalName = "Turkey",
                    DisplayName = "Turkey Backup",
                    X = 0.15f,
                    Y = 0.25f,
                    Width = 0.20f,
                    Height = 0.20f,
                    Priority = 5
                }
            },
            Regions = new[]
            {
                new StationRegion
                {
                    RegionKey = "build-main",
                    RegionType = "build_surface",
                    DisplayName = "Build Surface",
                    X = 0.40f,
                    Y = 0.45f,
                    Width = 0.35f,
                    Height = 0.35f,
                    Priority = 20
                }
            }
        };

        Assert(StationCalibrationPolicy.CanUse(calibration, frame), "matching camera geometry and compatible profile must be usable");

        var mismatched = new CameraFrame
        {
            Data = frame.Data,
            Width = 480,
            Height = 640,
            TimestampNanoseconds = 102,
            PixelFormat = "grayscale8"
        };
        Assert(!StationCalibrationPolicy.CanUse(calibration, mismatched), "camera geometry mismatch must disable station zones");

        var zones = StationCalibrationPolicy.ZonesForComponent(calibration, new BuildComponent
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey"
        });
        Assert(zones.Count == 2 && zones[0].ZoneKey == "turkey-primary", "ingredient zone lookup must return matching zones by priority");

        var best = StationCalibrationPolicy.HighestPriorityZoneAt(calibration, 0.18f, 0.28f);
        Assert(best != null && best.ZoneKey == "turkey-primary", "overlapping station zones must resolve by priority");
        var buildRegion = StationCalibrationPolicy.HighestPriorityRegionAt(calibration, "build_surface", 0.50f, 0.55f);
        Assert(buildRegion != null && buildRegion.RegionKey == "build-main", "station calibration must expose the configured build surface");
        Assert(StationCalibrationPolicy.HasRegionType(calibration, "build_surface"), "build-surface region type must be discoverable");

        var platform = new FakePlatform();
        var gateway = new FakeGateway { Calibration = calibration };
        var coordinator = new ArWorkflowCoordinator(platform, gateway, new FakeTokenStore("existing-token"));
        await coordinator.InitializeAsync();
        var loaded = await coordinator.RefreshStationCalibrationAsync(frame);
        Assert(loaded != null && loaded.PublicId == "station-cal-1", "coordinator must load station calibration from Gelato");
        Assert(coordinator.State == WorkflowState.Idle, "loading optional calibration must not disturb workflow state");

        gateway.FailCalibration = true;
        var unavailable = await coordinator.RefreshStationCalibrationAsync(frame);
        Assert(unavailable == null, "calibration transport failure must degrade to no spatial profile");
        Assert(coordinator.State == WorkflowState.Idle, "optional calibration failure must not put the kitchen workflow into Error");
        Assert(coordinator.LastCalibrationError == "simulated calibration failure", "optional calibration failure must remain diagnosable");
    }

    private static async Task VisionLabelProfileContract()
    {
        var profile = new VisionLabelProfile
        {
            Schema = "gelato.vision_label_profile.v1",
            DetectorName = "scripted-test-detector",
            BuildSessionPublicId = "build-1",
            ProfileHash = new string('b', 64),
            Mappings = new[]
            {
                new VisionLabelMapping
                {
                    ModelLabel = "turkey_slice",
                    NormalizedLabel = "turkey slice",
                    ComponentKey = "ingredient:42",
                    DisplayName = "Turkey",
                    IngredientId = 42,
                    MinimumConfidence = 0.85f,
                    SourceDetector = "scripted-test-detector"
                }
            }
        };

        var gateway = new FakeGateway
        {
            VisionProfile = profile,
            StartComponents = new[]
            {
                new BuildComponent
                {
                    ComponentKey = "ingredient:42",
                    DisplayName = "Turkey",
                    ExpectedQuantity = 1f,
                    Status = "waiting"
                }
            }
        };
        var coordinator = new ArWorkflowCoordinator(new FakePlatform(), gateway, new FakeTokenStore("existing-token"));

        await coordinator.InitializeAsync();
        await coordinator.RefreshWorkAsync();
        await coordinator.StartFocusBuildAsync();

        var loaded = await coordinator.RefreshVisionLabelProfileAsync("scripted-test-detector");
        Assert(loaded != null && loaded.ProfileHash == profile.ProfileHash, "coordinator must load the active build's detector label profile");
        Assert(gateway.VisionProfileCalls == 1, "vision profile must use the dedicated gateway action");
        Assert(coordinator.State == WorkflowState.Building, "optional vision-profile load must not disturb build workflow state");

        gateway.FailVisionProfile = true;
        var unavailable = await coordinator.RefreshVisionLabelProfileAsync("scripted-test-detector");
        Assert(unavailable == null, "vision profile transport failure must degrade to recipe-name fallback");
        Assert(coordinator.State == WorkflowState.Building, "optional vision-profile failure must not put the kitchen workflow into Error");
        Assert(coordinator.LastVisionProfileError == "simulated vision profile failure", "vision-profile fallback reason must remain diagnosable");

        coordinator.ResetForNextWork();
        Assert(coordinator.VisionLabelProfile == null && coordinator.LastVisionProfileError == null, "reset must clear per-build vision profile state");
    }

    private static async Task VisionModelRolloutContract()
    {
        var assignment = new VisionModelAssignment
        {
            Schema = "gelato.vision_model_assignment.v1",
            DetectorName = "scripted-test-detector",
            BuildSessionPublicId = "build-1",
            Action = "apply",
            AssignmentKey = new string('a', 64),
            Selection = "target",
            CanaryBucket = 7.25f,
            Rollout = new VisionModelRolloutAssignment
            {
                PublicId = "vision-rollout-1",
                Status = "active",
                CanaryPercent = 10f
            },
            Package = new VisionModelPackage
            {
                PublicId = "vision-model-2",
                DetectorName = "scripted-test-detector",
                ModelName = "sandwich-detector",
                ModelVersion = "2.0.0",
                RuntimeType = "onnx",
                Platform = "inmo_air3",
                ArtifactUrl = "https://models.example.test/sandwich-detector-2.onnx",
                ArtifactSha256 = new string('b', 64),
                ArtifactBytes = 2048,
                MinimumSdkVersion = "1.0.0",
                MinimumAppVersion = "1.0.0"
            },
            Compatibility = new VisionModelCompatibility { Compatible = true }
        };

        Assert(VisionModelAssignmentPolicy.CanApply(assignment, "scripted-test-detector", out var reasons)
            && reasons.Count == 0, "valid governed assignment must pass independent client policy");

        var insecure = new VisionModelAssignment
        {
            DetectorName = assignment.DetectorName,
            Action = "apply",
            Rollout = assignment.Rollout,
            Package = new VisionModelPackage
            {
                PublicId = "bad",
                DetectorName = assignment.DetectorName,
                RuntimeType = "onnx",
                Platform = "inmo_air3",
                ArtifactUrl = "http://models.example.test/model.onnx",
                ArtifactSha256 = new string('b', 64)
            },
            Compatibility = new VisionModelCompatibility { Compatible = true }
        };
        Assert(!VisionModelAssignmentPolicy.CanApply(insecure, "scripted-test-detector", out var insecureReasons)
            && Contains(insecureReasons, "artifact_url_not_https"), "client policy must reject non-HTTPS model artifacts");

        var badSha = new VisionModelAssignment
        {
            DetectorName = assignment.DetectorName,
            Action = "apply",
            Rollout = assignment.Rollout,
            Package = new VisionModelPackage
            {
                PublicId = "bad-sha",
                DetectorName = assignment.DetectorName,
                RuntimeType = "onnx",
                Platform = "inmo_air3",
                ArtifactUrl = "https://models.example.test/model.onnx",
                ArtifactSha256 = "not-a-checksum"
            },
            Compatibility = new VisionModelCompatibility { Compatible = true }
        };
        Assert(!VisionModelAssignmentPolicy.CanApply(badSha, "scripted-test-detector", out var shaReasons)
            && Contains(shaReasons, "artifact_sha256_invalid"), "client policy must reject invalid model checksums");

        var gateway = new FakeGateway { VisionModelAssignment = assignment };
        var coordinator = new ArWorkflowCoordinator(new FakePlatform(), gateway, new FakeTokenStore("existing-token"));
        await coordinator.InitializeAsync();
        await coordinator.RefreshWorkAsync();
        await coordinator.StartFocusBuildAsync();

        var loaded = await coordinator.RefreshVisionModelAssignmentAsync("scripted-test-detector");
        Assert(loaded != null && loaded.AssignmentKey == assignment.AssignmentKey, "coordinator must load the governed model assignment for the active build");
        Assert(gateway.VisionModelAssignmentCalls == 1, "model assignment must use the dedicated gateway action");
        Assert(coordinator.State == WorkflowState.Building, "optional model assignment must not disturb build workflow state");

        var seen = VisionModelAssignmentPolicy.AssignmentSeenReport(loaded!);
        Assert(seen.ReportType == "assignment_seen" && seen.ReportKey.EndsWith(":seen", StringComparison.Ordinal), "assignment-seen report must use an idempotent assignment-derived key");
        Assert(await coordinator.ReportVisionModelAsync(seen), "model assignment telemetry should report without mutating kitchen workflow");
        Assert(gateway.VisionModelReports.Count == 1 && gateway.VisionModelReports[0].PackagePublicId == "vision-model-2", "model report must preserve selected package identity");

        gateway.FailVisionModelAssignment = true;
        var unavailable = await coordinator.RefreshVisionModelAssignmentAsync("scripted-test-detector");
        Assert(unavailable == null, "model assignment transport failure must preserve the currently running detector");
        Assert(coordinator.State == WorkflowState.Building, "optional model assignment failure must not put the kitchen workflow into Error");
        Assert(coordinator.LastVisionModelError == "simulated vision model assignment failure", "assignment failure must remain diagnosable");

        gateway.FailVisionModelReport = true;
        var reportOk = await coordinator.ReportVisionModelAsync(new VisionModelReport
        {
            ReportKey = "report-failure",
            ReportType = "failed"
        });
        Assert(!reportOk && coordinator.State == WorkflowState.Building, "telemetry failure must not stop the cook workflow");

        coordinator.ResetForNextWork();
        Assert(coordinator.VisionModelAssignment == null && coordinator.LastVisionModelError == null, "reset must clear model-assignment state");
    }

    private static async Task SpatialEvidenceFusionContract()
    {
        var frame = new CameraFrame
        {
            Data = new byte[640 * 480],
            Width = 640,
            Height = 480,
            TimestampNanoseconds = 200,
            PixelFormat = "grayscale8"
        };

        var calibration = new StationCalibration
        {
            PublicId = "station-cal-fusion",
            FrameWidth = 640,
            FrameHeight = 480,
            PixelFormat = "grayscale8",
            Compatibility = new CalibrationCompatibility { Compatible = true },
            Zones = new[]
            {
                new IngredientZone
                {
                    ZoneKey = "turkey-pan",
                    IngredientId = 42,
                    DisplayName = "Turkey Pan",
                    X = 0.10f,
                    Y = 0.20f,
                    Width = 0.20f,
                    Height = 0.20f,
                    Priority = 20
                },
                new IngredientZone
                {
                    ZoneKey = "bacon-pan",
                    IngredientId = 43,
                    DisplayName = "Bacon Pan",
                    X = 0.50f,
                    Y = 0.20f,
                    Width = 0.20f,
                    Height = 0.20f,
                    Priority = 20
                }
            }
        };

        var supportedDetection = new VisionDetection
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            Confidence = 0.81f,
            Quantity = 1f,
            BoundingBox = new[] { 0.14f, 0.24f, 0.08f, 0.08f }
        };
        var supported = VisionEvidenceFusion.Evaluate(supportedDetection, calibration, frame, 0.06f, 0.20f);
        Assert(supported.Kind == SpatialEvidenceKind.Support, "detection centered in its calibrated ingredient zone must receive supporting spatial evidence");
        Assert(Math.Abs(supported.EffectiveConfidence - 0.87f) < 0.0001f, "supporting station evidence must apply only the configured bounded boost");
        Assert(supported.ZoneKey == "turkey-pan", "support evidence must identify the contributing station zone");

        var conflictingDetection = new VisionDetection
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            Confidence = 0.93f,
            Quantity = 1f,
            BoundingBox = new[] { 0.54f, 0.24f, 0.08f, 0.08f }
        };
        var conflicting = VisionEvidenceFusion.Evaluate(conflictingDetection, calibration, frame, 0.06f, 0.20f);
        Assert(conflicting.Kind == SpatialEvidenceKind.Conflict, "Turkey detected in the calibrated Bacon zone must be spatially conflicting");
        Assert(Math.Abs(conflicting.EffectiveConfidence - 0.73f) < 0.0001f, "conflicting station evidence must lower confidence by the configured penalty");
        Assert(conflicting.ZoneIngredientId == 43, "conflict evidence must identify the conflicting calibrated ingredient");

        var neutralDetection = new VisionDetection
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            Confidence = 0.81f,
            Quantity = 1f,
            BoundingBox = new[] { 0.80f, 0.70f, 0.08f, 0.08f }
        };
        var neutral = VisionEvidenceFusion.Evaluate(neutralDetection, calibration, frame, 0.06f, 0.20f);
        Assert(neutral.Kind == SpatialEvidenceKind.None && Math.Abs(neutral.EffectiveConfidence - 0.81f) < 0.0001f, "detection outside calibrated zones must keep raw visual confidence");

        var unexpectedDetection = new VisionDetection
        {
            ComponentKey = "vision:unexpected:cheese",
            DisplayName = "Cheese",
            Confidence = 0.94f,
            Quantity = 1f,
            BoundingBox = new[] { 0.14f, 0.24f, 0.08f, 0.08f },
            IsUnexpected = true
        };
        var unexpected = VisionEvidenceFusion.Evaluate(unexpectedDetection, calibration, frame, 0.06f, 0.20f);
        Assert(unexpected.Kind == SpatialEvidenceKind.None && Math.Abs(unexpected.EffectiveConfidence - 0.94f) < 0.0001f, "unexpected evidence must not be legitimized by a recipe ingredient zone");

        var turkey = new BuildComponent
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            ExpectedQuantity = 3f,
            Status = "waiting"
        };
        var context = new VisionFrameContext
        {
            BuildSessionPublicId = "build-spatial-fusion",
            ExpectedComponents = new[] { turkey },
            StationCalibration = calibration
        };
        var detector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "turkey-spatial", 0.81f, 1f, 0.14f, 0.24f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-spatial", 0.81f, 1f, 0.145f, 0.245f, 0.08f, 0.08f)
        );
        var pipeline = new VisionPipeline(detector);
        Assert((await pipeline.ProcessAsync(NextFrame(frame), context)).Count == 0, "spatial support must not bypass temporal stability");
        var fusedObservation = await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(fusedObservation.Count == 1, "stable visually detected ingredient should emit after spatial fusion");
        Assert(Math.Abs(fusedObservation[0].Confidence - 0.87f) < 0.0001f, "emitted observation must carry effective fused confidence");
        Assert(pipeline.Diagnostics.SpatialSupports == 2, "spatial support diagnostics must count candidate-frame evidence");

        var lowDetector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "turkey-too-low", 0.48f, 1f, 0.14f, 0.24f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-too-low", 0.48f, 1f, 0.145f, 0.245f, 0.08f, 0.08f)
        );
        var lowPipeline = new VisionPipeline(lowDetector);
        Assert((await lowPipeline.ProcessAsync(NextFrame(frame), context)).Count == 0, "below-floor raw visual evidence must be rejected before spatial support");
        Assert((await lowPipeline.ProcessAsync(NextFrame(frame), context)).Count == 0, "station location alone must never create a recipe observation");
        Assert(lowPipeline.Diagnostics.SpatialSupports == 0, "rejected raw visual candidates must not be counted as spatially supported observations");
    }

    private static async Task TransferSequenceEvidenceContract()
    {
        var frame = new CameraFrame
        {
            Data = new byte[640 * 480],
            Width = 640,
            Height = 480,
            TimestampNanoseconds = 500,
            PixelFormat = "grayscale8"
        };

        var turkey = new BuildComponent
        {
            ComponentKey = "ingredient:42",
            DisplayName = "Turkey",
            ExpectedQuantity = 3f,
            DetectedQuantity = 0f,
            Unit = "slices",
            Status = "waiting"
        };
        var bacon = new BuildComponent
        {
            ComponentKey = "ingredient:43",
            DisplayName = "Bacon",
            ExpectedQuantity = 3f,
            DetectedQuantity = 0f,
            Unit = "strips",
            Status = "waiting"
        };

        var calibration = new StationCalibration
        {
            PublicId = "station-cal-transfer",
            FrameWidth = 640,
            FrameHeight = 480,
            PixelFormat = "grayscale8",
            Compatibility = new CalibrationCompatibility { Compatible = true },
            Zones = new[]
            {
                new IngredientZone
                {
                    ZoneKey = "turkey-pan",
                    IngredientId = 42,
                    DisplayName = "Turkey Pan",
                    X = 0.05f,
                    Y = 0.15f,
                    Width = 0.20f,
                    Height = 0.22f,
                    Priority = 20
                },
                new IngredientZone
                {
                    ZoneKey = "bacon-pan",
                    IngredientId = 43,
                    DisplayName = "Bacon Pan",
                    X = 0.27f,
                    Y = 0.15f,
                    Width = 0.18f,
                    Height = 0.22f,
                    Priority = 20
                }
            },
            Regions = new[]
            {
                new StationRegion
                {
                    RegionKey = "build-main",
                    RegionType = "build_surface",
                    DisplayName = "Build Surface",
                    X = 0.40f,
                    Y = 0.45f,
                    Width = 0.38f,
                    Height = 0.35f,
                    Priority = 20
                }
            }
        };

        var context = new VisionFrameContext
        {
            BuildSessionPublicId = "build-transfer-1",
            ExpectedComponents = new[] { turkey, bacon },
            BuildSteps = new[]
            {
                new BuildStep
                {
                    StepKey = "step:1",
                    Order = 1,
                    Text = "Add Turkey",
                    ComponentKeys = new[] { "ingredient:42" }
                },
                new BuildStep
                {
                    StepKey = "step:2",
                    Order = 2,
                    Text = "Add Bacon",
                    ComponentKeys = new[] { "ingredient:43" }
                }
            },
            StationCalibration = calibration
        };

        var transferDetector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "turkey-transfer-a", 0.91f, 1f, 0.09f, 0.19f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-transfer-a", 0.92f, 1f, 0.10f, 0.20f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-transfer-a", 0.80f, 1f, 0.49f, 0.55f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-transfer-a", 0.82f, 1f, 0.50f, 0.56f, 0.08f, 0.08f)
        );
        var transferPipeline = new VisionPipeline(transferDetector);

        Assert((await transferPipeline.ProcessAsync(NextFrame(frame), context)).Count == 0, "ingredient sitting in its source pan must not be emitted as an add");
        Assert((await transferPipeline.ProcessAsync(NextFrame(frame), context)).Count == 0, "persistent source-pan presence must remain held");
        Assert(transferPipeline.ActiveTrackCount == 0, "source-pan evidence must not create a normal observation track");

        Assert((await transferPipeline.ProcessAsync(NextFrame(frame), context)).Count == 0, "first build-surface transfer frame must still satisfy temporal stability");
        var transferred = await transferPipeline.ProcessAsync(NextFrame(frame), context);
        Assert(transferred.Count == 1, "same tracked ingredient moving source → build surface must emit exactly one observation");
        Assert(transferred[0].Action == "added", "proven source → build transfer must be an added observation");
        Assert(Math.Abs(transferred[0].Confidence - 0.93f) < 0.0001f, "current-step transfer must receive bounded transfer + sequence support");
        Assert(transferred[0].EvidenceKind == "TransferConfirmed", "durable observation must retain transfer evidence kind");
        Assert(transferred[0].EvidenceSourceZoneKey == "turkey-pan", "durable observation must retain source ingredient zone");
        Assert(transferred[0].EvidenceDestinationRegionKey == "build-main", "durable observation must retain destination build region");
        Assert(transferred[0].EvidenceSequenceSupported, "durable observation must retain recipe-sequence support");
        Assert(transferPipeline.Diagnostics.TransferSourcesPrimed == 2, "source-zone evidence must be diagnosable");
        Assert(transferPipeline.Diagnostics.TransfersConfirmed == 2, "both stable build-surface transfer frames must retain transfer evidence");
        Assert(transferPipeline.Diagnostics.SequenceSupports == 2, "current recipe step must receive sequence support");
        Assert(transferPipeline.Diagnostics.ObservationsEmitted == 1, "one physical transfer must emit only one durable observation");

        var duplicateTransferDetector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "turkey-repeat", 0.92f, 1f, 0.09f, 0.19f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-repeat", 0.93f, 1f, 0.10f, 0.20f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-repeat", 0.82f, 1f, 0.50f, 0.56f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-repeat", 0.83f, 1f, 0.51f, 0.56f, 0.08f, 0.08f),
            Array.Empty<VisionDetection>(),
            Array.Empty<VisionDetection>(),
            Array.Empty<VisionDetection>(),
            D("ingredient:42", "Turkey", "turkey-repeat", 0.96f, 1f, 0.52f, 0.57f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-repeat", 0.97f, 1f, 0.53f, 0.57f, 0.08f, 0.08f)
        );
        var duplicateTransfer = new VisionPipeline(duplicateTransferDetector);
        await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        var firstTransfer = await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        Assert(firstTransfer.Count == 1 && firstTransfer[0].Action == "added", "first proven physical transfer must be additive");
        await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        Assert((await duplicateTransfer.ProcessAsync(NextFrame(frame), context)).Count == 0, "same physical instance must restabilize after occlusion");
        var repeatVisibility = await duplicateTransfer.ProcessAsync(NextFrame(frame), context);
        Assert(repeatVisibility.Count == 1 && repeatVisibility[0].Action == "seen", "same consumed transfer must never double-count as another add after occlusion");
        Assert(Math.Abs(repeatVisibility[0].Confidence - 0.74f) < 0.0001f, "repeat visibility of consumed transfer must remain review-only evidence");

        var sourceOnlyDetector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "turkey-bin-only", 0.97f, 1f, 0.10f, 0.20f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-bin-only", 0.98f, 1f, 0.11f, 0.20f, 0.08f, 0.08f)
        );
        var sourceOnly = new VisionPipeline(sourceOnlyDetector);
        Assert((await sourceOnly.ProcessAsync(NextFrame(frame), context)).Count == 0, "high-confidence ingredient in its bin must not count as added");
        Assert((await sourceOnly.ProcessAsync(NextFrame(frame), context)).Count == 0, "bin presence can never satisfy product quantity by itself");
        Assert(sourceOnly.Diagnostics.ObservationsEmitted == 0, "source-only evidence must not create a build observation");

        var unprimedDetector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "turkey-unprimed", 0.96f, 3f, 0.50f, 0.56f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "turkey-unprimed", 0.97f, 3f, 0.51f, 0.56f, 0.08f, 0.08f)
        );
        var unprimed = new VisionPipeline(unprimedDetector);
        Assert((await unprimed.ProcessAsync(NextFrame(frame), context)).Count == 0, "unprimed build-surface evidence must still stabilize");
        var unprimedObservation = await unprimed.ProcessAsync(NextFrame(frame), context);
        Assert(unprimedObservation.Count == 1, "visible ingredient already on build surface should remain reviewable evidence");
        Assert(unprimedObservation[0].Action == "seen", "unproven transfer must be seen, not added");
        Assert(Math.Abs(unprimedObservation[0].Confidence - 0.74f) < 0.0001f, "unproven transfer must remain below Gelato auto-confirm confidence");
        Assert(unprimedObservation[0].EvidenceKind == "WorkSurfaceUnprimed", "reviewable unprimed evidence must remain explainable");
        Assert(unprimed.Diagnostics.UnprimedWorkSurfaceDetections == 2, "unprimed work-surface evidence must be measurable");

        var baconDetector = new ScriptedVisionDetector(
            D("ingredient:43", "Bacon", "bacon-transfer", 0.91f, 1f, 0.30f, 0.20f, 0.08f, 0.08f),
            D("ingredient:43", "Bacon", "bacon-transfer", 0.92f, 1f, 0.31f, 0.20f, 0.08f, 0.08f),
            D("ingredient:43", "Bacon", "bacon-transfer", 0.82f, 1f, 0.52f, 0.57f, 0.08f, 0.08f),
            D("ingredient:43", "Bacon", "bacon-transfer", 0.83f, 1f, 0.53f, 0.57f, 0.08f, 0.08f)
        );
        var baconPipeline = new VisionPipeline(baconDetector);
        await baconPipeline.ProcessAsync(NextFrame(frame), context);
        await baconPipeline.ProcessAsync(NextFrame(frame), context);
        await baconPipeline.ProcessAsync(NextFrame(frame), context);
        var baconTransfer = await baconPipeline.ProcessAsync(NextFrame(frame), context);
        Assert(baconTransfer.Count == 1, "valid Bacon transfer may still be recognized even when cook order differs");
        Assert(Math.Abs(baconTransfer[0].Confidence - 0.91f) < 0.0001f, "out-of-sequence transfer gets transfer support but not current-step sequence boost");
        Assert(baconPipeline.Diagnostics.SequenceSupports == 0, "non-current recipe step must not receive sequence support");

        var unexpectedOutsideDetector = new ScriptedVisionDetector(
            DU("vision:unexpected:cheese", "Swiss Cheese", "cheese-counter", 0.96f, 0.78f, 0.20f, 0.08f, 0.08f),
            DU("vision:unexpected:cheese", "Swiss Cheese", "cheese-counter", 0.97f, 0.78f, 0.20f, 0.08f, 0.08f)
        );
        var unexpectedOutside = new VisionPipeline(unexpectedOutsideDetector);
        Assert((await unexpectedOutside.ProcessAsync(NextFrame(frame), context)).Count == 0, "unexpected ingredient away from build surface must not create a false product exception");
        Assert((await unexpectedOutside.ProcessAsync(NextFrame(frame), context)).Count == 0, "persistent unexpected ingredient off-product must remain held");

        var unexpectedBuildDetector = new ScriptedVisionDetector(
            DU("vision:unexpected:cheese", "Swiss Cheese", "cheese-build", 0.95f, 0.52f, 0.58f, 0.08f, 0.08f),
            DU("vision:unexpected:cheese", "Swiss Cheese", "cheese-build", 0.96f, 0.53f, 0.58f, 0.08f, 0.08f)
        );
        var unexpectedBuild = new VisionPipeline(unexpectedBuildDetector);
        Assert((await unexpectedBuild.ProcessAsync(NextFrame(frame), context)).Count == 0, "unexpected product evidence still requires temporal stability");
        var unexpectedProduct = await unexpectedBuild.ProcessAsync(NextFrame(frame), context);
        Assert(unexpectedProduct.Count == 1 && unexpectedProduct[0].ComponentKey == "vision:unexpected:cheese", "unexpected ingredient on build surface must still reach product validation");

        var noRegionsCalibration = new StationCalibration
        {
            PublicId = "station-cal-no-work-region",
            FrameWidth = 640,
            FrameHeight = 480,
            PixelFormat = "grayscale8",
            Compatibility = new CalibrationCompatibility { Compatible = true },
            Zones = calibration.Zones
        };
        var fallbackContext = new VisionFrameContext
        {
            BuildSessionPublicId = "build-transfer-fallback",
            ExpectedComponents = new[] { turkey },
            StationCalibration = noRegionsCalibration
        };
        var fallbackDetector = new ScriptedVisionDetector(
            D("ingredient:42", "Turkey", "fallback-a", 0.90f, 1f, 0.10f, 0.20f, 0.08f, 0.08f),
            D("ingredient:42", "Turkey", "fallback-a", 0.91f, 1f, 0.11f, 0.20f, 0.08f, 0.08f)
        );
        var fallbackPipeline = new VisionPipeline(fallbackDetector);
        await fallbackPipeline.ProcessAsync(NextFrame(frame), fallbackContext);
        var fallback = await fallbackPipeline.ProcessAsync(NextFrame(frame), fallbackContext);
        Assert(fallback.Count == 1, "stations without a build-surface region must preserve Section 12 visual/spatial behavior instead of breaking service");
    }

    private static async Task VisionPipelineContract()
    {
        var frame = new CameraFrame
        {
            Data = new byte[640 * 480],
            Width = 640,
            Height = 480,
            TimestampNanoseconds = 1,
            PixelFormat = "grayscale8"
        };
        var bread = new BuildComponent
        {
            ComponentKey = "ingredient:bread",
            DisplayName = "Bread",
            ExpectedQuantity = 3f,
            Unit = "slices",
            Status = "waiting"
        };
        var context = new VisionFrameContext
        {
            BuildSessionPublicId = "build-vision-1",
            ExpectedComponents = new[] { bread },
            Calibration = new CameraCalibration { Fx = 640f, Fy = 640f, Cx = 320f, Cy = 240f },
            Pose = new PoseState()
        };

        var detector = new ScriptedVisionDetector(
            D("ingredient:bread", "Bread", "bread-a", 0.92f, 1f, 0.20f, 0.40f, 0.12f, 0.12f),
            D("ingredient:bread", "Bread", "bread-a", 0.94f, 1f, 0.205f, 0.405f, 0.12f, 0.12f),
            D("ingredient:bread", "Bread", "bread-a", 0.95f, 1f, 0.21f, 0.41f, 0.12f, 0.12f),
            Array.Empty<VisionDetection>(),
            Array.Empty<VisionDetection>(),
            Array.Empty<VisionDetection>(),
            D("ingredient:bread", "Bread", "bread-a", 0.93f, 1f, 0.22f, 0.42f, 0.12f, 0.12f),
            D("ingredient:bread", "Bread", "bread-a", 0.96f, 1f, 0.225f, 0.425f, 0.12f, 0.12f)
        );
        var pipeline = new VisionPipeline(detector, new VisionPipelineOptions
        {
            MinimumConfidence = 0.50f,
            StableFramesRequired = 2,
            MaxMissingFrames = 3,
            AssociationIouThreshold = 0.25f
        });

        var r1 = await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(r1.Count == 0, "single-frame vision evidence must not emit before stability threshold");

        var r2 = await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(r2.Count == 1, "stable two-frame ingredient detection must emit once");
        Assert(r2[0].ComponentKey == "ingredient:bread" && r2[0].Action == "added", "vision observation must preserve component/action");
        Assert(r2[0].BoundingBox.Length == 4, "vision observation must preserve normalized bounding box");

        var r3 = await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(r3.Count == 0, "persistent visible ingredient must not be double-counted");

        await pipeline.ProcessAsync(NextFrame(frame), context);
        await pipeline.ProcessAsync(NextFrame(frame), context);
        await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(pipeline.ActiveTrackCount == 0, "track must retire after configured missing frames");

        var r7 = await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(r7.Count == 0, "reappearing ingredient must restabilize after track retirement");
        var r8 = await pipeline.ProcessAsync(NextFrame(frame), context);
        Assert(r8.Count == 1, "reappearing ingredient may emit as a new addition after restabilization");
        Assert(r8[0].ObservationKey != r2[0].ObservationKey, "retired/reappearing track must receive a distinct idempotency key");

        var constrainedDetector = new ScriptedVisionDetector(
            D("ingredient:cheese", "Cheese", "cheese-a", 0.40f, 1f, 0.1f, 0.2f, 0.1f, 0.1f),
            D("ingredient:cheese", "Cheese", "cheese-a", 0.97f, 1f, 0.1f, 0.2f, 0.1f, 0.1f),
            D("ingredient:cheese", "Cheese", "cheese-a", 0.97f, 1f, 0.1f, 0.2f, 0.1f, 0.1f),
            DU("vision:swiss", "Swiss Cheese", "swiss-a", 0.94f, 0.32f, 0.44f, 0.11f, 0.11f),
            DU("vision:swiss", "Swiss Cheese", "swiss-a", 0.95f, 0.32f, 0.44f, 0.11f, 0.11f)
        );
        var constrained = new VisionPipeline(constrainedDetector);
        Assert((await constrained.ProcessAsync(NextFrame(frame), context)).Count == 0, "below-threshold detection must be rejected");
        Assert((await constrained.ProcessAsync(NextFrame(frame), context)).Count == 0, "non-recipe detection must be rejected even at high confidence");
        Assert((await constrained.ProcessAsync(NextFrame(frame), context)).Count == 0, "non-recipe detection must never stabilize into a normal observation");
        Assert((await constrained.ProcessAsync(NextFrame(frame), context)).Count == 0, "unexpected evidence must still satisfy temporal stability");
        var unexpected = await constrained.ProcessAsync(NextFrame(frame), context);
        Assert(unexpected.Count == 1 && unexpected[0].ComponentKey == "vision:swiss", "explicit unexpected detector evidence must pass through after stabilization");

        var multiDetector = new ScriptedVisionDetector(
            new[]
            {
                D1("ingredient:bread", "Bread", "slice-a", 0.96f, 1f, 0.18f, 0.45f, 0.09f, 0.09f),
                D1("ingredient:bread", "Bread", "slice-b", 0.95f, 1f, 0.33f, 0.45f, 0.09f, 0.09f)
            },
            new[]
            {
                D1("ingredient:bread", "Bread", "slice-a", 0.97f, 1f, 0.18f, 0.45f, 0.09f, 0.09f),
                D1("ingredient:bread", "Bread", "slice-b", 0.96f, 1f, 0.33f, 0.45f, 0.09f, 0.09f)
            }
        );
        var multi = new VisionPipeline(multiDetector);
        await multi.ProcessAsync(NextFrame(frame), new VisionFrameContext
        {
            BuildSessionPublicId = "build-vision-multi",
            ExpectedComponents = new[] { bread }
        });
        var multiOutput = await multi.ProcessAsync(NextFrame(frame), new VisionFrameContext
        {
            BuildSessionPublicId = "build-vision-multi",
            ExpectedComponents = new[] { bread }
        });
        Assert(multiOutput.Count == 2, "distinct detector instance keys must allow multiple same-ingredient objects to emit separately");
        Assert(multiOutput[0].ObservationKey != multiOutput[1].ObservationKey, "same-ingredient instances must receive unique observation keys");

        var labelDetector = new ScriptedVisionDetector(
            DL("Turkey", "turkey-label-a", 0.93f, 1f, 0.24f, 0.42f, 0.10f, 0.10f),
            DL("Turkey", "turkey-label-a", 0.95f, 1f, 0.245f, 0.425f, 0.10f, 0.10f)
        );
        var labelPipeline = new VisionPipeline(labelDetector);
        var turkeyContext = new VisionFrameContext
        {
            BuildSessionPublicId = "build-vision-label-map",
            ExpectedComponents = new[]
            {
                new BuildComponent
                {
                    ComponentKey = "ingredient:turkey",
                    DisplayName = "Turkey",
                    ExpectedQuantity = 3f,
                    Status = "waiting"
                }
            }
        };
        Assert((await labelPipeline.ProcessAsync(NextFrame(frame), turkeyContext)).Count == 0, "label-only detector evidence must still satisfy temporal stability");
        var labelMapped = await labelPipeline.ProcessAsync(NextFrame(frame), turkeyContext);
        Assert(labelMapped.Count == 1 && labelMapped[0].ComponentKey == "ingredient:turkey", "detector labels must map deterministically to the active Gelato component without knowing its database key");
        Assert(labelPipeline.Diagnostics.DisplayNameFallbackMatches == 2, "exact recipe-name fallback must remain measurable when no registry mapping is used");

        var profileContext = new VisionFrameContext
        {
            BuildSessionPublicId = "build-profile-label-map",
            ExpectedComponents = turkeyContext.ExpectedComponents,
            VisionProfile = new VisionLabelProfile
            {
                Schema = "gelato.vision_label_profile.v1",
                DetectorName = "scripted-test-detector",
                BuildSessionPublicId = "build-profile-label-map",
                ProfileHash = new string('c', 64),
                Mappings = new[]
                {
                    new VisionLabelMapping
                    {
                        ModelLabel = "turkey_slice",
                        NormalizedLabel = "turkey slice",
                        ComponentKey = "ingredient:turkey",
                        DisplayName = "Turkey",
                        MinimumConfidence = 0.85f,
                        SourceDetector = "scripted-test-detector"
                    }
                }
            }
        };
        var profiledDetector = new ScriptedVisionDetector(
            DL("turkey_slice", "profile-turkey", 0.80f, 1f, 0.24f, 0.42f, 0.10f, 0.10f),
            DL("turkey_slice", "profile-turkey", 0.81f, 1f, 0.24f, 0.42f, 0.10f, 0.10f),
            DL("turkey_slice", "profile-turkey", 0.90f, 1f, 0.24f, 0.42f, 0.10f, 0.10f),
            DL("turkey_slice", "profile-turkey", 0.91f, 1f, 0.245f, 0.425f, 0.10f, 0.10f)
        );
        var profiledPipeline = new VisionPipeline(profiledDetector);
        Assert((await profiledPipeline.ProcessAsync(NextFrame(frame), profileContext)).Count == 0, "profile threshold must reject raw detector confidence below its configured floor");
        Assert((await profiledPipeline.ProcessAsync(NextFrame(frame), profileContext)).Count == 0, "repeated below-profile-floor evidence must remain rejected");
        Assert((await profiledPipeline.ProcessAsync(NextFrame(frame), profileContext)).Count == 0, "first above-profile-floor frame must still satisfy temporal stability");
        var profiledObservation = await profiledPipeline.ProcessAsync(NextFrame(frame), profileContext);
        Assert(profiledObservation.Count == 1 && profiledObservation[0].ComponentKey == "ingredient:turkey", "registry label must resolve to the active recipe component");
        Assert(profiledObservation[0].DetectorLabel == "turkey_slice", "emitted observation must retain the detector's original model label");
        Assert(profiledObservation[0].VisionProfileMatched, "emitted observation must state that a registry mapping resolved it");
        Assert(profiledObservation[0].VisionProfileMinimumConfidence.HasValue
            && Math.Abs(profiledObservation[0].VisionProfileMinimumConfidence.GetValueOrDefault() - 0.85f) < 0.0001f,
            "emitted observation must retain the applied profile confidence floor");
        Assert(profiledPipeline.Diagnostics.ProfileLabelMatches == 4, "profile matches must remain observable even when threshold policy rejects a candidate");
        Assert(profiledPipeline.Diagnostics.ProfileThresholdRejects == 2, "profile-threshold rejections must be counted separately");

        var staleProfileContext = new VisionFrameContext
        {
            BuildSessionPublicId = "build-profile-stale",
            ExpectedComponents = turkeyContext.ExpectedComponents,
            VisionProfile = profileContext.VisionProfile
        };
        var staleProfileDetector = new ScriptedVisionDetector(
            DL("turkey_slice", "stale-profile", 0.96f, 1f, 0.24f, 0.42f, 0.10f, 0.10f),
            DL("turkey_slice", "stale-profile", 0.97f, 1f, 0.245f, 0.425f, 0.10f, 0.10f)
        );
        var staleProfilePipeline = new VisionPipeline(staleProfileDetector);
        Assert((await staleProfilePipeline.ProcessAsync(NextFrame(frame), staleProfileContext)).Count == 0, "profile from another build session must be ignored");
        Assert((await staleProfilePipeline.ProcessAsync(NextFrame(frame), staleProfileContext)).Count == 0, "stale profile must never resolve a label that lacks exact-name fallback");
        Assert(staleProfilePipeline.Diagnostics.ProfileLabelMatches == 0, "stale profile must not be counted as applied");

        var nonRecipeProfileContext = new VisionFrameContext
        {
            BuildSessionPublicId = "build-profile-nonrecipe",
            ExpectedComponents = turkeyContext.ExpectedComponents,
            VisionProfile = new VisionLabelProfile
            {
                DetectorName = "scripted-test-detector",
                BuildSessionPublicId = "build-profile-nonrecipe",
                Mappings = new[]
                {
                    new VisionLabelMapping
                    {
                        ModelLabel = "turkey_slice",
                        NormalizedLabel = "turkey slice",
                        ComponentKey = "ingredient:bacon",
                        DisplayName = "Bacon",
                        MinimumConfidence = 0.60f
                    }
                }
            }
        };
        var nonRecipeDetector = new ScriptedVisionDetector(
            DL("turkey_slice", "wrong-target", 0.96f, 1f, 0.24f, 0.42f, 0.10f, 0.10f),
            DL("turkey_slice", "wrong-target", 0.97f, 1f, 0.245f, 0.425f, 0.10f, 0.10f)
        );
        var nonRecipePipeline = new VisionPipeline(nonRecipeDetector);
        Assert((await nonRecipePipeline.ProcessAsync(NextFrame(frame), nonRecipeProfileContext)).Count == 0, "profile mapping to a non-recipe component must fail closed");
        Assert((await nonRecipePipeline.ProcessAsync(NextFrame(frame), nonRecipeProfileContext)).Count == 0, "non-recipe profile target must never become a build observation");

        var unknownLabelDetector = new ScriptedVisionDetector(
            DL("Swiss Cheese", "unknown-a", 0.96f, 1f, 0.3f, 0.4f, 0.1f, 0.1f),
            DL("Swiss Cheese", "unknown-a", 0.96f, 1f, 0.3f, 0.4f, 0.1f, 0.1f)
        );
        var unknownLabelPipeline = new VisionPipeline(unknownLabelDetector);
        Assert((await unknownLabelPipeline.ProcessAsync(NextFrame(frame), turkeyContext)).Count == 0, "unknown normal labels must fail closed");
        Assert((await unknownLabelPipeline.ProcessAsync(NextFrame(frame), turkeyContext)).Count == 0, "unknown normal labels must never become unexpected implicitly");

        var generatedUnexpectedDetector = new ScriptedVisionDetector(
            DUL("Swiss Cheese", "unexpected-label-a", 0.95f, 0.34f, 0.45f, 0.1f, 0.1f),
            DUL("Swiss Cheese", "unexpected-label-a", 0.96f, 0.34f, 0.45f, 0.1f, 0.1f)
        );
        var generatedUnexpectedPipeline = new VisionPipeline(generatedUnexpectedDetector);
        await generatedUnexpectedPipeline.ProcessAsync(NextFrame(frame), turkeyContext);
        var generatedUnexpected = await generatedUnexpectedPipeline.ProcessAsync(NextFrame(frame), turkeyContext);
        Assert(generatedUnexpected.Count == 1 && generatedUnexpected[0].ComponentKey == "vision:unexpected:swiss-cheese", "explicit unexpected model evidence must receive a stable generated component key when the detector does not know Gelato IDs");

        var duplicateFrameDetector = new ScriptedVisionDetector(
            D("ingredient:bread", "Bread", "dup-a", 0.96f, 1f, 0.2f, 0.4f, 0.1f, 0.1f),
            D("ingredient:bread", "Bread", "dup-a", 0.97f, 1f, 0.2f, 0.4f, 0.1f, 0.1f)
        );
        var duplicatePipeline = new VisionPipeline(duplicateFrameDetector);
        var duplicateContext = new VisionFrameContext
        {
            BuildSessionPublicId = "build-duplicate-frame",
            ExpectedComponents = new[] { bread }
        };
        var duplicateFrame = NextFrame(frame);
        Assert((await duplicatePipeline.ProcessAsync(duplicateFrame, duplicateContext)).Count == 0, "first unique frame starts stability tracking");
        Assert((await duplicatePipeline.ProcessAsync(duplicateFrame, duplicateContext)).Count == 0, "the same camera timestamp must not count as a second stable frame");
        Assert(duplicatePipeline.Diagnostics.DuplicateOrStaleFramesSkipped == 1, "duplicate/stale frame skips must be observable in diagnostics");
        Assert((await duplicatePipeline.ProcessAsync(NextFrame(frame), duplicateContext)).Count == 1, "a genuinely new second frame may satisfy stability");
        Assert(duplicatePipeline.Diagnostics.ObservationsEmitted == 1, "vision diagnostics must count emitted observations");

        var a = new VisionBoundingBox(0.1f, 0.1f, 0.2f, 0.2f);
        var b = new VisionBoundingBox(0.15f, 0.15f, 0.2f, 0.2f);
        Assert(VisionBoundingBox.IntersectionOverUnion(a, b) > 0f, "vision tracker IoU must detect overlapping boxes");

        var clamped = new VisionBoundingBox(-1f, 0.9f, 4f, 4f);
        Assert(clamped.X == 0f && clamped.Y == 0.9f && clamped.Right <= 1f && clamped.Bottom <= 1f, "vision bounding boxes must be clamped to normalized image coordinates");
    }

    private static CameraFrame NextFrame(CameraFrame frame)
    {
        frame.TimestampNanoseconds++;
        return frame;
    }

    private static IReadOnlyList<VisionDetection> DL(
        string label, string instanceKey, float confidence, float quantity,
        float x, float y, float width, float height)
    {
        return new[]
        {
            new VisionDetection
            {
                Label = label,
                DisplayName = label,
                InstanceKey = instanceKey,
                Action = "added",
                Quantity = quantity,
                Confidence = confidence,
                BoundingBox = new[] { x, y, width, height }
            }
        };
    }

    private static IReadOnlyList<VisionDetection> DUL(
        string label, string instanceKey, float confidence,
        float x, float y, float width, float height)
    {
        return new[]
        {
            new VisionDetection
            {
                Label = label,
                DisplayName = label,
                InstanceKey = instanceKey,
                Action = "added",
                Quantity = 1f,
                Confidence = confidence,
                BoundingBox = new[] { x, y, width, height },
                IsUnexpected = true
            }
        };
    }

    private static IReadOnlyList<VisionDetection> D(
        string componentKey, string displayName, string instanceKey, float confidence, float quantity,
        float x, float y, float width, float height)
    {
        return new[] { D1(componentKey, displayName, instanceKey, confidence, quantity, x, y, width, height) };
    }

    private static VisionDetection D1(
        string componentKey, string displayName, string instanceKey, float confidence, float quantity,
        float x, float y, float width, float height)
    {
        return new VisionDetection
        {
            ComponentKey = componentKey,
            DisplayName = displayName,
            InstanceKey = instanceKey,
            Action = "added",
            Quantity = quantity,
            Confidence = confidence,
            BoundingBox = new[] { x, y, width, height }
        };
    }

    private static IReadOnlyList<VisionDetection> DU(
        string componentKey, string displayName, string instanceKey, float confidence,
        float x, float y, float width, float height)
    {
        return new[]
        {
            new VisionDetection
            {
                ComponentKey = componentKey,
                DisplayName = displayName,
                InstanceKey = instanceKey,
                Action = "added",
                Quantity = 1f,
                Confidence = confidence,
                BoundingBox = new[] { x, y, width, height },
                IsUnexpected = true
            }
        };
    }

    private sealed class ScriptedVisionDetector : IVisionDetector
    {
        private readonly Queue<IReadOnlyList<VisionDetection>> _frames;

        public ScriptedVisionDetector(params IReadOnlyList<VisionDetection>[] frames)
        {
            _frames = new Queue<IReadOnlyList<VisionDetection>>(frames);
        }

        public string DetectorName => "scripted-test-detector";

        public Task<IReadOnlyList<VisionDetection>> DetectAsync(
            CameraFrame frame,
            VisionFrameContext context,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var result = _frames.Count > 0 ? _frames.Dequeue() : Array.Empty<VisionDetection>();
            return Task.FromResult(result);
        }
    }

    private static bool Contains(IReadOnlyList<string> values, string expected)
    {
        foreach (var value in values) if (string.Equals(value, expected, StringComparison.Ordinal)) return true;
        return false;
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
        public bool FailCalibration { get; set; }
        public bool FailVisionProfile { get; set; }
        public bool FailVisionModelAssignment { get; set; }
        public bool FailVisionModelReport { get; set; }
        public StationCalibration? Calibration { get; set; }
        public VisionLabelProfile? VisionProfile { get; set; }
        public VisionModelAssignment? VisionModelAssignment { get; set; }
        public int VisionProfileCalls { get; private set; }
        public int VisionModelAssignmentCalls { get; private set; }
        public List<VisionModelReport> VisionModelReports { get; } = new List<VisionModelReport>();
        public int HandoffCalls { get; private set; }
        public int ConfirmCalls { get; private set; }
        public int ResolveCalls { get; private set; }
        public int CorrectionCalls { get; private set; }
        public int EvidenceCalls { get; private set; }
        public ObservationCorrection? LastCorrection { get; private set; }
        public IReadOnlyList<BuildComponent> StartComponents { get; set; } = Array.Empty<BuildComponent>();
        private readonly List<BuildComponent> _currentComponents = new List<BuildComponent>();

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

        public Task<StationCalibration?> GetStationCalibrationAsync(CameraFrame frame, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (FailCalibration) throw new InvalidOperationException("simulated calibration failure");
            return Task.FromResult(Calibration);
        }

        public Task<VisionLabelProfile?> GetVisionLabelProfileAsync(
            string buildSessionPublicId,
            string detectorName,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            VisionProfileCalls++;
            if (FailVisionProfile) throw new InvalidOperationException("simulated vision profile failure");
            return Task.FromResult(VisionProfile);
        }

        public Task<VisionModelAssignment?> GetVisionModelAssignmentAsync(
            string buildSessionPublicId,
            string detectorName,
            CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            VisionModelAssignmentCalls++;
            if (FailVisionModelAssignment) throw new InvalidOperationException("simulated vision model assignment failure");
            return Task.FromResult(VisionModelAssignment);
        }

        public Task ReportVisionModelAsync(VisionModelReport report, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (FailVisionModelReport) throw new InvalidOperationException("simulated vision model report failure");
            VisionModelReports.Add(report);
            return Task.CompletedTask;
        }

        public Task<BuildSession> StartBuildAsync(string kdsItemPublicId, string? sourceRevision, CancellationToken cancellationToken)
        {
            Assert(kdsItemPublicId == "kds-item-1", "focused KDS ID must start build");
            Assert(sourceRevision == "revision-1", "work revision must be forwarded");
            _currentComponents.Clear();
            foreach (var component in StartComponents) _currentComponents.Add(CloneComponent(component));
            return Task.FromResult(new BuildSession
            {
                PublicId = "build-1",
                Status = "active",
                KdsItemPublicId = kdsItemPublicId,
                SourceRevision = sourceRevision ?? string.Empty,
                Components = SnapshotComponents()
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
                KdsItemPublicId = "kds-item-1",
                Components = SnapshotComponents()
            });
        }

        public Task<BuildSession> ConfirmComponentAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken)
        {
            ConfirmCalls++;
            foreach (var component in _currentComponents)
            {
                if (!string.Equals(component.ComponentKey, componentKey, StringComparison.Ordinal)) continue;
                component.Status = "confirmed";
                component.DetectedQuantity = Math.Max(component.DetectedQuantity, component.ExpectedQuantity);
                component.Confidence = 1f;
            }
            return Task.FromResult(new BuildSession
            {
                PublicId = buildSessionPublicId,
                Status = "active",
                KdsItemPublicId = "kds-item-1",
                Components = SnapshotComponents()
            });
        }

        public Task<BuildSession> ResolveUnexpectedAsync(string buildSessionPublicId, string componentKey, CancellationToken cancellationToken)
        {
            ResolveCalls++;
            foreach (var component in _currentComponents)
                if (string.Equals(component.ComponentKey, componentKey, StringComparison.Ordinal)) component.Status = "ignored";

            return Task.FromResult(new BuildSession
            {
                PublicId = buildSessionPublicId,
                Status = "active",
                KdsItemPublicId = "kds-item-1",
                Components = SnapshotComponents()
            });
        }

        public Task<BuildSession> CorrectObservationAsync(
            string buildSessionPublicId,
            ObservationCorrection correction,
            CancellationToken cancellationToken)
        {
            CorrectionCalls++;
            LastCorrection = correction;
            return Task.FromResult(new BuildSession
            {
                PublicId = buildSessionPublicId,
                Status = "active",
                KdsItemPublicId = "kds-item-1",
                Components = SnapshotComponents()
            });
        }

        public Task<IReadOnlyList<ObservationEvidence>> GetEvidenceAsync(
            string buildSessionPublicId,
            int limit,
            CancellationToken cancellationToken)
        {
            EvidenceCalls++;
            IReadOnlyList<ObservationEvidence> evidence = new[]
            {
                new ObservationEvidence
                {
                    ObservationKey = "vision-build-1-track-7",
                    ComponentKey = "ingredient:42",
                    Action = "added",
                    Quantity = 1,
                    Confidence = 0.93f,
                    TrackingId = "vision-track-7",
                    LatestCorrection = new ObservationCorrection
                    {
                        CorrectionKey = "reject:vision-build-1-track-7",
                        ObservationKey = "vision-build-1-track-7",
                        Resolution = "reject",
                        Reason = "Wrong transfer"
                    }
                }
            };
            return Task.FromResult(evidence);
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

        private IReadOnlyList<BuildComponent> SnapshotComponents()
        {
            var snapshot = new List<BuildComponent>();
            foreach (var component in _currentComponents) snapshot.Add(CloneComponent(component));
            return snapshot;
        }

        private static BuildComponent CloneComponent(BuildComponent component)
        {
            return new BuildComponent
            {
                ComponentKey = component.ComponentKey,
                DisplayName = component.DisplayName,
                ExpectedQuantity = component.ExpectedQuantity,
                DetectedQuantity = component.DetectedQuantity,
                Unit = component.Unit,
                Status = component.Status,
                Confidence = component.Confidence
            };
        }
    }
}
