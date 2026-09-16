(() => {
  'use strict';
  if (window.GelatoWholesaleAgentContext) return;

  const nativeFetch = window.fetch.bind(window);
  const state = {selectedWholesaleOrderPublicId: '', selectedWholesaleBatchPublicId: '', orderLabel: ''};
  const clean = (value, max = 160) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);

  function snapshot() {
    return {
      module: 'wholesale',
      route: location.pathname.split('/').pop() || 'wholesale-fulfillment.php',
      pageTitle: 'Wholesale',
      selectedWholesaleOrderPublicId: clean(state.selectedWholesaleOrderPublicId, 100),
      selectedWholesaleBatchPublicId: clean(state.selectedWholesaleBatchPublicId, 100),
      orderLabel: clean(state.orderLabel, 160),
    };
  }

  function transportSnapshot() {
    const context = snapshot();
    return {
      module: context.module,
      route: context.route,
      selectedWholesaleOrderPublicId: context.selectedWholesaleOrderPublicId,
      selectedWholesaleBatchPublicId: context.selectedWholesaleBatchPublicId,
    };
  }

  function description(context = snapshot()) {
    return context.orderLabel ? `Wholesale · ${context.orderLabel}` : 'Wholesale Operations';
  }

  function placeholder(context = snapshot()) {
    return context.selectedWholesaleOrderPublicId
      ? 'Ask Gelato about this Wholesale order, shortages, allocation, or fulfillment batches…'
      : 'Ask Gelato about Wholesale orders, fulfillment, shortages, or delivery…';
  }

  function publish() {
    return window.GelatoAgentPageContext?.publish?.() || snapshot();
  }

  function register() {
    return !!window.GelatoAgentPageContext?.register?.(provider);
  }

  function applyDetail(detail) {
    if (!detail || typeof detail !== 'object' || !detail.order) return;
    const order = detail.order;
    state.selectedWholesaleOrderPublicId = clean(order.id || order.public_id, 100);
    state.orderLabel = clean([order.number, order.businessName].filter(Boolean).join(' · '), 160);
    const active = Array.isArray(detail.batches)
      ? detail.batches.filter((row) => !['delivered', 'cancelled'].includes(String(row?.status || '')))
      : [];
    state.selectedWholesaleBatchPublicId = active.length === 1 ? clean(active[0]?.id, 100) : '';
    publish();
  }

  function targetFile(input) {
    let value = '';
    if (typeof input === 'string') value = input;
    else if (input instanceof URL) value = input.toString();
    else if (typeof Request !== 'undefined' && input instanceof Request) value = input.url;
    try { return new URL(value, location.href).pathname.split('/').pop() || ''; }
    catch { return value.split('?')[0].split('/').pop() || ''; }
  }

  function requestAction(input, init) {
    if (String(init?.method || 'GET').toUpperCase() === 'POST' && typeof init?.body === 'string') {
      try { return String(JSON.parse(init.body)?.action || ''); } catch { return ''; }
    }
    try {
      const raw = typeof input === 'string' ? input : (input instanceof URL ? input.toString() : input?.url || '');
      return new URL(raw, location.href).searchParams.get('action') || '';
    } catch { return ''; }
  }

  window.fetch = async function gelatoWholesaleContextFetch(input, init) {
    const action = requestAction(input, init);
    const response = await nativeFetch(input, init);
    if (targetFile(input) === 'wholesale-fulfillment.php') {
      try {
        const data = await response.clone().json();
        if (response.ok && data?.ok && action === 'detail' && data.detail) applyDetail(data.detail);
        if (response.ok && data?.ok && data.detail && String(action).startsWith('batch.')) applyDetail(data.detail);
      } catch {}
    }
    return response;
  };

  document.addEventListener('click', (event) => {
    const target = event.target.closest?.('[data-batch-id],[data-batch]');
    const batchId = target?.dataset?.batchId || target?.dataset?.batch || '';
    if (batchId) {
      state.selectedWholesaleBatchPublicId = clean(batchId, 100);
      publish();
    }
  });

  const provider = {module: 'wholesale', snapshot, transportSnapshot, description, placeholder};
  window.addEventListener('gelato-agent-ready', register);
  document.addEventListener('DOMContentLoaded', () => { register(); publish(); }, {once: true});
  window.GelatoWholesaleAgentContext = {snapshot, transportSnapshot, description, placeholder, publish, register};
  register();
})();
