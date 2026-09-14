(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');
  const STORAGE_KEY = 'stonefellows.floorPlanner.evenDistanceFt';
  const GROUP_ATTR = 'fpGroupId';
  const LOCKED_ATTR = 'fpGroupLocked';
  const SPACING_ATTR = 'fpEvenSpacingFt';
  const ARC_ATTR = 'gelatoArcDegree';
  const GELATO_TYPE = 'gelato-display';
  const arcByPlan = new Map();
  let controls = null;
  let controlsTarget = null;
  let rotateDragging = false;
  let refreshQueued = false;

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

  function allNodes() {
    return [...(stage()?.querySelectorAll('.structure,.equipment') || [])];
  }

  function selectedNodes() {
    const root = stage();
    if (!root) return [];
    return [...new Set([...root.querySelectorAll('.fp-selected,.fp-primary,.selected')])]
      .filter(node => node.matches('.structure,.equipment') && node.isConnected);
  }

  function rememberedSpacing() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw === null || raw === '') return null;
      const value = Number(raw);
      return Number.isFinite(value) && value >= 0 ? value : null;
    } catch {
      return null;
    }
  }

  function rememberSpacing(raw) {
    const text = String(raw ?? '').trim();
    try {
      if (text === '') {
        localStorage.removeItem(STORAGE_KEY);
        return null;
      }
      const value = Number(text);
      if (!Number.isFinite(value) || value < 0) return null;
      localStorage.setItem(STORAGE_KEY, String(value));
      return value;
    } catch {
      return null;
    }
  }

  function spacingTargets(nodes) {
    const ids = new Set(nodes.map(node => String(node.dataset[GROUP_ATTR] || '')).filter(Boolean));
    return ids.size ? allNodes().filter(node => ids.has(String(node.dataset[GROUP_ATTR] || ''))) : nodes;
  }

  function applySpacingSetting(nodes, raw) {
    const text = String(raw ?? '').trim();
    const targets = [...new Set(spacingTargets(nodes))];
    if (text === '') {
      targets.forEach(node => delete node.dataset[SPACING_ATTR]);
      rememberSpacing('');
      return null;
    }
    const value = Number(text);
    if (!Number.isFinite(value) || value < 0) return null;
    targets.forEach(node => { node.dataset[SPACING_ATTR] = String(value); });
    rememberSpacing(value);
    return value;
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

  async function persistEquipment(nodes) {
    const id = planId();
    if (!id) return;
    const pxPerFoot = scale();
    const jobs = [...new Set(nodes)]
      .filter(node => node.classList.contains('equipment') && node.dataset.assetId)
      .map(node => fetch('api/equipment-placement.php', {
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

  async function alignSelectedVertically(nodes) {
    const unique = [...new Set(nodes)].filter(node => node?.isConnected);
    if (unique.length < 2) return;
    const anchor = itemRect(unique[0]);
    const centerX = anchor.x + anchor.w / 2;
    unique.slice(1).forEach(node => {
      const item = itemRect(node);
      node.style.left = Math.max(0, centerX - item.w / 2) + 'px';
    });
    try {
      await persistEquipment(unique);
      status(`Aligned ${unique.length} items vertically`);
    } catch (error) {
      status(error.message, true);
    }
  }

  function selectedGroupIds(nodes) {
    const ids = [];
    nodes.forEach(node => {
      const id = String(node.dataset[GROUP_ATTR] || '');
      if (!id || node.dataset[LOCKED_ATTR] !== '1' || ids.includes(id)) return;
      ids.push(id);
    });
    return ids;
  }

  function groupMembers(groupId) {
    return allNodes().filter(node => node.dataset[GROUP_ATTR] === groupId && node.dataset[LOCKED_ATTR] === '1');
  }

  function groupRect(groupId) {
    const members = groupMembers(groupId).map(itemRect);
    if (!members.length) return null;
    const left = Math.min(...members.map(item => item.x));
    const top = Math.min(...members.map(item => item.y));
    const right = Math.max(...members.map(item => item.x + item.w));
    const bottom = Math.max(...members.map(item => item.y + item.h));
    return {id:groupId, members, left, top, right, bottom, width:right-left, height:bottom-top};
  }

  function moveGroup(group, dx, dy) {
    const safeDx = Math.max(dx, -group.left);
    const safeDy = Math.max(dy, -group.top);
    group.members.forEach(item => {
      item.node.style.left = item.x + safeDx + 'px';
      item.node.style.top = item.y + safeDy + 'px';
    });
  }

  async function alignGroups(nodes, axis) {
    const groups = selectedGroupIds(nodes).map(groupRect).filter(Boolean);
    if (groups.length < 2) {
      status('Select at least two locked groups to align groups.', true);
      return;
    }
    const anchor = groups[0];
    const anchorCenterX = anchor.left + anchor.width / 2;
    const anchorCenterY = anchor.top + anchor.height / 2;
    const moved = [];
    groups.slice(1).forEach(group => {
      const centerX = group.left + group.width / 2;
      const centerY = group.top + group.height / 2;
      const dx = axis === 'vertical' ? anchorCenterX - centerX : 0;
      const dy = axis === 'horizontal' ? anchorCenterY - centerY : 0;
      moveGroup(group, dx, dy);
      group.members.forEach(item => moved.push(item.node));
    });
    try {
      await persistEquipment(moved);
      status(`Aligned ${groups.length} groups ${axis === 'vertical' ? 'vertically' : 'horizontally'}`);
    } catch (error) {
      status(error.message, true);
    }
  }

  function makeButton(label, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.groupAlignAction = action;
    button.textContent = label;
    return button;
  }

  function repositionMenu(menu) {
    requestAnimationFrame(() => {
      const box = menu.getBoundingClientRect();
      if (box.right > innerWidth - 8) menu.style.left = Math.max(8, innerWidth - box.width - 8) + 'px';
      if (box.bottom > innerHeight - 8) menu.style.top = Math.max(8, innerHeight - box.height - 8) + 'px';
    });
  }

  function injectMenu(menu) {
    if (!(menu instanceof Element) || menu.dataset.alignEnhanced === '1') return;
    menu.dataset.alignEnhanced = '1';
    const nodes = selectedNodes();
    if (nodes.length < 2) return;
    const evenButton = menu.querySelector('[data-group-action="even"]');
    const spacingInput = menu.querySelector('[data-group-spacing]');
    if (spacingInput) {
      const saved = rememberedSpacing();
      if (String(spacingInput.value || '').trim() === '' && saved !== null) {
        spacingInput.value = String(saved);
        applySpacingSetting(nodes, saved);
      }
      spacingInput.addEventListener('input', () => {
        const value = applySpacingSetting(nodes, spacingInput.value);
        if (value !== null) status(`Even Distance setting updated to ${value} ft.`);
      });
      spacingInput.addEventListener('change', () => {
        const value = applySpacingSetting(nodes, spacingInput.value);
        status(value === null ? 'Even Distance reset to automatic average.' : `Even Distance setting saved at ${value} ft.`);
      });
    }
    const vertical = makeButton('Align Vertically', 'vertical');
    if (evenButton) evenButton.insertAdjacentElement('afterend', vertical);
    else menu.appendChild(vertical);
    const groupIds = selectedGroupIds(nodes);
    if (groupIds.length >= 2) {
      const title = document.createElement('div');
      title.className = 'fp-group-align-title';
      title.textContent = 'Align Groups';
      menu.append(title, makeButton('Align Groups Vertically', 'groups-vertical'), makeButton('Align Groups Horizontally', 'groups-horizontal'));
    }
    repositionMenu(menu);
  }

  async function handleMenuAction(button) {
    const action = button.dataset.groupAlignAction;
    const nodes = selectedNodes();
    if (action === 'vertical') await alignSelectedVertically(nodes);
    if (action === 'groups-vertical') await alignGroups(nodes, 'vertical');
    if (action === 'groups-horizontal') await alignGroups(nodes, 'horizontal');
    document.getElementById('fpGroupMenu')?.remove();
  }

  function installMenuEnhancements() {
    document.addEventListener('click', event => {
      const button = event.target instanceof Element ? event.target.closest('#fpGroupMenu [data-group-align-action]') : null;
      if (!button) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      handleMenuAction(button);
    }, true);
    const observer = new MutationObserver(records => {
      records.forEach(record => record.addedNodes.forEach(node => {
        if (!(node instanceof Element)) return;
        if (node.id === 'fpGroupMenu') injectMenu(node);
        node.querySelectorAll?.('#fpGroupMenu').forEach(injectMenu);
      }));
    });
    observer.observe(document.documentElement, {childList:true,subtree:true});
    const existing = document.getElementById('fpGroupMenu');
    if (existing) injectMenu(existing);
  }

  function clampArc(value) {
    return Math.max(0, Math.min(45, Number(value) || 0));
  }

  function arcDegreeForNode(node) {
    const own = node?.dataset?.[ARC_ATTR];
    if (own !== undefined && own !== '') return clampArc(own);
    const cached = arcByPlan.get(planId())?.get(String(node?.dataset?.id || ''));
    return cached === undefined ? 18 : clampArc(cached);
  }

  function gelatoPath(degree) {
    const arc = clampArc(degree);
    const bow = arc / 45 * 22;
    const endInset = arc / 45 * 13;
    const topY = 17 - bow * .42;
    const bottomY = 83 - bow * .42;
    const topMidY = topY + bow;
    const bottomMidY = bottomY + bow;
    const topLeftX = 5 + endInset;
    const topRightX = 95 - endInset;
    return `M ${topLeftX.toFixed(2)} ${topY.toFixed(2)} Q 50 ${topMidY.toFixed(2)} ${topRightX.toFixed(2)} ${topY.toFixed(2)} L 95 ${bottomY.toFixed(2)} Q 50 ${bottomMidY.toFixed(2)} 5 ${bottomY.toFixed(2)} Z`;
  }

  function ensureGelatoShape(node) {
    if (!(node instanceof Element) || !node.matches('.gelato-display')) return;
    if (node.dataset[ARC_ATTR] === undefined || node.dataset[ARC_ATTR] === '') {
      node.dataset[ARC_ATTR] = String(arcDegreeForNode(node));
    }
    let svg = node.querySelector(':scope > .fp-gelato-curve-svg');
    if (!svg) {
      svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'fp-gelato-curve-svg');
      svg.setAttribute('viewBox', '0 0 100 100');
      svg.setAttribute('preserveAspectRatio', 'none');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('class', 'fp-gelato-curve-path');
      svg.appendChild(path);
      node.prepend(svg);
    }
    svg.querySelector('.fp-gelato-curve-path')?.setAttribute('d', gelatoPath(node.dataset[ARC_ATTR]));
  }

  function markArcEdited(node) {
    node.classList.toggle('fp-gelato-arc-edit-a');
    node.classList.toggle('fp-gelato-arc-edit-b');
  }

  function applyGelatoArc(node, degree, userEdit = false) {
    if (!node?.isConnected) return;
    const value = clampArc(degree);
    node.dataset[ARC_ATTR] = String(value);
    ensureGelatoShape(node);
    if (userEdit) markArcEdited(node);
    const label = controls?.querySelector('.fp-gelato-arc-value');
    if (label && node === controlsTarget) label.textContent = `${Math.round(value)}°`;
  }

  function normalizeAngle(value) {
    return ((Number(value) % 360) + 360) % 360;
  }

  function selectedGelato() {
    const root = stage();
    if (!root) return null;
    const node = root.querySelector('.gelato-display.fp-primary,.gelato-display.selected,.gelato-display.fp-selected');
    if (!node || node.dataset[LOCKED_ATTR] === '1') return null;
    return node;
  }

  function gelatoScreenCenter(node) {
    const root = stage();
    const stageBox = root.getBoundingClientRect();
    const item = itemRect(node);
    const z = zoom();
    return {
      x:stageBox.left + (item.x + item.w / 2) * z,
      y:stageBox.top + (item.y + item.h / 2) * z,
    };
  }

  function angleFromPointer(event, node) {
    const center = gelatoScreenCenter(node);
    return normalizeAngle(Math.atan2(event.clientY - center.y, event.clientX - center.x) * 180 / Math.PI + 90);
  }

  function applyGelatoRotation(node, angle) {
    if (!node?.isConnected) return;
    const normalized = Math.round(normalizeAngle(angle) * 10) / 10;
    node.dataset.rotation = String(normalized);
    node.style.transform = `rotate(${normalized}deg)`;
    updateControlsPosition();
    const value = controls?.querySelector('.fp-gelato-rotate-value');
    if (value && node === controlsTarget) value.textContent = `${Math.round(normalized)}°`;
  }

  function removeControls() {
    controls?.remove();
    controls = null;
    controlsTarget = null;
    rotateDragging = false;
  }

  function updateControlsPosition() {
    if (!controls || !controlsTarget?.isConnected) return;
    const item = itemRect(controlsTarget);
    controls.style.left = item.x + 'px';
    controls.style.top = item.y + 'px';
    controls.style.width = item.w + 'px';
    controls.style.height = item.h + 'px';
    controls.style.transform = `rotate(${Number(controlsTarget.dataset.rotation) || 0}deg)`;
  }

  function createControls(node) {
    const root = stage();
    if (!root) return null;
    const control = document.createElement('div');
    control.className = 'fp-gelato-controls';
    control.innerHTML = `
      <div class="fp-gelato-rotate-stem" aria-hidden="true"></div>
      <button class="fp-gelato-rotate-handle" type="button" aria-label="Rotate Gelato element" title="Rotate Gelato element">↻</button>
      <label class="fp-gelato-arc-control" title="Bow both Gelato edges and angle the ends to match the arc">
        <span>ARC <b class="fp-gelato-arc-value">0°</b></span>
        <input class="fp-gelato-arc-slider" type="range" min="0" max="45" step="1" aria-label="Gelato arc degree">
      </label>
      <span class="fp-gelato-rotate-value">0°</span>
    `;
    root.appendChild(control);
    const slider = control.querySelector('.fp-gelato-arc-slider');
    slider.value = String(arcDegreeForNode(node));
    control.querySelector('.fp-gelato-arc-value').textContent = `${Math.round(arcDegreeForNode(node))}°`;
    control.querySelector('.fp-gelato-rotate-value').textContent = `${Math.round(normalizeAngle(node.dataset.rotation || 0))}°`;
    slider.addEventListener('input', () => applyGelatoArc(node, slider.value, true));
    slider.addEventListener('change', () => {
      applyGelatoArc(node, slider.value, true);
      status(`Gelato arc ${Math.round(clampArc(slider.value))}°`);
    });
    const handle = control.querySelector('.fp-gelato-rotate-handle');
    handle.addEventListener('pointerdown', event => {
      if (!cfg.canEdit || event.button !== 0 || node.dataset[LOCKED_ATTR] === '1') return;
      event.preventDefault();
      event.stopPropagation();
      rotateDragging = true;
      handle.setPointerCapture?.(event.pointerId);
      applyGelatoRotation(node, angleFromPointer(event, node));
    });
    handle.addEventListener('pointermove', event => {
      if (!rotateDragging) return;
      event.preventDefault();
      applyGelatoRotation(node, angleFromPointer(event, node));
    });
    const endRotate = event => {
      if (!rotateDragging) return;
      rotateDragging = false;
      handle.releasePointerCapture?.(event.pointerId);
      status(`Gelato rotation ${Math.round(normalizeAngle(node.dataset.rotation || 0))}°`);
    };
    handle.addEventListener('pointerup', endRotate);
    handle.addEventListener('pointercancel', endRotate);
    return control;
  }

  function refreshGelato() {
    stage()?.querySelectorAll('.gelato-display').forEach(ensureGelatoShape);
    const target = selectedGelato();
    if (!target) {
      removeControls();
      return;
    }
    if (controlsTarget !== target) {
      removeControls();
      controlsTarget = target;
      controls = createControls(target);
    }
    updateControlsPosition();
    if (controls) {
      const slider = controls.querySelector('.fp-gelato-arc-slider');
      const arc = arcDegreeForNode(target);
      if (document.activeElement !== slider) slider.value = String(arc);
      controls.querySelector('.fp-gelato-arc-value').textContent = `${Math.round(arc)}°`;
      controls.querySelector('.fp-gelato-rotate-value').textContent = `${Math.round(normalizeAngle(target.dataset.rotation || 0))}°`;
    }
  }

  function queueGelatoRefresh() {
    if (refreshQueued) return;
    refreshQueued = true;
    requestAnimationFrame(() => {
      refreshQueued = false;
      refreshGelato();
    });
  }

  function installGelatoObservers() {
    const root = stage();
    if (!root) return;
    new MutationObserver(records => {
      const relevant = records.some(record => record.type === 'childList' ||
        (record.type === 'attributes' && record.target instanceof Element && record.target.matches('.gelato-display')));
      if (relevant) queueGelatoRefresh();
    }).observe(root, {
      childList:true,
      subtree:true,
      attributes:true,
      attributeFilter:['class','style','data-rotation','data-gelato-arc-degree','data-fp-group-id','data-fp-group-locked'],
    });
    window.addEventListener('resize', queueGelatoRefresh);
    document.getElementById('zoom')?.addEventListener('input', queueGelatoRefresh);
    document.getElementById('scale')?.addEventListener('input', queueGelatoRefresh);
    queueGelatoRefresh();
  }

  function installPlanPersistence() {
    const previousFetch = window.fetch.bind(window);
    window.fetch = async (input, init = {}) => {
      const raw = typeof input === 'string' || input instanceof URL ? String(input) : String(input?.url || '');
      const method = String(init.method || 'GET').toUpperCase();
      let nextInit = init;
      const planApi = /(?:floor-plan-api\.php|api\/floor-plans\.php)/.test(raw);
      if (planApi && method === 'POST' && typeof init.body === 'string') {
        try {
          const parsed = JSON.parse(init.body);
          if (parsed?.action === 'save' && Array.isArray(parsed?.plan?.data?.items)) {
            parsed.plan.data.items.forEach(item => {
              if (item?.type !== GELATO_TYPE) return;
              const node = allNodes().find(candidate => candidate.classList.contains('gelato-display') && String(candidate.dataset.id || '') === String(item.id || ''));
              if (node) item.gelatoArcDegree = arcDegreeForNode(node);
            });
            nextInit = {...init, body:JSON.stringify(parsed)};
          }
        } catch {}
      }
      const response = await previousFetch(input, nextInit);
      if (planApi && method === 'GET' && /[?&]id=/.test(raw) && response.ok) {
        try {
          const data = await response.clone().json();
          const id = String(data?.plan?.id || '');
          if (id) {
            const map = new Map();
            (Array.isArray(data?.plan?.data?.items) ? data.plan.data.items : []).forEach(item => {
              if (item?.type === GELATO_TYPE && item.id) map.set(String(item.id), clampArc(item.gelatoArcDegree ?? 18));
            });
            arcByPlan.set(id, map);
            queueGelatoRefresh();
          }
        } catch {}
      }
      return response;
    };
  }

  function injectStyles() {
    if (document.getElementById('fpGroupAlignStyles')) return;
    const style = document.createElement('style');
    style.id = 'fpGroupAlignStyles';
    style.textContent = `
      #fpGroupMenu .fp-group-align-title{margin:5px 0 2px;padding:7px 8px 4px;border-top:1px solid #eceff2;color:#6f7680;font-size:9px;font-weight:850;text-transform:uppercase;letter-spacing:.07em}
      #stage .structure.gelato-display{background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;overflow:visible!important}
      #stage .gelato-display>.fp-gelato-curve-svg{position:absolute;inset:0;width:100%;height:100%;z-index:0;overflow:visible;pointer-events:none}
      #stage .gelato-display .fp-gelato-curve-path{fill:#d7eef0;stroke:#4f7f86;stroke-width:2.2;vector-effect:non-scaling-stroke;filter:drop-shadow(0 2px 3px rgba(35,79,86,.18))}
      #stage .gelato-display>.label,#stage .gelato-display>.dim,#stage .gelato-display>.resize{z-index:2}
      #stage .fp-gelato-controls{position:absolute;z-index:499900;pointer-events:none;transform-origin:50% 50%;overflow:visible}
      #stage .fp-gelato-rotate-stem{position:absolute;left:50%;top:-25px;width:1px;height:25px;background:#275f68;transform:translateX(-50%);pointer-events:none}
      #stage .fp-gelato-rotate-handle{position:absolute;left:50%;top:-39px;width:27px;height:27px;border:2px solid #fff;border-radius:50%;background:#1d646e;color:#fff;transform:translateX(-50%);box-shadow:0 3px 8px rgba(0,0,0,.25);font:800 16px/21px system-ui,sans-serif;cursor:grab;pointer-events:auto;touch-action:none;padding:0}
      #stage .fp-gelato-rotate-handle:active{cursor:grabbing}
      #stage .fp-gelato-rotate-value{position:absolute;left:calc(50% + 21px);top:-31px;padding:2px 5px;border-radius:999px;background:#163f45;color:#fff;font:800 8px/1 Inter,system-ui,sans-serif;pointer-events:none}
      #stage .fp-gelato-arc-control{position:absolute;left:50%;bottom:7px;width:min(68%,180px);transform:translateX(-50%);display:grid;grid-template-columns:auto 1fr;gap:6px;align-items:center;padding:4px 6px;border:1px solid rgba(39,95,104,.28);border-radius:999px;background:rgba(255,255,255,.9);box-shadow:0 2px 7px rgba(0,0,0,.13);color:#285b63;font:800 8px/1 Inter,system-ui,sans-serif;pointer-events:auto;white-space:nowrap}
      #stage .fp-gelato-arc-control b{font-weight:900}
      #stage .fp-gelato-arc-slider{width:100%;min-width:52px;height:14px;margin:0;accent-color:#1d646e;cursor:ew-resize;pointer-events:auto}
    `;
    document.head.appendChild(style);
  }

  installPlanPersistence();

  function init() {
    injectStyles();
    installMenuEnhancements();
    installGelatoObservers();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
