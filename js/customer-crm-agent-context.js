(() => {
  'use strict';
  if (window.GelatoCrmAgentContext) return;

  const nativeFetch = window.fetch.bind(window);
  const state = {customerPublicId: '', customerLabel: ''};
  const clean = (value, max = 140) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);

  function snapshot() {
    return {
      module: 'crm',
      route: 'customer-crm.php',
      pageTitle: 'Customer CRM',
      customerPublicId: clean(state.customerPublicId, 100),
      customerLabel: clean(state.customerLabel, 140),
    };
  }

  function transportSnapshot() {
    const context = snapshot();
    return {
      module: context.module,
      route: context.route,
      customerPublicId: context.customerPublicId,
    };
  }

  function description(context = snapshot()) {
    return context.customerLabel ? `Customer CRM · ${context.customerLabel}` : 'Customer CRM';
  }

  function placeholder(context = snapshot()) {
    return context.customerPublicId
      ? 'Ask Gelato about this customer, visits, favorites, tags, or internal notes…'
      : 'Ask Gelato to find a customer or summarize CRM activity…';
  }

  function publish() {
    return window.GelatoAgentPageContext?.publish?.() || snapshot();
  }

  function register() {
    return !!window.GelatoAgentPageContext?.register?.(provider);
  }

  function applyCustomer(customer) {
    if (!customer || typeof customer !== 'object') return;
    state.customerPublicId = clean(customer.publicId || customer.public_id, 100);
    state.customerLabel = clean(customer.displayName || customer.display_name, 140);
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

  window.fetch = async function gelatoCrmContextFetch(input, init) {
    const action = requestAction(input, init);
    const response = await nativeFetch(input, init);
    if (targetFile(input) === 'customer-crm.php') {
      try {
        const data = await response.clone().json();
        if (response.ok && data?.ok) {
          if (action === 'customer' && data.customer) applyCustomer(data.customer);
          if (action === 'customer.save' && data.customer) applyCustomer(data.customer);
          if (action === 'customer.archive') {
            state.customerPublicId = '';
            state.customerLabel = '';
            publish();
          }
        }
      } catch {}
    }
    return response;
  };

  const provider = {module: 'crm', snapshot, transportSnapshot, description, placeholder};
  window.addEventListener('gelato-agent-ready', register);
  document.addEventListener('DOMContentLoaded', () => { register(); publish(); }, {once: true});
  window.GelatoCrmAgentContext = {snapshot, transportSnapshot, description, placeholder, publish, register};
  register();
})();
