(() => {
  'use strict';

  if (window.GelatoUniversalAdminPageShell) return;
  window.GelatoUniversalAdminPageShell = true;

  const STATE_KEY = 'gelato-admin-nav-accordion-v1';
  const MOBILE_BREAKPOINT = 900;
  const path = (location.pathname.split('/').pop() || '').toLowerCase();

  const pageTitles = {
    'admin.php': ['Restaurant Admin', 'Restaurant configuration and operating controls'],
    'admin-menu-import.php': ['Menu Import', 'Owner menu synchronization and canonical menu data'],
    'locations-admin.php': ['Locations', 'Restaurant locations, hours and service configuration'],
    'operations.php': ['Operations', 'Tasks, prep, inventory and restaurant work management'],
    'timeclock.php': ['Time Clock + Attendance', 'Attendance, labor and employee voice Agent'],
    'scheduling.php': ['Staff Scheduling', 'Shifts, availability, coverage and labor planning'],
    'purchasing.php': ['Purchasing + Receiving', 'Vendors, purchase orders, receiving and cost intelligence'],
    'equipment.php': ['Equipment Catalog', 'Restaurant assets, service history and maintenance intelligence'],
    'recipes.php': ['Recipe Library + Builder', 'Restaurant recipes, images and AI mapping'],
    'prep-intelligence.php': ['Prep Intelligence', 'Prep demand, restock pressure and production planning'],
    'floor-planner-v2.php': ['Floor Planner', 'Restaurant floor layout and service points'],
    'catering-operations.php': ['Catering Operations', 'Production, fulfillment and event execution'],
    'catering-pipeline.php': ['Catering Pipeline', 'Catering leads, quotes and customer pipeline'],
    'sales-intelligence.php': ['Sales Intelligence', 'Sales performance, demand and source health'],
    'sales-cost-intelligence.php': ['Cost Intelligence', 'Food cost, margin and sales-cost performance'],
    'customer-promotions.php': ['Customer Promotions', 'Customer campaigns and account Inbox promotions'],
    'customer-crm.php': ['Customer CRM', 'Identity, consent and transaction relationships'],
    'online-orders-admin.php': ['Online Orders', 'Customer pickup and online-order operations'],
    'wholesale-pipeline.php': ['Wholesale', 'Wholesale pipeline, accounts and growth'],
    'wholesale-accounts.php': ['Wholesale Customers', 'Wholesale customer accounts and order relationships'],
    'agent-canvas.php': ['Agent Canvas', 'Restaurant Agent tools and operating context'],
    'public-site-settings.php': ['Public Site Settings', 'Public website contact, location and brand settings'],
  };

  const fallbackTitle = (document.title || 'Gelato Admin').replace(/\s*[|·-]\s*Gelato.*$/i, '').trim() || 'Gelato Admin';
  const [title, subtitle] = pageTitles[path] || [fallbackTitle, 'Gelato restaurant administration'];

  const groups = [
    {
      id: 'team', label: 'Team & Hiring', items: [
        ['⌂', 'Workspace', 'workspace.php'],
        ['♙', 'User Accounts', 'workspace.php#users'],
        ['▣', 'Jobs', 'workspace.php#jobs'],
        ['☷', 'Form Builder', 'workspace.php#forms'],
        ['◫', 'Staff Scheduling', 'scheduling.php'],
        ['◷', 'Time Clock + Attendance', 'timeclock.php'],
      ],
    },
    {
      id: 'website', label: 'Website & Locations', items: [
        ['⌖', 'Locations', 'locations-admin.php'],
        ['◇', 'Public Site Settings', 'public-site-settings.php'],
        ['◇', 'Landing Page', 'workspace.php#landing-builder'],
        ['◐', 'Brand Settings', 'workspace.php#brand'],
      ],
    },
    {
      id: 'operations', label: 'Operations', items: [
        ['↗', 'Online Orders', 'online-orders-admin.php'],
        ['✓', 'Operations', 'operations.php'],
        ['▤', 'Recipe Library + Builder', 'recipes.php'],
        ['▤', 'Prep Intelligence', 'prep-intelligence.php'],
        ['▦', 'Floor Planner', 'floor-planner-v2.php'],
        ['⚙', 'Equipment Catalog', 'equipment.php'],
        ['▣', 'Purchasing + Receiving', 'purchasing.php'],
      ],
    },
    {
      id: 'sales', label: 'Sales & Events', items: [
        ['↗', 'Sales Intelligence', 'sales-intelligence.php'],
        ['$', 'Cost Intelligence', 'sales-cost-intelligence.php'],
        ['◎', 'Customer CRM', 'customer-crm.php'],
        ['✦', 'Customer Promotions', 'customer-promotions.php'],
        ['◈', 'Catering Operations', 'catering-operations.php'],
        ['◈', 'Catering Pipeline', 'catering-pipeline.php'],
        ['◇', 'Wholesale', 'wholesale-pipeline.php'],
        ['◎', 'Wholesale Customers', 'wholesale-accounts.php'],
      ],
    },
    {
      id: 'ai', label: 'AI & Knowledge', items: [
        ['✦', 'Owner Agent', 'workspace.php#owner'],
        ['✦', 'Agent Canvas', 'agent-canvas.php'],
        ['⌁', 'LLM API Keys', 'workspace.php#llm'],
        ['▤', 'Menu Knowledge', 'workspace.php#menu'],
      ],
    },
  ];

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[character]));

  function ensureTheme() {
    if (document.querySelector('link[data-admin-tech-theme]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'css/admin-tech-theme.css?v=20260915-tech1';
    link.dataset.adminTechTheme = '1';
    document.head.appendChild(link);
  }

  function readState() {
    const defaults = Object.fromEntries(groups.map((group) => [group.id, true]));
    try {
      const saved = JSON.parse(localStorage.getItem(STATE_KEY) || '{}');
      groups.forEach((group) => {
        if (typeof saved[group.id] === 'boolean') defaults[group.id] = saved[group.id];
      });
    } catch {}
    return defaults;
  }

  function writeState(state) {
    try { localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch {}
  }

  function currentAccount() {
    try {
      const session = JSON.parse(localStorage.getItem('restaurant-admin-session-v1') || '{}');
      const users = JSON.parse(localStorage.getItem('restaurant-admin-users-v1') || '[]');
      const user = users.find((item) => item?.id === session?.userId);
      if (!user) return null;
      const displayName = String(user.displayName || `${user.firstName || ''} ${user.lastName || ''}`.trim() || 'Account');
      const initials = String(user.initials || `${user.firstName?.[0] || ''}${user.lastName?.[0] || ''}` || 'A').toUpperCase().slice(0, 3);
      return {displayName, initials, role: String(user.role?.name || user.position || 'Account')};
    } catch { return null; }
  }

  function installStyles() {
    const style = document.createElement('style');
    style.dataset.universalAdminPageShell = '1';
    style.textContent = `
      :root{--uas-sidebar:244px;--uas-header:64px}
      body.gelato-universal-admin-page{padding-left:var(--uas-sidebar)!important;padding-top:var(--uas-header)!important;min-height:100vh!important}
      body.gelato-universal-admin-page>header.top,
      body.gelato-universal-admin-page>header.topbar,
      body.gelato-universal-admin-page>header.admin-top{display:none!important}
      .uas-header{position:fixed;z-index:10020;top:0;right:0;left:var(--uas-sidebar);height:var(--uas-header);display:flex;align-items:center;justify-content:space-between;gap:14px;padding:0 16px 0 20px;border-bottom:1px solid #dddcd5;background:rgba(255,255,255,.96);backdrop-filter:blur(14px);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#171815}
      .uas-title{min-width:0;display:flex;align-items:center;gap:11px}.uas-mobile{display:none;width:36px;height:36px;border:1px solid #dddcd5;border-radius:10px;background:#fff;font-size:17px}.uas-title-copy{min-width:0}.uas-title strong{display:block;font-size:15px;line-height:1.1}.uas-title small{display:block;margin-top:3px;color:#73766f;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:min(44vw,620px)}
      .uas-header-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px;min-width:0;overflow-x:auto;scrollbar-width:none}.uas-header-actions::-webkit-scrollbar{display:none}.uas-header-link,.uas-page-action{min-height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dddcd5;border-radius:10px;background:#fff;color:#171815;padding:7px 11px;text-decoration:none;font:800 10px/1 Inter,ui-sans-serif,system-ui;white-space:nowrap}.uas-header-link.dark{background:#171b1a;border-color:#171b1a;color:#fff}.uas-header-link.kds{background:#fff7f3;border-color:#efc6b9;color:#9a351f}.uas-header-actions>.btn,.uas-header-actions>button:not(.uas-account-button){min-height:36px!important;margin:0!important;white-space:nowrap}
      .uas-sidebar{position:fixed;z-index:10030;inset:0 auto 0 0;width:var(--uas-sidebar);display:flex;flex-direction:column;overflow:hidden;padding:13px 11px 9px;color:#fff;background:linear-gradient(180deg,#111514,#202622);border-right:1px solid rgba(255,255,255,.06);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
      .uas-brand{display:flex;align-items:center;gap:9px;padding:2px 7px 11px}.uas-logo{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:linear-gradient(145deg,#ff8a63,#d84626);box-shadow:0 8px 22px rgba(217,74,43,.25);font-size:18px;font-weight:900}.uas-brand h1{margin:0;font-size:14px;line-height:1.05}.uas-brand p{margin:2px 0 0;color:rgba(255,255,255,.52);font-size:9px}
      .uas-nav{min-height:0;overflow:auto;padding:2px 0 10px}.uas-section{border-top:1px solid rgba(255,255,255,.055)}.uas-section:first-child{border-top:0}.uas-section-toggle{appearance:none;width:100%;border:0;background:transparent;color:rgba(230,235,232,.62);display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 10px 7px;font:800 .58rem/1.2 Inter,ui-sans-serif,system-ui;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;text-align:left}.uas-section-toggle:hover{color:#fff}.uas-chevron{font-size:.78rem;color:rgba(230,235,232,.48);transition:transform .18s}.uas-section-toggle[aria-expanded="true"] .uas-chevron{transform:rotate(180deg)}.uas-items{display:grid;gap:1px;padding:0 0 6px}.uas-items[hidden]{display:none!important}.uas-link{width:100%;min-height:32px;display:flex;align-items:center;gap:7px;padding:6px 9px;border:1px solid transparent;border-radius:9px;color:rgba(255,255,255,.68);background:transparent;text-decoration:none;font-size:10px;font-weight:750;line-height:1.1}.uas-link:hover,.uas-link.active{color:#fff;border-color:rgba(255,255,255,.09);background:rgba(255,255,255,.075)}.uas-ico{width:19px;flex:0 0 19px;text-align:center;font-size:12px}.uas-foot{margin-top:auto;padding:8px 7px 2px;color:rgba(255,255,255,.4);font-size:8px;line-height:1.35}.uas-status{display:inline-block;width:6px;height:6px;margin-right:5px;border-radius:99px;background:#5bd08b;box-shadow:0 0 0 3px rgba(91,208,139,.12)}
      .uas-account{position:relative;flex:0 0 auto}.uas-account-button{min-height:38px;display:flex;align-items:center;gap:8px;border:1px solid #dddcd5;border-radius:11px;background:#fff;padding:5px 9px;cursor:pointer}.uas-avatar{width:27px;height:27px;display:grid;place-items:center;border-radius:8px;background:#d94a2b;color:#fff;font-size:9px;font-weight:900}.uas-account-copy{display:block;text-align:left}.uas-account-copy strong{display:block;font-size:9px}.uas-account-copy small{display:block;color:#73766f;font-size:7px;margin-top:2px}.uas-account-menu{position:absolute;top:calc(100% + 8px);right:0;width:218px;padding:7px;border:1px solid #dddcd5;border-radius:13px;background:#fff;box-shadow:0 18px 45px rgba(20,22,18,.16)}.uas-account-menu[hidden]{display:none!important}.uas-account-menu a{display:flex;align-items:center;gap:8px;padding:9px;border-radius:8px;color:#171815;text-decoration:none;font-size:10px;font-weight:750}.uas-account-menu a:hover{background:#f4f3ef}.uas-account-menu hr{border:0;border-top:1px solid #ecebe6;margin:4px 0}.uas-account-menu .danger{color:#a62a24}
      @media(max-width:${MOBILE_BREAKPOINT}px){body.gelato-universal-admin-page{padding-left:0!important}.uas-header{left:0;padding-left:10px}.uas-mobile{display:grid;place-items:center}.uas-title small{display:none}.uas-sidebar{transform:translateX(-102%);transition:transform .2s ease;box-shadow:18px 0 50px rgba(0,0,0,.22)}.uas-sidebar.open{transform:translateX(0)}.uas-account-copy{display:none}.uas-header-link{padding:7px 9px}}
      @media(max-width:560px){.uas-header-actions .uas-header-link:not(.dark):not(.kds){display:none}.uas-title strong{font-size:12px}.uas-header{gap:7px}}
    `;
    document.head.appendChild(style);
  }

  function renderSidebar() {
    const state = readState();
    const sidebar = document.createElement('aside');
    sidebar.className = 'uas-sidebar';
    sidebar.id = 'uasSidebar';
    sidebar.innerHTML = `<div class="uas-brand"><div class="uas-logo">G</div><div><h1>Gelato</h1><p>Restaurant operating system</p></div></div><nav class="uas-nav"></nav><div class="uas-foot"><span class="uas-status"></span>System agent online<br>Operations · Sales · Training · Admin</div>`;
    const nav = sidebar.querySelector('.uas-nav');
    groups.forEach((group) => {
      const section = document.createElement('section');
      section.className = 'uas-section';
      section.dataset.uasGroup = group.id;
      const itemsId = `uas-items-${group.id}`;
      section.innerHTML = `<button class="uas-section-toggle" type="button" aria-expanded="${state[group.id] !== false ? 'true' : 'false'}" aria-controls="${itemsId}"><span>${esc(group.label)}</span><span class="uas-chevron">⌃</span></button><div class="uas-items" id="${itemsId}" ${state[group.id] === false ? 'hidden' : ''}></div>`;
      const items = section.querySelector('.uas-items');
      group.items.forEach(([icon, label, href]) => {
        const link = document.createElement('a');
        link.className = 'uas-link';
        link.href = href;
        link.innerHTML = `<span class="uas-ico">${esc(icon)}</span><span>${esc(label)}</span>`;
        const hrefPath = href.split('#')[0].split('/').pop().toLowerCase();
        if (hrefPath === path) link.classList.add('active');
        items.appendChild(link);
      });
      section.querySelector('.uas-section-toggle').addEventListener('click', (event) => {
        const button = event.currentTarget;
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        items.hidden = !open;
        const next = readState();
        next[group.id] = open;
        writeState(next);
      });
      nav.appendChild(section);
    });
    document.body.prepend(sidebar);
    return sidebar;
  }

  function duplicateDestination(href) {
    if (!href) return false;
    const normalized = href.split('?')[0].split('#')[0].split('/').pop().toLowerCase();
    return new Set([
      'workspace.php','index.php','admin.php','pos.php','kds.php','online-orders-admin.php','operations.php','catering-operations.php','catering-pipeline.php','sales-intelligence.php','sales-cost-intelligence.php','customer-promotions.php','customer-crm.php','locations-admin.php','recipes.php','prep-intelligence.php','floor-planner.php','floor-planner-v2.php','equipment.php','purchasing.php','scheduling.php','timeclock.php','public-site-settings.php','wholesale-pipeline.php','wholesale-accounts.php','agent-canvas.php'
    ]).has(normalized);
  }

  function movePageActions(header, target) {
    if (!header) return;
    const actionBox = header.querySelector('.actions,.top-actions,.admin-top-actions');
    if (!actionBox) return;
    Array.from(actionBox.children).forEach((node) => {
      if (node.matches('a') && duplicateDestination(node.getAttribute('href') || '')) {
        node.remove();
        return;
      }
      if (node.matches('button,a')) {
        node.classList.add('uas-page-action');
        target.appendChild(node);
      }
    });
  }

  function renderHeader(sidebar) {
    const account = currentAccount();
    const header = document.createElement('header');
    header.className = 'uas-header';
    header.id = 'uasHeader';
    header.innerHTML = `<div class="uas-title"><button class="uas-mobile" type="button" aria-label="Open navigation">☰</button><div class="uas-title-copy"><strong>${esc(title)}</strong><small>${esc(subtitle)}</small></div></div><div class="uas-header-actions"><a class="uas-header-link dark" href="pos.php">POS</a><a class="uas-header-link kds" href="kds.php">KDS</a></div>`;
    const actions = header.querySelector('.uas-header-actions');
    const localHeader = document.querySelector('body > header.top, body > header.topbar, body > header.admin-top');
    movePageActions(localHeader, actions);

    const accountWrap = document.createElement('div');
    accountWrap.className = 'uas-account';
    accountWrap.innerHTML = `<button class="uas-account-button" type="button" aria-haspopup="true" aria-expanded="false"><span class="uas-avatar">${esc(account?.initials || 'A')}</span><span class="uas-account-copy"><strong>${esc(account?.displayName || 'Account')}</strong><small>${esc(account?.role || 'Restaurant access')}</small></span><span>⌄</span></button><div class="uas-account-menu" hidden><a href="workspace.php#profile">◉ My profile</a><a href="workspace.php#notifications">♢ Notifications</a><a href="admin.php">▦ Restaurant Admin</a><a href="index.php" target="_blank">◇ Public website ↗</a><hr><a class="danger" href="logout.php">↪ Log out</a></div>`;
    actions.appendChild(accountWrap);

    const button = accountWrap.querySelector('.uas-account-button');
    const menu = accountWrap.querySelector('.uas-account-menu');
    button.addEventListener('click', (event) => {
      event.stopPropagation();
      const open = menu.hidden;
      menu.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', (event) => {
      if (!accountWrap.contains(event.target)) {
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      }
    });
    header.querySelector('.uas-mobile').addEventListener('click', () => sidebar.classList.toggle('open'));
    sidebar.addEventListener('click', (event) => {
      if (window.innerWidth <= MOBILE_BREAKPOINT && event.target.closest('a')) sidebar.classList.remove('open');
    });
    document.body.prepend(header);
  }

  function install() {
    installStyles();
    ensureTheme();
    document.body.classList.add('gelato-universal-admin-page');
    const sidebar = renderSidebar();
    renderHeader(sidebar);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once: true});
  else install();
})();