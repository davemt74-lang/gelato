(() => {
  'use strict';

  const GROUP_ATTR = 'fpGroupId';
  const LOCKED_ATTR = 'fpGroupLocked';
  const SPACING_ATTR = 'fpEvenSpacingFt';
  const desired = new Map();
  let enforcing = false;

  function stage() { return document.getElementById('stage'); }

  function identity(node) {
    if (!(node instanceof Element)) return '';
    if (node.classList.contains('equipment')) return `equipment:${node.dataset.assetId || ''}`;
    if (node.classList.contains('structure')) return `structure:${node.dataset.id || ''}`;
    return '';
  }

  function selectedNodes() {
    const root = stage();
    if (!root) return [];
    return [...new Set([...root.querySelectorAll('.fp-selected,.fp-primary')])]
      .filter(node => node.matches('.structure,.equipment'));
  }

  function snapshot(node) {
    if (node.dataset[LOCKED_ATTR] !== '1' || !node.dataset[GROUP_ATTR]) return null;
    return {
      groupId:String(node.dataset[GROUP_ATTR]),
      spacing:node.dataset[SPACING_ATTR] === undefined ? null : String(node.dataset[SPACING_ATTR]),
    };
  }

  function remember(nodes, mode) {
    nodes.forEach(node => {
      const key = identity(node);
      if (!key) return;
      if (mode === 'unlock') {
        desired.set(key, null);
        node.classList.remove('fp-group-composite-member');
        return;
      }
      const state = snapshot(node);
      if (state) {
        desired.set(key, state);
        node.classList.add('fp-group-composite-member');
      }
    });
  }

  function enforceNode(node) {
    const key = identity(node);
    if (!key || !desired.has(key)) return;
    const state = desired.get(key);
    if (state === null) {
      if (node.dataset[GROUP_ATTR] !== undefined) delete node.dataset[GROUP_ATTR];
      if (node.dataset[LOCKED_ATTR] !== undefined) delete node.dataset[LOCKED_ATTR];
      if (node.dataset[SPACING_ATTR] !== undefined) delete node.dataset[SPACING_ATTR];
      node.classList.remove('fp-group-composite-member');
      return;
    }
    if (node.dataset[GROUP_ATTR] !== state.groupId) node.dataset[GROUP_ATTR] = state.groupId;
    if (node.dataset[LOCKED_ATTR] !== '1') node.dataset[LOCKED_ATTR] = '1';
    if (state.spacing === null) {
      if (node.dataset[SPACING_ATTR] !== undefined) delete node.dataset[SPACING_ATTR];
    } else if (node.dataset[SPACING_ATTR] !== state.spacing) {
      node.dataset[SPACING_ATTR] = state.spacing;
    }
    node.classList.add('fp-group-composite-member');
  }

  function enforceAll() {
    if (enforcing) return;
    enforcing = true;
    try {
      stage()?.querySelectorAll('.structure,.equipment').forEach(enforceNode);
    } finally {
      enforcing = false;
    }
  }

  function install() {
    const root = stage();
    if (!root) return;

    document.addEventListener('click', event => {
      const button = event.target instanceof Element ? event.target.closest('#fpGroupMenu [data-group-action]') : null;
      if (!button) return;
      const action = button.dataset.groupAction;
      if (action !== 'lock' && action !== 'unlock') return;
      const nodes = selectedNodes();
      setTimeout(() => {
        remember(nodes, action);
        enforceAll();
      }, 0);
    }, true);

    document.addEventListener('change', event => {
      if (!(event.target instanceof Element) || !event.target.matches('#fpGroupMenu [data-group-spacing]')) return;
      const nodes = selectedNodes();
      setTimeout(() => {
        remember(nodes, 'lock');
        enforceAll();
      }, 0);
    }, true);

    new MutationObserver(records => {
      if (enforcing) return;
      const relevant = records.some(record => record.type === 'attributes' &&
        ['data-fp-group-id','data-fp-group-locked','data-fp-even-spacing-ft'].includes(record.attributeName));
      if (relevant) queueMicrotask(enforceAll);
    }).observe(root, {
      subtree:true,
      attributes:true,
      attributeFilter:['data-fp-group-id','data-fp-group-locked','data-fp-even-spacing-ft'],
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, {once:true});
  else install();
})();
