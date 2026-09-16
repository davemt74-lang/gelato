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

  function ensureAddCanvas() {
    if (document.querySelector('script[data-global-add-canvas]')) return;
    const script = document.createElement('script');
    script.src = 'js/global-add-canvas.js?v=20260915-add1';
    script.dataset.globalAddCanvas = '1';
    document.head.appendChild(script);
  }

  function loadScriptOnce(src, marker) {
    const existing = document.querySelector(`script[data-${marker}]`);
    if (existing) {
      if (existing.dataset.loaded === '1') return Promise.resolve();
      return new Promise((resolve) => {
        existing.addEventListener('load', resolve, {once: true});
        existing.addEventListener('error', resolve, {once: true});
      });
    }
    return new Promise((resolve) => {
      const script = document.createElement('script');
      script.src = src;
      script.dataset[marker.replace(/-([a-z])/g, (_, ch) => ch.toUpperCase())] = '1';
      script.addEventListener('load', () => { script.dataset.loaded = '1'; resolve(); }, {once: true});
      script.addEventListener('error', resolve, {once: true});
      document.head.appendChild(script);
    });
  }

  async function ensureSharedAgent() {
    const contextByPage = {
      'equipment.php': 'js/equipment-agent-context.js?v=20260916-equipment1',
      'equipment-detail.php': 'js/equipment-agent-context.js?v=20260916-equipment1',
      'operations.php': 'js/operations-agent-context.js?v=20260916-context1',
      'purchasing.php': 'js/purchasing-agent-context.js?v=20260916-context1',
      'scheduling.php': 'js/scheduling-agent-context.js?v=20260916-context1',
      'prep-intelligence.php': 'js/prep-agent-context.js?v=20260916-context1',
      'catering-operations.php': 'js/catering-agent-context.js?v=20260916-context1',
      'customer-crm.php': 'js/customer-crm-agent-context.js?v=20260916-context1',
      'wholesale-fulfillment.php': 'js/wholesale-agent-context.js?v=20260916-context1',
      'wholesale-pipeline.php': 'js/wholesale-agent-context.js?v=20260916-context1',
    };
    await loadScriptOnce('js/agent-page-context.js?v=20260916-context1', 'agent-page-context');
    if (contextByPage[path]) await loadScriptOnce(contextByPage[path], 'agent-domain-context');
    await loadScriptOnce('js/global-agent.js?v=20260916-global1', 'global-agent-runtime');
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
      .uas-header-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px;min-width:0;overflow-x:auto;scrollbar-width:none}.uas-header-actions::-webkit-scrollbar{display:none}.uas-header-link,.uas-page-action{min-height:36px;display:inline-flex;align-items:center;justify-content:center;border-