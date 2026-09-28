using System;
using Gelato.Ar.Core;

internal static class DeviceRuntimeSupervisorContract
{
    public static void Run()
    {
        HealthyRuntimeIsReady();
        MissingCameraFailsClosed();
        RepeatedFrameBecomesStaleAndFreshFrameRecovers();
        DetectorFailureFailsClosed();
        HeartbeatAndThermalHealthAreGoverned();
        RecoveryCooldownAndFailureBudgetAreBounded();
        IncidentLedgerIsDeduplicatedAndBounded();
    }

    private static void HealthyRuntimeIsReady()
    {
        var supervisor = new DeviceRuntimeSupervisor(Options());
        var health = supervisor.Evaluate(Healthy(100), TimeSpan.Zero);
        Assert(health.State == DeviceRuntimeOperationalState.Ready, "healthy device runtime must be ready");
        Assert(health.CanProcessAutomatedVision, "only ready runtime may process automated vision");
        Assert(health.Telemetry.DistinctFrames == 1, "fresh frame telemetry must be counted");
    }

    private static void MissingCameraFailsClosed()
    {
        var supervisor = new DeviceRuntimeSupervisor(Options());
        var signals = Healthy(1);
        signals.CameraAvailable = false;
        var health = supervisor.Evaluate(signals, TimeSpan.Zero);
        Assert(!health.CanProcessAutomatedVision, "missing camera must hold automated vision fail-closed");
        Assert(health.PrimaryCode == "camera_unavailable", "missing camera must expose an operator-readable code");
    }

    private static void RepeatedFrameBecomesStaleAndFreshFrameRecovers()
    {
        var supervisor = new DeviceRuntimeSupervisor(Options());
        supervisor.Evaluate(Healthy(10), TimeSpan.Zero);
        var stale = supervisor.Evaluate(Healthy(10), TimeSpan.FromMilliseconds(60));
        Assert(stale.State == DeviceRuntimeOperationalState.Degraded, "repeated stale frame must degrade runtime");
        Assert(stale.PrimaryCode == "camera_stale", "stale frame must expose camera_stale");
        Assert(stale.Telemetry.StaleFrameBlocks == 1, "stale frame block must be telemetered");

        var recovered = supervisor.Evaluate(Healthy(11), TimeSpan.FromMilliseconds(61));
        Assert(recovered.State == DeviceRuntimeOperationalState.Ready, "new camera frame must recover readiness without resetting build state");
    }

    private static void DetectorFailureFailsClosed()
    {
        var supervisor = new DeviceRuntimeSupervisor(Options());
        var signals = Healthy(20);
        signals.DetectorState = VisionDetectorRuntimeState.Failed;
        var health = supervisor.Evaluate(signals, TimeSpan.Zero);
        Assert(health.State == DeviceRuntimeOperationalState.Failed, "failed detector must fail aggregate runtime");
        Assert(!health.CanProcessAutomatedVision, "failed detector must block automated evidence");
    }

    private static void HeartbeatAndThermalHealthAreGoverned()
    {
        var supervisor = new DeviceRuntimeSupervisor(Options());
        var heartbeat = Healthy(30);
        heartbeat.HeartbeatHealthy = false;
        heartbeat.HeartbeatError = "cloud heartbeat failed";
        var degraded = supervisor.Evaluate(heartbeat, TimeSpan.Zero);
        Assert(degraded.PrimaryCode == "heartbeat_failed", "heartbeat failure must degrade aggregate health");

        var thermal = Healthy(31);
        thermal.ThermalState = "critical";
        var failed = supervisor.Evaluate(thermal, TimeSpan.FromMilliseconds(1));
        Assert(failed.State == DeviceRuntimeOperationalState.Failed, "critical thermal state must fail closed");
        Assert(failed.PrimaryCode == "thermal_critical", "critical thermal state must be diagnosable");
    }

