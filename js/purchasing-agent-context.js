(() => {
  'use strict';
  if (window.GelatoPurchasingAgentContext) return;

  const state = {
    activeTab: 'suggestions',
    selectedPurchaseOrderPublicId: '',
  };

  const clean = (value, max = 120) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);

  function snapshot() {
    const active = document.querySelector('.tab.active[data-tab]')?.dataset.tab;
    if (active) state.activeTab = clean(active, 40);
    return {
      module: 'purchasing',
      route: 'purchasing.php',
      pageTitle: 'Purchasing + Receiving',
      activeTab: state.activeTab,
      selectedPurchaseOrderPublicId: clean(state.selectedPurchaseOrderPublicId, 100),
    };
  }

  function transportSnapshot() {
    const context = snapshot();
    return {
      module: context.module,
      route: context.route,
      activeTab: context.activeTab,
      selectedPurchaseOrderPublicId: context.selectedPurchaseOrderPublicId,
    };
  }

  function description(context = snapshot()) {
    const parts = ['Purchasing'];
    if (context.activeTab) parts.push(context.activeTab.replaceAll('_', ' '));
    if (context.selectedPurchaseOrderPublicId) parts.push('Selected PO');
    return parts.join(' · ');
  }

  function placeholder(context = snapshot()) {
    if (context.selectedPurchaseOrderPublicId) {
      return 'Ask Gelato about this PO, vendor, receiving, prices, submission, or cancellation…';
    }
    return 'Ask Gelato what to order, compare vendors, review inventory pressure, or draft a PO…';
  }

  function publish() {
    return window.GelatoAgentPageContext?.publish?.() || snapshot();
  }

  document.addEventListener('click', (event) => {
    const tab = event.target.closest('.tab[data-tab]');
    if (tab?.dataset.tab) state.activeTab = clean(tab.dataset.tab, 40);

    const row = event.target.closest('[data-po]');
    if (row?.dataset.po) state.selectedPurchaseOrderPublicId = clean(row.dataset.po, 100);

    const close = event.target.closest('[data-close],#drawerClose,.drawer-close');
    if (close) state.selectedPurchaseOrderPublicId = '';
    queueMicrotask(publish);
  }, true);

  window.addEventListener('gelato-agent-response', (event) => {
    const result = event.detail?.result;
    if (result?.skill !== 'purchasing.action_confirmed') return;
    if (result?.data?.purchaseOrderId) state.selectedPurchaseOrderPublicId = clean(result.data.purchaseOrderId, 100);
    setTimeout(() => window.location.reload(), 120);
  });

  const provider = {module: 'purchasing', snapshot, transportSnapshot, description, placeholder};
  function boot() {
    window.GelatoAgentPageContext?.register?.(provider);
    publish();
  }

  window.GelatoPurchasingAgentContext = {snapshot, transportSnapshot, description, placeholder, publish};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once: true});
  else boot();
})();