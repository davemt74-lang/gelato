(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');
  const COUNTER_EDGE_HIT_PX = 20;
  const COUNTER_NODE_LIMIT = 48;
  let activeCounter = null;
  let activeCounterNodeIndex = -1;

  function status(message, bad = false) {
    const el = document.getElementById('status');
    if (!el) return;
    el.textContent = message;
    el.style.color = bad ? '#ffb4ad' : '#c5cad0';
  }

  function injectCounterFinish() {
    if (document.getElementById('fpCounterSteelFix')) return;
    const style = document.createElement('style');
    style.id = 'fpCounterSteelFix';
    style.textContent = `
      .fp-counter-shape polygon{
        fill:rgba(204,210,214,.82)!important;
        stroke:rgba(204,210,214,.82)!important;
        stroke-width:10!important;
        filter:drop-shadow(0 -1px 0 rgba(255,255,255,.72)) drop-shadow(0 2px 2px rgba(64,70,76,.16)) brightness(1.04)!important;
      }
      .fp-counter-shape{
        opacity:.96;
      }
      .fp-counter-node{
        display:none!important;
      }
      .fp-counter-edge-node{
        position:absolute;
        width:15px;
        height:15px;
        z-index:200020;
        border:3px solid #fff;
        border-radius:50%;
        background:#2563eb;
        box-shadow:0 0 0 2px rgba(37,99,235,.36),0 3px 10px rgba(0,0,0,.24);
        transform:translate(-50%,-50%);
        cursor:grab;
        touch-action:none;
      }
      .fp-counter-edge-node:active{cursor:grabbing}
      body.fp-counter-node-mode #stage .fp-counter{cursor:crosshair!important}
    `;
    document.head.appendChild(style);
  }

  function counterPoints(counter) {
    try {
      const points = JSON.parse(counter.dataset.counterPoints || 'null');
      if (Array.isArray(points) && points.length >= 3) {
        return points.map(point => ({x:Number(point.x) || 0, y:Number(point.y) || 0}));
      }
    } catch {}
    return [{x:0,y:0},{x:1,y:0},{x:1,y:1},{x:0,y:1}];
  }

  function counterSvg(counter) {
    return counter?.querySelector('svg.fp-counter-shape') || null;
  }

  function svgPointFromClient(svg, clientX, clientY) {
    const matrix = svg?.getScreenCTM?.();
    if (!matrix) return null;
    const point = svg.createSVGPoint();
    point.x = clientX;
    point.y = clientY;
    const local = point.matrixTransform(matrix.inverse());
    return {x:local.x / 1000, y:local.y / 1000};
  }

  function clientPointFromSvg(svg, pointValue) {
    const matrix = svg?.getScreenCTM?.();
    if (!matrix) return null;
    const point = svg.createSVGPoint();
    point.x = pointValue.x * 1000;
    point.y = pointValue.y * 1000;
    const screen = point.matrixTransform(matrix);
    return {x:screen.x, y:screen.y};
  }

  function nearestCounterEdge(counter, clientX, clientY) {
    const svg = counterSvg(counter);
    const point = svgPointFromClient(svg, clientX, clientY);
    if (!svg || !point) return null;
    const points = counterPoints(counter);
    let best = null;

    points.forEach((a, index) => {
      const b = points[(index + 1) % points.length];
      const vx = b.x - a.x;
      const vy = b.y - a.y;
      const lengthSquared = Math.max(.0000001, vx * vx + vy * vy);
      const t = Math.max(0, Math.min(1, ((point.x - a.x) * vx + (point.y - a.y) * vy) / lengthSquared));
      const projected = {x:a.x + t * vx, y:a.y + t * vy};
      const client = clientPointFromSvg(svg, projected);
      if (!client) return;
      const distance = Math.hypot(clientX - client.x, clientY - client.y);
      if (!best || distance < best.distance) best = {edgeIndex:index, projected, distance};
    });

    return best;
  }

  function serializePoints(points) {
    return JSON.stringify(points.map(point => ({
      x:Number(point.x.toFixed(5)),
      y:Number(point.y.toFixed(5)),
    })));
  }

  function renderCounterGeometry(counter, points) {
    counter.dataset.counterPoints = serializePoints(points);
    counter.dataset.counterTransform = '0';
    counter.querySelectorAll('.fp-counter-node').forEach(node => node.remove());
    const polygon = counterSvg(counter)?.querySelector('polygon');
    if (polygon) {
      polygon.setAttribute('points', points.map(point => `${Math.round(point.x * 1000)},${Math.round(point.y * 1000)}`).join(' '));
    }
    positionActiveCounterNode();
  }

  function clearActiveCounterNode() {
    document.querySelectorAll('.fp-counter-edge-node').forEach(node => node.remove());
    activeCounter = null;
    activeCounterNodeIndex = -1;
  }

  function positionActiveCounterNode() {
    if (!activeCounter || activeCounterNodeIndex < 0 || !activeCounter.isConnected) return;
    const points = counterPoints(activeCounter);
    const point = points[activeCounterNodeIndex];
    if (!point) return;
    let handle = activeCounter.querySelector('.fp-counter-edge-node');
    if (!handle) {
      handle = document.createElement('span');
      handle.className = 'fp-counter-edge-node';
      handle.title = 'Drag to reshape counter';
      activeCounter.appendChild(handle);
    }
    handle.dataset.index = String(activeCounterNodeIndex);
    handle.style.left = `${point.x * 100}%`;
    handle.style.top = `${point.y * 100}%`;
  }

  function activateCounterNode(counter, index) {
    clearActiveCounterNode();
    activeCounter = counter;
    activeCounterNodeIndex = index;
    positionActiveCounterNode();
  }

  function constrainCounterPoint(point) {
    return {
      x:Math.max(-4, Math.min(5, point.x)),
      y:Math.max(-4, Math.min(5, point.y)),
    };
  }

  function dragCounterNode(event, counter, index) {
    const svg = counterSvg(counter);
    if (!svg) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    activateCounterNode(counter, index);

    const move = current => {
      const next = svgPointFromClient(svg, current.clientX, current.clientY);
      if (!next) return;
      const points = counterPoints(counter);
      if (!points[index]) return;
      points[index] = constrainCounterPoint(next);
      renderCounterGeometry(counter, points);
    };

    const up = () => {
      window.removeEventListener('pointermove', move, true);
      window.removeEventListener('pointerup', up, true);
      status('Counter shape updated. Ctrl-click another edge to add a node.');
    };

    window.addEventListener('pointermove', move, true);
    window.addEventListener('pointerup', up, true);
  }

  function addCounterEdgeNode(event, counter) {
    const hit = nearestCounterEdge(counter, event.clientX, event.clientY);
    if (!hit || hit.distance > COUNTER_EDGE_HIT_PX) return false;
    const points = counterPoints(counter);
    if (points.length >= COUNTER_NODE_LIMIT) {
      status(`Counter node limit reached (${COUNTER_NODE_LIMIT}).`, true);
      return true;
    }

    const index = hit.edgeIndex + 1;
    points.splice(index, 0, hit.projected);
    renderCounterGeometry(counter, points);
    activateCounterNode(counter, index);
    status('Counter node added — drag to extend the shape.');
    dragCounterNode(event, counter, index);
    return true;
  }

  function simplifyLegacyCounterMenu() {
    document.querySelectorAll('#fpCounterMenu').forEach(menu => {
      menu.querySelectorAll('[data-counter-action="add"],[data-counter-action="transform"]').forEach(button => button.remove());
      const reset = menu.querySelector('[data-counter-action="reset"]');
      if (reset) reset.textContent = 'Reset Counter Shape';
    });
  }

  function installCounterEdgeEditing() {
    document.addEventListener('keydown', event => {
      if (event.key === 'Control') document.body.classList.add('fp-counter-node-mode');
    }, true);
    document.addEventListener('keyup', event => {
      if (event.key === 'Control') document.body.classList.remove('fp-counter-node-mode');
    }, true);
    window.addEventListener('blur', () => document.body.classList.remove('fp-counter-node-mode'));

    document.addEventListener('pointerdown', event => {
      if (!cfg.canEdit || event.button !== 0 || !(event.target instanceof Element)) return;

      const handle = event.target.closest('.fp-counter-edge-node');
      if (handle) {
        const counter = handle.closest('#stage .fp-counter');
        const index = Number(handle.dataset.index);
        if (counter && Number.isInteger(index) && index >= 0) dragCounterNode(event, counter, index);
        return;
      }

      const counter = event.target.closest('#stage .fp-counter');
      if (event.ctrlKey && counter && addCounterEdgeNode(event, counter)) return;

      if (!counter && !event.target.closest('#fpCounterMenu')) clearActiveCounterNode();
    }, true);

    const menuObserver = new MutationObserver(simplifyLegacyCounterMenu);
    menuObserver.observe(document.body, {childList:true, subtree:true});
    simplifyLegacyCounterMenu();
  }

  async function createEquipmentFromPlanner(button) {
    if (!cfg.canEquipmentEdit) {
      status('Equipment edit permission is required.', true);
      return;
    }

    const name = document.getElementById('qaName')?.value.trim() || '';
    if (!name) {
      status('Equipment name is required.', true);
      document.getElementById('qaName')?.focus();
      return;
    }

    const asset = {
      name,
      assetType: document.getElementById('qaType')?.value || 'other',
      purpose: document.getElementById('qaPurpose')?.value.trim() || '',
      brand: document.getElementById('qaBrand')?.value.trim() || '',
      model: document.getElementById('qaModel')?.value.trim() || '',
      widthInches: document.getElementById('qaWidth')?.value || '',
      depthInches: document.getElementById('qaDepth')?.value || '',
    };

    button.disabled = true;
    status(`Creating ${name}…`);
    try {
      const response = await fetch('api/floor-planner-equipment-create.php', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({asset}),
      });
      let data = null;
      try {
        data = await response.json();
      } catch {
        throw new Error(`Invalid server response (${response.status}).`);
      }
      if (!response.ok || data?.ok === false) {
        throw new Error(data?.message || `Equipment creation failed (${response.status}).`);
      }

      document.getElementById('assetModal')?.classList.remove('open');
      status(data.message || `${name} created.`);
      setTimeout(() => location.reload(), 250);
    } catch (error) {
      status(error instanceof Error ? error.message : 'Equipment could not be created.', true);
    } finally {
      button.disabled = false;
    }
  }

  function installEquipmentCreateFix() {
    document.addEventListener('click', event => {
      const button = event.target instanceof Element ? event.target.closest('#createAssetBtn') : null;
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      createEquipmentFromPlanner(button);
    }, true);
  }

  function init() {
    injectCounterFinish();
    installCounterEdgeEditing();
    installEquipmentCreateFix();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once:true});
  } else {
    init();
  }
})();
