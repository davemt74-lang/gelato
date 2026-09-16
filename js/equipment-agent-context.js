(() => {
  'use strict';
  if (window.GelatoEquipmentAgentContext) return;

  const state = { selectedAssetPublicId: '', activeTab: '' };
  const page = (location.pathname.split('/').pop() || '').toLowerCase();
  const cleanId = (value) => /^[A-Za-z0-9._:-]{1,160}$/.test(String(value || '').trim()) ? String(value).trim() : '';

  function detectAsset() {
    const queryId = cleanId(new URLSearchParams(location.search).get('id'));
    if (queryId) return queryId;
    const field = document.getElementById('assetId');
    return cleanId(field?.value || field?.dataset?.assetId || '');
  }

  function detectTab() {
    const active = document.querySelector('[data-tab].active,[data-equipment-tab].active,[aria-selected="true"][data-tab]');
    return cleanId(active?.dataset?.tab || active?.dataset?.equipmentTab || '');
  }

  function sync() {
    const asset = detectAsset();
    const tab = detectTab();
    if (asset === state.selectedAssetPublicId && tab === state.activeTab) return;
    state.selectedAssetPublicId = asset;
    state.activeTab = tab;
    window.GelatoAgentPageContext?.refresh?.();
  }

  const provider = {
    snapshot() {
      sync();
      return {
        module: 'equipment',
        route: page,
        selectedAssetPublicId: state.selectedAssetPublicId || null,
        activeTab: state.activeTab || null,
        pageTitle: document.querySelector('h1,h2')?.textContent?.trim() || document.title || 'Equipment',
      };
    },
    transportSnapshot() {
      const snapshot = this.snapshot();
      return {
        module: 'equipment',
        route: snapshot.route,
        selectedAssetPublicId: snapshot.selectedAssetPublicId,
        activeTab: snapshot.activeTab,
      };
    },
    description() {
      const snapshot = this.snapshot();
      return snapshot.selectedAssetPublicId ? `Equipment · selected asset ${snapshot.selectedAssetPublicId}` : 'Equipment + Maintenance';
    },
  };

  window.GelatoEquipmentAgentContext = { state, provider, sync };
  function register() {
    if (!window.GelatoAgentPageContext?.registerProvider) return false;
    window.GelatoAgentPageContext.registerProvider('equipment', provider, { priority: 65 });
    window.GelatoAgentPageContext.refresh?.();
    return true;
  }

  if (!register()) {
    window.addEventListener('gelato-agent-page-context-ready', register, { once: true });
  }
  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-tab],[data-equipment-tab],[data-asset-id],a[href*="equipment-detail.php?id="]')) setTimeout(sync, 0);
  });
  const observer = new MutationObserver(sync);
  if (document.documentElement) observer.observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class','value','aria-selected','data-asset-id'] });
  sync();
})();
