(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const CAN_EDIT = Boolean(cfg.canEdit);
  const CAN_EQUIPMENT = Boolean(cfg.canEquipment);
  const CAN_EQUIPMENT_EDIT = Boolean(cfg.canEquipmentEdit);
  const CAN_AGENT = Boolean(cfg.canAgent);
  const CSRF = String(cfg.csrf || '');
  const $ = id => document.getElementById(id);
  const stage = $('stage');
  const holder = $('holder');

  let plans = [];
  let activePlanId = '';
  let planW = 50;
  let planH = 34;
  let scale = 24;
  let zoom = 1;
  let zCounter = 100;
  let selected = null;
  let selectedKind = '';
  let equipment = [];
  let legacyItems = [];
  let dirty = false;

  const revenueDefaults = {
    lOcc: 65, lTurns: 1.2, lCheck: 22,
    dOcc: 82, dTurns: 1.7, dCheck: 34,
    barPct: 18, gelPct: 24, gelAvg: 6.5,
  };

  const equipmentLegacyTypes = new Set([
    'pizza-oven','dough-mixer','prep-table','pizza','salad','server','walkin','keg','wine','shelving',
    'sink3','handsink','dish','batch-freezer','blast-freezer','gelato','pos',
  ]);

  const defs = {
    table:{label:'4-TOP',cls:'table',wIn:48,hIn:36,seats:4,zone:'dining'},
    chair:{label:'',cls:'chair',wIn:20,hIn:20,seats:1,zone:'dining'},
    bar:{label:'BAR',cls:'bar',wIn:180,hIn:72,seats:12,zone:'bar'},
    host:{label:'HOST',cls:'host',wIn:36,hIn:24,seats:0,zone:'none'},
    zone:{label:'ZONE',cls:'zone',wIn:120,hIn:96,seats:0,zone:'none'},
    aisle:{label:'AISLE',cls:'aisle',wIn:48,hIn:120,seats:0,zone:'none'},
    wall:{label:'',cls:'wall',wIn:120,hIn:6,seats:0,zone:'none'},
    door:{label:'DOOR',cls:'door',wIn:36,hIn:6,seats:0,zone:'none'},
  };

  const fallbackFootprints = {
    oven:[60,48], mixer:[30,36], refrigeration:[72,34], gelato_machine:[36,30], dishwasher:[30,30],
    sink:[36,24], utensil:[18,18], smallware:[18,18], bar_equipment:[36,24], pos:[24,18], other:[36,30],
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[char]));

  function setStatus(text, bad = false) {
    $('status').textContent = text;
    $('status').style.color = bad ? '#ffb4ad' : '#c5cad0';
    clearTimeout(setStatus.timer);
    setStatus.timer = setTimeout(() => {
      $('status').textContent = 'Ready';
      $('status').style.color = '#c5cad0';
    }, 2200);
  }

  function pxFromIn(inches) { return (Number(inches) || 0) / 12 * scale; }
  function inFromPx(px) { return (Number(px) || 0) / scale * 12; }
  function snap(px) { const step = scale / 2; return Math.round(px / step) * step; }
  function fmtIn(inches) {
    const value = Math.max(0, Math.round(Number(inches) || 0));
    return `${Math.floor(value / 12)}' ${value % 12}"`;
  }
  function money(value) { return '$' + Math.round(Number(value) || 0).toLocaleString(); }

  async function api(url, options = {}) {
    const request = {...options, headers:{Accept:'application/json', ...(options.headers || {})}};
    if (request.method && request.method !== 'GET') request.headers['X-CSRF-Token'] = CSRF;
    if (request.body && typeof request.body !== 'string') {
      request.headers['Content-Type'] = 'application/json';
      request.body = JSON.stringify(request.body);
    }
    const response = await fetch(url, request);
    let data;
    try { data = await response.json(); }
    catch { throw new Error(`Invalid server response (${response.status}).`); }
    if (!response.ok || data.ok === false) throw new Error(data.message || `Request failed (${response.status}).`);
    return data;
  }

  function updateCanvas() {
    const width = planW * scale;
    const height = planH * scale;
    stage.style.width = width + 'px';
    stage.style.height = height + 'px';
    stage.style.transform = `scale(${zoom})`;
    holder.style.width = (width * zoom) + 'px';
    holder.style.height = (height * zoom) + 'px';
    document.documentElement.style.setProperty('--grid', (scale / 2) + 'px');
    $('sqft').value = (planW * planH).toLocaleString(undefined, {maximumFractionDigits:1});
    $('sqftCard').textContent = Math.round(planW * planH).toLocaleString();
  }

  function normalizeStructure(item = {}) {
    return {
      id: item.id || '',
      type: String(item.type || 'zone'),
      label: String(item.label ?? ''),
      xPx: Number(item.xPx ?? item.x ?? 48),
      yPx: Number(item.yPx ?? item.y ?? 48),
      wPx: Number(item.wPx ?? item.w ?? 0) || undefined,
      hPx: Number(item.hPx ?? item.h ?? 0) || undefined,
      r: Number(item.r ?? item.rot ?? 0),
      z: Number(item.z ?? 1),
      seats: Number(item.seats ?? 0),
      seatZone: String(item.seatZone ?? item.zone ?? 'none'),
    };
  }

  function structuralData(el) {
    return {
      id: el.dataset.id,
      type: el.dataset.type,
      label: el.querySelector('.label').textContent,
      xPx: parseFloat(el.style.left) || 0,
      yPx: parseFloat(el.style.top) || 0,
      wPx: parseFloat(el.style.width) || 0,
      hPx: parseFloat(el.style.height) || 0,
      r: Number(el.dataset.rotation) || 0,
      z: Number(el.style.zIndex) || 1,
      seats: Number(el.dataset.seats) || 0,
      seatZone: el.dataset.seatZone || 'none',
    };
  }

  function makeStructure(type, x = 48, y = 48, raw = {}) {
    const data = normalizeStructure({...raw, type});
    const def = defs[type] || defs.zone;
    const el = document.createElement('div');
    el.className = 'structure ' + def.cls;
    el.dataset.id = data.id || `structure-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`;
    el.dataset.type = type;
    el.dataset.rotation = data.r;
    el.dataset.seats = raw.seats ?? def.seats;
    el.dataset.seatZone = raw.seatZone ?? raw.zone ?? def.zone;
    el.style.left = (raw.xPx ?? raw.x ?? snap(x)) + 'px';
    el.style.top = (raw.yPx ?? raw.y ?? snap(y)) + 'px';
    el.style.width = (raw.wPx ?? raw.w ?? pxFromIn(def.wIn)) + 'px';
    el.style.height = (raw.hPx ?? raw.h ?? pxFromIn(def.hIn)) + 'px';
    el.style.transform = `rotate(${data.r}deg)`;
    el.style.zIndex = raw.z || ++zCounter;
    el.innerHTML = '<span class="label"></span><span class="dim"></span><span class="resize"></span>';
    el.querySelector('.label').textContent = raw.label ?? def.label;
    stage.appendChild(el);
    bindStructure(el);
    updateDim(el);
    return el;
  }

  function updateDim(el) {
    el.querySelector('.dim').textContent = `${fmtIn(inFromPx(parseFloat(el.style.width)))} × ${fmtIn(inFromPx(parseFloat(el.style.height)))}`;
  }

  function bindStructure(el) {
    el.addEventListener('pointerdown', event => {
      if (!CAN_EDIT || event.button !== 0 || event.target.classList.contains('resize')) return;
      selectObject(el, 'structure');
      const startX = event.clientX, startY = event.clientY;
      const left = parseFloat(el.style.left), top = parseFloat(el.style.top);
      el.setPointerCapture(event.pointerId);
      const move = current => {
        el.style.left = Math.max(0, snap(left + (current.clientX - startX) / zoom)) + 'px';
        el.style.top = Math.max(0, snap(top + (current.clientY - startY) / zoom)) + 'px';
        dirty = true;
        recalc();
      };
      const up = current => {
        el.releasePointerCapture(current.pointerId);
        el.removeEventListener('pointermove', move);
        el.removeEventListener('pointerup', up);
      };
      el.addEventListener('pointermove', move);
      el.addEventListener('pointerup', up);
    });

    const handle = el.querySelector('.resize');
    handle.addEventListener('pointerdown', event => {
      if (!CAN_EDIT) return;
      event.stopPropagation();
      selectObject(el, 'structure');
      const startX = event.clientX, startY = event.clientY;
      const width = parseFloat(el.style.width), height = parseFloat(el.style.height);
      handle.setPointerCapture(event.pointerId);
      const move = current => {
        el.style.width = Math.max(scale / 2, snap(width + (current.clientX - startX) / zoom)) + 'px';
        el.style.height = Math.max(scale / 2, snap(height + (current.clientY - startY) / zoom)) + 'px';
        updateDim(el);
        dirty = true;
        recalc();
      };
      const up = current => {
        handle.releasePointerCapture(current.pointerId);
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
      };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up);
    });
  }

  function footprint(asset) {
    const fallback = fallbackFootprints[asset.assetType] || fallbackFootprints.other;
    return [asset.widthInches || fallback[0], asset.depthInches || fallback[1], !asset.widthInches || !asset.depthInches];
  }

  function makeEquipment(asset) {
    const [widthIn, depthIn, estimated] = footprint(asset);
    const el = document.createElement('div');
    el.className = `equipment type-${asset.assetType} status-${asset.operationalStatus}${asset.locked ? ' locked' : ''}`;
    el.dataset.assetId = asset.id;
    el.dataset.rotation = asset.rotationDeg || 0;
    el.style.left = ((asset.xFt ?? 1) * scale) + 'px';
    el.style.top = ((asset.yFt ?? 1) * scale) + 'px';
    el.style.width = pxFromIn(widthIn) + 'px';
    el.style.height = pxFromIn(depthIn) + 'px';
    el.style.transform = `rotate(${asset.rotationDeg || 0}deg)`;
    el.style.zIndex = asset.zIndex || 10;
    const due = asset.maintenanceRequired && asset.nextServiceOn && new Date(asset.nextServiceOn) <= new Date(Date.now() + 30 * 86400000);
    el.innerHTML = `<span class="label"></span><span class="asset-badge">ASSET</span>${due ? '<span class="service-badge">SERVICE</span>' : ''}<span class="dim"></span>${CAN_EQUIPMENT_EDIT ? '<span class="resize"></span>' : ''}`;
    el.querySelector('.label').textContent = asset.name;
    el.querySelector('.dim').textContent = `${fmtIn(widthIn)} × ${fmtIn(depthIn)}${estimated ? ' est.' : ''}`;
    stage.appendChild(el);
    bindEquipment(el);
    return el;
  }

  function assetById(id) { return equipment.find(asset => asset.id === id); }

  function bindEquipment(el) {
    el.addEventListener('click', event => { event.stopPropagation(); selectObject(el, 'equipment'); });
    el.addEventListener('dblclick', () => openAsset(assetById(el.dataset.assetId)));
    el.addEventListener('pointerdown', event => {
      const asset = assetById(el.dataset.assetId);
      if (!CAN_EDIT || asset?.locked || event.button !== 0 || event.target.classList.contains('resize')) return;
      selectObject(el, 'equipment');
      const startX = event.clientX, startY = event.clientY;
      const left = parseFloat(el.style.left), top = parseFloat(el.style.top);
      el.setPointerCapture(event.pointerId);
      const move = current => {
        el.style.left = Math.max(0, snap(left + (current.clientX - startX) / zoom)) + 'px';
        el.style.top = Math.max(0, snap(top + (current.clientY - startY) / zoom)) + 'px';
      };
      const up = async current => {
        el.releasePointerCapture(current.pointerId);
        el.removeEventListener('pointermove', move);
        el.removeEventListener('pointerup', up);
        try {
          await savePlacement(asset, 'move', parseFloat(el.style.left) / scale, parseFloat(el.style.top) / scale, Number(el.dataset.rotation) || 0, Number(el.style.zIndex) || 10);
        } catch (error) {
          setStatus(error.message, true);
          await loadPlacements();
        }
      };
      el.addEventListener('pointermove', move);
      el.addEventListener('pointerup', up);
    });

    const handle = el.querySelector('.resize');
    if (handle) handle.addEventListener('pointerdown', event => {
      const asset = assetById(el.dataset.assetId);
      if (!CAN_EQUIPMENT_EDIT || asset?.locked) return;
      event.stopPropagation();
      selectObject(el, 'equipment');
      const startX = event.clientX, startY = event.clientY;
      const width = parseFloat(el.style.width), height = parseFloat(el.style.height);
      handle.setPointerCapture(event.pointerId);
      const move = current => {
        el.style.width = Math.max(scale / 2, snap(width + (current.clientX - startX) / zoom)) + 'px';
        el.style.height = Math.max(scale / 2, snap(height + (current.clientY - startY) / zoom)) + 'px';
        el.querySelector('.dim').textContent = `${fmtIn(inFromPx(parseFloat(el.style.width)))} × ${fmtIn(inFromPx(parseFloat(el.style.height)))}`;
      };
      const up = async current => {
        handle.releasePointerCapture(current.pointerId);
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
        try {
          const data = await api('api/equipment-placement.php', {method:'POST', body:{
            action:'resize', assetId:asset.id,
            widthInches:inFromPx(parseFloat(el.style.width)), depthInches:inFromPx(parseFloat(el.style.height)),
          }});
          Object.assign(asset, data.asset);
          renderCatalog();
          renderEquipmentInspector(asset);
          recalc();
          setStatus('Equipment dimensions saved to catalog');
        } catch (error) {
          setStatus(error.message, true);
          await loadPlacements();
        }
      };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up);
    });
  }

  function clearStage() {
    stage.querySelectorAll('.structure,.equipment,.legacy').forEach(el => el.remove());
    selected = null;
    selectedKind = '';
    renderSelection();
  }

  function selectObject(el, kind) {
    document.querySelectorAll('.selected').forEach(node => node.classList.remove('selected'));
    selected = el;
    selectedKind = kind;
    if (el) el.classList.add('selected');
    renderSelection();
  }

  function renderSelection() {
    $('selectionEmpty').classList.toggle('hidden', Boolean(selected));
    $('structureInspector').classList.toggle('hidden', selectedKind !== 'structure');
    $('equipmentInspector').classList.toggle('hidden', selectedKind !== 'equipment');
    if (selectedKind === 'structure' && selected) {
      $('sLabel').value = selected.querySelector('.label').textContent;
      $('sW').value = (parseFloat(selected.style.width) / scale).toFixed(2);
      $('sH').value = (parseFloat(selected.style.height) / scale).toFixed(2);
      $('sSeats').value = selected.dataset.seats;
      $('sSeatZone').value = selected.dataset.seatZone;
    } else if (selectedKind === 'equipment' && selected) {
      renderEquipmentInspector(assetById(selected.dataset.assetId));
    }
  }

  function renderEquipmentInspector(asset) {
    if (!asset) return;
    const age = asset.manufactureYear ? Math.max(0, new Date().getFullYear() - asset.manufactureYear) + ' years' : '—';
    const due = asset.nextServiceOn || 'Not scheduled';
    const foot = footprint(asset);
    $('assetDetail').innerHTML = [
      ['Asset', asset.name], ['Type', asset.assetType], ['Brand / model', [asset.brand,asset.model].filter(Boolean).join(' ') || '—'],
      ['Status', String(asset.operationalStatus || '').replaceAll('_',' ')], ['Criticality', asset.criticality], ['Location', asset.locationName || '—'],
      ['Age', age], ['Footprint', `${fmtIn(foot[0])} × ${fmtIn(foot[1])}${foot[2] ? ' (visual estimate)' : ''}`],
      ['Next service', due], ['Plan position', `${Number(asset.xFt || 0).toFixed(1)}′, ${Number(asset.yFt || 0).toFixed(1)}′ · ${Number(asset.rotationDeg || 0)}°`],
    ].map(([key,value]) => `<div class="detail-row"><span>${esc(key)}</span><strong>${esc(value)}</strong></div>`).join('');
    $('lockAssetBtn').textContent = asset.locked ? 'Unlock Position' : 'Lock Position';
  }

  function structureItems() { return [...stage.querySelectorAll('.structure')].map(structuralData); }

  function loadStructure(data) {
    stage.querySelectorAll('.structure,.legacy').forEach(el => el.remove());
    legacyItems = [];
    const items = Array.isArray(data?.items) ? data.items : [];
    items.forEach(raw => {
      const item = normalizeStructure(raw);
      if (equipmentLegacyTypes.has(item.type)) {
        legacyItems.push(raw);
        const ghost = document.createElement('div');
        ghost.className = 'legacy';
        ghost.style.left = item.xPx + 'px';
        ghost.style.top = item.yPx + 'px';
        ghost.style.width = (item.wPx || 60) + 'px';
        ghost.style.height = (item.hPx || 40) + 'px';
        ghost.style.transform = `rotate(${item.r}deg)`;
        ghost.textContent = item.label || item.type;
        stage.appendChild(ghost);
        return;
      }
      if (defs[item.type]) makeStructure(item.type, item.xPx, item.yPx, item);
    });
    zCounter = Math.max(100, ...items.map(item => Number(item.z) || 1));
    if (legacyItems.length) setStatus(`${legacyItems.length} legacy equipment block(s) shown as ghosts`, true);
  }

  function revenueSettings() {
    const values = {};
    Object.keys(revenueDefaults).forEach(key => { values[key] = Number($(key)?.value ?? revenueDefaults[key]) || 0; });
    return values;
  }

  function loadRevenue(data) {
    const revenue = {...revenueDefaults, ...(data?.revenue || {})};
    Object.entries(revenue).forEach(([key,value]) => { if ($(key)) $(key).value = value; });
  }

  async function refreshPlanList(autoLoad = false) {
    const data = await api('api/floor-plans.php', {method:'GET'});
    plans = data.plans || [];
    $('planSelect').innerHTML = '<option value="">Choose saved plan…</option>' + plans.map(plan => `<option value="${esc(plan.id)}">${esc(plan.name)} · v${plan.version}</option>`).join('');
    if (activePlanId && plans.some(plan => plan.id === activePlanId)) $('planSelect').value = activePlanId;
    if (!autoLoad) return;
    const requested = new URLSearchParams(location.search).get('plan');
    if (!activePlanId && requested && plans.some(plan => plan.id === requested)) activePlanId = requested;
    if (!activePlanId && plans.length) activePlanId = (plans.find(plan => plan.isDefault) || plans[0]).id;
    if (activePlanId) {
      $('planSelect').value = activePlanId;
      await loadPlan(activePlanId);
    } else {
      newBlankPlan(false);
    }
  }

  async function loadPlan(id) {
    const data = await api('api/floor-plans.php?id=' + encodeURIComponent(id), {method:'GET'});
    const plan = data.plan;
    activePlanId = plan.id;
    $('planName').value = plan.name;
    planW = Number(plan.data?.planWft ?? plan.widthFt ?? 50);
    planH = Number(plan.data?.planHft ?? plan.depthFt ?? 34);
    scale = Number(plan.data?.scale ?? plan.scale ?? 24);
    $('planW').value = planW;
    $('planH').value = planH;
    $('scale').value = scale;
    loadRevenue(plan.data || {});
    updateCanvas();
    clearStage();
    loadStructure(plan.data || {});
    await loadPlacements();
    dirty = false;
    history.replaceState(null, '', '?plan=' + encodeURIComponent(activePlanId));
    setStatus('Plan loaded');
  }

  async function loadPlacements() {
    stage.querySelectorAll('.equipment').forEach(el => el.remove());
    if (!activePlanId || !CAN_EQUIPMENT) {
      equipment = [];
      renderCatalog();
      recalc();
      return;
    }
    try {
      const data = await api('api/equipment-placement.php?plan=' + encodeURIComponent(activePlanId), {method:'GET'});
      equipment = [...(data.placed || []), ...(data.unplaced || []), ...(data.otherPlans || [])];
      (data.placed || []).forEach(makeEquipment);
      renderCatalog();
      recalc();
    } catch (error) {
      equipment = [];
      renderCatalog();
      setStatus(error.message, true);
      recalc();
    }
  }

  function renderCatalog() {
    if (!CAN_EQUIPMENT || !$('assetSearch')) return;
    const query = $('assetSearch').value.trim().toLowerCase();
    const matches = asset => !query || [asset.name,asset.assetType,asset.brand,asset.model,asset.locationName].join(' ').toLowerCase().includes(query);
    const card = (asset, placed) => `<div class="asset-card" draggable="${!placed && CAN_EDIT ? 'true' : 'false'}" data-asset-card="${esc(asset.id)}"><div class="meta"><strong>${esc(asset.name)}</strong><span class="pill ${asset.operationalStatus === 'out_of_service' ? 'bad' : asset.operationalStatus === 'maintenance' ? 'warn' : ''}">${esc(String(asset.operationalStatus || '').replaceAll('_',' '))}</span></div><span>${esc([asset.assetType,asset.brand,asset.model].filter(Boolean).join(' · '))}</span>${placed ? `<span>${Number(asset.xFt || 0).toFixed(1)}′ × ${Number(asset.yFt || 0).toFixed(1)}′</span>` : '<span>Drag onto plan</span>'}</div>`;
    const placed = equipment.filter(asset => asset.floorPlanId === activePlanId && matches(asset));
    const unplaced = equipment.filter(asset => !asset.floorPlanId && matches(asset));
    $('unplacedAssets').innerHTML = unplaced.length ? unplaced.map(asset => card(asset, false)).join('') : '<div class="empty">No unplaced equipment matches.</div>';
    $('placedAssets').innerHTML = placed.length ? placed.map(asset => card(asset, true)).join('') : '<div class="empty">No equipment placed yet.</div>';
    document.querySelectorAll('[data-asset-card]').forEach(el => {
      const id = el.dataset.assetCard;
      el.addEventListener('dragstart', event => event.dataTransfer.setData('application/x-equipment-asset', id));
      el.addEventListener('click', () => {
        const asset = assetById(id);
        if (asset?.floorPlanId !== activePlanId) return;
        const node = [...stage.querySelectorAll('.equipment')].find(item => item.dataset.assetId === id);
        if (node) selectObject(node, 'equipment');
      });
    });
  }

  async function savePlacement(asset, action, xFt, yFt, rotation, zIndex) {
    const data = await api('api/equipment-placement.php', {method:'POST', body:{
      action, assetId:asset.id, planId:activePlanId, xFt, yFt, rotationDeg:rotation, zIndex,
    }});
    Object.assign(asset, data.asset);
    renderCatalog();
    recalc();
    setStatus(action === 'place' ? 'Equipment placed' : 'Equipment position saved');
    return data.asset;
  }

  async function placeAsset(id, xFt, yFt) {
    if (!activePlanId) return setStatus('Save the floor plan before placing equipment.', true);
    const asset = assetById(id);
    if (!asset) return;
    try {
      const placed = await savePlacement(asset, 'place', Math.max(0, xFt), Math.max(0, yFt), 0, ++zCounter);
      makeEquipment(placed);
      renderCatalog();
      recalc();
      const node = [...stage.querySelectorAll('.equipment')].find(item => item.dataset.assetId === id);
      if (node) selectObject(node, 'equipment');
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  function openAsset(asset) {
    if (!asset) return;
    location.href = 'equipment-detail.php?id=' + encodeURIComponent(asset.id) + '&return=' + encodeURIComponent('floor-planner-ops.php?plan=' + activePlanId);
  }

  async function savePlan() {
    if (!CAN_EDIT) return;
    const name = $('planName').value.trim();
    if (!name) return setStatus('Plan name is required.', true);
    if (legacyItems.length && !confirm(`This layout still contains ${legacyItems.length} legacy equipment block(s). Saving now removes those old plan-only blocks. Continue only after you have recreated any equipment you still need in the Equipment Catalog.`)) return;
    planW = Math.max(10, Number($('planW').value) || 50);
    planH = Math.max(10, Number($('planH').value) || 34);
    scale = Math.max(8, Number($('scale').value) || 24);
    const data = {
      version:5,
      planWft:planW,
      planHft:planH,
      scale,
      revenue:revenueSettings(),
      items:structureItems(),
      canonicalEquipment:true,
    };
    try {
      const response = await api('api/floor-plans.php', {method:'POST', body:{action:'save', plan:{id:activePlanId, name, data}}});
      activePlanId = response.plan.id;
      legacyItems = [];
      dirty = false;
      await refreshPlanList(false);
      $('planSelect').value = activePlanId;
      history.replaceState(null, '', '?plan=' + encodeURIComponent(activePlanId));
      setStatus(`Layout saved · v${response.plan.version}`);
      recalc();
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  async function archivePlan() {
    if (!CAN_EDIT || !activePlanId) return;
    if (!confirm('Archive this saved floor plan? Equipment must be moved or unplaced first.')) return;
    try {
      await api('api/floor-plans.php', {method:'POST', body:{action:'archive', id:activePlanId}});
      activePlanId = '';
      await refreshPlanList(false);
      newBlankPlan(false);
      setStatus('Floor plan archived');
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  function seedStarterLayout() {
    const add = (type, xFt, yFt, data = {}) => makeStructure(type, xFt * scale, yFt * scale, {
      ...data, xPx:xFt * scale, yPx:yFt * scale,
      wPx:data.wFt ? data.wFt * scale : undefined,
      hPx:data.hFt ? data.hFt * scale : undefined,
    });
    add('zone', .5, 3, {wFt:8,hFt:26,label:'COVERED PATIO'});
    add('bar', 21, 18, {wFt:15,hFt:7,seats:18,seatZone:'bar'});
    add('host', 42.3, 28.2, {label:'HOST'});
    add('aisle', 38.3, 17, {wFt:4,hFt:12,label:'MAIN AISLE'});
    [[9.5,8.5],[9.5,14],[9.5,19.5],[9.5,25],[9.5,29],[15.5,28.5]].forEach(([x,y]) => add('table',x,y,{seats:4,seatZone:'dining'}));
    [[2.1,5],[2.1,11.8],[2.1,18.8],[2.1,25.7]].forEach(([x,y]) => add('table',x,y,{seats:4,seatZone:'patio'}));
  }

  function newBlankPlan(confirmFirst = true) {
    if (confirmFirst && dirty && !confirm('Discard unsaved structural layout and revenue changes?')) return;
    activePlanId = '';
    $('planName').value = 'New Pizzeria Layout';
    $('planSelect').value = '';
    planW = 50; planH = 34; scale = 24;
    $('planW').value = 50; $('planH').value = 34; $('scale').value = 24;
    loadRevenue({revenue:revenueDefaults});
    updateCanvas();
    clearStage();
    equipment = [];
    legacyItems = [];
    renderCatalog();
    seedStarterLayout();
    dirty = true;
    recalc();
    history.replaceState(null, '', location.pathname);
    setStatus('New unsaved plan');
  }

  function applyStructureInspector() {
    if (selectedKind !== 'structure' || !selected || !CAN_EDIT) return;
    selected.querySelector('.label').textContent = $('sLabel').value;
    selected.style.width = Math.max(scale / 2, (Number($('sW').value) || .5) * scale) + 'px';
    selected.style.height = Math.max(scale / 2, (Number($('sH').value) || .5) * scale) + 'px';
    selected.dataset.seats = Math.max(0, Number($('sSeats').value) || 0);
    selected.dataset.seatZone = $('sSeatZone').value;
    updateDim(selected);
    dirty = true;
    recalc();
  }

  function rect(el) {
    return {x:parseFloat(el.style.left)||0,y:parseFloat(el.style.top)||0,w:parseFloat(el.style.width)||0,h:parseFloat(el.style.height)||0};
  }

  function edgeGap(a, b) {
    const dx = Math.max(a.x - b.x - b.w, b.x - a.x - a.w, 0);
    const dy = Math.max(a.y - b.y - b.h, b.y - a.y - a.h, 0);
    return Math.sqrt(dx * dx + dy * dy);
  }

  function recalc() {
    const structures = [...stage.querySelectorAll('.structure')];
    let dining = 0, bar = 0, patio = 0;
    structures.forEach(el => {
      const seats = Number(el.dataset.seats) || 0;
      if (el.dataset.seatZone === 'dining') dining += seats;
      else if (el.dataset.seatZone === 'bar') bar += seats;
      else if (el.dataset.seatZone === 'patio') patio += seats;
    });
    const totalSeats = dining + bar + patio;
    $('diningSeats').textContent = dining;
    $('barSeats').textContent = bar;
    $('patioSeats').textContent = patio;
    $('seatCount').textContent = totalSeats;
    $('sqftCard').textContent = Math.round(planW * planH).toLocaleString();

    const tables = structures.filter(el => el.dataset.type === 'table');
    let minimumTableGap = Infinity;
    for (let i = 0; i < tables.length; i++) for (let j = i + 1; j < tables.length; j++) minimumTableGap = Math.min(minimumTableGap, edgeGap(rect(tables[i]), rect(tables[j])));
    $('tableSpacing').textContent = Number.isFinite(minimumTableGap) ? fmtIn(inFromPx(minimumTableGap)) : '—';

    const aisles = structures.filter(el => el.dataset.type === 'aisle');
    let minimumAisle = Infinity;
    aisles.forEach(el => { minimumAisle = Math.min(minimumAisle, inFromPx(parseFloat(el.style.width) || 0)); });
    $('aisleWidth').textContent = Number.isFinite(minimumAisle) ? fmtIn(minimumAisle) : '—';

    const placed = equipment.filter(asset => asset.floorPlanId === activePlanId);
    const soon = new Date(Date.now() + 30 * 86400000);
    const due = placed.filter(asset => asset.maintenanceRequired && asset.nextServiceOn && new Date(asset.nextServiceOn) <= soon).length;
    const out = placed.filter(asset => asset.operationalStatus === 'out_of_service').length;
    $('equipmentCount').textContent = placed.length;
    $('dueCount').textContent = due;
    $('outCount').textContent = out;

    const revenue = revenueSettings();
    const lunchCovers = totalSeats * (revenue.lOcc / 100) * revenue.lTurns;
    const dinnerCovers = totalSeats * (revenue.dOcc / 100) * revenue.dTurns;
    const baseSales = lunchCovers * revenue.lCheck + dinnerCovers * revenue.dCheck;
    const barSales = baseSales * (revenue.barPct / 100);
    const gelatoSales = (lunchCovers + dinnerCovers) * (revenue.gelPct / 100) * revenue.gelAvg;
    $('lCovers').textContent = Math.round(lunchCovers);
    $('dCovers').textContent = Math.round(dinnerCovers);
    $('barRevenue').textContent = money(barSales);
    $('gelatoRevenue').textContent = money(gelatoSales);
    $('dailyRevenue').textContent = money(baseSales + barSales + gelatoSales);

    const warnings = [];
    if (!activePlanId) warnings.push('Save this plan before placing canonical equipment assets.');
    if (legacyItems.length) warnings.push(`${legacyItems.length} legacy equipment block(s) are shown as ghosts. Replace them with Equipment Catalog assets before saving the canonical layout.`);
    if (Number.isFinite(minimumTableGap) && inFromPx(minimumTableGap) < 36) warnings.push(`Some tables are closer than 3' edge-to-edge (${fmtIn(inFromPx(minimumTableGap))}).`);
    if (Number.isFinite(minimumAisle) && minimumAisle < 36) warnings.push(`A marked aisle is under 3' wide (${fmtIn(minimumAisle)}).`);
    const outOfBounds = structures.filter(el => {
      const r = rect(el);
      return r.x < 0 || r.y < 0 || r.x + r.w > planW * scale + 0.5 || r.y + r.h > planH * scale + 0.5;
    });
    if (outOfBounds.length) warnings.push(`${outOfBounds.length} structural object(s) extend beyond the plan boundary.`);
    if (out) warnings.push(`${out} placed asset(s) are marked out of service.`);
    if (due) warnings.push(`${due} placed asset(s) have maintenance overdue or due within 30 days.`);
    const checks = $('checks');
    checks.className = 'alert ' + (warnings.length ? 'warn' : 'good');
    checks.innerHTML = warnings.length ? warnings.map(esc).join('<br>') : 'Canonical equipment placement, spacing, and current maintenance status look clear.';
  }

  async function askAgent(message) {
    if (!CAN_AGENT || !message.trim()) return;
    try {
      $('agentOutput').textContent = 'Thinking…';
      const data = await api('api/agent-brain.php', {method:'POST', body:{action:'ask', message:message.trim()}});
      $('agentOutput').textContent = data.answer || 'No answer returned.';
    } catch (error) {
      $('agentOutput').textContent = error.message;
    }
  }

  function bind() {
    $('backBtn').onclick = () => { location.href = 'index.php'; };
    $('equipmentCatalogBtn').onclick = () => { location.href = 'equipment.php'; };
    $('newPlanBtn').onclick = () => newBlankPlan();
    $('savePlanBtn').onclick = savePlan;
    $('archivePlanBtn').onclick = archivePlan;
    $('planSelect').onchange = event => { if (event.target.value) loadPlan(event.target.value); };
    $('zoom').oninput = event => { zoom = Number(event.target.value) / 100; updateCanvas(); };

    $('applyScaleBtn').onclick = () => {
      if (!CAN_EDIT) return;
      const oldScale = scale;
      planW = Math.max(10, Number($('planW').value) || 50);
      planH = Math.max(10, Number($('planH').value) || 34);
      scale = Math.max(8, Number($('scale').value) || 24);
      const ratio = scale / oldScale;
      stage.querySelectorAll('.structure').forEach(el => {
        el.style.left = parseFloat(el.style.left) * ratio + 'px';
        el.style.top = parseFloat(el.style.top) * ratio + 'px';
        el.style.width = parseFloat(el.style.width) * ratio + 'px';
        el.style.height = parseFloat(el.style.height) * ratio + 'px';
        updateDim(el);
      });
      updateCanvas();
      stage.querySelectorAll('.equipment').forEach(el => {
        const asset = assetById(el.dataset.assetId);
        const foot = footprint(asset);
        el.style.left = (asset.xFt || 0) * scale + 'px';
        el.style.top = (asset.yFt || 0) * scale + 'px';
        el.style.width = pxFromIn(foot[0]) + 'px';
        el.style.height = pxFromIn(foot[1]) + 'px';
      });
      dirty = true;
      recalc();
    };

    document.querySelectorAll('[data-structure]').forEach(palette => {
      palette.addEventListener('dragstart', event => {
        if (!CAN_EDIT) return event.preventDefault();
        event.dataTransfer.setData('application/x-structure', palette.dataset.structure);
      });
      palette.addEventListener('click', () => {
        if (!CAN_EDIT) return;
        const el = makeStructure(palette.dataset.structure, 60, 60);
        selectObject(el, 'structure');
        dirty = true;
        recalc();
      });
    });

    stage.addEventListener('dragover', event => { if (CAN_EDIT) event.preventDefault(); });
    stage.addEventListener('drop', event => {
      if (!CAN_EDIT) return;
      event.preventDefault();
      const bounds = stage.getBoundingClientRect();
      const x = (event.clientX - bounds.left) / zoom;
      const y = (event.clientY - bounds.top) / zoom;
      const assetId = event.dataTransfer.getData('application/x-equipment-asset');
      const type = event.dataTransfer.getData('application/x-structure');
      if (assetId) placeAsset(assetId, x / scale, y / scale);
      else if (type) {
        const el = makeStructure(type, x, y);
        selectObject(el, 'structure');
        dirty = true;
        recalc();
      }
    });
    stage.addEventListener('click', event => { if (event.target === stage) selectObject(null, ''); });

    ['sLabel','sW','sH','sSeats','sSeatZone'].forEach(id => $(id).addEventListener('input', applyStructureInspector));
    Object.keys(revenueDefaults).forEach(id => $(id).addEventListener('input', () => { dirty = true; recalc(); }));
    $('assetSearch')?.addEventListener('input', renderCatalog);

    $('deleteBtn').onclick = async () => {
      if (!selected || !CAN_EDIT) return;
      if (selectedKind === 'structure') {
        selected.remove(); selectObject(null, ''); dirty = true; recalc(); return;
      }
      const asset = assetById(selected.dataset.assetId);
      if (!asset || !confirm(`Remove ${asset.name} from this floor plan? The equipment record will remain in the catalog.`)) return;
      try {
        const data = await api('api/equipment-placement.php', {method:'POST', body:{action:'unplace', assetId:asset.id}});
        Object.assign(asset, data.asset);
        selected.remove();
        selectObject(null, '');
        renderCatalog();
        recalc();
        setStatus('Equipment removed from plan');
      } catch (error) { setStatus(error.message, true); }
    };

    $('rotateBtn').onclick = async () => {
      if (!selected || !CAN_EDIT) return;
      if (selectedKind === 'structure') {
        const rotation = ((Number(selected.dataset.rotation) || 0) + 90) % 360;
        selected.dataset.rotation = rotation;
        selected.style.transform = `rotate(${rotation}deg)`;
        dirty = true;
        recalc();
        return;
      }
      const asset = assetById(selected.dataset.assetId);
      if (!asset || asset.locked) return;
      const rotation = ((Number(asset.rotationDeg) || 0) + 90) % 360;
      try {
        const updated = await savePlacement(asset, 'move', asset.xFt || 0, asset.yFt || 0, rotation, asset.zIndex || 10);
        selected.dataset.rotation = updated.rotationDeg;
        selected.style.transform = `rotate(${updated.rotationDeg}deg)`;
        renderEquipmentInspector(updated);
      } catch (error) {
        setStatus(error.message, true);
        await loadPlacements();
      }
    };

    $('frontBtn').onclick = async () => {
      if (!selected || !CAN_EDIT) return;
      if (selectedKind === 'structure') { selected.style.zIndex = ++zCounter; dirty = true; return; }
      const asset = assetById(selected.dataset.assetId);
      if (!asset || asset.locked) return;
      try {
        await savePlacement(asset, 'move', asset.xFt || 0, asset.yFt || 0, asset.rotationDeg || 0, ++zCounter);
        selected.style.zIndex = zCounter;
      } catch (error) { setStatus(error.message, true); }
    };

    $('duplicateBtn').onclick = () => {
      if (!selected || selectedKind !== 'structure' || !CAN_EDIT) return;
      const data = structuralData(selected);
      data.id = '';
      data.xPx += scale;
      data.yPx += scale;
      const el = makeStructure(data.type, data.xPx, data.yPx, data);
      selectObject(el, 'structure');
      dirty = true;
      recalc();
    };

    $('openAssetBtn').onclick = () => selectedKind === 'equipment' && openAsset(assetById(selected.dataset.assetId));
    $('lockAssetBtn').onclick = async () => {
      if (selectedKind !== 'equipment' || !selected || !CAN_EDIT) return;
      const asset = assetById(selected.dataset.assetId);
      try {
        const data = await api('api/equipment-placement.php', {method:'POST', body:{action:'lock',assetId:asset.id,locked:!asset.locked}});
        Object.assign(asset, data.asset);
        selected.classList.toggle('locked', asset.locked);
        renderEquipmentInspector(asset);
        setStatus(asset.locked ? 'Position locked' : 'Position unlocked');
      } catch (error) { setStatus(error.message, true); }
    };

    $('quickAssetBtn')?.addEventListener('click', () => $('assetModal').classList.add('open'));
    $('closeAssetModal')?.addEventListener('click', () => $('assetModal').classList.remove('open'));
    $('createAssetBtn')?.addEventListener('click', async () => {
      const name = $('qaName').value.trim();
      if (!name) return setStatus('Equipment name is required.', true);
      try {
        await api('api/equipment.php', {method:'POST', body:{action:'save_asset',asset:{
          name, assetType:$('qaType').value, purpose:$('qaPurpose').value.trim(), brand:$('qaBrand').value.trim(), model:$('qaModel').value.trim(),
          widthInches:$('qaWidth').value, depthInches:$('qaDepth').value, criticality:'medium', operationalStatus:'active', conditionStatus:'good',
        }}});
        $('assetModal').classList.remove('open');
        await loadPlacements();
        $('assetSearch').value = name;
        renderCatalog();
        setStatus('Equipment asset created');
      } catch (error) { setStatus(error.message, true); }
    });

    $('agentAskBtn')?.addEventListener('click', () => { askAgent($('agentInput').value); $('agentInput').value = ''; });
    $('agentInput')?.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); $('agentAskBtn').click(); } });
    document.querySelectorAll('[data-agent-prompt]').forEach(button => { button.onclick = () => askAgent(button.dataset.agentPrompt); });
    $('askSelectedService')?.addEventListener('click', () => {
      const asset = selectedKind === 'equipment' && selected ? assetById(selected.dataset.assetId) : null;
      askAgent(asset ? `Who should I call to service ${asset.name}?` : 'Who are our equipment service contacts?');
    });

    window.addEventListener('beforeunload', event => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });
  }

  bind();
  loadRevenue({revenue:revenueDefaults});
  updateCanvas();
  refreshPlanList(true).catch(error => setStatus(error.message, true));
})();
