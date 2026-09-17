(() => {
  'use strict';
  if (window.GelatoTimeclockAgentContext) return;

  const state = {selectedUserId: null};
  const intId = value => {
    const n = Number(value || 0);
    return Number.isInteger(n) && n > 0 ? n : null;
  };
  const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(String(value || '')) ? String(value) : null;

  function locationId() {
    const field = document.getElementById('locationFilter') || document.querySelector('select[name="location"]');
    return intId(field?.value) || intId(window.GELATO_TIME_CLOCK?.locationId);
  }

  function selectedDate() {
    return validDate(document.getElementById('dateFilter')?.value) || validDate(window.GELATO_TIME_CLOCK?.today) || null;
  }

  function snapshot() {
    return {
      module: 'timeclock',
      route: 'timeclock.php',
      pageTitle: 'Time Clock + Attendance',
      selectedDate: selectedDate(),
      selectedUserId: state.selectedUserId,
      locationId: locationId(),
    };
  }

  function transportSnapshot() {
    const current = snapshot();
    return {
      module: current.module,
      route: current.route,
      selectedDate: current.selectedDate,
      selectedUserId: current.selectedUserId,
      locationId: current.locationId,
    };
  }

  function description(context = snapshot()) {
    return context.selectedUserId ? 'Time Clock · selected employee' : `Time Clock · ${context.selectedDate || 'today'}`;
  }

  function placeholder(context = snapshot()) {
    return context.selectedUserId
      ? 'Ask Gelato about this employee’s clock status or attendance…'
      : 'Ask Gelato who is clocked in, attendance exceptions, labor variance, or your clock status…';
  }

  function publish() { return window.GelatoAgentPageContext?.publish?.() || snapshot(); }
  function register() { return !!window.GelatoAgentPageContext?.register?.(provider); }
  const provider = {module: 'timeclock', snapshot, transportSnapshot, description, placeholder};
  window.GelatoTimeclockAgentContext = {state, provider, snapshot, transportSnapshot, description, placeholder, publish, sync: publish, register};

  document.addEventListener('click', event => {
    const row = event.target.closest?.('[data-timeclock-user-id]');
    if (!row) return;
    document.querySelectorAll('[data-timeclock-user-id].is-agent-selected').forEach(node => node.classList.remove('is-agent-selected'));
    row.classList.add('is-agent-selected');
    state.selectedUserId = intId(row.dataset.timeclockUserId);
    setTimeout(publish, 0);
  });
  document.addEventListener('change', event => {
    if (['dateFilter', 'locationFilter'].includes(event.target?.id) || event.target?.matches?.('select[name="location"]')) publish();
  });
  window.addEventListener('gelato-agent-ready', () => { register(); publish(); });
  document.addEventListener('DOMContentLoaded', () => { register(); publish(); }, {once:true});
  register();
  publish();
})();
