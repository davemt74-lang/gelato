(() => {
  'use strict';
  if (window.GelatoAgentPageContext) return;

  const state = {explicit: {}};
  const queryKeys = new Set([
    'id','employee_id','user_id','location_id','week','start','date','order_id','ticket_id','table_id',
    'customer_id','contact_id','event_id','catering_id','vendor_id','po_id','item_id','recipe_id','account_id',
    'asset_id','lead_id','status','stage','filter','search','q'
  ]);
  const fieldNames = new Set(['week','date','status','stage','filter','location_id','employee_id','user_id']);
  const routeModules = {
    'workspace.php':'workspace','scheduling.php':'scheduling','timeclock.php':'timeclock',
    'employee-home.php':'employee','employee-development.php':'employee','admin.php':'employee',
    'purchasing.php':'purchasing','customer-crm.php':'crm',
    'catering.php':'catering','catering-operations.php':'catering','catering-pipeline.php':'catering',
    'wholesale.php':'wholesale','wholesale-accounts.php':'wholesale','wholesale-customer-360.php':'wholesale',
    'wholesale-pipeline.php':'wholesale','wholesale-acquisition.php':'wholesale','wholesale-commerce.php':'wholesale',
    'wholesale-demand.php':'wholesale','wholesale-fulfillment.php':'wholesale','wholesale-purchasing.php':'wholesale',
    'wholesale-receivables.php':'wholesale','wholesale-order-entry.php':'wholesale','wholesale-portal.php':'wholesale',
    'pos.php':'orders','kds.php':'orders','kds-dashboard.php':'orders','table-service.php':'orders','host-stand.php':'orders',
    'online-orders-admin.php':'orders','order-recovery.php':'orders','pickup-fulfillment.php':'orders',
    'operations.php':'operations','prep-intelligence.php':'prep','recipes.php':'recipes','menu-manager.php':'menu',
    'equipment.php':'equipment','equipment-detail.php':'equipment','sales-intelligence.php':'sales',
    'sales-cost-intelligence.php':'sales','sales-import-center.php':'sales','locations-admin.php':'locations'
  };

  const clean = (value, max = 120) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);

  function routeName() {
    return clean(String(location.pathname || '').split('/').filter(Boolean).pop() || 'workspace.php', 80).toLowerCase();
  }

  function pageTitle() {
    return clean(
      document.getElementById('topPageTitle')?.textContent ||
      document.querySelector('[data-page-title]')?.textContent ||
      document.querySelector('main h1, main h2, h1')?.textContent ||
      document.title || 'Restaurant workspace', 140
    );
  }

  function explicitDomContext() {
    const node = document.querySelector('[data-agent-entity-type],[data-agent-context]');
    if (!node) return {};
    const dataset = node.dataset || {};
    return {entityType:clean(dataset.agentEntityType,40),entityId:clean(dataset.agentEntityId,100),entityLabel:clean(dataset.agentEntityLabel,120)};
  }

  function queryFilters() {
    const out = {};
    const params = new URLSearchParams(location.search || '');
    for (const [key, value] of params.entries()) {
      const normalized = key.toLowerCase();
      if (!queryKeys.has(normalized) || Object.keys(out).length >= 10) continue;
      const safe = clean(value, 120);
      if (safe) out[normalized] = safe;
    }
    return out;
  }

  function formFilters() {
    const out = {};
    document.querySelectorAll('select[name],input[name]').forEach((field) => {
      if (Object.keys(out).length >= 8) return;
      const key = clean(field.name, 40).toLowerCase();
      if (!fieldNames.has(key) || field.disabled || field.type === 'password') return;
      const value = field.type === 'checkbox' ? (field.checked ? field.value || '1' : '') : field.value;
      const safe = clean(value, 120);
      if (safe) out[key] = safe;
    });
    return out;
  }

  function dashboardScope() {
    const scope = window.RestaurantAdminDashboardContext?.scope || {};
    return {locationId:clean(scope.locationId || '',80),locationName:clean(scope.locationName || '',100)};
  }

  function snapshot() {
    const route = routeName();
    const dom = explicitDomContext();
    const scope = dashboardScope();
    const seeded = window.GELATO_AGENT_PAGE_CONTEXT && typeof window.GELATO_AGENT_PAGE_CONTEXT === 'object' ? window.GELATO_AGENT_PAGE_CONTEXT : {};
    const explicit = {...seeded, ...(state.explicit || {})};
    const filters = {...queryFilters(), ...formFilters(), ...(seeded.filters || {}), ...(state.explicit.filters || {})};
    const normalizedFilters = {};
    Object.entries(filters).slice(0, 12).forEach(([key, value]) => {
      const safeKey = clean(key, 40).toLowerCase().replace(/[^a-z0-9_-]/g, '');
      const safeValue = clean(value, 120);
      if (safeKey && safeValue) normalizedFilters[safeKey] = safeValue;
    });
    return {
      route,
      module: routeModules[route] || 'general',
      pageTitle: clean(explicit.pageTitle || pageTitle(), 140),
      entity: {
        type: clean(explicit.entityType || explicit.entity?.type || dom.entityType, 40),
        id: clean(explicit.entityId || explicit.entity?.id || dom.entityId, 100),
        label: clean(explicit.entityLabel || explicit.entity?.label || dom.entityLabel, 120),
      },
      filters: normalizedFilters,
      scope: {
        locationId: clean(explicit.locationId || explicit.scope?.locationId || scope.locationId, 80),
        locationName: clean(explicit.locationName || explicit.scope?.locationName || scope.locationName, 100),
      },
    };
  }

  function description(context = snapshot()) {
    const parts = [context.pageTitle || 'Restaurant workspace'];
    if (context.entity?.label) parts.push(context.entity.label);
    if (context.scope?.locationName && context.scope.locationName !== 'All locations') parts.push(context.scope.locationName);
    return parts.filter(Boolean).join(' · ');
  }

  function updateDrawerContext(context = snapshot()) {
    const node = document.querySelector('#gelato-agent-response-drawer .gar-context');
    if (node) node.textContent = description(context);
  }

  function ensureActionStyles() {
    if (document.querySelector('style[data-agent-context-actions]')) return;
    const style = document.createElement('style');
    style.dataset.agentContextActions = '1';
    style.textContent = `
      #gelato-agent-response-drawer .gar-actions{display:flex;gap:6px;overflow-x:auto;padding:9px 12px 11px;border-top:1px solid #ecece7;background:#fbfbf9;scrollbar-width:thin}
      #gelato-agent-response-drawer .gar-action{flex:0 0 auto;border:1px solid #dddcd5;background:#fff;color:#242622;border-radius:999px;padding:7px 10px;font:800 9px/1 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;cursor:pointer;white-space:nowrap}
      #gelato-agent-response-drawer .gar-action:hover{border-color:#bdbdb4;background:#f5f5f0}
      #gelato-agent-response-drawer .gar-action:disabled{opacity:.55;cursor:default}
    `;
    document.head.appendChild(style);
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function renderActions(actions = []) {
    const drawer = document.getElementById('gelato-agent-response-drawer');
    if (!drawer) return;
    ensureActionStyles();
    let row = drawer.querySelector('.gar-actions');
    if (!row) {
      row = document.createElement('div');
      row.className = 'gar-actions';
      row.setAttribute('aria-label', 'Contextual Agent actions');
      drawer.appendChild(row);
    }
    const safeActions = Array.isArray(actions) ? actions.slice(0, 5) : [];
    row.innerHTML = safeActions.map((action, index) => `<button class="gar-action" type="button" data-agent-action="${index}">${escapeHtml(clean(action?.label || 'Ask Gelato', 40))}</button>`).join('');
    row.hidden = safeActions.length === 0;
    row._gelatoActions = safeActions;
  }

  function installDrawerEnhancement() {
    const drawer = document.getElementById('gelato-agent-response-drawer');
    if (!drawer || drawer.dataset.contextActionsReady === '1') return;
    drawer.dataset.contextActionsReady = '1';
    updateDrawerContext();
    renderActions(window.GelatoGlobalAgent?.contextActions || []);
    drawer.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-agent-action]');
      if (!button || button.disabled) return;
      const row = drawer.querySelector('.gar-actions');
      const action = row?._gelatoActions?.[Number(button.dataset.agentAction)];
      const prompt = clean(action?.prompt, 500);
      if (!prompt || !window.GelatoGlobalAgent?.send) return;
      button.disabled = true;
      try {
        window.GelatoDynamicAgentCanvas?.openDrawer?.({pending:true});
        await window.GelatoGlobalAgent.send(prompt, false);
      } finally { button.disabled = false; }
    });
  }

  function set(next = {}) {
    const safe = next && typeof next === 'object' ? next : {};
    state.explicit = {
      pageTitle: clean(safe.pageTitle, 140),
      entityType: clean(safe.entityType || safe.entity?.type, 40),
      entityId: clean(safe.entityId || safe.entity?.id, 100),
      entityLabel: clean(safe.entityLabel || safe.entity?.label, 120),
      locationId: clean(safe.locationId || safe.scope?.locationId, 80),
      locationName: clean(safe.locationName || safe.scope?.locationName, 100),
      filters: safe.filters && typeof safe.filters === 'object' ? safe.filters : {},
    };
    const current = snapshot();
    updateDrawerContext(current);
    window.dispatchEvent(new CustomEvent('gelato-agent-context-change', {detail: current}));
    return current;
  }

  function clear() {
    state.explicit = {};
    updateDrawerContext();
    window.dispatchEvent(new CustomEvent('gelato-agent-context-change', {detail: snapshot()}));
  }

  window.GelatoAgentPageContext = {snapshot, description, set, clear};
  window.addEventListener('gelato-set-agent-context', (event) => set(event.detail || {}));
  window.addEventListener('gelato-agent-context-change', (event) => updateDrawerContext(event.detail || snapshot()));
  window.addEventListener('gelato-agent-response', (event) => {
    installDrawerEnhancement();
    updateDrawerContext(event.detail?.pageContext || snapshot());
    renderActions(event.detail?.contextActions || window.GelatoGlobalAgent?.contextActions || []);
  });
  new MutationObserver(() => installDrawerEnhancement()).observe(document.documentElement, {childList:true, subtree:true});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installDrawerEnhancement, {once:true});
  else installDrawerEnhancement();
})();
