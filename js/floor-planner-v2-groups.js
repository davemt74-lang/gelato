(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');
  const GELATO_TYPE = 'gelato-display';
  const GROUP_ATTR = 'fpGroupId';
  const LOCKED_ATTR = 'fpGroupLocked';
  const SPACING_ATTR = 'fpEvenSpacingFt';
  const hydratedPlans = new Map();
  let menuNodes = [];
  let hydrationTimer = 0;

  const stage = () => document.getElementById('stage');
  const scale = () => Math.max(8, Number(document.getElementById('scale')?.value) || 24);
  const zoom = () => Math.max(.1, Number(document.getElementById('zoom')?.value) / 100 || 1);
  const planId = () => new URLSearchParams(location.search).get('plan') || document.getElementById('planSelect')?.value || '';

  function status(message, bad = false) {
    const el = document.getElementById('status');
    if (!el) return;
    el.textContent = message;
    el.style.color = bad ? '#ffb4ad' : '#c5cad0';
  }

  function selectedNodes() {
    const root = stage();
    if (!root) return [];
    return [...new Set([...root.querySelectorAll('.fp-selected,.fp-primary')])]
      .filter(node => node.matches('.structure,.equipment') && node.isConnected);
  }

  function allFloorNodes() {
    return [...(stage()?.querySelectorAll('.structure,.equipment') || [])];
  }

  function nodeIdentity(node) {
    return node.classList.contains('equipment')
      ? {kind:'equipment', id:String(node.dataset.assetId || '')}
      : {kind:'structure', id:String(node.dataset.id || '')};
  }

  function nodesForGroup(groupId) {
    if (!groupId) return [];
    return allFloorNodes().filter(node => node.dataset[GROUP_ATTR] === groupId);
  }

  function toggleSelectionNode(node) {
    if (!node?.isConnected) return;
    try {
      node.dispatchEvent(new PointerEvent('pointerdown', {
        bubbles:true, cancelable:true, button:0, buttons:1, shiftKey:true,
      }));
    } catch {
      // PointerEvent is required by the Floor Planner interaction layer.
    }
  }

  function syncV2Selection(targetNodes, additive = false) {
    const target = [...new Set(targetNodes)].filter(node => node?.isConnected);
    const wanted = new Set(additive ? [...selectedNodes(), ...target] : target);
    const current = new Set(selectedNodes());
    current.forEach(node => { if (!wanted.has(node)) toggleSelectionNode(node); });
    wanted.forEach(node => { if (!current.has(node)) toggleSelectionNode(node); });
  }

  function itemFrom(target) {
    return target instanceof Element ? target.closest('#stage .structure,#stage .equipment') : null;
  }

  function installLockedGroupSelection() {
    window.addEventListener('pointerdown', event => {
      if (!event.isTrusted || !cfg.canEdit) return;
      const item = itemFrom(event.target);
      if (!item || item.dataset[LOCKED_ATTR] !== '1' || !item.dataset[GROUP_ATTR]) return;
      const members = nodesForGroup(item.dataset[GROUP_ATTR]);
      if (members.length < 2) return;

      if (event.target instanceof Element && event.target.closest('.resize')) {
        syncV2Selection(members, false);
        event.preventDefault();
        event.stopImmediatePropagation();
        status('Unlock the group to resize an individual item.');
        return;
      }

      const additive = event.shiftKey || event.ctrlKey || event.metaKey;
      syncV2Selection(members, additive);
      if (additive) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    }, true);
  }

  function groupRecords() {
    const groups = new Map();
    allFloorNodes().forEach(node => {
      const id = String(node.dataset[GROUP_ATTR] || '');
      if (!id || node.dataset[LOCKED_ATTR] !== '1') return;
      if (!groups.has(id)) {
        const spacing = node.dataset[SPACING_ATTR];
        groups.set(id, {id, locked:true, spacingFt:spacing === undefined || spacing === '' ? null : Number(spacing), members:[]});
      }
      const identity = nodeIdentity(node);
      if (identity.id) groups.get(id).members.push(identity);
    });
    return [...groups.values()].filter(group => group.members.length >= 2);
  }

  function currentSpacing(nodes) {
    const values = nodes
      .map(node => node.dataset[SPACING_ATTR])
      .filter(value => value !== undefined && value !== '')
      .map(Number)
      .filter(Number.isFinite);
    return values.length ? values[0] : null;
  }

  function setSpacing(nodes, feet) {
    const targets = [...new Set(nodes)];
    if (feet === null) {
      targets.forEach(node => delete node.dataset[SPACING_ATTR]);
      return;
    }
    targets.forEach(node => { node.dataset[SPACING_ATTR] = String(Math.max(0, feet)); });
  }

  function itemRect(node) {
    return {
      node,
      x:parseFloat(node.style.left) || 0,
      y:parseFloat(node.style.top) || 0,
      w:parseFloat(node.style.width) || node.offsetWidth || 0,
      h:parseFloat(node.style.height) || node.offsetHeight || 0,
    };
  }

  function distributionAxis(rects) {
    const centersX = rects.map(rect => rect.x + rect.w / 2);
    const centersY = rects.map(rect => rect.y + rect.h / 2);
    const spanX = Math.max(...centersX) - Math.min(...centersX);
    const spanY = Math.max(...centersY) - Math.min(...centersY);
    return spanX >= spanY ? 'x' : 'y';
  }

  function averageGap(rects, axis) {
    const sorted = [...rects].sort((a,b) => axis === 'x'
      ? (a.x + a.w / 2) - (b.x + b.w / 2)
      : (a.y + a.h / 2) - (b.y + b.h / 2));
    if (sorted.length < 2) return 0;
    let total = 0;
    for (let i = 1; i < sorted.length; i += 1) {
      const previousEnd = axis === 'x' ? sorted[i - 1].x + sorted[i - 1].w : sorted[i - 1].y + sorted[i - 1].h;
      const nextStart = axis === 'x' ? sorted[i].x : sorted[i].y;
      total += Math.max(0, nextStart - previousEnd);
    }
    return total / (sorted.length - 1);
  }

  async function persistEquipmentPositions(nodes) {
    const id = planId();
    if (!id) return;
    const pxPerFoot = scale();
    const jobs = nodes.filter(node => node.classList.contains('equipment')).map(node => fetch('api/equipment-placement.php', {
      method:'POST',
      headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({
        action:'move', assetId:node.dataset.assetId, planId:id,
        xFt:(parseFloat(node.style.left) || 0) / pxPerFoot,
        yFt:(parseFloat(node.style.top) || 0) / pxPerFoot,
        rotationDeg:Number(node.dataset.rotation) || 0,
        zIndex:Number(node.style.zIndex) || 10,
      }),
    }).then(response => {
      if (!response.ok) throw new Error(`Equipment move failed (${response.status}).`);
      return response;
    }));
    if (jobs.length) await Promise.all(jobs);
  }

  async function applyEvenDistance(nodes) {
    const unique = [...new Set(nodes)].filter(node => node?.isConnected);
    if (unique.length < 3) {
      status('Select at least three items to use Even Distance.', true);
      return;
    }
    const rects = unique.map(itemRect);
    const axis = distributionAxis(rects);
    const sorted = [...rects].sort((a,b) => axis === 'x'
      ? (a.x + a.w / 2) - (b.x + b.w / 2)
      : (a.y + a.h / 2) - (b.y + b.h / 2));
    const setting = currentSpacing(unique);
    const gap = setting === null ? averageGap(sorted, axis) : setting * scale();

    let cursor = axis === 'x' ? sorted[0].x : sorted[0].y;
    sorted.forEach((rect, index) => {
      if (index === 0) return;
      const previous = sorted[index - 1];
      cursor += (axis === 'x' ? previous.w : previous.h) + gap;
      if (axis === 'x') rect.node.style.left = Math.max(0, cursor) + 'px';
      else rect.node.style.top = Math.max(0, cursor) + 'px';
    });

    try {
      await persistEquipmentPositions(unique);
      status(`Even distance applied ${axis === 'x' ? 'horizontally' : 'vertically'} · ${setting === null ? 'average gap' : `${setting} ft gap`}`);
    } catch (error) {
      status(error.message, true);
    }
  }

  function newGroupId() {
    return `group-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`;
  }

  function lockGroup(nodes) {
    const unique = [...new Set(nodes)].filter(node => node?.isConnected);
    if (unique.length < 2) return status('Select at least two items to lock a group.', true);
    const spacing = currentSpacing(unique);
    const id = newGroupId();
    unique.forEach(node => {
      node.dataset[GROUP_ATTR] = id;
      node.dataset[LOCKED_ATTR] = '1';
      if (spacing !== null) node.dataset[SPACING_ATTR] = String(spacing);
    });
    syncV2Selection(unique, false);
    status(`Group locked as one element · ${unique.length} items`);
  }

  function unlockGroup(nodes) {
    const ids = new Set(nodes.map(node => node.dataset[GROUP_ATTR]).filter(Boolean));
    if (!ids.size) return status('The selected items are not a locked group.', true);
    const members = allFloorNodes().filter(node => ids.has(node.dataset[GROUP_ATTR]));
    members.forEach(node => {
      delete node.dataset[GROUP_ATTR];
      delete node.dataset[LOCKED_ATTR];
      delete node.dataset[SPACING_ATTR];
    });
    syncV2Selection(members, false);
    status(`Group unlocked · ${members.length} items are independent again`);
  }

  function cloneStructure(node, offset, groupId = '') {
    const clone = node.cloneNode(true);
    clone.classList.remove('selected','fp-selected','fp-primary');
    clone.querySelectorAll('.selected,.fp-selected,.fp-primary').forEach(child => child.classList.remove('selected','fp-selected','fp-primary'));
    clone.dataset.id = `structure-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`;
    clone.style.left = Math.max(0, (parseFloat(node.style.left) || 0) + offset) + 'px';
    clone.style.top = Math.max(0, (parseFloat(node.style.top) || 0) + offset) + 'px';
    clone.style.zIndex = String((Number(node.style.zIndex) || 1) + 1);
    if (groupId) {
      clone.dataset[GROUP_ATTR] = groupId;
      clone.dataset[LOCKED_ATTR] = '1';
    } else {
      delete clone.dataset[GROUP_ATTR];
      delete clone.dataset[LOCKED_ATTR];
    }
    return clone;
  }

  function duplicateGroup(nodes) {
    if (!cfg.canEdit) return;
    const structures = nodes.filter(node => node.classList.contains('structure'));
    const skipped = nodes.length - structures.length;
    if (!structures.length) return status('Equipment records are unique and cannot be duplicated as new assets.', true);
    const sourceLocked = nodes.some(node => node.dataset[LOCKED_ATTR] === '1');
    const copyGroupId = sourceLocked && structures.length > 1 ? newGroupId() : '';
    const offset = scale();
    const root = stage();
    const clones = structures.map(node => cloneStructure(node, offset, copyGroupId));
    clones.forEach(clone => root.appendChild(clone));
    syncV2Selection(clones, false);
    status(`Duplicated ${clones.length} group item${clones.length === 1 ? '' : 's'}${skipped ? ` · ${skipped} equipment asset${skipped === 1 ? '' : 's'} skipped` : ''}`);
  }

  function hideGroupMenu() {
    document.getElementById('fpGroupMenu')?.remove();
    menuNodes = [];
  }

  function showGroupMenu(nodes, event) {
    hideGroupMenu();
    menuNodes = [...new Set(nodes)].filter(node => node?.isConnected);
    const spacing = currentSpacing(menuNodes);
    const hasLocked = menuNodes.some(node => node.dataset[LOCKED_ATTR] === '1' && node.dataset[GROUP_ATTR]);
    const hasStructures = menuNodes.some(node => node.classList.contains('structure'));
    const menu = document.createElement('div');
    menu.id = 'fpGroupMenu';
    menu.innerHTML = `
      <div class="fp-group-menu-title">Group · ${menuNodes.length} items</div>
      <button type="button" data-group-action="even">Even Distance</button>
      <label class="fp-group-spacing"><span>Even Distance Setting</span><div><input type="number" min="0" step="0.25" data-group-spacing placeholder="Auto average" value="${spacing === null ? '' : spacing}"><b>ft</b></div></label>
      <button type="button" data-group-action="duplicate"${hasStructures ? '' : ' disabled'}>Duplicate Group</button>
      <button type="button" data-group-action="lock">Lock Group</button>
      <button type="button" data-group-action="unlock"${hasLocked ? '' : ' disabled'}>Unlock Group</button>
    `;
    document.body.appendChild(menu);
    const width = 230;
    const height = 252;
    menu.style.left = Math.max(8, Math.min(event.clientX, innerWidth - width - 8)) + 'px';
    menu.style.top = Math.max(8, Math.min(event.clientY, innerHeight - height - 8)) + 'px';

    menu.addEventListener('click', async click => {
      const action = click.target.closest('button')?.dataset.groupAction;
      if (!action) return;
      click.preventDefault();
      if (action === 'even') await applyEvenDistance(menuNodes);
      if (action === 'duplicate') duplicateGroup(menuNodes);
      if (action === 'lock') lockGroup(menuNodes);
      if (action === 'unlock') unlockGroup(menuNodes);
      hideGroupMenu();
    });

    menu.querySelector('[data-group-spacing]')?.addEventListener('change', change => {
      const raw = String(change.target.value || '').trim();
      const value = raw === '' ? null : Math.max(0, Number(raw) || 0);
      const ids = new Set(menuNodes.map(node => node.dataset[GROUP_ATTR]).filter(Boolean));
      const targets = ids.size
        ? allFloorNodes().filter(node => ids.has(node.dataset[GROUP_ATTR]))
        : menuNodes;
      setSpacing(targets, value);
      status(value === null ? 'Even Distance set to automatic average.' : `Even Distance setting saved at ${value} ft.`);
    });
  }

  function installGroupMenu() {
    window.addEventListener('contextmenu', event => {
      const item = itemFrom(event.target);
      if (!item) return;

      if (item.dataset[LOCKED_ATTR] === '1' && item.dataset[GROUP_ATTR]) {
        const members = nodesForGroup(item.dataset[GROUP_ATTR]);
        const current = selectedNodes();
        if (!current.includes(item)) syncV2Selection(members, false);
      }

      const current = selectedNodes();
      if (current.length < 2 || !current.includes(item)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      document.getElementById('fpCounterMenu')?.remove();
      showGroupMenu(current, event);
    }, true);

    document.addEventListener('pointerdown', event => {
      if (!event.target.closest?.('#fpGroupMenu')) hideGroupMenu();
    }, true);
    window.addEventListener('blur', hideGroupMenu);
  }

  function formatInches(value) {
    const inches = Math.max(0, Math.round(Number(value) || 0));
    return `${Math.floor(inches / 12)}' ${inches % 12}\"`;
  }

  function gelatoNode(raw = {}) {
    const root = stage();
    if (!root) return null;
    const pxPerFoot = scale();
    const node = document.createElement('div');
    node.className = 'structure gelato-display';
    node.dataset.id = raw.id || `structure-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`;
    node.dataset.type = GELATO_TYPE;
    node.dataset.rotation = String(Number(raw.r ?? raw.rot ?? raw.rotation ?? 0) || 0);
    node.dataset.seats = String(Number(raw.seats ?? 0) || 0);
    node.dataset.seatZone = String(raw.seatZone ?? raw.zone ?? 'none');
    node.style.left = Number(raw.xPx ?? raw.x ?? 60) + 'px';
    node.style.top = Number(raw.yPx ?? raw.y ?? 60) + 'px';
    node.style.width = Number(raw.wPx ?? raw.w ?? pxPerFoot * 6) + 'px';
    node.style.height = Number(raw.hPx ?? raw.h ?? pxPerFoot * 2.5) + 'px';
    node.style.transform = `rotate(${node.dataset.rotation}deg)`;
    node.style.zIndex = String(Number(raw.z ?? 20) || 20);
    node.innerHTML = '<span class="label"></span><span class="dim"></span><span class="resize"></span>';
    node.querySelector('.label').textContent = String(raw.label ?? 'GELATO');
    node.querySelector('.dim').textContent = `${formatInches((parseFloat(node.style.width) || 0) / pxPerFoot * 12)} × ${formatInches((parseFloat(node.style.height) || 0) / pxPerFoot * 12)}`;
    root.appendChild(node);
    return node;
  }

  function installGelatoPalette() {
    if (document.querySelector('[data-structure="gelato-display"]')) return;
    const palette = document.querySelector('.palette');
    if (!palette) return;
    const item = document.createElement('div');
    item.className = 'pal';
    item.draggable = true;
    item.dataset.structure = GELATO_TYPE;
    item.innerHTML = '<span class="ico">G</span>Gelato';
    palette.appendChild(item);

    item.addEventListener('dragstart', event => {
      if (!cfg.canEdit) return event.preventDefault();
      event.stopImmediatePropagation();
      event.dataTransfer.setData('application/x-structure', GELATO_TYPE);
    });
    item.addEventListener('click', event => {
      if (!cfg.canEdit) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const node = gelatoNode();
      if (node) {
        syncV2Selection([node], false);
        status('Gelato element added');
      }
    });

    window.addEventListener('drop', event => {
      const type = event.dataTransfer?.getData('application/x-structure');
      if (type !== GELATO_TYPE || !cfg.canEdit) return;
      const root = stage();
      if (!root) return;
      const bounds = root.getBoundingClientRect();
      const node = gelatoNode({
        xPx:(event.clientX - bounds.left) / zoom(),
        yPx:(event.clientY - bounds.top) / zoom(),
      });
      if (!node) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      syncV2Selection([node], false);
      status('Gelato element added');
    }, true);
  }

  function injectStyles() {
    if (document.getElementById('fpGroupToolsStyles')) return;
    const style = document.createElement('style');
    style.id = 'fpGroupToolsStyles';
    style.textContent = `
      #fpGroupMenu{position:fixed;z-index:500000;width:230px;padding:7px;background:#fff;border:1px solid #d9dde3;border-radius:11px;box-shadow:0 18px 45px rgba(0,0,0,.22);font:11px Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#17191c}
      #fpGroupMenu .fp-group-menu-title{padding:6px 8px 8px;font-size:9px;font-weight:850;text-transform:uppercase;letter-spacing:.07em;color:#6f7680}
      #fpGroupMenu button{display:block;width:100%;padding:8px 9px;border:0;border-radius:7px;background:#fff;text-align:left;font-size:11px;font-weight:750;color:#17191c}
      #fpGroupMenu button:hover{background:#f1f3f5}#fpGroupMenu button:disabled{opacity:.38;cursor:not-allowed}
      #fpGroupMenu .fp-group-spacing{display:block;margin:4px 0 6px;padding:7px 8px;border-top:1px solid #eceff2;border-bottom:1px solid #eceff2;color:#59616b;font-size:9px;font-weight:800}
      #fpGroupMenu .fp-group-spacing>div{display:flex;align-items:center;gap:5px;margin-top:5px}#fpGroupMenu .fp-group-spacing input{width:100%;min-width:0;padding:6px 7px;border:1px solid #d8dce1;border-radius:6px;font-size:10px}#fpGroupMenu .fp-group-spacing b{font-size:9px;color:#777}
      #stage .structure.gelato-display{background:linear-gradient(180deg,#e8faf8 0%,#cfeaec 100%)!important;border:2px solid #4f7f86!important;border-radius:24px 24px 15px 15px / 18px 18px 12px 12px!important;box-shadow:inset 0 3px 0 rgba(255,255,255,.72),0 2px 5px rgba(35,79,86,.15)!important;color:#244f55!important;font-weight:850!important}
      #stage .structure.gelato-display .label{letter-spacing:.04em}
    `;
    document.head.appendChild(style);
  }

  function specialItemsForCurrentPlan() {
    return hydratedPlans.get(planId())?.gelato || [];
  }

  function restoreGelatoItems() {
    const root = stage();
    if (!root) return false;
    let added = false;
    specialItemsForCurrentPlan().forEach(raw => {
      const exists = [...root.querySelectorAll('.structure')].some(node => String(node.dataset.id || '') === String(raw.id || ''));
      if (!exists) {
        gelatoNode(raw);
        added = true;
      }
    });
    return added;
  }

  function applyGroups() {
    const root = stage();
    if (!root) return;
    const groups = hydratedPlans.get(planId())?.groups || [];
    groups.forEach(group => {
      if (!group?.id || !Array.isArray(group.members) || group.locked === false) return;
      group.members.forEach(member => {
        const node = allFloorNodes().find(candidate => {
          const identity = nodeIdentity(candidate);
          return identity.kind === member.kind && identity.id === String(member.id || '');
        });
        if (!node) return;
        node.dataset[GROUP_ATTR] = String(group.id);
        node.dataset[LOCKED_ATTR] = '1';
        if (group.spacingFt !== null && group.spacingFt !== undefined && Number.isFinite(Number(group.spacingFt))) {
          node.dataset[SPACING_ATTR] = String(Math.max(0, Number(group.spacingFt)));
        }
      });
    });
  }

  function hydrateCurrentPlan() {
    const added = restoreGelatoItems();
    applyGroups();
    if (added) {
      const statusEl = document.getElementById('status');
      if (statusEl && /^Plan loaded/i.test(statusEl.textContent || '')) statusEl.textContent = 'Plan loaded';
    }
  }

  function scheduleHydration() {
    clearTimeout(hydrationTimer);
    hydrationTimer = setTimeout(hydrateCurrentPlan, 30);
    setTimeout(hydrateCurrentPlan, 180);
  }

  function installPersistence() {
    const previousFetch = window.fetch.bind(window);
    window.fetch = async (input, init = {}) => {
      const raw = typeof input === 'string' || input instanceof URL ? String(input) : String(input?.url || '');
      const method = String(init.method || 'GET').toUpperCase();
      let nextInit = init;

      if (method === 'POST' && /(?:floor-plan-api\.php|api\/floor-plans\.php)/.test(raw) && typeof init.body === 'string') {
        try {
          const parsed = JSON.parse(init.body);
          if (parsed?.action === 'save' && parsed?.plan?.data) {
            parsed.plan.data.version = Math.max(7, Number(parsed.plan.data.version) || 0);
            parsed.plan.data.groups = groupRecords();
            nextInit = {...init, body:JSON.stringify(parsed)};
          }
        } catch {}
      }

      const response = await previousFetch(input, nextInit);
      if (method === 'GET' && /(?:floor-plan-api\.php|api\/floor-plans\.php)/.test(raw) && /[?&]id=/.test(raw) && response.ok) {
        try {
          const data = await response.clone().json();
          const id = String(data?.plan?.id || '');
          if (id) {
            const items = Array.isArray(data?.plan?.data?.items) ? data.plan.data.items : [];
            const groups = Array.isArray(data?.plan?.data?.groups) ? data.plan.data.groups : [];
            hydratedPlans.set(id, {gelato:items.filter(item => item?.type === GELATO_TYPE), groups});
            scheduleHydration();
          }
        } catch {}
      }
      if (method === 'GET' && /api\/equipment-placement\.php/.test(raw) && response.ok) scheduleHydration();
      return response;
    };
  }

  function initObservers() {
    const root = stage();
    if (!root) return;
    new MutationObserver(records => {
      if (records.some(record => record.type === 'childList')) scheduleHydration();
    }).observe(root, {childList:true,subtree:false});
    const statusEl = document.getElementById('status');
    if (statusEl) {
      new MutationObserver(() => {
        if (/^Plan loaded/i.test(statusEl.textContent || '')) scheduleHydration();
      }).observe(statusEl, {childList:true,subtree:true,characterData:true});
    }
  }

  // Add the palette node immediately so the canonical planner can see it during
  // its own DOMContentLoaded binding pass. Our listeners own the Gelato behavior.
  installGelatoPalette();
  installPersistence();

  function init() {
    injectStyles();
    installLockedGroupSelection();
    installGroupMenu();
    initObservers();
    scheduleHydration();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
