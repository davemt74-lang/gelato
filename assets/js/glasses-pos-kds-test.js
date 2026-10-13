(() => {
  'use strict';
  const body = document.body;
  const choices = new Set(['all', 'pos', 'kds', 'glasses']);
  const buttons = [...document.querySelectorAll('[data-view-target]')];
  const compact = window.matchMedia('(max-width:1120px)');
  let userSelected = false;
  const setView = (value, manual = true) => {
    if (!choices.has(value)) return;
    if (manual) userSelected = true;
    const view = compact.matches && value === 'all' ? 'pos' : value;
    body.dataset.view = view;
    buttons.forEach((button) => {
      const selected = button.dataset.viewTarget === view;
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      button.setAttribute('aria-current', selected ? 'true' : 'false');
    });
    document.querySelectorAll('[data-focus]').forEach((button) => {
      const active = view === button.dataset.focus;
      button.textContent = active ? 'Show all' : 'Focus';
      button.setAttribute('aria-label', active ? 'Return to all workflow panels' : 'Focus ' + button.dataset.focus + ' workflow panel');
    });
  };
  buttons.forEach((button) => button.addEventListener('click', () => setView(button.dataset.viewTarget)));
  document.querySelectorAll('[data-focus]').forEach((button) => {
    button.addEventListener('click', () => setView(body.dataset.view === button.dataset.focus ? 'all' : button.dataset.focus));
  });
  compact.addEventListener('change', () => {
    if (!userSelected) setView(compact.matches ? 'pos' : 'all', false);
    else if (compact.matches && body.dataset.view === 'all') setView('pos', false);
  });
  setView(compact.matches ? 'pos' : 'all', false);
})();