    private static void RecoveryCooldownAndFailureBudgetAreBounded()
    {
        var options = Options();
        options.RecoveryCooldown = TimeSpan.FromMilliseconds(50);
        options.MaxConsecutiveRecoveryFailures = 2;
        var supervisor = new DeviceRuntimeSupervisor(options);
        var failedSignals = Healthy(40);
        failedSignals.DetectorState = VisionDetectorRuntimeState.Failed;
        supervisor.Evaluate(failedSignals, TimeSpan.Zero);

        Assert(supervisor.ShouldAttemptRecovery(TimeSpan.Zero), "failed runtime must allow first recovery attempt");
        supervisor.RecordRecoveryAttempt(TimeSpan.Zero, "restart detector");
        supervisor.RecordRecoveryResult(TimeSpan.FromMilliseconds(1), false, "restart_failed", "restart failed");
        Assert(!supervisor.ShouldAttemptRecovery(TimeSpan.FromMilliseconds(20)), "recovery attempts must obey cooldown");
        Assert(supervisor.ShouldAttemptRecovery(TimeSpan.FromMilliseconds(51)), "recovery may retry after cooldown");

        supervisor.RecordRecoveryAttempt(TimeSpan.FromMilliseconds(51), "restart detector");
        supervisor.RecordRecoveryResult(TimeSpan.FromMilliseconds(52), false, "restart_failed", "restart failed again");
        Assert(supervisor.State == DeviceRuntimeOperationalState.Failed, "recovery failure budget exhaustion must transition failed");
        Assert(!supervisor.ShouldAttemptRecovery(TimeSpan.FromMilliseconds(200)), "exhausted recovery budget must prevent restart loops");
    }

    private static void IncidentLedgerIsDeduplicatedAndBounded()
    {
        var options = Options();
        options.IncidentCapacity = 3;
        var supervisor = new DeviceRuntimeSupervisor(options);

        var missing = Healthy(50);
        missing.CameraAvailable = false;
        supervisor.Evaluate(missing, TimeSpan.Zero);
        supervisor.Evaluate(missing, TimeSpan.FromMilliseconds(1));
        var duplicateSnapshot = supervisor.Evaluate(missing, TimeSpan.FromMilliseconds(2));
        Assert(duplicateSnapshot.Incidents.Count == 1, "unchanged health reason must not spam duplicate incidents");

        supervisor.Evaluate(Healthy(51), TimeSpan.FromMilliseconds(3));
        var hot = Healthy(52);
        hot.ThermalState = "hot";
        supervisor.Evaluate(hot, TimeSpan.FromMilliseconds(4));
        var critical = Healthy(53);
        critical.ThermalState = "critical";
        var bounded = supervisor.Evaluate(critical, TimeSpan.FromMilliseconds(5));
        Assert(bounded.Incidents.Count == 3, "incident ledger must remain bounded");
        Assert(bounded.Incidents[0].Sequence < bounded.Incidents[2].Sequence, "incident sequence must preserve operator history ordering");
    }

    private static DeviceRuntimeSignals Healthy(long frameTimestamp) => new DeviceRuntimeSignals
    {
        PlatformInitialized = true,
        CameraAvailable = true,
        FrameTimestampNanoseconds = frameTimestamp,
        DetectorState = VisionDetectorRuntimeState.Ready,
        ModelRuntimeReady = true,
        HeartbeatHealthy = true,
        ThermalState = "normal",
        ResourceConstrained = false
    };

    private static DeviceRuntimeSupervisorOptions Options() => new DeviceRuntimeSupervisorOptions
    {
        CameraStaleAfter = TimeSpan.FromMilliseconds(50),
        HeartbeatStaleAfter = TimeSpan.FromMilliseconds(100),
        RecoveryCooldown = TimeSpan.FromMilliseconds(25),
        MaxConsecutiveRecoveryFailures = 3,
        IncidentCapacity = 8
    };

    private static void Assert(bool condition, string message)
    {
        if (!condition) throw new InvalidOperationException(message);
    }
}
