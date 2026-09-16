(() => {
  'use strict';
  if (window.GelatoEquipmentAgentContext) return;

  const state = {selectedAssetPublicId: '', assetLabel: '', activeTab: ''};
  const page = (location.pathname.split('/').pop() || '').toLowerCase();
  const clean = (value, max = 160) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
  const cleanId = (value) => /^[A-Za-z0-9._:-]{1,160}$/.test(String(value || '').trim()) ? String(value).trim() : '';

  function detectAsset() {
    const queryId = cleanId(new URLSearchParams(location.search).get('id'));
    if (queryId) return queryId;
    const field = document.getElementById('assetId');
    return cleanId(field?.value || field?.dataset?.assetId || '');
  }

  function detectLabel() {
    const title = document.getElementById('editorTitle')?.textContent?.trim();
    if (title && !/^select equipment|new equipment$/i.test(title)) return clean(title, 160);
    const heading = document.querySelector('.hero h1')?.textContent?.trim();
    return clean(heading || '', 160);
  }

  function detectTab() {
    const active = document.querySelector('[data-tab].active,[data-equipment-tab].active,[aria-selected="true"][data-tab]');
    return clean(active?.dataset?.tab || active?.dataset?.equipmentTab || '', 40);
  }

  function snapshot() {
    state.selectedAssetPublicId = detectAsset();
    state.assetLabel = detectLabel();
    state.activeTab = detectTab();
    return {
      module: 'equipment',
      route: page || 'equipment.php',
      pageTitle: 'Equipment + Maintenance',
      selectedAssetPublicId: state.selectedAssetPublicId || null,
      assetLabel: state.assetLabel || null,
      activeTab: state.activeTab || null,
    };
  }

  function transportSnapshot() {
    const current = snapshot();
    return {
      module: current.module,
      route: current.route,
      selectedAssetPublicId: current.selectedAssetPublicId,
      activeTab: current.activeTab,
    };
  }

  function description(context = snapshot()) {
    return context.assetLabel ? `Equipment · ${context.assetLabel}` : 'Equipment + Maintenance';
  }

  function placeholder(context = snapshot()) {
    return context.selectedAssetPublicId
      ? 'Ask Gelato about this equipment, service history, maintenance, contacts, or status…'
      : 'Ask Gelato about equipment, maintenance due, outages, service history, or repair contacts…';
  }

  function publish() {
    return window.GelatoAgentPageContext?.publish?.() || snapshot();
  }

  function register() {
    return !!window.GelatoAgentPageContext?.register?.(provider);
  }

  function removeLegacyEquipmentBrain() {
    if (page !== 'equipment.php') return;
    const legacy = document.querySelector('aside.right .card.brain');
    if (legacy?.querySelector('#brainAsk,#brainInput,#brainOutput')) legacy.remove();
  }

  const provider = {module: 'equipment', snapshot, transportSnapshot, description, placeholder};
  window.GelatoEquipmentAgentContext = {state, provider, snapshot, transportSnapshot, description, placeholder, publish, register};

  window.addEventListener('gelato-agent-ready', () => { register(); publish(); });
  document.addEventListener('DOMContentLoaded', () => { removeLegacyEquipmentBrain(); register(); publish(); }, {once: true});
  document.addEventListener('click', (event) => {
    if (event.target.closest?.('[data-tab],[data-equipment-tab],.asset,[data-asset-id],a[href*="equipment-detail.php?id="]')) setTimeout(publish, 0);
  });
  document.addEventListener('change', (event) => {
    if (event.target?.id === 'assetId') setTimeout(publish, 0);
  });

  removeLegacyEquipmentBrain();
  register();
  publish();
})();
