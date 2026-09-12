(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth || !Auth.has('equipment.view')) return;

  const csrf = String(window.RESTAURANT_CSRF_TOKEN || '');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

  function installNav() {
    const adminNav = document.querySelector('[data-nav-group="admin"]');
    if (!adminNav || adminNav.querySelector('[data-equipment-nav]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-btn';
    button.dataset.equipmentNav = '1';
    button.innerHTML = '<span class="nav-ico">⚒</span>Equipment';
    button.addEventListener('click', () => { window.location.href = 'equipment.php'; });
    adminNav.appendChild(button);
    const adminMode = document.querySelector('[data-workspace-mode="admin"]');
    if (adminMode) adminMode.hidden = false;
  }

  async function loadBrainSummary() {
    if (!Auth.has('agent.equipment_skills')) return;
    const ownerPage = document.getElementById('page-owner');
    if (!ownerPage || ownerPage.querySelector('[data-equipment-brain-card]')) return;
    try {
      const response = await fetch('api/agent-brain.php?action=summary', {headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok || data.ok === false) return;
      const s = data.summary || {};
      const card = document.createElement('section');
      card.className = 'card';
      card.dataset.equipmentBrainCard = '1';
      card.style.marginTop = '14px';
      card.innerHTML = `
        <div class="admin-card-head"><div><p class="eyebrow">Internal agent skill</p><h4>Equipment Brain</h4><p>Structured equipment, maintenance, service history, and service-contact knowledge is available to the authenticated agent.</p></div><button class="btn btn-light" type="button" data-open-equipment>Open Equipment Catalog</button></div>
        <div class="admin-kpis" style="margin-top:12px">
          <article><small>Equipment assets</small><strong>${esc(s.assets || 0)}</strong></article>
          <article><small>Maintenance overdue</small><strong>${esc(s.overdueMaintenance || 0)}</strong></article>
          <article><small>Due within 30 days</small><strong>${esc(s.dueWithin30Days || 0)}</strong></article>
          <article><small>Out of service</small><strong>${esc(s.outOfService || 0)}</strong></article>
        </div>`;
      const dialogue = ownerPage.querySelector('.owner-agent-dialogue');
      if (dialogue) dialogue.insertAdjacentElement('beforebegin', card);
      else ownerPage.appendChild(card);
      card.querySelector('[data-open-equipment]')?.addEventListener('click', () => { window.location.href = 'equipment.php'; });
    } catch (_) {
      // Equipment migration may not be installed yet; the main workspace remains usable.
    }
  }

  function install() {
    installNav();
    loadBrainSummary();
    document.querySelectorAll('[data-nav="owner"]').forEach(button => button.addEventListener('click', () => setTimeout(loadBrainSummary, 30)));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once:true});
  else install();
})();
