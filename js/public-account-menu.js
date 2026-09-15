(() => {
  'use strict';

  document.querySelectorAll('a[href="landing.html"]').forEach((link) => link.setAttribute('href', 'index.php'));

  if (!document.querySelector('script[data-public-shell-script],script[src*="assets/js/public-shell.js"]')) {
    const shell = document.createElement('script');
    shell.src = 'assets/js/public-shell.js?v=20260915-1';
    shell.dataset.publicShellScript = 'true';
    document.head.appendChild(shell);
  }

  const page = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
  if (['index.php', 'landing.html', ''].includes(page) && !document.querySelector('script[data-public-agent]')) {
    const script = document.createElement('script');
    script.src = 'js/public-agent.js?v=20260804-agent1';
    script.dataset.publicAgent = 'true';
    document.head.appendChild(script);
  }
})();
