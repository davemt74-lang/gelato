(() => {
  'use strict';

  if (window.GelatoAdminCommandDashboard) return;

  const S = {
    snapshot: null,
    locationId: Number(localStorage.getItem('gelato-admin-dashboard-location') || 0),
    busy: false,
    mounted: false,
  };

  const $ = (id) => document.getElementById(id);
  const money = (value) => '$' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const number = (value) => Number(value || 0).toLocaleString();
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

  function commandMarkup() {
    return `
      <div class="cmd-shell">
        <section class="cmd-hero">
          <div>
            <p class="cmd-eyebrow">Restaurant command center</p>
            <h3>Sales, orders, locations, and live service.</h3>
            <p>This dashboard brings POS, table service, KDS, online ordering, wholesale, catering, customer activity, and Gelato Agent context into one operating view.</p>
          </div>
          <div class="cmd-controls">
            <select id="cmdLocation" aria-label="Dashboard location"><option value="0">All locations</option></select>
            <button id="cmdRefresh" type="button">Refresh</button>
            <span class="cmd-refresh-meta" id="cmdRefreshMeta">Loading…</span>
          </div>
        </section>

        <section class="cmd-kpis" aria-label="Restaurant operating metrics">
          <article class="cmd-kpi"><small>Sales today</small><strong id="cmdSalesToday">$0.00</strong><span id="cmdSalesTodayMeta">0 paid tickets</span></article>
          <article class="cmd-kpi"><small>Sales this week</small><strong id="cmdSalesWeek">$0.00</strong><span>Paid POS revenue</span></article>
          <article class="cmd-kpi"><small>Sales this month</small><strong id="cmdSalesMonth">$0.00</strong><span>Paid POS revenue</span></article>
          <article class="cmd-kpi"><small>Active tables</small><strong id="cmdActiveTables">0</strong><span>Currently seated / ordering</span></article>
          <article class="cmd-kpi"><small>Open POS tickets</small><strong id="cmdActiveTickets">0</strong><span id="cmdOpenValue">$0.00 open value</span></article>
          <article class="cmd-kpi alert"><small>READY tickets</small><strong id="cmdReadyTickets">0</strong><span>Kitchen tickets ready for service</span></article>
          <article class="cmd-kpi"><small>Open online orders</small><strong id="cmdOnlineOpen">0</strong><span id="cmdOnlineToday">0 submitted today</span></article>
          <article class="cmd-kpi"><small>Average check today</small><strong id="cmdAvgCheck">$0.00</strong><span id="cmdCovers">0 covers today</span></article>
        </section>

        <section class="cmd-secondary">
          <div class="cmd-mini"><span>Paid tickets today</span><strong id="cmdTicketsToday">0</strong></div>
          <div class="cmd-mini"><span>Open check value</span><strong id="cmdOpenValueMini">$0.00</strong></div>
          <div class="cmd-mini"><span>Wholesale open orders</span><strong id="cmdWholesaleOpenMini">0</strong></div>
          <div class="cmd-mini"><span>Catering next 7 days</span><strong id="cmdCateringNextMini">0</strong></div>
        </section>

        <div class="cmd-grid">
          <div class="cmd-stack">
            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Location performance</h4><p>Daily, weekly, monthly sales and live service load by restaurant.</p></div><a href="locations-admin.php">Manage locations →</a></div>
              <div style="overflow:auto"><table class="cmd-location-table"><thead><tr><th>Location</th><th>Today</th><th>Week</th><th>Month</th><th>Tables</th><th>Open tickets</th><th>READY</th><th>Online</th></tr></thead><tbody id="cmdLocations"></tbody></table></div>
            </section>

            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Active tables & tickets</h4><p>Open POS checks, table assignment, check age, and current value.</p></div><a href="pos.php">Open POS →</a></div>
              <div class="cmd-ticket-list" id="cmdTickets"></div>
            </section>

            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Wholesale</h4><p>Customer accounts, pipeline, in-progress orders, delivered revenue, and receivables.</p></div><a href="wholesale-pipeline.php">Wholesale workspace →</a></div>
              <div class="cmd-wholesale">
                <div class="cmd-wholesale-grid">
                  <div class="cmd-wholesale-stat"><span>Active accounts</span><strong id="cmdWholesaleAccounts">0</strong></div>
                  <div class="cmd-wholesale-stat"><span>Pipeline leads</span><strong id="cmdWholesaleLeads">0</strong></div>
                  <div class="cmd-wholesale-stat"><span>Open orders</span><strong id="cmdWholesaleOrders">0</strong></div>
                  <div class="cmd-wholesale-stat"><span>Open order value</span><strong id="cmdWholesaleValue">$0.00</strong></div>
                  <div class="cmd-wholesale-stat"><span>Delivered this month</span><strong id="cmdWholesaleDelivered">$0.00</strong></div>
                  <div class="cmd-wholesale-stat"><span>Outstanding A/R</span><strong id="cmdWholesaleReceivables">$0.00</strong></div>
                </div>
                <div class="cmd-wholesale-links"><a class="cmd-link dark" href="wholesale-pipeline.php">Pipeline</a><a class="cmd-link" href="wholesale-accounts.php">Customers</a><a class="cmd-link" href="wholesale-accounts.php">Orders & receivables</a><span class="cmd-link" id="cmdWholesaleOverdue">0 overdue invoices</span></div>
              </div>
            </section>
          </div>

          <aside class="cmd-stack">
            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Needs attention</h4><p>Live exceptions pulled from the restaurant systems.</p></div><a href="operations.php">Operations →</a></div>
              <div class="cmd-priority-list" id="cmdPriorities"></div>
            </section>

            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Operating pulse</h4><p>Catering, customers, promotions, kitchen and digital orders.</p></div></div>
              <div class="cmd-pulse-grid">
                <div class="cmd-pulse"><small>Catering events</small><strong id="cmdCateringActive">0</strong><span id="cmdCateringMeta">0 next 7 days</span></div>
                <div class="cmd-pulse"><small>Catering readiness</small><strong id="cmdCateringReadiness">0%</strong><span id="cmdCateringRisk">0 at risk</span></div>
                <div class="cmd-pulse"><small>Active customers</small><strong id="cmdCustomers">0</strong><span>CRM customers</span></div>
                <div class="cmd-pulse"><small>Promotions 30d</small><strong id="cmdPromotions">0</strong><span id="cmdPromotionRecipients">0 recipients</span></div>
              </div>
            </section>

            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Gelato Agent</h4><p>Ask the same live operating data shown on this dashboard.</p></div><a href="agent-canvas.php">Agent Canvas →</a></div>
              <div class="cmd-agent">
                <div class="cmd-agent-copy"><small>Live restaurant context</small><p id="cmdAgentAnswer">I can compare locations, summarize sales, review active tables and READY tickets, check online orders, wholesale, catering, and the highest-priority operating exceptions.</p></div>
                <div class="cmd-agent-suggestions"><button type="button" data-cmd-prompt="Give me the restaurant command-center snapshot.">Command snapshot</button><button type="button" data-cmd-prompt="Compare locations today.">Compare locations</button><button type="button" data-cmd-prompt="What needs attention right now on the dashboard?">Needs attention</button><button type="button" data-cmd-prompt="How is wholesale doing right now?">Wholesale status</button></div>
                <form class="cmd-agent-form" id="cmdAgentForm"><input id="cmdAgentInput" autocomplete="off" placeholder="Ask Gelato about sales, tables, orders, wholesale…"><button type="submit">Ask</button></form>
              </div>
            </section>

            <section class="cmd-panel">
              <div class="cmd-panel-head"><div><h4>Open workspaces</h4><p>Jump directly into the system behind each dashboard metric.</p></div></div>
              <div class="cmd-quick-links">
                <a href="pos.php"><span>POS</span><b>→</b></a><a href="kds.php"><span>KDS</span><b>→</b></a><a href="online-orders-admin.php"><span>Online orders</span><b>→</b></a><a href="sales-intelligence.php"><span>Sales</span><b>→</b></a><a href="catering-operations.php"><span>Catering</span><b>→</b></a><a href="customer-crm.php"><span>CRM</span><b>→</b></a><a href="wholesale-pipeline.php"><span>Wholesale</span><b>→</b></a><a href="operations.php"><span>Operations</span><b>→</b></a><a href="locations-admin.php"><span>Locations</span><b>→</b></a>
              </div>
            </section>
          </aside>
        </div>
      </div>`;
  }

  function mount() {
    if (S.mounted) return;
    const legacy = document.getElementById('page-dashboard');
    if (!legacy) return;
    legacy.id = 'page-training-dashboard-legacy';
    legacy.classList.remove('active');
    legacy.hidden = true;

    const dashboard = document.createElement('section');
    dashboard.id = 'page-dashboard';
    dashboard.className = 'page standard-page admin-command-dashboard active';
    dashboard.dataset.commandDashboard = '1';
    dashboard.innerHTML = commandMarkup();
    legacy.parentNode.insertBefore(dashboard, legacy);
    S.mounted = true;

    $('cmdLocation')?.addEventListener('change', (event) => {
      S.locationId = Number(event.target.value || 0);
      localStorage.setItem('gelato-admin-dashboard-location', String(S.locationId));
      refresh();
    });
    $('cmdRefresh')?.addEventListener('click', refresh);
    $('cmdAgentForm')?.addEventListener('submit', (event) => {
      event.preventDefault();
      askAgent($('cmdAgentInput')?.value || '');
    });
    document.querySelectorAll('[data-cmd-prompt]').forEach((button) => button.addEventListener('click', () => askAgent(button.dataset.cmdPrompt || '')));

    const titleObserver = new MutationObserver(updateHeader);
    titleObserver.observe(dashboard, {attributes: true, attributeFilter: ['class']});
    updateHeader();
    refresh();
  }

  function updateHeader() {
    const dashboard = $('page-dashboard');
    if (!dashboard?.classList.contains('active')) return;
    const title = $('topPageTitle');
    const subtitle = $('topPageSubtitle');
    if (title) title.textContent = 'Restaurant Command Center';
    if (subtitle) subtitle.textContent = 'Sales, orders, locations, wholesale, and live restaurant operations.';
    document.querySelectorAll('.nav-btn').forEach((button) => button.classList.toggle('active', button.dataset.nav === 'dashboard'));
  }

  async function fetchDashboard() {
    const query = S.locationId > 0 ? `?locationId=${encodeURIComponent(S.locationId)}` : '';
    const response = await fetch('api/admin-dashboard.php' + query, {headers: {Accept: 'application/json'}, cache: 'no-store'});
    const data = await response.json().catch(() => ({ok: false, message: 'Invalid dashboard response.'}));
    if (!response.ok || !data.ok) throw new Error(data.message || 'Restaurant dashboard could not be loaded.');
    return data.dashboard;
  }

  function setText(id, value) {
    const node = $(id);
    if (node) node.textContent = value;
  }

  function renderLocations(snapshot) {
    const select = $('cmdLocation');
    if (select) {
      const known = new Set((snapshot.locations || []).map((location) => Number(location.id)));
      if (S.locationId && !known.has(S.locationId)) S.locationId = 0;
      select.innerHTML = '<option value="0">All locations</option>' + (snapshot.locations || []).map((location) => `<option value="${Number(location.id)}" ${Number(location.id) === S.locationId ? 'selected' : ''}>${esc(location.name)}</option>`).join('');
    }
    const tbody = $('cmdLocations');
    if (!tbody) return;
    const rows = snapshot.locations || [];
    tbody.innerHTML = rows.length ? rows.map((location) => `<tr><td><span class="cmd-location-name">${esc(location.name)}<small>${esc([location.city, location.state].filter(Boolean).join(', '))}</small></span></td><td>${money(location.salesToday)}</td><td>${money(location.salesWeek)}</td><td>${money(location.salesMonth)}</td><td>${number(location.activeTables)}</td><td>${number(location.activeTickets)}</td><td>${number(location.readyTickets)}</td><td>${number(location.onlineOrdersOpen)}</td></tr>`).join('') : '<tr><td colspan="8" class="cmd-empty">No active restaurant locations.</td></tr>';
  }

  function renderTickets(snapshot) {
    const node = $('cmdTickets');
    if (!node) return;
    const tickets = snapshot.activeTickets || [];
    node.innerHTML = tickets.length ? tickets.map((ticket) => {
      const age = Number(ticket.ageMinutes || 0);
      const destination = ticket.tableName || String(ticket.serviceMode || '').replaceAll('_', ' ');
      return `<a class="cmd-ticket" href="pos.php"><div><strong>${esc(ticket.number)}</strong><br><span>${esc(ticket.locationName)}</span></div><span>${esc(destination)} · ${Number(ticket.guestCount || 1)} guest${Number(ticket.guestCount || 1) === 1 ? '' : 's'}</span><span class="cmd-ticket-age ${age >= 45 ? 'warn' : ''}">${number(age)} min</span><span class="cmd-ticket-value">${money(ticket.value)}</span></a>`;
    }).join('') : '<div class="cmd-empty">No open POS tickets in this dashboard scope.</div>';
  }

  function renderPriorities(snapshot) {
    const node = $('cmdPriorities');
    if (!node) return;
    const priorities = snapshot.priorities || [];
    node.innerHTML = priorities.length ? priorities.map((item) => `<a class="cmd-priority ${esc(item.severity || 'normal')}" href="${esc(item.href || 'operations.php')}"><i class="cmd-priority-dot"></i><div><strong>${esc(item.title)}</strong><p>${esc(item.detail || '')}</p></div><span class="cmd-priority-arrow">→</span></a>`).join('') : '<div class="cmd-empty">No dashboard exceptions.</div>';
  }

  function render(snapshot) {
    S.snapshot = snapshot;
    window.RestaurantAdminDashboardContext = snapshot;
    const t = snapshot.totals || {};
    const online = snapshot.onlineOrders || {};
    const wholesale = snapshot.wholesale || {};
    const catering = snapshot.catering || {};
    const customers = snapshot.customers || {};

    setText('cmdSalesToday', money(t.salesToday));
    setText('cmdSalesWeek', money(t.salesWeek));
    setText('cmdSalesMonth', money(t.salesMonth));
    setText('cmdSalesTodayMeta', `${number(t.ticketsToday)} paid ticket${Number(t.ticketsToday) === 1 ? '' : 's'}`);
    setText('cmdActiveTables', number(t.activeTables));
    setText('cmdActiveTickets', number(t.activeTickets));
    setText('cmdOpenValue', `${money(t.openValue)} open value`);
    setText('cmdReadyTickets', number(t.readyTickets));
    setText('cmdOnlineOpen', number(online.open));
    setText('cmdOnlineToday', `${number(online.today)} submitted today`);
    setText('cmdAvgCheck', money(t.avgCheckToday));
    setText('cmdCovers', `${number(t.coversToday)} covers today`);
    setText('cmdTicketsToday', number(t.ticketsToday));
    setText('cmdOpenValueMini', money(t.openValue));
    setText('cmdWholesaleOpenMini', number(wholesale.openOrders));
    setText('cmdCateringNextMini', number(catering.next7Days));

    setText('cmdWholesaleAccounts', number(wholesale.activeAccounts));
    setText('cmdWholesaleLeads', number(wholesale.pipelineLeads));
    setText('cmdWholesaleOrders', number(wholesale.openOrders));
    setText('cmdWholesaleValue', money(wholesale.openOrderValue));
    setText('cmdWholesaleDelivered', money(wholesale.monthDelivered));
    setText('cmdWholesaleReceivables', money(wholesale.outstandingReceivables));
    setText('cmdWholesaleOverdue', `${number(wholesale.overdueInvoices)} overdue invoice${Number(wholesale.overdueInvoices) === 1 ? '' : 's'}`);

    setText('cmdCateringActive', number(catering.activeEvents));
    setText('cmdCateringMeta', `${number(catering.next7Days)} next 7 days`);
    setText('cmdCateringReadiness', `${number(catering.averageReadiness)}%`);
    setText('cmdCateringRisk', `${number(catering.atRisk)} at risk`);
    setText('cmdCustomers', number(customers.activeCustomers));
    setText('cmdPromotions', number(customers.promotionsSent30d));
    setText('cmdPromotionRecipients', `${number(customers.promotionRecipients30d)} recipients`);

    renderLocations(snapshot);
    renderTickets(snapshot);
    renderPriorities(snapshot);
    const refreshed = new Date(snapshot.generatedAt || Date.now());
    setText('cmdRefreshMeta', `Updated ${Number.isNaN(refreshed.getTime()) ? 'now' : refreshed.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}`);
    updateHeader();
  }

  async function refresh() {
    if (S.busy) return;
    S.busy = true;
    $('page-dashboard')?.classList.add('cmd-loading');
    try {
      render(await fetchDashboard());
    } catch (error) {
      setText('cmdRefreshMeta', 'Dashboard unavailable');
      const priorities = $('cmdPriorities');
      if (priorities) priorities.innerHTML = `<div class="cmd-error">${esc(error.message || 'The restaurant dashboard could not be loaded.')}</div>`;
    } finally {
      S.busy = false;
      $('page-dashboard')?.classList.remove('cmd-loading');
    }
  }

  async function directAgentAsk(message) {
    const response = await fetch('api/admin-dashboard-agent.php', {
      method: 'POST',
      cache: 'no-store',
      headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': window.RESTAURANT_CSRF_TOKEN || ''},
      body: JSON.stringify({action: 'ask', message, csrf_token: window.RESTAURANT_CSRF_TOKEN || ''}),
    });
    const data = await response.json().catch(() => ({ok: false, message: 'Invalid Agent response.'}));
    if (!response.ok || !data.ok) throw new Error(data.message || 'Gelato could not answer that dashboard question.');
    return data;
  }

  async function askAgent(raw) {
    const message = String(raw || '').trim();
    if (!message || S.busy) return;
    const input = $('cmdAgentInput');
    if (input) input.value = '';
    setText('cmdAgentAnswer', 'Gelato is checking the live restaurant systems…');
    try {
      const result = window.GelatoGlobalAgent?.send ? await window.GelatoGlobalAgent.send(message, false) : await directAgentAsk(message);
      const answer = result?.answer || result?.reply || 'Done.';
      setText('cmdAgentAnswer', answer);
      if (result?.data || result?.skill === 'admin.dashboard') refresh();
    } catch (error) {
      setText('cmdAgentAnswer', error.message || 'Gelato could not complete that request.');
    }
  }

  window.GelatoAdminCommandDashboard = {
    refresh,
    ask: askAgent,
    get snapshot() { return S.snapshot; },
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once: true});
  else mount();
})();