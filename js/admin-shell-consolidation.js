(() => {
  'use strict';

  if (window.GelatoAdminShellConsolidated) return;
  window.GelatoAdminShellConsolidated = true;

  const $ = (selector, root = document) => root.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[character]));

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
      #page-workspace>.workspace>.composer{display:none!important}
      #page-workspace .chat-canvas{padding-bottom:105px}
      .standard-page.admin-page{padding-bottom:105px}
      @media(max-width:840px){#page-workspace .chat-canvas{padding-bottom:96px}.standard-page.admin-page{padding-bottom:96px}}
    `;
    document.head.appendChild(style);
  }

  function normalizeOperationsNavigation() {
    const adminNav = $('[data-nav-group="admin"]');
    if (!adminNav) return;

    const wholesale = $('[data-wholesale-nav]', adminNav);
    const wholesaleCustomers = $('[data-wholesale-accounts-nav]', adminNav);
    const catering = $('[data-catering-nav]', adminNav);

    if (wholesale) wholesale.innerHTML = '<span class="nav-ico">◇</span>Wholesale';
    if (catering) catering.innerHTML = '<span class="nav-ico">◈</span>Catering';

    if (wholesale && catering) {
      adminNav.insertBefore(wholesale, catering);
      if (wholesaleCustomers) adminNav.insertBefore(wholesaleCustomers, catering.nextSibling);
    }
  }

  function appendCanvasMessage(role, text) {
    const messages = document.getElementById('messages');
    if (!messages || !String(text || '').trim()) return;

    const row = document.createElement('div');
    row.className = `message ${role}`;
    const safe = esc(String(text).trim()).replace(/\n/g, '<br>');
    if (role === 'user') {
      row.innerHTML = `<div class="bubble"><p>${safe}</p></div><div class="avatar">You</div>`;
    } else {
      row.innerHTML = `<div class="avatar">A</div><div class="bubble"><p>${safe}</p></div>`;
    }
    messages.appendChild(row);

    const hero = document.getElementById('agentHero');
    if (hero) hero.classList.add('hidden');
    requestAnimationFrame(() => row.scrollIntoView({behavior: 'smooth', block: 'end'}));
  }

  function installGlobalAgentCanvasBridge() {
    let pendingText = '';
    let pendingAt = 0;

    function captureGlobalInput() {
      const input = document.getElementById('gaInput');
      const text = String(input?.value || '').trim();
      if (!text) return;
      const now = Date.now();
      if (text === pendingText && now - pendingAt < 800) return;
      pendingText = text;
      pendingAt = now;
      appendCanvasMessage('user', text);
    }

    document.addEventListener('click', (event) => {
      if (event.target.closest('#gaSend')) captureGlobalInput();
    }, true);

    document.addEventListener('keydown', (event) => {
      if (event.target?.id === 'gaInput' && event.key === 'Enter' && !event.shiftKey) captureGlobalInput();
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

  function install() {
    installStyles();
    normalizeOperationsNavigation();
    clarifyMainAgentCanvas();
    installGlobalAgentCanvasBridge();

    // Existing scripts still update hidden legacy hooks such as sidebarRole and
    // owner chat elements. Keep those nodes in the DOM and remove them visually.
    queueMicrotask(normalizeOperationsNavigation);
    requestAnimationFrame(normalizeOperationsNavigation);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install, {once: true});
  } else {
    install();
  }
})();
