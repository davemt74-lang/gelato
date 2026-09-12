(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth || !Auth.has('floorplans.view')) return;

  function installFloorPlannerNav() {
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!adminNav || adminNav.querySelector('[data-floor-planner-nav]')) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-btn';
    button.dataset.floorPlannerNav = '1';
    button.innerHTML = '<span class="nav-ico">▦</span>Floor Planner';
    button.addEventListener('click', () => { window.location.href = 'floor-planner.php'; });
    adminNav.appendChild(button);

    const adminMode = document.querySelector('[data-workspace-mode="admin"]');
    if (adminMode) adminMode.hidden = false;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installFloorPlannerNav, { once: true });
  } else {
    installFloorPlannerNav();
  }
})();
