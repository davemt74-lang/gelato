(() => {
  'use strict';
  if (window.GelatoAgentPageContext?.register) return;

  const nativeFetch = window.fetch.bind(window);
  const state = {provider: null, module: '', last: {}};
  const clean = (value, max = 160) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);

  function sanitize(value, depth = 0) {
    if (depth > 4) return null;
    if (value === null || value === undefined) return value ?? null;
    if (typeof value === 'string') return clean(value, 180);
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
    if (typeof value === 'boolean') return value;
    if (Array.isArray(value)) return value.slice(0, 80).map((item) => sanitize(item, depth + 1));
    if (typeof value === 'object') {
      const out = {};
      Object.entries(value).slice(0, 80).forEach(([key, item]) => {
        if (/password|secret|token|csrf|cookie|authorization/i.test(key)) return;
        out[clean(key, 80)] = sanitize(item, depth + 1);
      });
      return out;
    }
    return null;
  }

  function snapshot() {
    const provider = state.provider;
    const value = provider?.snapshot ? provider.snapshot() : state.last;
    return sanitize(value && typeof value === 'object' ? value : {}) || {};
  }

  function transportSnapshot() {
    const provider = state.provider;
    const value = provider?.transportSnapshot ? provider.transportSnapshot() : snapshot();
    return sanitize(value && typeof value === 'object' ? value : {}) || {};
  }

  function description(context = snapshot()) {
    if (state.provider?.description) return clean(state.provider.description(context), 240);
    return clean(context.pageTitle || document.title || 'Restaurant workspace', 240);
  }

  function placeholder(context = snapshot()) {
    if (state.provider?.placeholder) return clean(state.provider.placeholder(context), 260);
    return 'Ask Gelato about this page, staff, schedules, menus, sales, or operations…';
  }

  function publish() {
    const context = snapshot();
    state.last = context;
    window.GELATO_AGENT_PAGE_CONTEXT = context;
    const input = document.getElementById('gaInput');
    if (input) input.placeholder = placeholder(context);
    const drawer = document.querySelector('#gelato-agent-response-drawer .gar-context');
    if (drawer) drawer.textContent = description(context);
    window.dispatchEvent(new CustomEvent('gelato-agent-context-change', {detail: context}));
    return context;
  }

  function register(provider) {
    if (!provider || typeof provider.snapshot !== 'function') return false;
    state.provider = provider;
    state.module = clean(provider.module || provider.snapshot()?.module || '', 80);
    publish();
    return true;
  }

  function targetFile(input) {
    let value = '';
    if (typeof input === 'string') value = input;
    else if (input instanceof URL) value = input.toString();
    else if (typeof Request !== 'undefined' && input instanceof Request) value = input.url;
    try { return new URL(value, window.location.href).pathname.split('/').pop() || ''; }
    catch { return value.split('?')[0].split('/').pop() || ''; }
  }

  function isAgentPost(file, init) {
    const method = String(init?.method || 'GET').toUpperCase();
    return method === 'POST' && (file === 'agent-workspace.php' || /-agent\.php$/i.test(file));
  }

  function withContext(input, init) {
    const file = targetFile(input);
    if (!isAgentPost(file, init) || typeof init?.body !== 'string') return init;
    const next = {...init};
    try {
      const body = JSON.parse(next.body);
      if (!body || typeof body !== 'object' || Array.isArray(body)) return next;
      const context = transportSnapshot();
      if (Object.keys(context).length) body.pageContext = context;
      next.body = JSON.stringify(body);
    } catch {}
    return next;
  }

  function emitError(message) {
    window.dispatchEvent(new CustomEvent('gelato-agent-error', {
      detail: {message: clean(message || 'Gelato could not complete that request.', 500)},
    }));
  }

  window.fetch = async function gelatoContextFetch(input, init) {
    const file = targetFile(input);
    const next = withContext(input, init);
    const agentPost = isAgentPost(file, next);
    let response;
    try {
      response = await nativeFetch(input, next);
    } catch (error) {
      if (agentPost) emitError(error?.message || 'Network error while contacting Gelato.');
      throw error;
    }
    if (!response.ok && agentPost) {
      try {
        const data = await response.clone().json();
        emitError(data?.message || 'Gelato could not complete that request.');
      } catch { emitError('Gelato could not complete that request.'); }
    }
    return response;
  };

  window.GelatoAgentPageContext = {register, snapshot, transportSnapshot, description, placeholder, publish};
  window.addEventListener('gelato-agent-ready', publish);
})();