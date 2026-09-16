(() => {
  'use strict';
  if (window.GelatoSchedulingAgentContext) return;

  const state = {
    activeTab: 'schedule',
    week: '',
    selectedShiftPublicId: '',
    selectedStaffUserId: 0,
    availabilityUserId: 0,
  };

  const clean = (value, max = 120) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
  const asId = (value) => Math.max(0, Number.parseInt(String(value ?? '0'), 10) || 0);

  function currentWeek() {
    return clean(document.getElementById('weekPicker')?.value || state.week, 10);
  }

  function snapshot() {
    const active = document.querySelector('.tab.active[data-tab]')?.dataset.tab;
    if (active) state.activeTab = clean(active, 40);
    state.week = currentWeek();
    const availability = document.getElementById('availabilityUser');
    if (availability?.value) state.availabilityUserId = asId(availability.value);
    return {
      module: 'scheduling',
      route: 'scheduling.php',
      pageTitle: 'Staff Scheduling',
      week: state.week,
      activeTab: state.activeTab,
      selectedShiftPublicId: clean(state.selectedShiftPublicId, 100),
      selectedStaffUserId: asId(state.selectedStaffUserId),
      availabilityUserId: asId(state.availabilityUserId),
    };
  }

  function transportSnapshot() {
    const context = snapshot();
    return {
      module: context.module,
      route: context.route,
      week: context.week,
      activeTab: context.activeTab,
      selectedShiftPublicId: context.selectedShiftPublicId,
      selectedStaffUserId: context.selectedStaffUserId,
      availabilityUserId: context.availabilityUserId,
    };
  }

  function description(context = snapshot()) {
    const parts = ['Scheduling'];
    if (context.week) parts.push(`Week of ${context.week}`);
    if (context.selectedShiftPublicId) parts.push('Selected shift');
    else if (context.selectedStaffUserId) parts.push('Selected employee');
    return parts.join(' · ');
  }

  function placeholder(context = snapshot()) {
    if (context.selectedShiftPublicId) return 'Ask Gelato about this shift, coverage, changes, or cancellation…';
    if (context.selectedStaffUserId) return 'Ask Gelato about this employee’s schedule, availability, or send a shift message…';
    return 'Ask Gelato about this week, coverage, open shifts, availability, or schedule changes…';
  }

  function publish() {
    window.GelatoAgentPageContext?.publish?.();
  }

  function removeLegacyBar() {
    const input = document.getElementById('scheduleAgentInput');
    const bar = input?.closest('.agent-bar');
    if (bar) bar.remove();
  }

  document.addEventListener('click', (event) => {
    const tab = event.target.closest('.tab[data-tab]');
    if (tab?.dataset.tab) state.activeTab = clean(tab.dataset.tab, 40);
    const shift = event.target.closest('[data-shift]');
    if (shift?.dataset.shift) {
      state.selectedShiftPublicId = clean(shift.dataset.shift, 100);
      state.selectedStaffUserId = 0;
    }
    const staff = event.target.closest('[data-staff]');
    if (staff?.dataset.staff) {
      state.selectedStaffUserId = asId(staff.dataset.staff);
      state.selectedShiftPublicId = '';
    }
    const newShift = event.target.closest('#newShift');
    if (newShift) state.selectedShiftPublicId = '';
    queueMicrotask(publish);
  }, true);

  document.addEventListener('change', (event) => {
    if (event.target?.id === 'weekPicker') {
      state.week = clean(event.target.value, 10);
      state.selectedShiftPublicId = '';
    }
    if (event.target?.id === 'availabilityUser') state.availabilityUserId = asId(event.target.value);
    queueMicrotask(publish);
  }, true);

  const provider = {module: 'scheduling', snapshot, transportSnapshot, description, placeholder};
  function boot() {
    removeLegacyBar();
    state.week = currentWeek();
    window.GelatoAgentPageContext?.register?.(provider);
    publish();
  }

  window.GelatoSchedulingAgentContext = {snapshot, transportSnapshot, description, placeholder, publish};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once: true});
  else boot();
})();