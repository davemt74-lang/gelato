(() => {
  'use strict';

  if (window.GelatoUniversalAdminPageShell) return;
  window.GelatoUniversalAdminPageShell = true;

  const STATE_KEY = 'gelato-admin-nav-accordion-v1';
  const MOBILE_BREAKPOINT = 900;
  const path = (location.pathname.split('/').pop() || 'workspace.php').toLowerCase();
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[character]));

  async function loadShell() {
    const response = await fetch(`api/admin-shell.php?page=${encodeURIComponent(path)}`, {
      headers: {Accept: 'application/json'},
      cache: 'no-store',
      credentials: 'same-origin',
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok || !payload.shell) throw new Error(payload.message || 'Admin shell could not be loaded.');
    return payload.shell;
  }

  function ensureTheme() {
    if (document.querySelector('link[data-admin-tech-theme]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'css/admin-tech-theme.css?v=20260915-tech1';
    link.dataset.adminTechTheme = '1';
    document.head.appendChild(link);
  }

  function readState(groups) {
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

  function installStyles() {
    if (document.querySelector('style[data-universal-admin-page-shell]')) return;
    const style = document.createElement('style');
    style.dataset.universalAdminPageShell = '1';
    style.textContent = `
      :root{--uas-sidebar:244px;--uas-header:64px}
      body.gelato-universal-admin-page{padding-left:var(--uas-sidebar)!important;padding-top:var(--uas-header)!important;min-height:100vh!important;background:#fff!important}
      body.gelato-universal-admin-page>header.top,
      body.gelato-universal-admin-page>header.topbar,
      body.gelato-universal-admin-page>header.admin-top{display:none!important}
      .uas-header{position:fixed;z-index:10020;top:0;right:0;left:var(--uas-sidebar);height:var(--uas-header);display:flex;align-items:center;justify-content:space-between;gap:14px;padding:0 16px 0 20px;border-bottom:1px solid #e6e6e1;background:rgba(255,255,255,.97);backdrop-filter:blur(14px);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#171815}
      .uas-title{min-width:0;display:flex;align-items:center;gap:11px}.uas-mobile{display:none;width:36px;height:36px;border:1px solid #deded8;border-radius:10px;background:#fff;font-size:17px}.uas-title-copy{min-width:0}.uas-title strong{display:block;font-size:15px;line-height:1.1}.uas-title small{display:block;margin-top:3px;color:#73766f;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:min(44vw,620px)}
      .uas-header-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px;min-width:0;overflow-x:auto;scrollbar-width:none}.uas-header-actions::-webkit-scrollbar{display:none}.uas-header-link,.uas-page-action{min-height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #deded8;border-radius:10px;background:#fff;color:#171815;padding:7px 11px;text-decoration:none;font:800 10px/1 Inter,ui-sans-serif,system-ui;white-space:nowrap}.uas-header-link.dark{background:#171b1a;border-color:#171b1a;color:#fff}.uas-header-link.kds{background:#fff7f3;border-color:#efc6b9;color:#9a351f}.uas-header-actions>.btn,.uas-header-actions>button:not(.uas-account-button){min-height:36px!important;margin:0!important;white-space:nowrap}
      .uas-sidebar{position:fixed;z-index:10030;inset:0 auto 0 0;width:var(--uas-sidebar);display:flex;flex-direction:column;overflow:hidden;padding:13px 11px 9px;color:#171815;background:#f7f7f5;border-right:1px solid #e6e6e1;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
      .uas-brand{display:flex;align-items:center;gap:9px;padding:2px 7px 11px}.uas-logo{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:#171b1a;color:#fff;font-size:18px;font-weight:900}.uas-brand h1{margin:0;font-size:14px;line-height:1.05}.uas-brand p{margin:2px 0 0;color:#777b76;font-size:9px}
      .uas-nav{min-height:0;overflow:auto;padding:2px 0 10px}.uas-section{border-top:1px solid #e8e8e4}.uas-section:first-child{border-top:0}.uas-section-toggle{appearance:none;width:100%;border:0;background:transparent;color:#737873;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 10px 7px;font:800 .58rem/1.2 Inter,ui-sans-serif,system-ui;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;text-align:left}.uas-section-toggle:hover{color:#171815}.uas-chevron{font-size:.78rem;color:#9a9e99;transition:transform .18s}.uas-section-toggle[aria-expanded="true"] .uas-chevron{transform:rotate(180deg)}.uas-items{display:grid;gap:1px;padding:0 0 6px}.uas-items[hidden]{display:none!important}.uas-link{width:100%;min-height:32px;display:flex;align-items:center;gap:7px;padding:6px 9px;border:1px solid transparent;border-radius:9px;color:#555a55;background:transparent;text-decoration:none;font-size:10px;font-weight:750;line-height:1.1}.uas-link:hover,.uas-link.active{color:#171815;border-color:#deded8;background:#fff;box-shadow:0 4px 14px rgba(20,22,18,.04)}.uas-ico{width:19px;flex:0 0 19px;text-align:center;font-size:12px}.uas-foot{margin-top:auto;padding:8px 7px 2px;color:#878b86;font-size:8px;line-height:1.35}.uas-status{display:inline-block;width:6px;height:6px;margin-right:5px;border-radius:99px;background:#2e9f68;box-shadow:0 0 0 3px rgba(46,159,104,.10)}
      .uas-account{position:relative;flex:0 0 auto}.uas-account-button{min-height:38px;display:flex;align-items:center;gap:8px;border:1px solid #deded8;border-radius:11px;background:#fff;padding:5px 9px;cursor:pointer}.uas-avatar{width:27px;height:27px;display:grid;place-items:center;border-radius:8px;background:#d94a2b;color:#fff;font-size:9px;font-weight:900}.uas-account-copy{display:block;text-align:left}.uas-account-copy strong{display:block;font-size:9px}.uas-account-copy small{display:block;color:#73766f;font-size:7px;margin-top:2px}.uas-account-menu{position:absolute;top:calc(100% + 8px);right:0;width:218px;padding:7px;border:1px solid #deded8;border-radius:13px;background:#fff;box-shadow:0 18px 45px rgba(20,22,18,.16)}.uas-account-menu[hidden]{display:none!important}.uas-account-menu a{display:flex;align-items:center;gap:8px;padding:9px;border-radius:8px;color:#171815;text-decoration:none;font-size:10px;font-weight:750}.uas-account-menu a:hover{background:#f4f3ef}.uas-account-menu hr{border:0;border-top:1px solid #ecebe6;margin:4px 0}.uas-account-menu .danger{color:#a62a24}
      @media(max-width:${MOBILE_BREAKPOINT}px){body.gelato-universal-admin-page{padding-left:0!important}.uas-header{left:0;padding-left:10px}.uas-mobile{display:grid;place-items:center}.uas-title small{display:none}.uas-sidebar{transform:translateX(-102%);transition:transform .2s ease;box-shadow:18px 0 50px rgba(0,0,0,.18)}.uas-sidebar.open{transform:translateX(0)}.uas-account-copy{display:none}.uas-header-link{padding:7px 9px}}
      @media(max-width:560px){.uas-header-actions .uas-header-link:not(.dark):not(.kds){display:none}.uas-title strong{font-size:12px}.uas-header{gap:7px}}
    `;
    document.head.appendChild(style);
  }

  function renderSidebar(shell) {
    const groups = Array.isArray(shell.navigation) ? shell.navigation : [];
    const state = readState(groups);
    const sidebar = document.createElement('aside');
    sidebar.className = 'uas-sidebar';
    sidebar.id = 'uasSidebar';
    sidebar.innerHTML = '<div class="uas-brand"><div class="uas-logo">G</div><div><h1>Gelato</h1><p>Restaurant operating system</p></div></div><nav class="uas-nav"></nav><div class="uas-foot"><span class="uas-status"></span>System agent online<br>Permission-scoped restaurant access</div>';
    const nav = sidebar.querySelector('.uas-nav');

    groups.forEach((group) => {
      const section = document.createElement('section');
      section.className = 'uas-section';
      section.dataset.uasGroup = group.id;
      const itemsId = `uas-items-${group.id}`;
      const open = state[group.id] !== false;
      section.innerHTML = `<button class="uas-section-toggle" type="button" aria-expanded="${open ? 'true' : 'false'}" aria-controls="${itemsId}"><span>${esc(group.label)}</span><span class="uas-chevron">⌃</span></button><div class="uas-items" id="${itemsId}" ${open ? '' : 'hidden'}></div>`;
      const items = section.querySelector('.uas-items');
      (group.items || []).forEach((item) => {
        const link = document.createElement('a');
        link.className = 'uas-link';
        link.href = item.href;
        link.innerHTML = `<span class="uas-ico">${esc(item.icon)}</span><span>${esc(item.label)}</span>`;
        const hrefPath = String(item.href || '').split('#')[0].split('?')[0].split('/').pop().toLowerCase();
        if (hrefPath === path) link.classList.add('active');
        items.appendChild(link);
      });
      section.querySelector('.uas-section-toggle').addEventListener('click', (event) => {
        const button = event.currentTarget;
        const nextOpen = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
        items.hidden = !nextOpen;
        const next = readState(groups);
        next[group.id] = nextOpen;
        writeState(next);
      });
      nav.appendChild(section);
    });

    document.body.prepend(sidebar);
    return sidebar;
  }

  function routePath(href) {
    if (!href) return '';
    return String(href).split('?')[0].split('#')[0].split('/').pop().toLowerCase();
  }

  function canonicalDestinations(shell) {
    const destinations = new Set();
    (shell.headerLinks || []).forEach((item) => destinations.add(routePath(item.href)));
    (shell.navigation || []).forEach((group) => (group.items || []).forEach((item) => destinations.add(routePath(item.href))));
    ['workspace.php', 'index.php', 'admin.php'].forEach((item) => destinations.add(item));
    return destinations;
  }

  function movePageActions(shell, target) {
    const localHeader = document.querySelector('body > header.top, body > header.topbar, body > header.admin-top');
    if (!localHeader) return;
    const actionBox = localHeader.querySelector('.actions,.top-actions,.admin-top-actions');
    if (!actionBox) return;
    const destinations = canonicalDestinations(shell);
    Array.from(actionBox.children).forEach((node) => {
      if (!node.matches('button,a')) return;
      if (node.matches('a') && destinations.has(routePath(node.getAttribute('href') || ''))) return;
      node.classList.add('uas-page-action');
      target.appendChild(node);
    });
  }

  function renderAccount(shell, target) {
    const account = shell.account || {};
    const wrap = document.createElement('div');
    wrap.className = 'uas-account';
    const menuHtml = (account.menu || []).map((item) => {
      if (item.separator) return '<hr>';
      const targetAttr = item.target ? ` target="${esc(item.target)}" rel="noopener"` : '';
      return `<a${item.danger ? ' class="danger"' : ''} href="${esc(item.href)}"${targetAttr}>${esc(item.icon || '')} ${esc(item.label)}</a>`;
    }).join('');
    wrap.innerHTML = `<button class="uas-account-button" type="button" aria-haspopup="true" aria-expanded="false"><span class="uas-avatar">${esc(account.initials || 'A')}</span><span class="uas-account-copy"><strong>${esc(account.displayName || 'Account')}</strong><small>${esc(account.role || 'Restaurant access')}</small></span><span>⌄</span></button><div class="uas-account-menu" hidden>${menuHtml}</div>`;
    target.appendChild(wrap);

    const button = wrap.querySelector('.uas-account-button');
    const menu = wrap.querySelector('.uas-account-menu');
    button.addEventListener('click', (event) => {
      event.stopPropagation();
      const open = menu.hidden;
      menu.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', (event) => {
      if (!wrap.contains(event.target)) {
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function renderHeader(shell, sidebar) {
    const header = document.createElement('header');
    header.className = 'uas-header';
    header.id = 'uasHeader';
    header.innerHTML = `<div class="uas-title"><button class="uas-mobile" type="button" aria-label="Open navigation">☰</button><div class="uas-title-copy"><strong>${esc(shell.title || 'Gelato Admin')}</strong><small>${esc(shell.subtitle || 'Restaurant administration')}</small></div></div><div class="uas-header-actions"></div>`;
    const actions = header.querySelector('.uas-header-actions');
    (shell.headerLinks || []).forEach((item) => {
      const link = document.createElement('a');
      link.className = `uas-header-link ${item.style || ''}`.trim();
      link.href = item.href;
      link.textContent = item.label;
      actions.appendChild(link);
    });
    movePageActions(shell, actions);
    renderAccount(shell, actions);
    header.querySelector('.uas-mobile').addEventListener('click', () => sidebar.classList.toggle('open'));
    sidebar.addEventListener('click', (event) => {
      if (window.innerWidth <= MOBILE_BREAKPOINT && event.target.closest('a')) sidebar.classList.remove('open');
    });
    document.body.prepend(header);
  }

  async function install() {
    try {
      const shell = await loadShell();
      window.GELATO_ADMIN_SHELL = shell;
      window.dispatchEvent(new CustomEvent('gelato-admin-shell-config', {detail: shell}));
      if (shell.type !== 'standard') return;
      ensureTheme();
      installStyles();
      document.body.classList.add('gelato-universal-admin-page');
      const sidebar = renderSidebar(shell);
      renderHeader(shell, sidebar);
    } catch (error) {
      console.error('[Gelato shell]', error);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once: true});
  else install();
})();
