(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth) return;

  function installFloorPlannerNav() {
    if (!Auth.has('floorplans.view')) return;
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

  function loadEquipmentModule() {
    if (document.querySelector('script[data-equipment-module]')) return;
    const script = document.createElement('script');
    script.src = 'js/equipment-module.js';
    script.dataset.equipmentModule = '1';
    script.defer = true;
    document.head.appendChild(script);
  }

  function install() {
    installFloorPlannerNav();
    loadEquipmentModule();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install, { once: true });
  } else {
    install();
  }
})();
