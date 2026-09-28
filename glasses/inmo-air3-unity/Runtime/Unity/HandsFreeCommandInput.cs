using System;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    /// <summary>
    /// Vendor-neutral command ingress for buttons, gestures, speech recognizers, or
    /// accessibility input. INMO-specific code should call these methods instead of
    /// reaching into ArWorkflowCoordinator directly.
    /// </summary>
    public sealed class HandsFreeCommandInput : MonoBehaviour
    {
        [SerializeField] private GelatoArBootstrap bootstrap;

        private HandsFreeCommandRouter _router;

        public string LastCommand { get; private set; } = string.Empty;
        public string LastMessage { get; private set; } = string.Empty;
        public bool LastSucceeded { get; private set; }

        private HandsFreeCommandRouter Router
        {
            get
            {
                if (_router != null) return _router;
                if (bootstrap == null || bootstrap.Coordinator == null)
                    throw new InvalidOperationException("Gelato AR workflow is not initialized.");
                _router = new HandsFreeCommandRouter(bootstrap.Coordinator);
                return _router;
            }
        }

        public async void SubmitCommand(string command)
        {
            try
            {
                await SubmitCommandAsync(command);
            }
            catch (Exception ex)
            {
                LastSucceeded = false;
                LastMessage = ex.Message;
                Debug.LogError("[Gelato AR Hands-Free] " + ex);
            }
        }

        public async Task<HandsFreeCommandResult> SubmitCommandAsync(string command)
        {
            LastCommand = command ?? string.Empty;
            var result = await Router.ExecuteAsync(LastCommand);
            LastSucceeded = result.Succeeded;
            LastMessage = result.Message;
            return result;
        }

        // Canonical callbacks for SDK button/gesture bindings.
        public void RefreshWork() => SubmitCommand("refresh work");
        public void StartBuild() => SubmitCommand("start build");
        public void ValidateProduct() => SubmitCommand("validate product");
        public void Confirm() => SubmitCommand("confirm");
        public void RejectLast() => SubmitCommand("undo last");
        public void ResolveUnexpected() => SubmitCommand("resolve unexpected");
        public void SendToExpo() => SubmitCommand("send to expo");

        public void ResetRouter()
        {
            _router = null;
            LastCommand = string.Empty;
            LastMessage = string.Empty;
            LastSucceeded = false;
        }
    }
}
