(() => {
  'use strict';

  function isActive(job) {
    if (!job || job.status !== 'published') return false;
    if (!job.closesAt) return true;
    const closingDate = new Date(`${job.closesAt}T23:59:59`);
    return Number.isNaN(closingDate.getTime()) || closingDate >= new Date();
  }

  function localJobs() {
    try {
      return JSON.parse(localStorage.getItem('restaurant-jobs-v1') || '[]').filter(isActive);
    } catch {
      return [];
    }
  }

  async function load() {
    try {
      const response = await fetch('api/public-jobs.php', {
        headers: {Accept: 'application/json'},
        cache: 'no-store'
      });
      const data = await response.json();
      if (response.ok && Array.isArray(data.jobs)) return data.jobs.filter(isActive);
    } catch {}
    return localJobs();
  }

  window.RestaurantPublicJobs = {defaults: [], isActive, load};
})();
