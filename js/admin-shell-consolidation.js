(() => {
  'use strict';

  if (window.GelatoAdminShellConsolidated) return;
  window.GelatoAdminShellConsolidated = true;

  const $ = (selector, root = document) => root.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[character]));

  const ADMIN_NAV_STATE_KEY = 'gelato-admin-nav-accordion-v1';
  const ADMIN_NAV_CATEGORIES = [
    {id: 'team', label: 'Team & Hiring'},
    {id: 'website', label: 'Website & Locations'},
    {id: 'operations', label: 'Operations'},
    {id: 'sales', label: 'Sales & Events'},
    {id: 'ai', label: 'AI & Knowledge'},
  ];

  let organizingAdminNav = false;
  let adminNavScheduled = false;

  function installStyles() {
    if ($('style[data-admin-shell-consolidation]')) return;
    const style = document.createElement('style');
    style.dataset.adminShellConsolidation = '1';
    style.textContent = `
      .sidebar .role-card{display:none!important}
      #page-owner>.page-head{display:none!important}
      #page-owner .owner-agent-dialogue,
      #page-owner .owner-agent-composer{display:none!important}
      #page-owner .admin-grid,
      .admin-page .admin-grid,
      .admin-page .admin-grid.equal{grid-template-columns:minmax(0,1fr)!important}
      #page-owner .owner-briefing,
      #page-owner .admin-stack,
      #page-owner .admin-stack>.card{width:100%;max-width:none}
      body.gelato-global-agent-ready #page-workspace>.workspace>.composer{display:none!important}
      #page-workspace .chat-canvas{padding-bottom:105px}
      .standard-page.admin-page{padding-bottom:105px}
      [data-nav-group="admin"].admin-nav-accordion-ready{padding-bottom:10px}
      .admin-nav-accordion{display:block;border-top:1px solid rgba(255,255,255,.055)}
      .admin-nav-accordion:first-child{border-top:0}
      .admin-nav-section-toggle{appearance:none;width:100%;border:0;background:transparent;color:rgba(230,235,232,.62);display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 10px 7px;font-family:inherit;font-size:.58rem;font-weight:800;line-height:1.2;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;text-align:left}
      .admin-nav-section-toggle:hover{color:#fff}
      .admin-nav-section-toggle:focus-visible{outline:2px solid rgba(255,255,255,.34);outline-offset:-2px;border-radius:7px}
      .admin-nav-chevron{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;font-size:.78rem;transition:transform .18s ease;color:rgba(230,235,232,.48)}
      .admin-nav-section-toggle[aria-expanded="true"] .admin-nav-chevron{transform:rotate(180deg)}
      .admin-nav-section-items{display:grid;gap:1px;padding:0 0 6px}
      .admin-nav-section-items[hidden]{display:none!important}
      .admin-nav-section-items>.nav-btn,
      .admin-nav-section-items>.nav-item{width:100%;box-sizing:border-box}
      .top-actions .gelato-header-shortcut{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 11px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);font-size:10px;font-weight:900;text-decoration:none;white-space:nowrap}
      .top-actions .gelato-header-shortcut.pos{background:var(--dark);border-color:var(--dark);color:#fff}
      .top-actions .gelato-header-shortcut.kds{background:#fff7f3;border-color:#efc6b9;color:#9a351f}
      @media(max-width:840px){#page-workspace .chat-canvas{padding-bottom:96px}.standard-page.admin-page{padding-bottom:96px}.top-actions .gelato-header-shortcut{display:none}}
    `;
    document.head.appendChild(style);
  }

  function readAdminNavState() {
    const state = Object.fromEntries(ADMIN_NAV_CATEGORIES.map((category) => [category.id, true]));
    try {
      const stored = JSON.parse(localStorage.getItem(ADMIN_NAV_STATE_KEY) || '{}');
      if (stored && typeof stored === 'object') {
        ADMIN_NAV_CATEGORIES.forEach((category) => {
          if (typeof stored[category.id] === 'boolean') state[category.id] = stored[category.id];
        });
      }
    } catch {
      // Storage can be unavailable in hardened/private browser contexts. Keep all sections open.
    }
    return state;
  }

  function writeAdminNavState(state) {
    try {
      localStorage.setItem(ADMIN_NAV_STATE_KEY, JSON.stringify(state));
    } catch {
      // Navigation still works without persistence when storage is unavailable.
    }
  }

  function adminNavSignature(node) {
    if (!node) return '';
    return [
      node.dataset?.nav || '',
      node.dataset?.employeeHomeLink ? 'employee home' : '',
      node.dataset?.locationsNav ? 'locations' : '',
      node.dataset?.onlineOrdersNav ? 'online orders' : '',
      node.dataset?.operationsNav ? 'operations' : '',
      node.dataset?.floorPlannerNav ? 'floor planner' : '',
      node.dataset?.equipmentNav ? 'equipment catalog' : '',
      node.dataset?.wholesaleNav ? 'wholesale' : '',
      node.dataset?.wholesaleAccountsNav ? 'wholesale customers' : '',
      node.dataset?.cateringNav ? 'catering pipeline' : '',
      node.dataset?.cateringOperationsNav ? 'catering operations' : '',
      node.dataset?.salesIntelligenceNav ? 'sales intelligence' : '',
      node.dataset?.customerCrmNav ? 'customer crm' : '',
      node.dataset?.customerPromotionsNav ? 'customer promotions' : '',
      node.dataset?.recipesNav ? 'recipe library builder' : '',
      node.dataset?.purchasingNav ? 'purchasing receiving' : '',
      node.dataset?.schedulingNav ? 'staff scheduling' : '',
      node.dataset?.timeclockNav ? 'time clock attendance' : '',
      node.dataset?.agentCanvasNav ? 'agent canvas' : '',
      node.getAttribute?.('href') || '',
      node.textContent || '',
    ].join(' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function adminNavCategory(node) {
    const signature = adminNavSignature(node);
    if (/owner agent|agent canvas|public ai agent|knowledge center|llm/.test(signature)) return 'ai';
    if (/sales intelligence|customer crm|customer promotions|wholesale|catering/.test(signature)) return 'sales';
    if (/landing page|public site|locations?|brand settings?/.test(signature)) return 'website';
    if (/employee home|user accounts?|account types?|resume|jobs?|form builder|staff scheduling|my schedule|time clock|my time/.test(signature)) return 'team';
    return 'operations';
  }

  function adminNavRank(node) {
    const signature = adminNavSignature(node);
    const rules = [
      [/employee home/, 10], [/user accounts?/, 20], [/account types?/, 30], [/resume/, 40], [/jobs?/, 50], [/form builder/, 60], [/staff scheduling|my schedule/, 70], [/time clock|my time/, 80],
      [/landing page/, 110], [/public site/, 120], [/locations?/, 130], [/brand settings?/, 140],
      [/online orders/, 200], [/\boperations\b/, 210], [/floor planner/, 220], [/equipment catalog/, 230], [/recipe library/, 240], [/purchasing|receiving/, 250],
      [/sales intelligence/, 300], [/customer crm/, 310], [/customer promotions/, 320], [/catering operations/, 330], [/catering pipeline/, 340], [/wholesale(?!.*customer)/, 350], [/wholesale customers?/, 360],
      [/owner agent/, 410], [/agent canvas/, 420], [/public ai agent/, 430], [/knowledge center/, 440], [/llm/, 450],
    ];
    for (const [pattern, rank] of rules) {
      if (pattern.test(signature)) return rank;
    }
    return 999;
  }

  function setAdminNavSectionOpen(section, open, persist = true) {
    const button = $('.admin-nav-section-toggle', section);
    const items = $('.admin-nav-section-items', section);
    if (!button || !items) return;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    items.hidden = !open;
    section.classList.toggle('is-open', open);
    if (!persist) return;
    const state = readAdminNavState();
    state[section.dataset.adminNavSection] = open;
    writeAdminNavState(state);
  }

  function ensureAdminNavSections(adminNav) {
    const state = readAdminNavState();
    ADMIN_NAV_CATEGORIES.forEach((category) => {
      if (adminNav.querySelector(`:scope > [data-admin-nav-section="${category.id}"]`)) return;
      const section = document.createElement('section');
      section.className = 'admin-nav-accordion';
      section.dataset.adminNavSection = category.id;
      const itemsId = `admin-nav-section-${category.id}`;
      section.innerHTML = `
        <button class="admin-nav-section-toggle" type="button" aria-expanded="true" aria-controls="${itemsId}">
          <span>${category.label}</span><span class="admin-nav-chevron" aria-hidden="true">⌃</span>
        </button>
        <div class="admin-nav-section-items" id="${itemsId}"></div>`;
      const toggle = $('.admin-nav-section-toggle', section);
      toggle.addEventListener('click', () => {
        setAdminNavSectionOpen(section, toggle.getAttribute('aria-expanded') !== 'true');
      });
      adminNav.appendChild(section);
      setAdminNavSectionOpen(section, state[category.id] !== false, false);
    });
    adminNav.classList.add('admin-nav-accordion-ready');
  }

  function organizeAdminNavigation() {
    if (organizingAdminNav) return;
    const adminNav = $('[data-nav-group="admin"]');
    if (!adminNav) return;
    organizingAdminNav = true;
    try {
      ensureAdminNavSections(adminNav);
      const looseItems = Array.from(adminNav.children).filter((child) => !child.matches('[data-admin-nav-section]'));
      looseItems.forEach((item) => {
        const category = adminNavCategory(item);
        const target = $(`[data-admin-nav-section="${category}"] .admin-nav-section-items`, adminNav);
        if (target) target.appendChild(item);
      });
      ADMIN_NAV_CATEGORIES.forEach((category) => {
        const target = $(`[data-admin-nav-section="${category.id}"] .admin-nav-section-items`, adminNav);
        if (!target) return;
        Array.from(target.children)
          .sort((left, right) => adminNavRank(left) - adminNavRank(right))
          .forEach((item) => target.appendChild(item));
      });
    } finally {
      organizingAdminNav = false;
    }
  }

  function scheduleAdminNavOrganization() {
    if (adminNavScheduled) return;
    adminNavScheduled = true;
    queueMicrotask(() => {
      adminNavScheduled = false;
      organizeAdminNavigation();
    });
  }

  function installAdminNavAccordion() {
    const adminNav = $('[data-nav-group="admin"]');
    if (!adminNav) return;
    organizeAdminNavigation();
    if (adminNav.dataset.adminNavObserver === '1') return;
    adminNav.dataset.adminNavObserver = '1';
    const observer = new MutationObserver((mutations) => {
      if (organizingAdminNav) return;
      if (mutations.some((mutation) => mutation.type === 'childList')) scheduleAdminNavOrganization();
    });
    observer.observe(adminNav, {childList: true});
  }

  function removeEmployeeHomeHeaderLink() {
    document.querySelectorAll('header [data-employee-home-link], .topbar [data-employee-home-link], .top [data-employee-home-link]')
      .forEach((node) => node.remove());
  }

  function normalizeOperationsNavigation() {
    const adminNav = $('[data-nav-group="admin"]');
    if (!adminNav) return;
    const wholesale = $('[data-wholesale-nav]', adminNav);
    const catering = $('[data-catering-nav]', adminNav);
    const recipes = $('[data-recipes-nav]', adminNav);
    if (wholesale) wholesale.innerHTML = '<span class="nav-ico">◇</span>Wholesale';
    if (catering) catering.innerHTML = '<span class="nav-ico">◈</span>Catering Pipeline';
    if (recipes) recipes.innerHTML = '<span class="nav-ico">▤</span>Recipe Library + Builder';
    scheduleAdminNavOrganization();
  }

  function installHeaderShortcuts() {
    const actions = $('.topbar .top-actions');
    if (!actions) return;
    const notifications = $('.notification-wrap', actions);
    const before = notifications || actions.firstChild;
    const can = (permission) => !window.RestaurantAuth || window.RestaurantAuth.has(permission);
    if (can('pos.use') && !$('#gelatoHeaderPos')) {
      const link = document.createElement('a');
      link.id = 'gelatoHeaderPos';
      link.className = 'gelato-header-shortcut pos';
      link.href = 'pos.php';
      link.textContent = 'POS';
      actions.insertBefore(link, before);
    }
    if (can('kds.view') && !$('#gelatoHeaderKds')) {
      const link = document.createElement('a');
      link.id = 'gelatoHeaderKds';
      link.className = 'gelato-header-shortcut kds';
      link.href = 'kds.php';
      link.textContent = 'KDS';
      const pos = $('#gelatoHeaderPos');
      if (pos?.nextSibling) actions.insertBefore(link, pos.nextSibling);
      else if (pos) actions.appendChild(link);
      else actions.insertBefore(link, before);
    }
  }

  function cleanProfileMenu() {
    const list = $('.profile-menu-list');
    if (!list) return;

    list.querySelectorAll('a[href="landing.html"],a[href="apply.html"]').forEach((node) => node.remove());
    const adminLink = $('#restaurantAdminControlLink', list);
    const settings = $('#profileSettingsLink', list);
    if (adminLink && settings) settings.remove();

    if (!list.querySelector('a[data-public-website-link]')) {
      const anchor = document.createElement('a');
      anchor.href = 'index.php';
      anchor.target = '_blank';
      anchor.dataset.publicWebsiteLink = '1';
      anchor.textContent = '◇ Public website ↗';
      const logout = $('#logoutButton', list);
      const separator = document.createElement('hr');
      if (logout) {
        const existingSeparator = logout.previousElementSibling?.tagName === 'HR' ? logout.previousElementSibling : null;
        list.insertBefore(anchor, existingSeparator || logout);
        if (!existingSeparator) list.insertBefore(separator, logout);
      } else list.appendChild(anchor);
    }

    const seen = new Set();
    list.querySelectorAll('a,button').forEach((node) => {
      const key = node.matches('a')
        ? `href:${(node.getAttribute('href') || '').replace(/^\.\//, '')}`
        : node.dataset.nav
          ? `nav:${node.dataset.nav}`
          : node.id
            ? `id:${node.id}`
            : `text:${node.textContent.trim().toLowerCase()}`;
      if (seen.has(key)) node.remove();
      else seen.add(key);
    });
  }

  function watchProfileMenu() {
    cleanProfileMenu();
    const list = $('.profile-menu-list');
    if (!list || list.dataset.shellMenuObserver === '1') return;
    list.dataset.shellMenuObserver = '1';
    const observer = new MutationObserver(() => cleanProfileMenu());
    observer.observe(list, {childList: true, subtree: false});
  }

  function appendCanvasMessage(role, text) {
    const messages = document.getElementById('messages');
    if (!messages || !String(text || '').trim()) return;
    const row = document.createElement('div');
    row.className = `message ${role}`;
    const safe = esc(String(text).trim()).replace(/\n/g, '<br>');
    row.innerHTML = role === 'user'
      ? `<div class="bubble"><p>${safe}</p></div><div class="avatar">You</div>`
      : `<div class="avatar">A</div><div class="bubble"><p>${safe}</p></div>`;
    messages.appendChild(row);
    document.getElementById('agentHero')?.classList.add('hidden');
    requestAnimationFrame(() => row.scrollIntoView({behavior: 'smooth', block: 'end'}));
  }

  function sendToGuidedTraining(text) {
    const session = window.RestaurantGuidedAgent?.current?.();
    if (!session?.active) return false;
    const localInput = document.getElementById('chatInput');
    const localSend = document.getElementById('sendChat');
    if (!localInput || !localSend) return false;
    localInput.value = text;
    const globalInput = document.getElementById('gaInput');
    if (globalInput) globalInput.value = '';
    localSend.click();
    return true;
  }

  function installGlobalAgentCanvasBridge() {
    let lastText = '';
    let lastAt = 0;
    const readInput = () => String(document.getElementById('gaInput')?.value || '').trim();
    const mirrorUser = (text) => {
      if (!text) return;
      const currentAt = Date.now();
      if (text === lastText && currentAt - lastAt < 800) return;
      lastText = text;
      lastAt = currentAt;
      appendCanvasMessage('user', text);
    };

    document.addEventListener('click', (event) => {
      if (!event.target.closest('#gaSend')) return;
      const text = readInput();
      if (!text) return;
      if (sendToGuidedTraining(text)) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      mirrorUser(text);
    }, true);

    document.addEventListener('keydown', (event) => {
      if (event.target?.id !== 'gaInput' || event.key !== 'Enter' || event.shiftKey) return;
      const text = readInput();
      if (!text) return;
      if (sendToGuidedTraining(text)) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      mirrorUser(text);
    }, true);

    window.addEventListener('gelato-agent-response', (event) => {
      const answer = event.detail?.answer;
      if (answer) appendCanvasMessage('agent', answer);
    });
  }

  function clarifyMainAgentCanvas() {
    const hero = document.getElementById('agentHero');
    if (!hero) return;
    const eyebrow = $('.eyebrow', hero);
    const heading = $('h3', hero);
    const copy = $('p:not(.eyebrow)', hero);
    if (eyebrow) eyebrow.textContent = 'Restaurant Agent · shared conversation canvas';
    if (heading) heading.textContent = 'One Agent conversation across the restaurant workspace.';
    if (copy) copy.textContent = 'Use the persistent Agent bar at the bottom of the workspace. Questions and Agent responses are mirrored here so training, operations, catering, wholesale, equipment, and owner work share one conversation surface.';
  }

  function setGlobalAgentReady() {
    document.body.classList.add('gelato-global-agent-ready');
    clarifyMainAgentCanvas();
  }

  function install() {
    installStyles();
    removeEmployeeHomeHeaderLink();
    normalizeOperationsNavigation();
    installAdminNavAccordion();
    installHeaderShortcuts();
    watchProfileMenu();
    installGlobalAgentCanvasBridge();
    window.addEventListener('gelato-agent-ready', setGlobalAgentReady, {once: true});
    if (document.getElementById('gelato-agent-bar')) setGlobalAgentReady();
    queueMicrotask(() => {
      removeEmployeeHomeHeaderLink();
      normalizeOperationsNavigation();
      organizeAdminNavigation();
      installHeaderShortcuts();
      cleanProfileMenu();
    });
    requestAnimationFrame(() => {
      removeEmployeeHomeHeaderLink();
      normalizeOperationsNavigation();
      organizeAdminNavigation();
      installHeaderShortcuts();
      cleanProfileMenu();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once: true});
  else install();
})();