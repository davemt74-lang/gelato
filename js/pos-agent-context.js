(() => {
  'use strict';
  if (window.GelatoPosAgentContext) return;

  const contextFetch = window.fetch.bind(window);
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

  function transportSnapshot() {
    const context = snapshot();
    return {
      module: context.module,
      route: context.route,
      locationId: context.locationId,
      view: context.view,
      checkPublicId: context.checkPublicId,
      focusedLineId: context.focusedLineId,
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

  function placeholder(context = snapshot()) {
    return context.checkPublicId
      ? 'Ask Gelato about this check, selected item, ingredients, prep, allergens, customer, or promotions…'
      : 'Ask Gelato about the menu, ingredients, allergens, or POS…';
  }

  function publish() {
    return window.GelatoAgentPageContext?.publish?.() || snapshot();
  }

  function focusLine(value) {
    const id = asId(value);
    state.focusedLineId = id && state.lineItemIds.includes(id) ? id : 0;
    publish();
    return state.focusedLineId;
  }

  function applyPosPayload(data) {
    if (!data || typeof data !== 'object' || !data.ok) return;
    const previousLineIds = state.lineItemIds.slice();
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
        const newlyAdded = state.lineItemIds.filter((id) => !previousLineIds.includes(id));
        if (newlyAdded.length) state.focusedLineId = newlyAdded[newlyAdded.length - 1];
        else if (state.focusedLineId && !state.lineItemIds.includes(state.focusedLineId)) state.focusedLineId = state.lineItemIds[state.lineItemIds.length - 1] || 0;
        else if (!state.focusedLineId && state.lineItemIds.length === 1) state.focusedLineId = state.lineItemIds[0];
      }
    }
    publish();
  }

  function targetFile(input) {
    let value = '';
    if (typeof input === 'string') value = input;
    else if (input instanceof URL) value = input.toString();
    else if (typeof Request !== 'undefined' && input instanceof Request) value = input.url;
    try { return new URL(value, window.location.href).pathname.split('/').pop() || ''; }
    catch { return value.split('?')[0].split('/').pop() || ''; }
  }

  window.fetch = async function gelatoPosStateFetch(input, init) {
    const response = await contextFetch(input, init);
    if (['pos.php', 'pos-item-remove.php'].includes(targetFile(input))) {
      try { applyPosPayload(await response.clone().json()); } catch {}
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
    if (lineAction?.dataset.line) focusLine(lineAction.dataset.line);
    const check = event.target.closest('[data-check]');
    if (check) state.focusedLineId = 0;
  }, true);

  const provider = {module: 'pos', snapshot, transportSnapshot, description, placeholder};
  window.GelatoAgentPageContext?.register?.(provider);
  window.GelatoPosAgentContext = {snapshot, transportSnapshot, description, placeholder, publish, focusLine, applyPosPayload};
  publish();
})();