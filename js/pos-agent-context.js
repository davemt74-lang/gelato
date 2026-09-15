(() => {
  'use strict';
  if (window.GelatoPosAgentContext) return;

  const nativeFetch = window.fetch.bind(window);
  const state = {
    locationId: 0,
    locationName: '',
    view: 'menu',
    checkPublicId: '',
    checkNumber: '',
    serviceMode: '',
    tableName: '',
    guestCount: 0,
    customerPublicId: '',
    customerLabel: '',
    menuItemIds: [],
    lineItemIds: [],
    focusedLineId: 0,
  };

  const clean = (value, max = 120) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
  const asId = (value) => Math.max(0, Number.parseInt(String(value ?? '0'), 10) || 0);

  function snapshot() {
    return {
      module: 'pos',
      route: 'pos.php',
      pageTitle: 'Gelato POS',
      locationId: asId(state.locationId),
      locationName: clean(state.locationName, 100),
      view: ['menu', 'floor', 'ready'].includes(state.view) ? state.view : 'menu',
      checkPublicId: clean(state.checkPublicId, 100),
      checkNumber: clean(state.checkNumber, 80),
      serviceMode: clean(state.serviceMode, 40),
      tableName: clean(state.tableName, 120),
      guestCount: Math.max(0, Math.min(99, asId(state.guestCount))),
      customerPublicId: clean(state.customerPublicId, 100),
      customerLabel: clean(state.customerLabel, 120),
      menuItemIds: state.menuItemIds.slice(0, 40).map(asId).filter(Boolean),
      lineItemIds: state.lineItemIds.slice(0, 60).map(asId).filter(Boolean),
      focusedLineId: asId(state.focusedLineId),
    };
  }

  function description(context = snapshot()) {
    const parts = ['POS'];
    if (context.checkNumber) parts.push(context.checkNumber);
    if (context.customerLabel) parts.push(context.customerLabel);
    else if (context.checkPublicId) parts.push('Walk-in / unlinked guest');
    if (context.locationName) parts.push(context.locationName);
    return parts.join(' · ');
  }

  function publish() {
    const context = snapshot();
    window.GELATO_AGENT_PAGE_CONTEXT = context;
    window.dispatchEvent(new CustomEvent('gelato-agent-context-change', {detail: context}));
    const drawer = document.querySelector('#gelato-agent-response-drawer .gar-context');
    if (drawer) drawer.textContent = description(context);
    const input = document.getElementById('gaInput');
    if (input) input.placeholder = context.checkPublicId
      ? 'Ask Gelato about this check, menu items, allergens, customer, or promotions…'
      : 'Ask Gelato about the menu, ingredients, allergens, or POS…';
    return context;
  }

  function applyPosPayload(data) {
    if (!data || typeof data !== 'object' || !data.ok) return;
    if (data.locationId !== undefined) state.locationId = asId(data.locationId);
    if (Array.isArray(data.locations) && state.locationId) {
      const location = data.locations.find((entry) => asId(entry?.id) === state.locationId);
      if (location?.name) state.locationName = clean(location.name, 100);
    }
    if (data.settings?.locationName) state.locationName = clean(data.settings.locationName, 100);
    if (Object.prototype.hasOwnProperty.call(data, 'check')) {
      const check = data.check;
      if (!check) {
        state.checkPublicId = '';
        state.checkNumber = '';
        state.serviceMode = '';
        state.tableName = '';
        state.guestCount = 0;
        state.customerPublicId = '';
        state.customerLabel = '';
        state.menuItemIds = [];
        state.lineItemIds = [];
        state.focusedLineId = 0;
      } else {
        state.locationId = asId(check.locationId || state.locationId);
        state.locationName = clean(check.locationName || state.locationName, 100);
        state.checkPublicId = clean(check.publicId, 100);
        state.checkNumber = clean(check.checkNumber, 80);
        state.serviceMode = clean(check.serviceMode, 40);
        state.tableName = clean(check.tableName, 120);
        state.guestCount = asId(check.guestCount);
        state.customerPublicId = clean(check.customer?.publicId, 100);
        state.customerLabel = clean(check.customer?.displayName, 120);
        const active = Array.isArray(check.items) ? check.items.filter((item) => String(item?.status || 'active') === 'active') : [];
        state.menuItemIds = [...new Set(active.map((item) => asId(item?.menu_item_id)).filter(Boolean))];
        state.lineItemIds = active.map((item) => asId(item?.id)).filter(Boolean);
        if (state.focusedLineId && !state.lineItemIds.includes(state.focusedLineId)) state.focusedLineId = 0;
      }
    }
    publish();
  }

  function targetUrl(input) {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.toString();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.url;
    return '';
  }

  function localPath(input) {
    const url = targetUrl(input);
    try { return new URL(url, window.location.href).pathname.replace(/^.*\//, ''); }
    catch { return url.split('?')[0].split('/').pop() || ''; }
  }

  function withAgentContext(input, init) {
    const file = localPath(input);
    if (!['agent-workspace.php', 'pos-agent.php'].includes(file)) return init;
    const next = {...(init || {})};
    if (String(next.method || 'GET').toUpperCase() !== 'POST' || typeof next.body !== 'string') return next;
    try {
      const body = JSON.parse(next.body);
      if (!body || typeof body !== 'object' || Array.isArray(body)) return next;
      body.pageContext = snapshot();
      next.body = JSON.stringify(body);
    } catch {}
    return next;
  }

  window.fetch = async function gelatoPosContextFetch(input, init) {
    const nextInit = withAgentContext(input, init);
    const response = await nativeFetch(input, nextInit);
    if (localPath(input) === 'pos.php') {
      try {
        const data = await response.clone().json();
        applyPosPayload(data);
      } catch {}
    }
    return response;
  };

  function setView(view) {
    if (['menu', 'floor', 'ready'].includes(view)) {
      state.view = view;
      publish();
    }
  }

  document.addEventListener('click', (event) => {
    const view = event.target.closest('[data-pos-view]');
    if (view?.dataset.posView) setView(view.dataset.posView);
    const lineAction = event.target.closest('[data-line]');
    if (lineAction?.dataset.line) {
      state.focusedLineId = asId(lineAction.dataset.line);
      publish();
    }
    const check = event.target.closest('[data-check]');
    if (check) state.focusedLineId = 0;
  }, true);

  window.addEventListener('gelato-agent-ready', publish);
  window.addEventListener('gelato-agent-response', publish);
  window.addEventListener('gelato-agent-context-change', (event) => {
    if (event.detail?.module === 'pos') {
      const drawer = document.querySelector('#gelato-agent-response-drawer .gar-context');
      if (drawer) drawer.textContent = description(event.detail);
    }
  });

  window.GelatoAgentPageContext = {
    snapshot,
    description,
    set(next = {}) {
      if (next && typeof next === 'object' && next.view) setView(String(next.view));
      return publish();
    },
  };
  window.GelatoPosAgentContext = {snapshot, description, publish};
  publish();
})();
