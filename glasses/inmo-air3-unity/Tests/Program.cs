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
