(() => {
  'use strict';

  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const CAN_EDIT = Boolean(cfg.canEdit);
  let dirty = false;
  let armed = false;
  let hasEdited = false;
  let mutationTimer = 0;

  function init() {
    const stage = document.getElementById('stage');
    const status = document.getElementById('status');
    const scaleInput = document.getElementById('scale');
    const zoomInput = document.getElementById('zoom');
    if (!stage) return;

    const scale = () => Math.max(8, Number(scaleInput?.value) || 24);
    const zoom = () => Math.max(.1, Number(zoomInput?.value) / 100 || 1);
    const snap = value => {
      const step = scale() / 2;
      return Math.round(value / step) * step;
    };

    function markDirty() {
      if (!armed) return;
      dirty = true;
      hasEdited = true;
    }

    function markClean() {
      dirty = false;
    }

    function resetLoadedBaseline() {
      dirty = false;
      hasEdited = false;
    }

    // Replace the legacy per-node structural drag with event delegation. This
    // keeps structures restored by Undo fully movable without rebinding private
    // functions from the canonical planner IIFE.
    stage.addEventListener('pointerdown', event => {
      if (!CAN_EDIT || event.button !== 0) return;
      const structure = event.target instanceof Element ? event.target.closest('#stage .structure') : null;
      if (!structure) return;

      // Floor Planner 2.0 owns marquee/group movement before this handler. If
      // more than one item is selected, leave the event to the group handler.
      const selected = stage.querySelectorAll('.fp-selected');
      if (selected.length > 1 && structure.classList.contains('fp-selected')) return;

      const resize = event.target instanceof Element ? event.target.closest('.resize') : null;
      event.preventDefault();
      event.stopImmediatePropagation();

      if (resize) {
        const startX = event.clientX;
        const startY = event.clientY;
        const width = parseFloat(structure.style.width) || structure.offsetWidth;
        const height = parseFloat(structure.style.height) || structure.offsetHeight;
        const move = current => {
          structure.style.width = Math.max(scale() / 2, snap(width + (current.clientX - startX) / zoom())) + 'px';
          structure.style.height = Math.max(scale() / 2, snap(height + (current.clientY - startY) / zoom())) + 'px';
          const dim = structure.querySelector('.dim');
          if (dim) {
            const wIn = (parseFloat(structure.style.width) || 0) / scale() * 12;
            const hIn = (parseFloat(structure.style.height) || 0) / scale() * 12;
            dim.textContent = `${formatInches(wIn)} × ${formatInches(hIn)}`;
          }
          markDirty();
        };
        const up = () => {
          window.removeEventListener('pointermove', move, true);
          window.removeEventListener('pointerup', up, true);
          markDirty();
          structure.click();
        };
        window.addEventListener('pointermove', move, true);
        window.addEventListener('pointerup', up, true);
        return;
      }

      const startX = event.clientX;
      const startY = event.clientY;
      const left = parseFloat(structure.style.left) || 0;
      const top = parseFloat(structure.style.top) || 0;
      const move = current => {
        structure.style.left = Math.max(0, snap(left + (current.clientX - startX) / zoom())) + 'px';
        structure.style.top = Math.max(0, snap(top + (current.clientY - startY) / zoom())) + 'px';
        markDirty();
      };
      const up = () => {
        window.removeEventListener('pointermove', move, true);
        window.removeEventListener('pointerup', up, true);
        markDirty();
        structure.click();
      };
      window.addEventListener('pointermove', move, true);
      window.addEventListener('pointerup', up, true);
    }, true);

    // Keep the comprehensive Equipment Record workflow inside Floor Planner 2.0.
    document.getElementById('openAssetBtn')?.addEventListener('click', event => {
      const equipment = stage.querySelector('.equipment.fp-primary') || stage.querySelector('.equipment.fp-selected') || stage.querySelector('.equipment.selected');
      const assetId = equipment?.dataset.assetId || '';
      if (!assetId) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const plan = new URLSearchParams(location.search).get('plan') || document.getElementById('planSelect')?.value || '';
      const returnTo = 'floor-planner-v2.php' + (plan ? '?plan=' + encodeURIComponent(plan) : '');
      location.href = 'equipment-detail.php?id=' + encodeURIComponent(assetId) + '&return=' + encodeURIComponent(returnTo);
    }, true);

    // Track edits made by the v2 group/nudge/counter layer, which lives outside
    // the canonical planner's private `dirty` variable.
    const mutationObserver = new MutationObserver(records => {
      if (!armed) return;
      if (records.some(record => record.type === 'attributes' || record.type === 'childList')) {
        clearTimeout(mutationTimer);
        mutationTimer = setTimeout(markDirty, 20);
      }
    });
    mutationObserver.observe(stage, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['style', 'class', 'data-counter-points', 'data-rotation', 'data-seats', 'data-seat-zone'],
    });

    if (status) {
      new MutationObserver(() => {
        const text = status.textContent || '';
        if (text.startsWith('Plan loaded') || text.startsWith('New unsaved plan')) resetLoadedBaseline();
        else if (text.startsWith('Layout saved')) markClean();
      }).observe(status, {childList:true,subtree:true,characterData:true});
    }

    // The preloader already owns the stable Floor Plan API route. Wrap its fetch
    // so a successful save clears the v2 dirty state as well.
    const previousFetch = window.fetch.bind(window);
    window.fetch = async (input, init = {}) => {
      const raw = typeof input === 'string' || input instanceof URL ? String(input) : String(input?.url || '');
      const method = String(init.method || 'GET').toUpperCase();
      const response = await previousFetch(input, init);
      if (method === 'POST' && /(?:floor-plan-api\.php|api\/floor-plans\.php)/.test(raw) && response.ok) {
        try {
          const data = await response.clone().json();
          if (data?.ok !== false && data?.plan) markClean();
        } catch {}
      }
      return response;
    };

    // Do not let the editor's pre-hydration blank snapshot become a user-facing
    // Undo target. Once the user has made a real edit, normal undo/redo proceeds.
    document.addEventListener('keydown', event => {
      if (hasEdited) return;
      if (!(event.ctrlKey || event.metaKey)) return;
      const key = event.key.toLowerCase();
      if (key !== 'z' && key !== 'y') return;
      event.preventDefault();
      event.stopImmediatePropagation();
    }, true);

    window.addEventListener('beforeunload', event => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });

    // Ignore DOM construction during initial plan hydration; edits after this
    // point are user-driven and should participate in the unsaved-change guard.
    setTimeout(() => {
      armed = true;
      resetLoadedBaseline();
    }, 1400);
  }

  function formatInches(value) {
    const inches = Math.max(0, Math.round(Number(value) || 0));
    return `${Math.floor(inches / 12)}' ${inches % 12}"`;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
