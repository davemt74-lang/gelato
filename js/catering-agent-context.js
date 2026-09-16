(() => {
  'use strict';
  if (window.GelatoCateringAgentContext) return;

  const nativeFetch = window.fetch.bind(window);
  const state = {selectedOperationPublicId: '', operationLabel: '', activeTab: 'overview'};
  const clean = (value, max = 160) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);

  function snapshot() {
    return {
      module: 'catering',
      route: location.pathname.split('/').pop() || 'catering-operations.php',
      pageTitle: 'Catering Operations',
      selectedOperationPublicId: clean(state.selectedOperationPublicId, 100),
      operationLabel: clean(state.operationLabel, 160),
      activeTab: clean(state.activeTab, 40),
    };
  }

  function transportSnapshot() {
    const context = snapshot();
    return {
      module: context.module,
      route: context.route,
      selectedOperationPublicId: context.selectedOperationPublicId,
      activeTab: context.activeTab,
    };
  }

  function description(context = snapshot()) {
    return context.operationLabel ? `Catering · ${context.operationLabel}` : 'Catering Operations';
  }

  function placeholder(context = snapshot()) {
    return context.selectedOperationPublicId
      ? 'Ask Gelato about this event, readiness, ingredients, tasks, or staffing…'
      : 'Ask Gelato about catering events, readiness, or execution…';
  }

  function publish() {
    return window.GelatoAgentPageContext?.publish?.() || snapshot();
  }

  function register() {
    return !!window.GelatoAgentPageContext?.register?.(provider);
  }

  function applyOperation(operation) {
    if (!operation || typeof operation !== 'object') return;
    state.selectedOperationPublicId = clean(operation.public_id || operation.publicId, 100);
    state.operationLabel = clean(operation.title || operation.company_name || operation.contact_name, 160);
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

  window.fetch = async function gelatoCateringContextFetch(input, init) {
    const action = requestAction(input, init);
    const response = await nativeFetch(input, init);
    if (targetFile(input) === 'catering-operations.php') {
      try {
        const data = await response.clone().json();
        if (response.ok && data?.ok && action === 'detail' && data.operation) applyOperation(data.operation);
      } catch {}
    }
    return response;
  };

  document.addEventListener('click', (event) => {
    const tab = event.target.closest?.('[data-tab]');
    if (tab?.dataset?.tab) {
      state.activeTab = clean(tab.dataset.tab, 40) || 'overview';
      publish();
    }
  });

  const provider = {module: 'catering', snapshot, transportSnapshot, description, placeholder};
  window.addEventListener('gelato-agent-ready', register);
  document.addEventListener('DOMContentLoaded', () => { register(); publish(); }, {once: true});
  window.GelatoCateringAgentContext = {snapshot, transportSnapshot, description, placeholder, publish, register};
  register();
})();
