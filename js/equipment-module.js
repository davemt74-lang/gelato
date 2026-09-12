(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth || !Auth.has('equipment.view')) return;

  const csrf = String(window.RESTAURANT_CSRF_TOKEN || '');
  const ownerChatKey = 'restaurant-owner-agent-chat-v1';
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

  function isEquipmentQuestion(value) {
    return /\b(equipment|oven|mixer|freezer|refrigerat|walk[- ]?in|dishwasher|dish machine|gelato machine|gelato case|blast freezer|batch freezer|maintenance|repair|technician|warranty|serial number|asset tag|model number|service contact|service company|replacement cost|spare parts|consumables|filter|belt|floor plan|layout|where is|where are|located|placement|positioned)\b/i.test(String(value || ''));
  }

  function addOwnerChatMessage(role, text) {
    const messages = Auth.read(ownerChatKey, []);
    messages.push({
      id: `owner-equipment-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`,
      role,
      text,
      actionId: null,
      createdAt: new Date().toISOString(),
    });
    Auth.write(ownerChatKey, messages.slice(-60));
    if (window.RestaurantAdmin?.onNavigate) window.RestaurantAdmin.onNavigate('owner');
  }

  async function askOwnerEquipmentBrain(message) {
    if (!Auth.has('agent.equipment_skills')) return false;
    const value = String(message || '').trim();
    if (!value || !isEquipmentQuestion(value)) return false;
    addOwnerChatMessage('user', value);
    const input = document.getElementById('ownerAgentInput');
    if (input) input.value = '';
    try {
      const response = await fetch('api/agent-brain.php', {
        method: 'POST',
        headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},
        body: JSON.stringify({action:'ask', message:value}),
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.message || `Request failed (${response.status}).`);
      addOwnerChatMessage('agent', data.answer || 'No equipment answer was returned.');
    } catch (error) {
      addOwnerChatMessage('agent', `Equipment Brain could not complete that request: ${error.message}`);
    }
    return true;
  }

  function bindOwnerAgentEquipmentRouting() {
    if (!Auth.has('agent.equipment_skills')) return;
    const send = document.getElementById('ownerAgentSend');
    const input = document.getElementById('ownerAgentInput');
    if (send && !send.dataset.equipmentBrainBound) {
      send.dataset.equipmentBrainBound = '1';
      send.addEventListener('click', event => {
        const value = input?.value.trim() || '';
        if (!isEquipmentQuestion(value)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        askOwnerEquipmentBrain(value);
      }, true);
    }
    if (input && !input.dataset.equipmentBrainBound) {
      input.dataset.equipmentBrainBound = '1';
      input.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.shiftKey) return;
        const value = input.value.trim();
        if (!isEquipmentQuestion(value)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        askOwnerEquipmentBrain(value);
      }, true);
    }
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
        <div class="admin-card-head"><div><p class="eyebrow">Internal agent skill</p><h4>Equipment Brain</h4><p>Structured equipment, floor placement, maintenance, service history, and service-contact knowledge is available directly in this Owner Agent conversation.</p></div><div style="display:flex;gap:7px"><button class="btn btn-light" type="button" data-open-floor>Floor Planner</button><button class="btn btn-light" type="button" data-open-equipment>Equipment Catalog</button></div></div>
        <div class="admin-kpis" style="margin-top:12px">
          <article><small>Equipment assets</small><strong>${esc(s.assets || 0)}</strong></article>
          <article><small>Maintenance overdue</small><strong>${esc(s.overdueMaintenance || 0)}</strong></article>
          <article><small>Due within 30 days</small><strong>${esc(s.dueWithin30Days || 0)}</strong></article>
          <article><small>Out of service</small><strong>${esc(s.outOfService || 0)}</strong></article>
        </div>
        <div class="quick-prompts" style="margin-top:12px">
          <button class="prompt-chip" type="button" data-equipment-question="What equipment is on our floor plan?">Floor-plan equipment</button>
          <button class="prompt-chip" type="button" data-equipment-question="What equipment maintenance is due in the next 30 days?">Maintenance due</button>
          <button class="prompt-chip" type="button" data-equipment-question="Which equipment is out of service?">Out of service</button>
          <button class="prompt-chip" type="button" data-equipment-question="Who are our equipment service contacts?">Service contacts</button>
        </div>`;
      const dialogue = ownerPage.querySelector('.owner-agent-dialogue');
      if (dialogue) dialogue.insertAdjacentElement('beforebegin', card);
      else ownerPage.appendChild(card);
      card.querySelector('[data-open-equipment]')?.addEventListener('click', () => { window.location.href = 'equipment.php'; });
      card.querySelector('[data-open-floor]')?.addEventListener('click', () => { window.location.href = 'floor-planner-ops.php'; });
      card.querySelectorAll('[data-equipment-question]').forEach(button => button.addEventListener('click', () => askOwnerEquipmentBrain(button.dataset.equipmentQuestion)));
    } catch (_) {
      // Equipment migration may not be installed yet; the main workspace remains usable.
    }
  }

  function install() {
    installNav();
    bindOwnerAgentEquipmentRouting();
    loadBrainSummary();
    document.querySelectorAll('[data-nav="owner"]').forEach(button => button.addEventListener('click', () => setTimeout(() => {
      bindOwnerAgentEquipmentRouting();
      loadBrainSummary();
    }, 30)));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once:true});
  else install();
})();
