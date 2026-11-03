(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!window.RESTAURANT_SERVER_SESSION || !Auth?.has('resumes.view')) return;
  let loading = null, generation = 0;
  const list = document.getElementById('resumeList');
  const button = document.createElement('button');
  button.type = 'button'; button.className = 'mini-btn'; button.textContent = 'Refresh applications';
  if (list) list.before(button);
  function redraw() {
    window.RestaurantAdmin?.renderResumes?.();
    window.RestaurantAdmin?.renderJobs?.();
    window.RestaurantAdmin?.renderOwner?.();
    window.RestaurantAdmin?.setupProfile?.();
  }
  async function refresh() {
    if (loading) return loading;
    const current = generation;
    button.disabled = true;
    loading = (async () => {
      const records = []; let before = null;
      do {
        const response = await fetch('api/resumes.php' + (before ? '?before='+encodeURIComponent(before) : ''), {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok || !Array.isArray(data.resumes)) throw new Error(data.message || 'Applications could not be loaded.');
        if (current !== generation) return;
        records.push(...data.resumes);
        const next = data.nextCursor;
        if (next !== null && (!Number.isInteger(next) || next <= 0 || (before !== null && next >= before))) throw new Error('The application queue could not be loaded completely.');
        before = next;
      } while (before !== null);
      if (current !== generation) return;
      Auth.write('restaurant-resumes-v1', records);
      redraw();
    })().catch(error => {
      if (current !== generation) return;
      Auth.write('restaurant-resumes-v1', []);
      redraw();
      if (list) { list.textContent = error.message || 'Applications are unavailable. Refresh to try again.'; list.setAttribute('role','alert'); }
    }).finally(() => { loading = null; button.disabled = false; });
    return loading;
  }
  button.onclick = refresh;
  window.addEventListener('pageshow',refresh);
  window.addEventListener('focus',()=>{if (location.hash === '#resumes') refresh();});
  window.addEventListener('hashchange',()=>{if (location.hash === '#resumes') refresh();});
  window.addEventListener('gelato:private-state-cleared',()=>{generation++;if(list)list.textContent='Sign in to view applications.';});
  window.RestaurantHiring = {refresh};
  refresh();
})();
