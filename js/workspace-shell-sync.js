(() => {
  'use strict';

  if (window.GelatoWorkspaceShellSync) return;
  window.GelatoWorkspaceShellSync = true;

  async function loadShell() {
    const response = await fetch('api/admin-shell.php?page=workspace.php', {
      headers: {Accept: 'application/json'},
      cache: 'no-store',
      credentials: 'same-origin',
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok || !payload.shell) throw new Error(payload.message || 'Workspace shell could not be loaded.');
    return payload.shell;
  }

  function ensureAddCanvas() {
    if (document.querySelector('script[data-global-add-canvas]')) return;
    const script = document.createElement('script');
    script.src = 'js/global-add-canvas.js?v=20260915-add1';
    script.dataset.globalAddCanvas = '1';
    document.head.appendChild(script);
  }

  function syncHeader(shell) {
    const actions = document.querySelector('.topbar .top-actions');
    if (!actions) return;

    actions.querySelectorAll('#gelatoHeaderPos,#gelatoHeaderKds,[data-canonical-shell-shortcut]').forEach((node) => node.remove());
    actions.querySelectorAll('a.header-link[href="landing.html"],a.header-link[href="index.php"],a.header-link[href="workspace.php"]').forEach((node) => node.remove());

    const notification = actions.querySelector('.notification-wrap');
    (shell.headerLinks || []).forEach((item) => {
      const link = document.createElement('a');
      link.dataset.canonicalShellShortcut = '1';
      link.className = `gelato-header-shortcut ${item.style === 'dark' ? 'pos' : item.style || ''}`.trim();
      link.href = item.href;
      link.textContent = item.label;
      actions.insertBefore(link, notification || actions.firstChild);
    });

    const account = shell.account || {};
    const name = document.getElementById('headerUserName');
    const role = document.getElementById('headerUserRole');
    const avatar = document.getElementById('headerAvatar');
    if (name && account.displayName) name.textContent = account.displayName;
    if (role && account.role) role.textContent = account.role;
    if (avatar && account.initials) avatar.textContent = account.initials;
  }

  function syncProfileMenu(shell) {
    const list = document.querySelector('.profile-menu-list');
    if (!list) return;
    list.querySelectorAll('a[href="landing.html"],a[href="apply.html"]').forEach((node) => node.remove());

    const allowedAdmin = (shell.account?.menu || []).some((item) => item.href === 'admin.php');
    const adminLink = document.getElementById('restaurantAdminControlLink');
    if (!allowedAdmin) adminLink?.remove();

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

  async function install() {
    try {
      const shell = await loadShell();
      window.GELATO_ADMIN_SHELL = shell;
      syncHeader(shell);
      syncProfileMenu(shell);
      ensureAddCanvas();
      window.dispatchEvent(new CustomEvent('gelato-admin-shell-config', {detail: shell}));
    } catch (error) {
      console.error('[Gelato workspace shell]', error);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once: true});
  else install();
})();
