(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');
  let clipboard = [];
  let pasteCount = 0;
  let renderingEquipment = false;
  let pickerAssets = [];
  let pickerQuery = '';
  let activeServiceAssetId = '';
  let activeServiceMode = 'service';

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[char]));
  const escAttr = value => esc(value).replace(/`/g, '&#96;');

  async function api(url, options = {}) {
    const request = {...options, headers:{Accept:'application/json', ...(options.headers || {})}};
    if (request.method && request.method !== 'GET') request.headers['X-CSRF-Token'] = csrf;
    if (request.body && typeof request.body !== 'string') {
      request.headers['Content-Type'] = 'application/json';
      request.body = JSON.stringify(request.body);
    }
    const response = await fetch(url, request);
    let data = null;
    try { data = await response.json(); }
    catch { throw new Error(`Invalid server response (${response.status}).`); }
    if (!response.ok || data?.ok === false) throw new Error(data?.message || `Request failed (${response.status}).`);
    return data;
  }

  function status(message, bad = false) {
    const el = document.getElementById('status');
    if (!el) return;
    el.textContent = message;
    el.style.color = bad ? '#ffb4ad' : '#c5cad0';
  }

  function scale() {
    return Math.max(8, Number(document.getElementById('scale')?.value) || 24);
  }

  function planId() {
    return new URLSearchParams(location.search).get('plan') || document.getElementById('planSelect')?.value || '';
  }

  function editableTarget(target) {
    return target instanceof HTMLElement && !!target.closest('input,textarea,select,[contenteditable="true"]');
  }

  function selectedNodes() {
    const nodes = [...document.querySelectorAll('#stage .fp-selected,#stage .fp-primary')];
    return [...new Set(nodes)].filter(node => node.isConnected);
  }

  function selectedStructures() {
    return selectedNodes().filter(node => node.classList.contains('structure'));
  }

  function cloneTemplate(node) {
    const clone = node.cloneNode(true);
    clone.classList.remove('selected','fp-selected','fp-primary');
    clone.querySelectorAll('.selected,.fp-selected,.fp-primary').forEach(child => child.classList.remove('selected','fp-selected','fp-primary'));
    return clone;
  }

  function copySelection() {
    const structures = selectedStructures();
    if (!structures.length) {
      const hasEquipment = selectedNodes().some(node => node.classList.contains('equipment'));
      status(hasEquipment ? 'Equipment assets are unique records and cannot be copied.' : 'Select a floor item to copy.', true);
      return false;
    }
    clipboard = structures.map(cloneTemplate);
    pasteCount = 0;
    const skipped = selectedNodes().length - structures.length;
    status(`Copied ${structures.length} floor item${structures.length === 1 ? '' : 's'}${skipped ? ' · equipment skipped' : ''}`);
    return true;
  }

  function selectCreated(nodes) {
    if (!nodes.length) return;
    nodes[0].dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true, detail:1}));
    nodes.slice(1).forEach(node => node.dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true, detail:1, shiftKey:true})));
  }

  function pasteSelection(source = clipboard) {
    if (!cfg.canEdit) return;
    if (!source.length) return status('Nothing has been copied yet.', true);
    const stage = document.getElementById('stage');
    if (!stage) return;
    pasteCount += 1;
    const offset = scale() * pasteCount;
    const created = source.map(template => {
      const node = cloneTemplate(template);
      node.dataset.id = `structure-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`;
      node.style.left = Math.max(0, (parseFloat(template.style.left) || 0) + offset) + 'px';
      node.style.top = Math.max(0, (parseFloat(template.style.top) || 0) + offset) + 'px';
      node.style.zIndex = String((Number(template.style.zIndex) || 1) + pasteCount);
      stage.appendChild(node);
      return node;
    });
    selectCreated(created);
    status(`Pasted ${created.length} floor item${created.length === 1 ? '' : 's'}`);
  }

  function duplicateSelection(event) {
    const structures = selectedStructures();
    if (!cfg.canEdit || !structures.length) return;
    event?.preventDefault();
    event?.stopImmediatePropagation();
    const templates = structures.map(cloneTemplate);
    pasteCount = 0;
    pasteSelection(templates);
    const skipped = selectedNodes().filter(node => node.classList.contains('equipment')).length;
    if (skipped) status(`Duplicated ${structures.length} layout item${structures.length === 1 ? '' : 's'} · equipment skipped`);
  }

  function deleteSelectionViaV2() {
    const nodes = selectedNodes();
    if (!nodes.length || !cfg.canEdit) return;
    document.dispatchEvent(new KeyboardEvent('keydown', {key:'Delete', code:'Delete', bubbles:true, cancelable:true}));
  }

  function installClipboardTools() {
    document.getElementById('duplicateBtn')?.addEventListener('click', duplicateSelection, true);
    document.getElementById('deleteBtn')?.addEventListener('click', event => {
      if (!selectedNodes().length) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      deleteSelectionViaV2();
    }, true);

    document.addEventListener('keydown', event => {
      if (editableTarget(event.target)) return;
      const mod = event.ctrlKey || event.metaKey;
      if (!mod) return;
      const key = event.key.toLowerCase();
      if (key === 'c') {
        event.preventDefault();
        copySelection();
      } else if (key === 'v') {
        event.preventDefault();
        pasteSelection();
      } else if (key === 'x') {
        event.preventDefault();
        deleteSelectionViaV2();
      }
    }, true);
  }

  function injectStyles() {
    const style = document.createElement('style');
    style.id = 'floorPlannerV2Enhancements';
    style.textContent = `
      .app{grid-template-rows:48px 1fr!important}
      .top{padding:5px 8px!important;gap:5px!important;min-height:48px!important}
      .top strong{font-size:12px!important;margin-right:2px!important}
      .top button,.top input,.top select{padding:5px 7px!important;border-radius:6px!important;font-size:10px!important;min-height:28px!important}
      .top input{width:160px!important}.top select{width:185px!important}.top .status{font-size:9px!important}
      .top label{font-size:9px!important}.top input[type="range"]{height:24px!important;padding:0!important}
      .inspect{top:48px!important}
      .fp-counter-shape polygon{fill:#f4dfa0!important;stroke:#c6a348!important;stroke-width:14!important}
      .structure.aisle .label{color:#3d341d!important;text-shadow:none!important;font-weight:850!important}
      .fp-picker-backdrop,.fp-service-backdrop{position:fixed;inset:0;z-index:300000;background:rgba(0,0,0,.5);display:none;place-items:center;padding:18px}
      .fp-picker-backdrop.open,.fp-service-backdrop.open{display:grid}
      .fp-picker{width:min(820px,96vw);max-height:88vh;overflow:auto;background:#fff;border-radius:15px;box-shadow:0 25px 70px rgba(0,0,0,.25)}
      .fp-picker-head{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:8px;padding:12px 14px;background:#111214;color:#fff}
      .fp-picker-head strong{font-size:14px}.fp-picker-head .spacer{flex:1}.fp-picker-head button{border:1px solid #3c4146;background:#202327;color:#fff;border-radius:7px;padding:6px 9px;font-size:10px}
      .fp-picker-body{padding:14px}.fp-picker-tools{display:flex;gap:8px;margin-bottom:12px}.fp-picker-tools input{flex:1;padding:9px;border:1px solid #d9dde3;border-radius:8px}
      .fp-equipment-list{display:grid;gap:7px}.fp-equipment-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px;border:1px solid #e0e3e6;border-radius:10px;background:#fff}
      .fp-equipment-row b{display:block;font-size:12px}.fp-equipment-row span{display:block;margin-top:2px;color:#68707a;font-size:10px}.fp-equipment-row button{border:1px solid #d7dade;border-radius:7px;background:#fff;padding:7px 9px;font-size:10px;font-weight:800}.fp-equipment-row button.primary{background:#17191c;border-color:#17191c;color:#fff}
      .fp-equipment-row button:disabled{opacity:.45;cursor:not-allowed}.fp-picker-empty{padding:18px;border:1px dashed #c9ced3;border-radius:10px;color:#6f7680;text-align:center;font-size:11px}
      .fp-equipment-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin:10px 0}.fp-equipment-actions button{border:1px solid #d9dde3;background:#fff;border-radius:8px;padding:8px;font-size:10px;font-weight:850}.fp-equipment-actions button.primary{background:#17191c;color:#fff;border-color:#17191c}
      .fp-service-form{padding:14px}.fp-service-form .grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}.fp-service-form label{display:block;margin-bottom:4px;font-size:9px;font-weight:850;text-transform:uppercase;color:#656b72}.fp-service-form input,.fp-service-form select,.fp-service-form textarea{width:100%;padding:8px;border:1px solid #d9dde3;border-radius:7px}.fp-service-form textarea{min-height:72px;resize:vertical}.fp-service-form .field{margin-bottom:9px}.fp-service-form .invoice-fields.hidden{display:none!important}.fp-service-note{font-size:10px;color:#6f7680;line-height:1.45;margin-bottom:10px}
      @media(max-width:760px){.top #planName{display:none}.top label{display:none}.fp-service-form .grid2{grid-template-columns:1fr}.fp-equipment-actions{grid-template-columns:1fr}.fp-picker-head strong{font-size:12px}}
    `;
    document.head.appendChild(style);
  }

  function compactHeader() {
    document.querySelectorAll('.top span').forEach(span => {
      if (/Shift-click|drag-select|Triple-click item/i.test(span.textContent || '')) span.remove();
    });
  }

  function installEquipmentPicker() {
    if (!document.getElementById('fpEquipmentPicker')) {
      const wrap = document.createElement('div');
      wrap.id = 'fpEquipmentPicker';
      wrap.className = 'fp-picker-backdrop';
      wrap.innerHTML = `
        <div class="fp-picker" role="dialog" aria-modal="true" aria-label="Add equipment to floor plan">
          <div class="fp-picker-head"><strong>Add Equipment to Floor Plan</strong><div class="spacer"></div><button type="button" id="fpCreateEquipment">+ Create New Equipment</button><button type="button" id="fpCloseEquipmentPicker">Close ×</button></div>
          <div class="fp-picker-body">
            <div class="fp-picker-tools"><input id="fpEquipmentSearch" placeholder="Search the equipment list by name, type, brand or location..."></div>
            <div class="fp-service-note">Choose an existing equipment record to place it on this floor plan, or create a new equipment record first. Physical equipment records stay canonical in the Equipment Catalog.</div>
            <div id="fpEquipmentList" class="fp-equipment-list"><div class="fp-picker-empty">Loading equipment…</div></div>
          </div>
        </div>`;
      document.body.appendChild(wrap);
      wrap.addEventListener('click', event => { if (event.target === wrap) closeEquipmentPicker(); });
      document.getElementById('fpCloseEquipmentPicker').onclick = closeEquipmentPicker;
      document.getElementById('fpEquipmentSearch').addEventListener('input', event => { pickerQuery = event.target.value.trim().toLowerCase(); renderEquipmentPickerList(); });
      document.getElementById('fpCreateEquipment').onclick = () => {
        const modal = document.getElementById('assetModal');
        if (!modal) return status('Equipment creation form is unavailable.', true);
        modal.classList.add('open');
      };
      document.getElementById('fpEquipmentList').addEventListener('click', async event => {
        const button = event.target.closest('[data-place-asset]');
        if (!button) return;
        const assetId = button.dataset.placeAsset;
        const asset = pickerAssets.find(item => item.id === assetId);
        if (!asset) return;
        await placeEquipmentAsset(asset);
      });
    }

    document.addEventListener('click', event => {
      const node = event.target instanceof Element ? event.target.closest('#fpEquipmentNode') : null;
      if (!node) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      openEquipmentPicker();
    }, true);

    document.getElementById('createAssetBtn')?.addEventListener('click', () => {
      setTimeout(() => { if (document.getElementById('fpEquipmentPicker')?.classList.contains('open')) refreshEquipmentPicker(); }, 700);
    });
  }

  async function openEquipmentPicker() {
    const picker = document.getElementById('fpEquipmentPicker');
    if (!picker) return;
    if (!cfg.canEquipment) {
      status('Equipment Catalog permission is required.', true);
      return;
    }
    picker.classList.add('open');
    pickerQuery = '';
    const input = document.getElementById('fpEquipmentSearch');
    if (input) input.value = '';
    await refreshEquipmentPicker();
  }

  function closeEquipmentPicker() {
    document.getElementById('fpEquipmentPicker')?.classList.remove('open');
  }

  async function refreshEquipmentPicker() {
    const list = document.getElementById('fpEquipmentList');
    if (list) list.innerHTML = '<div class="fp-picker-empty">Loading equipment…</div>';
    try {
      const data = await api('api/equipment.php', {method:'GET'});
      pickerAssets = data.assets || [];
      renderEquipmentPickerList();
    } catch (error) {
      if (list) list.innerHTML = `<div class="fp-picker-empty">${esc(error.message)}</div>`;
    }
  }

  function renderEquipmentPickerList() {
    const list = document.getElementById('fpEquipmentList');
    if (!list) return;
    const currentPlan = planId();
    const rows = pickerAssets.filter(asset => {
      if (!pickerQuery) return true;
      return [asset.name, asset.assetType, asset.brand, asset.model, asset.locationName, asset.assetTag].join(' ').toLowerCase().includes(pickerQuery);
    });
    if (!rows.length) {
      list.innerHTML = '<div class="fp-picker-empty">No equipment matches this search.</div>';
      return;
    }
    list.innerHTML = rows.map(asset => {
      const onCurrent = asset.floorPlanId && asset.floorPlanId === currentPlan;
      const onOther = asset.floorPlanId && asset.floorPlanId !== currentPlan;
      const label = onCurrent ? 'Already on plan' : onOther ? 'Move to this plan' : 'Place on plan';
      const disabled = onCurrent ? ' disabled' : '';
      const meta = [asset.assetType, asset.brand, asset.model, asset.locationName].filter(Boolean).join(' · ');
      return `<div class="fp-equipment-row"><div><b>${esc(asset.name)}</b><span>${esc(meta || 'Equipment asset')}</span><span>${esc(String(asset.operationalStatus || 'active').replaceAll('_',' '))}${onOther ? ' · currently assigned to another floor plan' : ''}</span></div><button class="${onCurrent ? '' : 'primary'}" data-place-asset="${escAttr(asset.id)}"${disabled}>${esc(label)}</button></div>`;
    }).join('');
  }

  async function placeEquipmentAsset(asset) {
    const id = planId();
    if (!id) return status('Save or choose a floor plan before placing equipment.', true);
    if (asset.floorPlanId && asset.floorPlanId !== id && !confirm(`${asset.name} is assigned to another floor plan. Move it to this plan?`)) return;
    const width = Math.max(10, Number(document.getElementById('planW')?.value) || 50);
    const depth = Math.max(10, Number(document.getElementById('planH')?.value) || 34);
    try {
      status(`Placing ${asset.name}…`);
      await api('api/equipment-placement.php', {method:'POST', body:{
        action:'place', assetId:asset.id, planId:id,
        xFt:Math.max(0, Math.min(width - 1, width / 2 - 1.5)),
        yFt:Math.max(0, Math.min(depth - 1, depth / 2 - 1.5)),
        rotationDeg:0, zIndex:250,
      }});
      closeEquipmentPicker();
      status(`${asset.name} placed on the floor plan`);
      setTimeout(() => location.reload(), 250);
    } catch (error) {
      status(error.message, true);
    }
  }

  function installServiceModal() {
    if (document.getElementById('fpServiceModal')) return;
    const wrap = document.createElement('div');
    wrap.id = 'fpServiceModal';
    wrap.className = 'fp-service-backdrop';
    wrap.innerHTML = `
      <div class="fp-picker" role="dialog" aria-modal="true" aria-label="Equipment service history">
        <div class="fp-picker-head"><strong id="fpServiceTitle">Record Service History</strong><div class="spacer"></div><button type="button" id="fpCloseServiceModal">Close ×</button></div>
        <form class="fp-service-form" id="fpServiceForm">
          <div class="fp-service-note" id="fpServiceNote">Record maintenance, repair or inspection work against the canonical equipment asset.</div>
          <div class="grid2"><div class="field"><label>Service date</label><input id="fpServiceDate" type="date" required></div><div class="field"><label>Event type</label><select id="fpServiceType"><option value="maintenance">Maintenance</option><option value="repair">Repair</option><option value="inspection">Inspection</option><option value="cleaning">Deep cleaning</option><option value="installation">Installation</option></select></div></div>
          <div class="grid2"><div class="field"><label>Service provider</label><select id="fpServiceContact"><option value="">No linked provider</option></select></div><div class="field"><label>Technician</label><input id="fpServiceTech"></div></div>
          <div class="invoice-fields hidden" id="fpInvoiceFields"><div class="grid2"><div class="field"><label>Invoice number</label><input id="fpInvoiceNumber"></div><div class="field"><label>Invoice reference</label><input id="fpInvoiceReference" placeholder="PO, receipt URL, file name, etc."></div></div></div>
          <div class="field"><label>Description / work performed</label><textarea id="fpServiceDescription" required></textarea></div>
          <div class="field"><label>Parts used</label><textarea id="fpServiceParts"></textarea></div>
          <div class="grid2"><div class="field"><label>Cost</label><input id="fpServiceCost" type="number" step=".01" min="0"></div><div class="field"><label>Downtime minutes</label><input id="fpServiceDowntime" type="number" min="0"></div></div>
          <div class="field"><label>Next service due</label><input id="fpServiceNext" type="date"></div>
          <button class="btn primary" type="submit" style="width:100%">Save Service History</button>
        </form>
      </div>`;
    document.body.appendChild(wrap);
    wrap.addEventListener('click', event => { if (event.target === wrap) closeServiceModal(); });
    document.getElementById('fpCloseServiceModal').onclick = closeServiceModal;
    document.getElementById('fpServiceForm').addEventListener('submit', saveServiceHistory);
  }

  async function openServiceModal(assetId, mode) {
    activeServiceAssetId = assetId;
    activeServiceMode = mode;
    const modal = document.getElementById('fpServiceModal');
    if (!modal) return;
    document.getElementById('fpServiceTitle').textContent = mode === 'invoice' ? 'Create Service History from Invoice' : 'Record Service History';
    document.getElementById('fpServiceNote').textContent = mode === 'invoice'
      ? 'Enter the service invoice details. The invoice number/reference are retained in the service-history description alongside cost, provider, parts and service date.'
      : 'Record maintenance, repair or inspection work against the canonical equipment asset.';
    document.getElementById('fpInvoiceFields').classList.toggle('hidden', mode !== 'invoice');
    document.getElementById('fpServiceDate').value = new Date().toISOString().slice(0,10);
    document.getElementById('fpServiceType').value = mode === 'invoice' ? 'repair' : 'maintenance';
    ['fpServiceTech','fpInvoiceNumber','fpInvoiceReference','fpServiceDescription','fpServiceParts','fpServiceCost','fpServiceDowntime','fpServiceNext'].forEach(id => { const el=document.getElementById(id); if(el)el.value=''; });
    try {
      const data = await api('api/equipment.php?asset=' + encodeURIComponent(assetId), {method:'GET'});
      const asset = (data.assets || []).find(item => item.id === assetId);
      const contacts = asset?.contacts || [];
      const all = data.contacts || [];
      const options = contacts.map(link => ({...link, ...(all.find(contact => contact.id === link.id) || {})}));
      document.getElementById('fpServiceContact').innerHTML = '<option value="">No linked provider</option>' + options.map(contact => `<option value="${escAttr(contact.id)}">${esc(contact.companyName || contact.contactName || 'Service provider')}</option>`).join('');
    } catch {}
    modal.classList.add('open');
  }

  function closeServiceModal() {
    document.getElementById('fpServiceModal')?.classList.remove('open');
  }

  async function saveServiceHistory(event) {
    event.preventDefault();
    if (!activeServiceAssetId) return;
    let description = document.getElementById('fpServiceDescription').value.trim();
    if (!description) return status('Service description is required.', true);
    if (activeServiceMode === 'invoice') {
      const number = document.getElementById('fpInvoiceNumber').value.trim();
      const reference = document.getElementById('fpInvoiceReference').value.trim();
      const prefix = [number && `Invoice ${number}`, reference && `Ref ${reference}`].filter(Boolean).join(' · ');
      if (prefix) description = `${prefix} — ${description}`;
    }
    try {
      await api('api/equipment.php', {method:'POST', body:{action:'save_event', event:{
        assetId:activeServiceAssetId,
        contactId:document.getElementById('fpServiceContact').value,
        eventType:document.getElementById('fpServiceType').value,
        status:'completed',
        servicedOn:document.getElementById('fpServiceDate').value,
        technicianName:document.getElementById('fpServiceTech').value.trim(),
        description,
        partsUsed:document.getElementById('fpServiceParts').value.trim(),
        cost:document.getElementById('fpServiceCost').value,
        downtimeMinutes:document.getElementById('fpServiceDowntime').value,
        nextDueOn:document.getElementById('fpServiceNext').value,
      }}});
      closeServiceModal();
      status('Equipment service history saved');
      await renderEquipmentPanel(activeServiceAssetId);
    } catch (error) {
      status(error.message, true);
    }
  }

  async function renderEquipmentPanel(assetId) {
    if (!assetId || renderingEquipment || !cfg.canEquipment) return;
    const detail = document.getElementById('assetDetail');
    if (!detail) return;
    renderingEquipment = true;
    try {
      const data = await api('api/equipment.php?asset=' + encodeURIComponent(assetId), {method:'GET'});
      const asset = (data.assets || []).find(item => item.id === assetId);
      if (!asset) throw new Error('Equipment asset not found.');
      const linkedContacts = (asset.contacts || []).map(link => ({...link, ...((data.contacts || []).find(contact => contact.id === link.id) || {})}));
      const events = (data.events || []).filter(item => item.assetId === assetId);
      const row = (key, value) => `<div class="detail-row"><span>${esc(key)}</span><strong>${esc(value || '—')}</strong></div>`;
      const returnTo = 'floor-planner-v2.php' + (planId() ? '?plan=' + encodeURIComponent(planId()) : '');
      detail.innerHTML = `
        <div data-fp-equipment-enhanced="1">
          <div class="fp-equipment-title">${esc(asset.name)}</div>
          ${row('Type', asset.assetType)}${row('Brand / model', [asset.brand,asset.model].filter(Boolean).join(' '))}${row('Serial / tag', [asset.serialNumber,asset.assetTag].filter(Boolean).join(' · '))}
          ${row('Status', String(asset.operationalStatus || '').replaceAll('_',' '))}${row('Condition', asset.conditionStatus)}${row('Criticality', asset.criticality)}${row('Purpose', asset.purpose)}
          ${row('Location', asset.locationName)}${row('Dimensions', [asset.widthInches && asset.widthInches+' in W',asset.depthInches && asset.depthInches+' in D',asset.heightInches && asset.heightInches+' in H'].filter(Boolean).join(' × '))}
          ${row('Utilities', [asset.utilityType,asset.voltage,asset.phase,asset.amperage].filter(Boolean).join(' · '))}${row('Water', asset.waterRequirement)}${row('Drain', asset.drainRequirement)}${row('Ventilation', asset.ventilationRequirement)}
          ${row('Warranty expires', asset.warrantyExpiresOn)}${row('Last service', asset.lastServiceOn)}${row('Next service', asset.nextServiceOn)}${row('Maintenance interval', asset.maintenanceIntervalDays ? asset.maintenanceIntervalDays+' days' : '')}${row('Replacement cost', asset.replacementCost != null ? '$'+Number(asset.replacementCost).toLocaleString() : '')}
          <div class="fp-equipment-actions"><button class="primary" type="button" data-equipment-service="service" data-asset-id="${escAttr(asset.id)}">+ Record Service</button><button type="button" data-equipment-service="invoice" data-asset-id="${escAttr(asset.id)}">Create from Invoice</button></div>
          <div class="fp-detail-heading">Service Contacts</div>
          ${linkedContacts.length ? linkedContacts.map(contact => `<div class="fp-service-card"><b>${esc(contact.companyName || 'Service provider')}</b><span>${esc([contact.contactName,contact.specialty,contact.role].filter(Boolean).join(' · '))}</span><span>${esc([contact.phone,contact.emergencyPhone].filter(Boolean).join(' · '))}</span><span>${contact.contractNumber ? 'Contract '+esc(contact.contractNumber) : ''}${contact.accountNumber ? ' · Acct '+esc(contact.accountNumber) : ''}</span></div>`).join('') : '<div class="empty">No service contacts are linked to this equipment yet.</div>'}
          <div class="fp-detail-heading">Service History</div>
          ${events.length ? events.slice(0,12).map(item => `<div class="fp-service-card"><b>${esc(item.servicedOn)} · ${esc(String(item.eventType || 'service').replaceAll('_',' '))}</b><span>${esc(item.description)}</span><span>${esc([item.companyName,item.technicianName].filter(Boolean).join(' · '))}</span>${item.partsUsed ? `<span>Parts: ${esc(item.partsUsed)}</span>` : ''}${item.cost != null ? `<span>Cost: $${Number(item.cost).toFixed(2)}</span>` : ''}${item.nextDueOn ? `<span>Next due: ${esc(item.nextDueOn)}</span>` : ''}</div>`).join('') : '<div class="empty">No service history recorded.</div>'}
          <div class="fp-detail-heading">Operating Knowledge</div>
          ${row('Maintenance', asset.maintenanceNotes)}${row('Cleaning', asset.cleaningNotes)}${row('Operating', asset.operatingNotes)}${row('Safety', asset.safetyNotes)}${row('Parts / consumables', asset.partsConsumables)}
          ${asset.manualUrl ? `<a class="fp-manual" target="_blank" rel="noopener" href="${escAttr(asset.manualUrl)}">Open manual / manufacturer link ↗</a>` : ''}
          <a class="fp-manual" href="equipment-detail.php?id=${encodeURIComponent(asset.id)}&return=${encodeURIComponent(returnTo)}">Open full Equipment Record →</a>
        </div>`;
    } catch (error) {
      detail.innerHTML = `<div data-fp-equipment-enhanced="1" class="alert bad">${esc(error.message)}</div>`;
    } finally {
      renderingEquipment = false;
    }
  }

  function installEquipmentInspectorEnhancements() {
    const detail = document.getElementById('assetDetail');
    if (!detail) return;
    let timer = 0;
    const observer = new MutationObserver(() => {
      if (renderingEquipment || detail.querySelector('[data-fp-equipment-enhanced="1"]')) return;
      const selected = document.querySelector('#stage .equipment.fp-primary,#stage .equipment.fp-selected');
      if (!selected?.dataset.assetId) return;
      clearTimeout(timer);
      timer = setTimeout(() => renderEquipmentPanel(selected.dataset.assetId), 20);
    });
    observer.observe(detail, {childList:true, subtree:true});
    document.getElementById('equipmentInspector')?.addEventListener('click', event => {
      const button = event.target.closest('[data-equipment-service]');
      if (!button) return;
      openServiceModal(button.dataset.assetId, button.dataset.equipmentService);
    });
  }

  function init() {
    injectStyles();
    compactHeader();
    installClipboardTools();
    installEquipmentPicker();
    installServiceModal();
    installEquipmentInspectorEnhancements();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
