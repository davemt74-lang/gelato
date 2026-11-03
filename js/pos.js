(() => {
  'use strict';

  const version = '20261103-pos-location1';
  const load = (src) => new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = false;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Failed to load ${src}`));
    document.head.appendChild(script);
  });

  async function boot() {
    await load(`js/agent-page-context.js?v=${version}`);
    await load(`js/pos-agent-context.js?v=${version}`);
    await load(`js/pos-runtime.js?v=${version}`);
    await load(`js/global-agent.js?v=20261103-e1`);
    await load(`js/dynamic-agent-canvas.js?v=${version}-hardening-c1`);
  }

  boot().catch((error) => console.error('Gelato POS boot failed:', error));
})();