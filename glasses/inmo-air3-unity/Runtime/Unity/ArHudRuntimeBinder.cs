using Gelato.Ar.Core;
using UnityEngine;

namespace Gelato.Ar.Unity
{
    public sealed class ArHudRuntimeBinder : MonoBehaviour
    {
        [SerializeField] private GelatoArBootstrap bootstrap;
        [SerializeField] private ArHudController hud;
        [SerializeField] private IngredientOutlineOverlay ingredientOutline;
        [SerializeField] private float refreshIntervalSeconds = 0.1f;

        private float _nextRefreshAt;

        private void Update()
        {
            if (bootstrap == null || hud == null || bootstrap.Coordinator == null) return;
            if (Time.unscaledTime < _nextRefreshAt) return;

            _nextRefreshAt = Time.unscaledTime + Mathf.Max(0.05f, refreshIntervalSeconds);
            var coordinator = bootstrap.Coordinator;
            var model = HudViewModelFactory.Create(
                coordinator.CurrentWork,
                coordinator.BuildSession,
                coordinator.Validation,
                coordinator.Handoff
            );
            hud.Render(model);
        }

        public void ShowIngredientObservation(IngredientObservation observation)
        {
            if (ingredientOutline != null) ingredientOutline.Show(observation);
        }

        public void ClearIngredientOutline()
        {
            if (ingredientOutline != null) ingredientOutline.Hide();
        }
    }
}
