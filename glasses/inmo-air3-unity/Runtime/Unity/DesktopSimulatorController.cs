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
        private HandsFreeCommandRouter _commands;

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
                else if (Input.GetKeyDown(KeyCode.F1)) await ExecuteHandsFreeCommandAsync("refresh work");
                else if (Input.GetKeyDown(KeyCode.F2)) await ExecuteHandsFreeCommandAsync("start build");
                else if (Input.GetKeyDown(KeyCode.V)) await ExecuteHandsFreeCommandAsync("validate product");
                else if (Input.GetKeyDown(KeyCode.E)) await ExecuteHandsFreeCommandAsync("send to expo");
                else if (Input.GetKeyDown(KeyCode.R)) ResetWorkflow();
                else if (Input.GetKeyDown(KeyCode.U)) await SimulateUnexpectedAsync();
                else if (Input.GetKeyDown(KeyCode.C)) await ExecuteHandsFreeCommandAsync("confirm");
                else if (Input.GetKeyDown(KeyCode.X)) await ExecuteHandsFreeCommandAsync("resolve unexpected");
                else if (Input.GetKeyDown(KeyCode.Z)) await ExecuteHandsFreeCommandAsync("undo last");
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
                    SystemVersion = SystemInfo.operatingSystem,
                    VisionModelRuntimes = new[] { "onnx", "tflite", "unity_barracuda", "vendor" }
                });
                LogLine("Paired with Gelato.");
            });
        }

        private HandsFreeCommandRouter Commands
        {
            get
            {
                if (_commands != null) return _commands;
                if (bootstrap == null || bootstrap.Coordinator == null)
                    throw new InvalidOperationException("Gelato AR workflow is not initialized.");
                _commands = new HandsFreeCommandRouter(bootstrap.Coordinator);
                return _commands;
            }
        }

        public async Task ExecuteHandsFreeCommandAsync(string command)
        {
            await RunBusyAsync(async () =>
            {
                var result = await Commands.ExecuteAsync(command);
                LogLine((result.Succeeded ? "ACTION: " : "REVIEW: ") + command + " → " + result.Message);
            });
        }

        public Task RefreshWorkAsync()
        {
            return ExecuteHandsFreeCommandAsync("refresh work");
        }

        public Task StartBuildAsync()
        {
            return ExecuteHandsFreeCommandAsync("start build");
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

        public Task ConfirmFirstVerifyAsync()
        {
            return ExecuteHandsFreeCommandAsync("confirm");
        }

        public Task ResolveFirstUnexpectedAsync()
        {
            return ExecuteHandsFreeCommandAsync("resolve unexpected");
        }

        public Task RejectLastObservationAsync()
        {
            return ExecuteHandsFreeCommandAsync("undo last");
        }

        public Task EvaluateAsync()
        {
            return ExecuteHandsFreeCommandAsync("validate product");
        }

        public Task HandoffAsync()
        {
            return ExecuteHandsFreeCommandAsync("send to expo");
        }

        public void ResetWorkflow()
        {
            bootstrap.Coordinator.ResetForNextWork();
            _commands = null;
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
