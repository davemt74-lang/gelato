(() => {
  'use strict';
  const prefix = 'gelato-private-v1:';
  const principalKey = 'gelato-workspace-principal-v1';
  const logoutKey = 'gelato-workspace-logout-v1';
  const bootstrap = window.RESTAURANT_WORKSPACE_BOOTSTRAP;
  const identity = bootstrap?.session?.identity;
  const scope = bootstrap?.organizationId && bootstrap?.currentUser?.id && identity
    ? `${prefix}${bootstrap.organizationId}:${bootstrap.currentUser.id}:${identity}:` : null;
  const memory = new Map();
  let active = Boolean(scope);
  const legacyPrivate = key => /^(?:restaurant-admin-|restaurant-owner-agent-chat-|restaurant-audit-|restaurant-resumes-|restaurant-jobs-|restaurant-notification-read-|restaurant-activity-sync-|gelato-owner-prep-context-|gelato-agent-dismissed-next-moves)/.test(key)
    || /^(?:restaurant-(?:agent-training|quiz|flash|daily|kitchen|certifications|mistakes|custom-flashcards|mistake-flashcard|active-position|menu-sections))/.test(key);
  function storage(name) { try { return window[name]; } catch { return null; } }
  function purge(store, keepCurrent = false) {
    if (!store) return;
    try {
      for (let i = store.length - 1; i >= 0; i--) {
        const key = store.key(i);
        if (key && (legacyPrivate(key) || (key.startsWith(prefix) && !(keepCurrent && scope && key.startsWith(scope))))) store.removeItem(key);
      }
    } catch { /* A blocked storage area must not prevent secure server login. */ }
  }
  function clear(options = {}) {
    active = false;
    memory.clear();
    purge(storage('localStorage'));
    purge(storage('sessionStorage'));
    window.dispatchEvent(new CustomEvent('gelato:private-state-cleared'));
    if (options.broadcast !== false) {
      const local = storage('localStorage');
      try {
        local?.removeItem(principalKey);
        local?.setItem(logoutKey, `${Date.now()}:${Math.random()}`);
        local?.removeItem(logoutKey);
      } catch {}
    }
  }
  const privateStorage = {
    getItem(key) {
      if (!active) return null;
      key = String(key);
      try { const value = storage('sessionStorage')?.getItem(scope + key); if (value !== null && value !== undefined) return value; } catch {}
      return memory.has(key) ? memory.get(key) : null;
    },
    setItem(key, value) {
      if (!active) return;
      key = String(key); value = String(value);
      memory.set(key, value);
      try { storage('sessionStorage')?.setItem(scope + key, value); } catch {}
    },
    removeItem(key) {
      key = String(key); memory.delete(key);
      try { if (scope) storage('sessionStorage')?.removeItem(scope + key); } catch {}
    },
  };
  // Legacy shared caches never become evidence for the new authenticated actor.
  purge(storage('localStorage'));
  purge(storage('sessionStorage'), true);
  window.GelatoPrivateState = {storage: privateStorage, clear};
  if (scope) {
    try { storage('localStorage')?.setItem(principalKey, scope); } catch {}
    window.addEventListener('storage', event => {
      const signedOut = event.key === logoutKey && event.newValue;
      const switched = event.key === principalKey && event.newValue && event.newValue !== scope;
      if (!signedOut && !switched) return;
      clear({broadcast: false});
      window.location.replace('workspace.php');
    });
    window.addEventListener('pageshow', event => {
      if (event.persisted) window.location.reload();
    });
  }
  const logoutTarget = document.currentScript?.dataset?.logoutTarget;
  if (logoutTarget === 'login.php' || logoutTarget === 'customer-login.php') {
    clear();
    window.location.replace(logoutTarget);
  }
})();
