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
      body.gelato-global-agent-ready #page-workspace>.workspace>.composer{display:none!important}
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
    normalizeOperationsNavigation();
    installGlobalAgentCanvasBridge();
    window.addEventListener('gelato-agent-ready', setGlobalAgentReady, {once: true});
    if (document.getElementById('gelato-agent-bar')) setGlobalAgentReady();
    queueMicrotask(normalizeOperationsNavigation);
    requestAnimationFrame(normalizeOperationsNavigation);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once: true});
  else install();
})();
