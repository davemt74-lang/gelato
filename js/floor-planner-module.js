(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth) return;

  function hydratePermissionCatalog() {
    if (!Auth.keys?.permissions || !Array.isArray(Auth.permissionCatalog)) return;
    const stored = Auth.read(Auth.keys.permissions, []);
    stored.forEach((permission) => {
      if (!permission?.key || Auth.permissionCatalog.some((item) => item.key === permission.key)) return;
      Auth.permissionCatalog.push(permission);
    });
  }

  function addAdminNav(permission, marker, icon, label, href) {
    if (!Auth.has(permission)) return;
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!adminNav || adminNav.querySelector(`[data-${marker}]`)) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-btn';
    button.dataset[marker.replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = '1';
    button.innerHTML = `<span class="nav-ico">${icon}</span>${label}`;
    button.addEventListener('click', () => { window.location.href = href; });
    adminNav.appendChild(button);
    const adminMode = document.querySelector('[data-workspace-mode="admin"]');
    if (adminMode) adminMode.hidden = false;
  }

  function addAdminNavAny(permissions, marker, icon, label, href) {
    if (!permissions.some((permission) => Auth.has(permission))) return;
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!adminNav || adminNav.querySelector(`[data-${marker}]`)) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-btn';
    button.dataset[marker.replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = '1';
    button.innerHTML = `<span class="nav-ico">${icon}</span>${label}`;
    button.addEventListener('click', () => { window.location.href = href; });
    adminNav.appendChild(button);
    const adminMode = document.querySelector('[data-workspace-mode="admin"]');
    if (adminMode) adminMode.hidden = false;
  }

  function installOperationsNav() {
    addAdminNavAny(['pos.use','pos.manage','kds.view','kds.configure','crm.view','crm.manage'], 'online-orders-nav', '↗', 'Online Orders', 'online-orders-admin.php');
    addAdminNavAny(['tasks.view','tasks.manage','inventory.view','inventory.manage'], 'operations-nav', '✓', 'Operations', 'operations.php');
    addAdminNav('sales.view', 'sales-intelligence-nav', '↗', 'Sales Intelligence', 'sales-intelligence.php');
    addAdminNav('crm.view', 'customer-crm-nav', '◎', 'Customer CRM', 'customer-crm.php');
    addAdminNav('customer_promotions.manage', 'customer-promotions-nav', '✦', 'Customer Promotions', 'customer-promotions.php');
    addAdminNav('catering.view', 'catering-operations-nav', '◈', 'Catering Operations', 'catering-operations.php');

    // Floor Planner 2.0 wraps the canonical floor-planner-ops.php runtime.
    addAdminNav('floorplans.view', 'floor-planner-nav', '▦', 'Floor Planner', 'floor-planner-v2.php');
    addAdminNav('locations.manage', 'locations-nav', '⌖', 'Locations', 'locations-admin.php');
    addAdminNav('equipment.view', 'equipment-nav', '⚙', 'Equipment Catalog', 'equipment.php');
    addAdminNav('wholesale.view', 'wholesale-nav', '◇', 'Wholesale', 'wholesale-pipeline.php');
    addAdminNav('wholesale.view', 'wholesale-accounts-nav', '◎', 'Wholesale Customers', 'wholesale-accounts.php');
    addAdminNav('catering.view', 'catering-nav', '◈', 'Catering Pipeline', 'catering-pipeline.php');
    addAdminNav('recipes.view', 'recipes-nav', '▤', 'Recipe Library + Builder', 'recipes.php');
    addAdminNav('purchasing.view', 'purchasing-nav', '▣', 'Purchasing + Receiving', 'purchasing.php');
    addAdminNav('schedule.view', 'scheduling-nav', '◫', 'Staff Scheduling', 'scheduling.php');
    addAdminNav('schedule.self', 'scheduling-nav', '◫', 'My Schedule', 'scheduling.php');
    addAdminNav('timeclock.view', 'timeclock-nav', '◷', 'Time Clock + Attendance', 'timeclock.php');
    addAdminNav('timeclock.self', 'timeclock-nav', '◷', 'My Time + Gelato', 'timeclock.php');
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (adminNav && !adminNav.querySelector('[data-agent-canvas-nav]')) {
      const button=document.createElement('button');button.type='button';button.className='nav-btn';button.dataset.agentCanvasNav='1';button.innerHTML='<span class="nav-ico">✦</span>Agent Canvas';button.addEventListener('click',()=>{window.location.href='agent-canvas.php';});adminNav.appendChild(button);
    }
  }

  function loadScript(src, marker) {
    if (document.querySelector(`script[data-${marker}]`)) return;
    const script = document.createElement('script');
    script.src = src;
    script.dataset[marker.replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = '1';
    script.defer = true;
    document.head.appendChild(script);
  }

  hydratePermissionCatalog();

  function install() {
    installOperationsNav();
    loadScript('js/global-agent.js?v=20260913-1', 'gelato-global-agent');
    loadScript('js/admin-shell-consolidation.js?v=20260915-1', 'gelato-admin-shell-consolidation');
    loadScript('js/equipment-module.js', 'equipment-module');
    loadScript('js/catering-module.js', 'catering-module');
    loadScript('js/restaurant-agent-bridge.js', 'restaurant-agent-bridge');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
  else install();
})();