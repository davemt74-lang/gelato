(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth || !Auth.has('catering.view')) return;

  function installNav() {
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!adminNav || adminNav.querySelector('[data-catering-nav]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-btn';
    button.dataset.cateringNav = '1';
    button.innerHTML = '<span class="nav-ico">◫</span>Catering';
    button.addEventListener('click', () => { window.location.href = 'catering-pipeline.php'; });
    adminNav.appendChild(button);
    const adminMode = document.querySelector('[data-workspace-mode="admin"]');
    if (adminMode) adminMode.hidden = false;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installNav, {once:true});
  else installNav();
})();
