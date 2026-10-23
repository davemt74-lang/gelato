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
    script.src = 'js/global-add-canvas.js?v=20261023-manual-chat1';
    script.dataset.globalAddCanvas = '1';
    document.head.appendChild(script);
  }

  function ensureWorkspaceEnhancements() {
    if (document.querySelector('script[data-workspace-command-enhancements]')) return;
    const script = document.createElement('script');
    script.src = 'js/workspace-command-enhancements.js?v=20260915-workforce1';
    script.dataset.workspaceCommandEnhancements = '1';
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
      if (item.label === 'POS') link.id = 'gelatoHeaderPos';
      if (item.label === 'KDS') link.id = 'gelatoHeaderKds';
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

  function navDestination(href) {
    try {
      const url = new URL(String(href || ''), window.location.href);
      if (url.origin !== window.location.origin) return '';
      return url.pathname.split('/').pop().toLowerCase() + url.hash.toLowerCase();
    } catch {
      return '';
    }
  }

  function syncAdminSidebar(shell) {
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!adminNav) return;

    // Server permission-filtered catalog owns this list. Keep in-canvas
    // buttons when allowed, and use one accessible link per PHP destination.
    const destinations = new Map();
    (shell.navigation || []).forEach((group) => {
      (group.items || []).forEach((item) => {
        const destination = navDestination(item.href);
        if (!destination || destinations.has(destination)) return;
        destinations.set(destination, { ...item, group: group.id });
      });
    });
    let reconciling = false;
    let scheduled = false;
    const reconcile = () => {
      if (reconciling) return;
      reconciling = true;
      try {
        adminNav.querySelectorAll('.gelato-sidebar-route-link,.gelato-sidebar-route-heading').forEach((node) => node.remove());
        const spaButtons = new Map();
        adminNav.querySelectorAll('.nav-btn[data-nav]').forEach((node) => {
          const destination = navDestination('workspace.php#' + (node.dataset.nav || ''));
          if (!destinations.has(destination) || spaButtons.has(destination)) {
            node.remove();
          } else {
            spaButtons.set(destination, node);
            node.removeAttribute('disabled');
            node.removeAttribute('aria-disabled');
          }
        });
        // Modules may add old JavaScript buttons late. They point at PHP
        // modules and have no native SPA data-nav; canonical links replace them.
        adminNav.querySelectorAll('.nav-btn:not([data-nav])').forEach((node) => node.remove());
        const existingLinks = new Map();
        adminNav.querySelectorAll('a[href]').forEach((node) => {
          const destination = navDestination(node.getAttribute('href'));
          if (!destinations.has(destination) || spaButtons.has(destination) || existingLinks.has(destination)) {
            node.remove();
          } else {
            existingLinks.set(destination, node);
          }
        });
        destinations.forEach((item, destination) => {
          if (spaButtons.has(destination)) return;
          let link = existingLinks.get(destination);
          if (!link) {
            link = document.createElement('a');
            link.className = 'nav-item gelato-canonical-nav-link';
            link.href = item.href;
            link.innerHTML = '<span class="nav-ico"></span><span class="gelato-nav-label"></span>';
          }
          link.classList.add('gelato-canonical-nav-link');
          link.removeAttribute('aria-disabled');
          link.removeAttribute('disabled');
          if (!link.querySelector('.gelato-nav-label')) {
            link.innerHTML = '<span class="nav-ico"></span><span class="gelato-nav-label"></span>';
          }
          link.querySelector('.nav-ico').textContent = item.icon || '◇';
          link.querySelector('.gelato-nav-label').textContent = item.label || '';
          const groupItems = adminNav.querySelector('[data-admin-nav-section="' + item.group + '"] .admin-nav-section-items');
          const container = groupItems || adminNav;
          if (!link.isConnected || link.parentElement !== container) container.appendChild(link);
        });
      } finally {
        reconciling = false;
      }
    };
    const schedule = () => {
      if (scheduled) return;
      scheduled = true;
      requestAnimationFrame(() => {
        scheduled = false;
        reconcile();
      });
    };
    reconcile();
    if (adminNav.dataset.canonicalSidebarObserver !== '1') {
      adminNav.dataset.canonicalSidebarObserver = '1';
      new MutationObserver((mutations) => {
        if (!reconciling && mutations.some((mutation) => mutation.type === 'childList')) schedule();
      }).observe(adminNav, { childList: true, subtree: true });
    }
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
      syncAdminSidebar(shell);
      syncProfileMenu(shell);
      ensureAddCanvas();
      ensureWorkspaceEnhancements();
      window.dispatchEvent(new CustomEvent('gelato-admin-shell-config', {detail: shell}));
    } catch (error) {
      console.error('[Gelato workspace shell]', error);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once: true});
  else install();
})();
