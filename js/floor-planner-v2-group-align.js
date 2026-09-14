(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');
  const STORAGE_KEY = 'stonefellows.floorPlanner.evenDistanceFt';
  const GROUP_ATTR = 'fpGroupId';
  const LOCKED_ATTR = 'fpGroupLocked';
  const SPACING_ATTR = 'fpEvenSpacingFt';
  let rotateControl = null;
  let rotateTarget = null;
  let rotateDragging = false;

  const stage = () => document.getElementById('stage');
  const scale = () => Math.max(8, Number(document.getElementById('scale')?.value) || 24);
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
          action:'move',
          assetId:node.dataset.assetId,
          planId:id,
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

  function selectedGelato() {
    const root = stage();
    if (!root) return null;
    const candidates = [
      root.querySelector('.gelato-display.fp-primary'),
      root.querySelector('.gelato-display.selected'),
      root.querySelector('.gelato-display.fp-selected'),
    ].filter(Boolean);
    const node = candidates[0] || null;
    if (!node || node.dataset[LOCKED_ATTR] === '1') return null;
    return node;
  }

  function normalizeAngle(value) {
    return ((Number(value) % 360) + 360) % 360;
  }

  function thumbPoint(angle, radius) {
    const radians = (normalizeAngle(angle) - 90) * Math.PI / 180;
    return {x:50 + Math.cos(radians) * radius, y:50 + Math.sin(radians) * radius};
  }

  function updateRotateVisual() {
    if (!rotateControl || !rotateTarget?.isConnected) return;
    const item = itemRect(rotateTarget);
    const radiusPx = Math.max(item.w, item.h) / 2 + 28;
    const diameter = radiusPx * 2;
    rotateControl.style.width = diameter + 'px';
    rotateControl.style.height = diameter + 'px';
    rotateControl.style.left = item.x + item.w / 2 - radiusPx + 'px';
    rotateControl.style.top = item.y + item.h / 2 - radiusPx + 'px';
    const angle = normalizeAngle(rotateTarget.dataset.rotation || 0);
    const point = thumbPoint(angle, 43);
    const thumb = rotateControl.querySelector('.fp-gelato-rotate-thumb');
    if (thumb) {
      thumb.setAttribute('cx', String(point.x));
      thumb.setAttribute('cy', String(point.y));
    }
    const value = rotateControl.querySelector('.fp-gelato-rotate-value');
    if (value) value.textContent = `${Math.round(angle)}°`;
  }

  function angleFromPointer(event) {
    const box = rotateControl.getBoundingClientRect();
    const cx = box.left + box.width / 2;
    const cy = box.top + box.height / 2;
    const degrees = Math.atan2(event.clientY - cy, event.clientX - cx) * 180 / Math.PI + 90;
    return normalizeAngle(degrees);
  }

  function applyGelatoRotation(angle) {
    if (!rotateTarget?.isConnected) return;
    const normalized = normalizeAngle(angle);
    rotateTarget.dataset.rotation = String(Math.round(normalized * 10) / 10);
    rotateTarget.style.transform = `rotate(${normalized}deg)`;
    updateRotateVisual();
  }

  function removeRotateControl() {
    rotateControl?.remove();
    rotateControl = null;
    rotateTarget = null;
    rotateDragging = false;
  }

  function ensureRotateControl() {
    const target = selectedGelato();
    if (!target) {
      removeRotateControl();
      return;
    }
    if (rotateTarget !== target) removeRotateControl();
    rotateTarget = target;
    if (!rotateControl) {
      const root = stage();
      if (!root) return;
      const control = document.createElement('div');
      control.className = 'fp-gelato-rotate-control';
      control.setAttribute('aria-label', 'Gelato rotation arc slider');
      control.innerHTML = `
        <svg viewBox="0 0 100 100" aria-hidden="true">
          <circle class="fp-gelato-rotate-arc" cx="50" cy="50" r="43"></circle>
          <circle class="fp-gelato-rotate-thumb" cx="50" cy="7" r="4.7"></circle>
        </svg>
        <span class="fp-gelato-rotate-value">0°</span>
      `;
      root.appendChild(control);
      rotateControl = control;

      const begin = event => {
        if (!cfg.canEdit || !rotateTarget || event.button !== 0) return;
        event.preventDefault();
        event.stopPropagation();
        rotateDragging = true;
        control.setPointerCapture?.(event.pointerId);
        applyGelatoRotation(angleFromPointer(event));
      };
      control.addEventListener('pointerdown', begin);
      control.addEventListener('pointermove', event => {
        if (!rotateDragging) return;
        event.preventDefault();
        applyGelatoRotation(angleFromPointer(event));
      });
      const end = event => {
        if (!rotateDragging) return;
        rotateDragging = false;
        control.releasePointerCapture?.(event.pointerId);
        status(`Gelato rotation ${Math.round(normalizeAngle(rotateTarget?.dataset.rotation || 0))}°`);
      };
      control.addEventListener('pointerup', end);
      control.addEventListener('pointercancel', end);
    }
    updateRotateVisual();
  }

  function installRotateControl() {
    const root = stage();
    if (!root) return;
    const observer = new MutationObserver(() => requestAnimationFrame(ensureRotateControl));
    observer.observe(root, {
      childList:true,
      subtree:true,
      attributes:true,
      attributeFilter:['class','style','data-fp-group-locked','data-fp-group-id','data-rotation'],
    });
    window.addEventListener('resize', ensureRotateControl);
    document.getElementById('zoom')?.addEventListener('input', ensureRotateControl);
    document.getElementById('scale')?.addEventListener('input', ensureRotateControl);
    ensureRotateControl();
  }

  function injectStyles() {
    if (document.getElementById('fpGroupAlignStyles')) return;
    const style = document.createElement('style');
    style.id = 'fpGroupAlignStyles';
    style.textContent = `
      #fpGroupMenu .fp-group-align-title{margin:5px 0 2px;padding:7px 8px 4px;border-top:1px solid #eceff2;color:#6f7680;font-size:9px;font-weight:850;text-transform:uppercase;letter-spacing:.07em}
      .fp-gelato-rotate-control{position:absolute;z-index:499900;pointer-events:none;overflow:visible;transform:none!important}
      .fp-gelato-rotate-control svg{width:100%;height:100%;overflow:visible;pointer-events:auto;touch-action:none;cursor:crosshair}
      .fp-gelato-rotate-arc{fill:none;stroke:rgba(41,89,96,.62);stroke-width:2.2;stroke-linecap:round;stroke-dasharray:232 38;transform:rotate(109deg);transform-origin:50% 50%;filter:drop-shadow(0 1px 1px rgba(255,255,255,.7))}
      .fp-gelato-rotate-thumb{fill:#1d646e;stroke:#fff;stroke-width:2.2;filter:drop-shadow(0 2px 3px rgba(0,0,0,.24));pointer-events:auto;cursor:grab}
      .fp-gelato-rotate-control:active .fp-gelato-rotate-thumb{cursor:grabbing}
      .fp-gelato-rotate-value{position:absolute;left:50%;top:2px;transform:translate(-50%,-100%);padding:3px 6px;border-radius:999px;background:#163f45;color:#fff;font:800 9px/1 Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;box-shadow:0 2px 6px rgba(0,0,0,.2);pointer-events:none}
    `;
    document.head.appendChild(style);
  }

  function init() {
    injectStyles();
    installMenuEnhancements();
    installRotateControl();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
