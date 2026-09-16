(() => {
  'use strict';
  if (window.GelatoOnlineOrderAgentContext) return;

  const page = (location.pathname.split('/').pop() || '').toLowerCase();
  const module = page === 'pickup-fulfillment.php' ? 'pickup_fulfillment' : (page === 'order-recovery.php' ? 'order_recovery' : 'online_orders');
  const state = {selectedOrderPublicId: ''};
  const cleanId = value => /^[A-Za-z0-9._:-]{1,160}$/.test(String(value || '').trim()) ? String(value).trim() : '';

  function detectOrder() {
    const query = cleanId(new URLSearchParams(location.search).get('order'));
    if (query) return query;
    const selected = document.querySelector('[data-order].is-agent-selected,[data-order].is-selected,[data-order][aria-selected="true"]');
    return cleanId(selected?.dataset?.order || selected?.getAttribute('data-order') || state.selectedOrderPublicId);
  }

  function locationId() {
    const field = document.getElementById('locationFilter') || document.getElementById('recoveryLocation') || document.querySelector('select[name="location"]');
    const value = Number(field?.value || 0);
    return Number.isInteger(value) && value > 0 ? value : null;
  }

  function snapshot() {
    state.selectedOrderPublicId = detectOrder();
    return {
      module,
      route: page,
      pageTitle: 'Online Ordering + Pickup Fulfillment',
      selectedOrderPublicId: state.selectedOrderPublicId || null,
      locationId: locationId(),
    };
  }

  function transportSnapshot() {
    const current = snapshot();
    return {module: current.module, route: current.route, selectedOrderPublicId: current.selectedOrderPublicId, locationId: current.locationId};
  }

  function description(context = snapshot()) {
    return context.selectedOrderPublicId ? 'Online order · selected pickup' : 'Online Ordering + Pickup Fulfillment';
  }

  function placeholder(context = snapshot()) {
    return context.selectedOrderPublicId
      ? 'Ask Gelato about this pickup order, readiness, payment status, promise time, handoff, or recovery…'
      : 'Ask Gelato about online orders, pickup readiness, late promises, payment due, or recovery…';
  }

  function publish() { return window.GelatoAgentPageContext?.publish?.() || snapshot(); }
  function register() { return !!window.GelatoAgentPageContext?.register?.(provider); }
  const provider = {module, snapshot, transportSnapshot, description, placeholder};
  window.GelatoOnlineOrderAgentContext = {state, provider, snapshot, transportSnapshot, description, placeholder, publish, sync: publish, register};

  document.addEventListener('click', event => {
    const card = event.target.closest?.('[data-order]');
    if (!card) return;
    document.querySelectorAll('[data-order].is-agent-selected').forEach(node => node.classList.remove('is-agent-selected'));
    card.classList.add('is-agent-selected');
    state.selectedOrderPublicId = cleanId(card.dataset.order || '');
    setTimeout(publish, 0);
  });
  document.addEventListener('change', event => {
    if (['locationFilter', 'recoveryLocation'].includes(event.target?.id) || event.target?.matches?.('select[name="location"]')) publish();
  });
  window.addEventListener('popstate', publish);
  window.addEventListener('gelato-agent-ready', () => { register(); publish(); });
  document.addEventListener('DOMContentLoaded', () => { register(); publish(); }, {once:true});
  register();
  publish();
})();
