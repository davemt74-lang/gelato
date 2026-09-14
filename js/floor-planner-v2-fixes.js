(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');

  function status(message, bad = false) {
    const el = document.getElementById('status');
    if (!el) return;
    el.textContent = message;
    el.style.color = bad ? '#ffb4ad' : '#c5cad0';
  }

  function injectCounterFinish() {
    if (document.getElementById('fpCounterSteelFix')) return;
    const style = document.createElement('style');
    style.id = 'fpCounterSteelFix';
    style.textContent = `
      .fp-counter-shape polygon{
        fill:rgba(204,210,214,.82)!important;
        stroke:rgba(204,210,214,.82)!important;
        stroke-width:10!important;
        filter:drop-shadow(0 -1px 0 rgba(255,255,255,.72)) drop-shadow(0 2px 2px rgba(64,70,76,.16)) brightness(1.04)!important;
      }
      .fp-counter-shape{
        opacity:.96;
      }
    `;
    document.head.appendChild(style);
  }

  async function createEquipmentFromPlanner(button) {
    if (!cfg.canEquipmentEdit) {
      status('Equipment edit permission is required.', true);
      return;
    }

    const name = document.getElementById('qaName')?.value.trim() || '';
    if (!name) {
      status('Equipment name is required.', true);
      document.getElementById('qaName')?.focus();
      return;
    }

    const asset = {
      name,
      assetType: document.getElementById('qaType')?.value || 'other',
      purpose: document.getElementById('qaPurpose')?.value.trim() || '',
      brand: document.getElementById('qaBrand')?.value.trim() || '',
      model: document.getElementById('qaModel')?.value.trim() || '',
      widthInches: document.getElementById('qaWidth')?.value || '',
      depthInches: document.getElementById('qaDepth')?.value || '',
    };

    button.disabled = true;
    status(`Creating ${name}…`);
    try {
      const response = await fetch('api/floor-planner-equipment-create.php', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({asset}),
      });
      let data = null;
      try {
        data = await response.json();
      } catch {
        throw new Error(`Invalid server response (${response.status}).`);
      }
      if (!response.ok || data?.ok === false) {
        throw new Error(data?.message || `Equipment creation failed (${response.status}).`);
      }

      document.getElementById('assetModal')?.classList.remove('open');
      status(data.message || `${name} created.`);
      setTimeout(() => location.reload(), 250);
    } catch (error) {
      status(error instanceof Error ? error.message : 'Equipment could not be created.', true);
    } finally {
      button.disabled = false;
    }
  }

  function installEquipmentCreateFix() {
    document.addEventListener('click', event => {
      const button = event.target instanceof Element ? event.target.closest('#createAssetBtn') : null;
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      createEquipmentFromPlanner(button);
    }, true);
  }

  function init() {
    injectCounterFinish();
    installEquipmentCreateFix();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once:true});
  } else {
    init();
  }
})();
