(() => {
  'use strict';

  const Auth = window.RestaurantAuth;
  if (!Auth) return;

  if (!Auth.current()) {
    const target = location.pathname.split('/').pop() || 'index.html';
    location.replace(`login.php?return=${encodeURIComponent(target)}`);
    return;
  }

  const ASSET_VERSION = '20260914-employee-home';

  function loadScript(src, marker) {
    if (document.querySelector(`script[${marker}]`)) return;
    const script = document.createElement('script');
    script.src = src;
    script.setAttribute(marker, 'true');
    script.async = false;
    document.head.appendChild(script);
  }

  function initializeExtensions() {
    const canUseAgentAdmin = Boolean(
      Auth.has?.('public_agent.view') ||
      Auth.has?.('knowledge.view')
    );

    if (canUseAgentAdmin) {
      const adminMode = document.querySelector('[data-workspace-mode="admin"]');
      if (adminMode) adminMode.hidden = false;

      const adminNav = document.querySelector('[data-nav-group="admin"]');
      if (adminNav) {
        adminNav.style.flex = '1 1 auto';
        adminNav.style.minHeight = '0';
        adminNav.style.overflowX = 'hidden';
        adminNav.style.overflowY = 'auto';
        adminNav.style.paddingRight = '2px';
      }
    }

    loadScript(`js/employee-home-module.js?v=${ASSET_VERSION}`, 'data-gelato-employee-home-nav');
    loadScript(`js/global-agent.js?v=${ASSET_VERSION}`, 'data-gelato-global-agent');
    loadScript(`js/form-media-admin.js?v=${ASSET_VERSION}`, 'data-form-media-admin');
    if (canUseAgentAdmin) {
      loadScript(`js/public-agent-admin.js?v=${ASSET_VERSION}`, 'data-public-agent-admin');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeExtensions, {once: true});
  } else {
    initializeExtensions();
  }
})();