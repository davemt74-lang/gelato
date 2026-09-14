(() => {
  'use strict';

  const STORAGE_KEY = 'floorPlanner.measurementsVisible';
  const HIDDEN_CLASS = 'fp-measurements-hidden';

  function status(message) {
    const el = document.getElementById('status');
    if (!el) return;
    el.textContent = message;
    el.style.color = '#c5cad0';
  }

  function editableTarget(target) {
    return target instanceof HTMLElement && !!target.closest('input,textarea,select,[contenteditable="true"]');
  }

  function readPreference() {
    try {
      return localStorage.getItem(STORAGE_KEY) !== '0';
    } catch {
      return true;
    }
  }

  function writePreference(visible) {
    try {
      localStorage.setItem(STORAGE_KEY, visible ? '1' : '0');
    } catch {
      // Storage can be unavailable in restrictive/private browser contexts.
    }
  }

  function setMeasurementsVisible(visible, announce = false) {
    document.documentElement.classList.toggle(HIDDEN_CLASS, !visible);
    writePreference(visible);
    if (announce) status(`Item measurements ${visible ? 'shown' : 'hidden'}.`);
  }

  function measurementsVisible() {
    return !document.documentElement.classList.contains(HIDDEN_CLASS);
  }

  function injectStyles() {
    if (document.getElementById('fpMeasurementDisplayStyles')) return;
    const style = document.createElement('style');
    style.id = 'fpMeasurementDisplayStyles';
    style.textContent = `
      html.${HIDDEN_CLASS} #stage .dim{
        display:none!important;
      }
    `;
    document.head.appendChild(style);
  }

  function installShortcut() {
    window.addEventListener('keydown', event => {
      if (editableTarget(event.target)) return;
      const key = String(event.key || '').toLowerCase();
      if (!event.ctrlKey || !event.shiftKey || event.altKey || key !== 'a') return;

      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      setMeasurementsVisible(!measurementsVisible(), true);
    }, true);
  }

  function init() {
    injectStyles();
    setMeasurementsVisible(readPreference(), false);
    installShortcut();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once:true});
  } else {
    init();
  }
})();
