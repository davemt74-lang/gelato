using System;
using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class GelatoArBootstrap : MonoBehaviour
    {
        [SerializeField] private MonoBehaviour glassesPlatform;
        [SerializeField] private MonoBehaviour gelatoGateway;
        [SerializeField] private MonoBehaviour deviceTokenStore;

        public ArWorkflowCoordinator Coordinator { get; private set; }

        private async void Start()
        {
            try
            {
                var platform = glassesPlatform as IGlassesPlatform;
                var gateway = gelatoGateway as IGelatoGateway;
                var tokenStore = deviceTokenStore as IDeviceTokenStore;

                if (platform == null) throw new InvalidOperationException("Assigned glassesPlatform must implement IGlassesPlatform.");
                if (gateway == null) throw new InvalidOperationException("Assigned gelatoGateway must implement IGelatoGateway.");
                if (tokenStore == null) throw new InvalidOperationException("Assigned deviceTokenStore must implement IDeviceTokenStore.");

                Coordinator = new ArWorkflowCoordinator(platform, gateway, tokenStore);
                await Coordinator.InitializeAsync();
            }
            catch (Exception ex)
            {
                Debug.LogError("Gelato AR bootstrap failed: " + ex);
            }
        }
    }
}
