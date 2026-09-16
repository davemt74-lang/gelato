(() => {
  'use strict';
  if (window.GelatoGlobalAddCanvas) return;

  const state = {shell: null, overlay: null, launcher: null, lastFocus: null};
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const groups = ['People','Menu & Products','Operations','Sales & Customers','Purchasing & Assets'];
  const page = (location.pathname.split('/').pop() || '').toLowerCase();
  const wholesaleAgentPages = new Set([
    'wholesale-accounts.php','wholesale-acquisition.php','wholesale-commerce.php','wholesale-customer-360.php',
    'wholesale-demand.php','wholesale-fulfillment.php','wholesale-order-entry.php','wholesale-pipeline.php',
    'wholesale-purchasing.php','wholesale-receivables.php','wholesale.php',
  ]);

  function ensureScript(src, marker) {
    if (document.querySelector(`script[${marker}]`)) return;
    const script = document.createElement('script');
    script.src = src;
    script.async = false;
    script.setAttribute(marker, '1');
    document.head.appendChild(script);
  }

  function ensureAgentExperience() {
    ensureScript('js/agent-page-context.js?v=20260915-context3', 'data-agent-page-context-loader');
    if (page === 'scheduling.php') ensureScript('js/scheduling-agent-context.js?v=20260915-context2', 'data-scheduling-agent-context-loader');
    if (page === 'purchasing.php') ensureScript('js/purchasing-agent-context.js?v=20260915-context2', 'data-purchasing-agent-context-loader');
    if (page === 'customer-crm.php') ensureScript('js/customer-crm-agent-context.js?v=20260915-context1', 'data-crm-agent-context-loader');
    if (page === 'catering-operations.php' || page === 'catering-pipeline.php') ensureScript('js/catering-agent-context.js?v=20260916-context1', 'data-catering-agent-context-loader');
    if (wholesaleAgentPages.has(page)) ensureScript('js/wholesale-agent-context.js?v=20260916-context1', 'data-wholesale-agent-context-loader');
    if (page === 'prep-intelligence.php') ensureScript('js/prep-agent-context.js?v=20260915-context1', 'data-prep-agent-context-loader');
    if (page === 'operations.php') ensureScript('js/operations-agent-context.js?v=20260915-context1', 'data-operations-agent-context-loader');
    if (page === 'kds.php' || page === 'kds-dashboard.php') ensureScript('js/kds-agent-context.js?v=20260915-context1', 'data-kds-agent-context-loader');
    if (!window.GelatoGlobalAgent) ensureScript('js/global-agent.js?v=20260915-agent2', 'data-gelato-global-agent-loader');
    ensureScript('js/dynamic-agent-canvas.js?v=20260915-drawer1', 'data-dynamic-agent-canvas-loader');
    ensureScript('js/agent-next-moves.js?v=20260916-brain1', 'data-agent-next-moves-loader');
  }

  function ensureStyles() {
    if (document.querySelector('link[data-global-add-canvas]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'css/global-add-canvas.css?v=20260915-add1';
    link.dataset.globalAddCanvas = '1';
    document.head.appendChild(link);
  }

  function actions() {
    return Array.isArray(state.shell?.addActions) ? state.shell.addActions : [];
  }

  function renderCards(query = '') {
    if (!state.overlay) return;
    const normalized = query.trim().toLowerCase();
    let visibleTotal = 0;
    groups.forEach((groupName) => {
      const group = state.overlay.querySelector(`[data-gac-group="${CSS.escape(groupName)}"]`);
      if (!group) return;
      let count = 0;
      group.querySelectorAll('.gac-card').forEach((card) => {
        const haystack = (card.dataset.search || '').toLowerCase();
        const show = !normalized || haystack.includes(normalized);
        card.hidden = !show;
        if (show) count++;
      });
      group.hidden = count === 0;
      const counter = group.querySelector('.gac-count');
      if (counter) counter.textContent = `${count} action${count === 1 ? '' : 's'}`;
      visibleTotal += count;
    });
    const empty = state.overlay.querySelector('.gac-empty');
    if (empty) empty.hidden = visibleTotal !== 0;
  }

  function buildOverlay() {
    const overlay = document.createElement('section');
    overlay.className = 'gac-overlay';
    overlay.id = 'gelatoAddCanvas';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'gelatoAddCanvasTitle');

    const grouped = new Map(groups.map((group) => [group, []]));
    actions().forEach((action) => {
      const group = grouped.has(action.group) ? action.group : 'Operations';
      grouped.get(group).push(action);
    });

    const groupHtml = groups.map((groupName) => {
      const list = grouped.get(groupName) || [];
      if (!list.length) return '';
      const cards = list.map((action) => {
        const planned = action.status === 'planned' || !action.href;
        const search = `${action.label || ''} ${action.description || ''} ${groupName}`;
        const body = `<span class="gac-card-icon">${esc(action.icon || '+')}</span><span class="gac-card-copy"><strong>${esc(action.label)}</strong><span>${esc(action.description || '')}</span></span>${planned ? `<span class="gac-badge">${esc(action.badge || 'Coming soon')}</span>` : '<span class="gac-card-go">↗</span>'}`;
        if (planned) return `<button type="button" class="gac-card planned" data-search="${esc(search)}" data-planned="1" aria-label="${esc(action.label)} — ${esc(action.badge || 'coming soon')}">${body}</button>`;
        return `<a class="gac-card" data-search="${esc(search)}" href="${esc(action.href)}">${body}</a>`;
      }).join('');
      return `<section class="gac-group" data-gac-group="${esc(groupName)}"><div class="gac-group-head"><h2>${esc(groupName)}</h2><span class="gac-count">${list.length} action${list.length === 1 ? '' : 's'}</span></div><div class="gac-grid">${cards}</div></section>`;
    }).join('');

    overlay.innerHTML = `<div class="gac-shell"><header class="gac-top"><div><div class="gac-kicker">Global create</div><h1 id="gelatoAddCanvasTitle">Add something</h1><p>Start the most common restaurant actions from one place. Available actions are automatically filtered to your account permissions.</p></div><button class="gac-close" type="button" aria-label="Close add canvas">×</button></header><div class="gac-tools"><label class="gac-search-wrap"><span class="gac-search-icon">⌕</span><input class="gac-search" type="search" autocomplete="off" placeholder="Search actions — recipe, equipment, receipt…" aria-label="Search add actions"></label><span class="gac-hint">Esc to close</span></div><div class="gac-groups">${groupHtml}</div><div class="gac-empty" hidden><strong>No matching actions.</strong><span>Try a different action name.</span></div></div>`;

    overlay.querySelector('.gac-close').addEventListener('click', close);
    overlay.querySelector('.gac-search').addEventListener('input', (event) => renderCards(event.currentTarget.value));
    overlay.addEventListener('click', (event) => {
      const planned = event.target.closest('[data-planned="1"]');
      if (planned) planned.animate([{transform:'scale(1)'},{transform:'scale(.985)'},{transform:'scale(1)'}], {duration:180});
    });
    document.body.appendChild(overlay);
    state.overlay = overlay;
  }

  function launcherTarget() {
    return document.querySelector('.uas-header-actions') || document.querySelector('.topbar .top-actions');
  }

  function buildLauncher() {
    const target = launcherTarget();
    if (!target || target.querySelector('[data-global-add-launch]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'gac-launch';
    button.dataset.globalAddLaunch = '1';
    button.setAttribute('aria-label', 'Add');
    button.setAttribute('aria-haspopup', 'dialog');
    button.textContent = '+';
    button.addEventListener('click', open);
    const account = target.querySelector('.uas-account,.profile-wrap,.profile-menu-wrap,.notification-wrap');
    target.insertBefore(button, account || target.firstChild);
    state.launcher = button;
  }

  function open() {
    if (!state.overlay) return;
    state.lastFocus = document.activeElement;
    state.overlay.hidden = false;
    document.body.classList.add('gac-open');
    const search = state.overlay.querySelector('.gac-search');
    search.value = '';
    renderCards('');
    requestAnimationFrame(() => search.focus());
  }

  function close() {
    if (!state.overlay || state.overlay.hidden) return;
    state.overlay.hidden = true;
    document.body.classList.remove('gac-open');
    if (state.lastFocus instanceof HTMLElement) state.lastFocus.focus();
  }

  function keydown(event) {
    if (event.key === 'Escape') close();
  }

  function install(shell) {
    ensureAgentExperience();
    if (!shell || !Array.isArray(shell.addActions) || shell.addActions.length === 0) return;
    state.shell = shell;
    ensureStyles();
    if (state.overlay) state.overlay.remove();
    buildOverlay();
    buildLauncher();
    document.removeEventListener('keydown', keydown);
    document.addEventListener('keydown', keydown);
  }

  ensureAgentExperience();
  window.GelatoGlobalAddCanvas = {install, open, close};
  window.addEventListener('gelato-admin-shell-config', (event) => install(event.detail));
  if (window.GELATO_ADMIN_SHELL) install(window.GELATO_ADMIN_SHELL);
})();