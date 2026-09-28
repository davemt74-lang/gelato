using System;
using System.Collections.Generic;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class DesktopSimulatorController : MonoBehaviour
    {
        [SerializeField] private GelatoArBootstrap bootstrap;
        [SerializeField] private ArHudRuntimeBinder hudBinder;
        [SerializeField] private string pairingCode = string.Empty;
        [SerializeField] private string unexpectedIngredientName = "Swiss Cheese";
        [SerializeField] private bool enableKeyboardControls = true;

        private readonly List<string> _log = new List<string>();
        private int _observationSequence;
        private bool _busy;

        public IReadOnlyList<string> Log => _log;
        public bool Busy => _busy;

        private void Start()
        {
            LogLine("Desktop AIR3 simulator ready.");
        }

        private async void Update()
        {
            if (!enableKeyboardControls || _busy || bootstrap == null || bootstrap.Coordinator == null) return;

            try
            {
                if (Input.GetKeyDown(KeyCode.P)) await PairAsync();
                else if (Input.GetKeyDown(KeyCode.F1)) await RefreshWorkAsync();
                else if (Input.GetKeyDown(KeyCode.F2)) await StartBuildAsync();
                else if (Input.GetKeyDown(KeyCode.V)) await EvaluateAsync();
                else if (Input.GetKeyDown(KeyCode.E)) await HandoffAsync();
                else if (Input.GetKeyDown(KeyCode.R)) ResetWorkflow();
                else if (Input.GetKeyDown(KeyCode.U)) await SimulateUnexpectedAsync();
                else if (Input.GetKeyDown(KeyCode.C)) await ConfirmFirstVerifyAsync();
                else if (Input.GetKeyDown(KeyCode.X)) await ResolveFirstUnexpectedAsync();
                else if (Input.GetKeyDown(KeyCode.Z)) await RejectLastObservationAsync();
                else
                {
                    for (var i = 0; i < 9; i++)
                    {
                        var key = (KeyCode)((int)KeyCode.Alpha1 + i);
                        if (!Input.GetKeyDown(key)) continue;
                        await SimulateComponentAsync(i, Input.GetKey(KeyCode.LeftShift) || Input.GetKey(KeyCode.RightShift));
                        break;
                    }
                }
            }
            catch (Exception ex)
            {
                LogLine("ERROR: " + ex.Message);
            }
        }

        public async Task PairAsync()
        {
            if (string.IsNullOrWhiteSpace(pairingCode)) throw new InvalidOperationException("Enter a Gelato pairing code in the simulator inspector.");
            await RunBusyAsync(async () =>
            {
                await bootstrap.Coordinator.PairAsync(pairingCode.Trim(), new DeviceDescriptor
                {
                    HardwareIdentifier = "DESKTOP-SIM-" + SystemInfo.deviceUniqueIdentifier,
                    DisplayName = "Desktop AIR3 Simulator",
                    Platform = "desktop_simulator",
                    SdkVersion = "simulator",
                    AppVersion = Application.version,
                    SystemVersion = SystemInfo.operatingSystem
                });
                LogLine("Paired with Gelato.");
            });
        }

        public async Task RefreshWorkAsync()
        {
            await RunBusyAsync(async () =>
            {
                var work = await bootstrap.Coordinator.RefreshWorkAsync();
                LogLine(work.FocusItem == null
                    ? "No focused station work."
                    : "Focused: " + work.FocusItem.Name + " [" + work.FocusItem.Status + "]");
            });
        }

        public async Task StartBuildAsync()
        {
            await RunBusyAsync(async () =>
            {
                var build = await bootstrap.Coordinator.StartFocusBuildAsync();
                LogLine("Build started: " + build.PublicId + " (" + build.Components.Count + " components)");
            });
        }

        public async Task SimulateComponentAsync(int componentIndex, bool lowConfidence)
        {
            var build = bootstrap.Coordinator.BuildSession;
            if (build == null || !string.Equals(build.Status, "active", StringComparison.Ordinal))
                throw new InvalidOperationException("Start a build session before simulating ingredients.");
            if (componentIndex < 0 || componentIndex >= build.Components.Count)
                throw new InvalidOperationException("No build component is assigned to that simulator key.");

            _observationSequence++;
            var component = build.Components[componentIndex];
            var observation = SimulatorObservationFactory.Create(component, _observationSequence, lowConfidence);

            if (hudBinder != null) hudBinder.ShowIngredientObservation(observation);

            await RunBusyAsync(async () =>
            {
                var validation = await bootstrap.Coordinator.SubmitObservationAsync(observation);
                LogLine(
                    "Detected " + component.DisplayName
                    + " qty " + observation.Quantity
                    + " confidence " + observation.Confidence.ToString("0.00")
                    + " → " + validation.Status
                );
            });
        }

        public async Task SimulateUnexpectedAsync()
        {
            var build = bootstrap.Coordinator.BuildSession;
            if (build == null || !string.Equals(build.Status, "active", StringComparison.Ordinal))
                throw new InvalidOperationException("Start a build session before simulating ingredients.");

            _observationSequence++;
            var observation = SimulatorObservationFactory.CreateUnexpected(unexpectedIngredientName, _observationSequence);
            if (hudBinder != null) hudBinder.ShowIngredientObservation(observation);

            await RunBusyAsync(async () =>
            {
                var validation = await bootstrap.Coordinator.SubmitObservationAsync(observation);
                LogLine("Unexpected: " + observation.DisplayName + " → " + validation.Status);
            });
        }

        public async Task ConfirmFirstVerifyAsync()
        {
            var build = bootstrap.Coordinator.BuildSession;
            if (build == null) throw new InvalidOperationException("There is no active build session.");

            BuildComponent target = null;
            foreach (var component in build.Components)
            {
                if (string.Equals(component.Status, "verify", StringComparison.Ordinal))
                {
                    target = component;
                    break;
                }
            }
            if (target == null) throw new InvalidOperationException("No Verify component is waiting for manual confirmation.");

            await RunBusyAsync(async () =>
            {
                var validation = await bootstrap.Coordinator.ConfirmComponentAsync(target.ComponentKey);
                LogLine("Confirmed " + target.DisplayName + " → " + validation.Status);
            });
        }

        public async Task ResolveFirstUnexpectedAsync()
        {
            var build = bootstrap.Coordinator.BuildSession;
            if (build == null) throw new InvalidOperationException("There is no active build session.");

            BuildComponent target = null;
            foreach (var component in build.Components)
            {
                if (string.Equals(component.Status, "unexpected", StringComparison.Ordinal))
                {
                    target = component;
                    break;
                }
            }
            if (target == null) throw new InvalidOperationException("No unexpected component is waiting for resolution.");

            await RunBusyAsync(async () =>
            {
                var validation = await bootstrap.Coordinator.ResolveUnexpectedAsync(target.ComponentKey);
                LogLine("Resolved unexpected " + target.DisplayName + " → " + validation.Status);
            });
        }

        public async Task RejectLastObservationAsync()
        {
            await RunBusyAsync(async () =>
            {
                var key = bootstrap.Coordinator.LastSubmittedObservationKey;
                if (string.IsNullOrWhiteSpace(key))
                    throw new InvalidOperationException("No submitted observation is available to reject.");

                var validation = await bootstrap.Coordinator.RejectLastObservationAsync("Desktop simulator correction");
                LogLine("Rejected observation " + key + " → " + validation.Status);
            });
        }

        public async Task EvaluateAsync()
        {
            await RunBusyAsync(async () =>
            {
                var validation = await bootstrap.Coordinator.EvaluateAsync();
                LogLine("Validation: " + validation.Status + (validation.Next.Available ? " | NEXT available" : string.Empty));
            });
        }

        public async Task HandoffAsync()
        {
            await RunBusyAsync(async () =>
            {
                var handoff = await bootstrap.Coordinator.HandoffToExpoAsync();
                LogLine("Handoff: " + handoff.Label + " | KDS " + handoff.KdsStatus);
            });
        }

        public void ResetWorkflow()
        {
            bootstrap.Coordinator.ResetForNextWork();
            if (hudBinder != null) hudBinder.ClearIngredientOutline();
            LogLine("Workflow reset.");
        }

        private async Task RunBusyAsync(Func<Task> action)
        {
            if (_busy) return;
            _busy = true;
            try { await action(); }
            finally { _busy = false; }
        }

        private void LogLine(string message)
        {
            var line = DateTime.Now.ToString("HH:mm:ss") + "  " + message;
            _log.Add(line);
            if (_log.Count > 14) _log.RemoveAt(0);
            Debug.Log("[Gelato AIR3 Simulator] " + message);
        }
    }
}
