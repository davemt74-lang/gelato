(() => {
  const menus = document.querySelectorAll('[data-public-account-menu]');

  function installPublicLinks() {
    const links = [
      {href:'wholesale.php', label:'Wholesale Gelato', menuLabel:'◇ Wholesale Gelato'},
      {href:'catering.php', label:'Catering', menuLabel:'◫ Catering & Events'},
    ];
    document.querySelectorAll('.public-nav').forEach((nav) => {
      const account = nav.querySelector('[data-public-account-menu]');
      links.forEach((item) => {
        if (nav.querySelector(`a[href="${item.href}"]`)) return;
        const link = document.createElement('a');
        link.href = item.href;
        link.textContent = item.label;
        if (account) nav.insertBefore(link, account); else nav.appendChild(link);
      });
    });
    document.querySelectorAll('.guest-menu-list').forEach((list) => {
      links.forEach((item) => {
        if (list.querySelector(`a[href="${item.href}"]`)) return;
        const link = document.createElement('a');
        link.href = item.href;
        link.textContent = item.menuLabel;
        list.appendChild(link);
      });
    });
  }

  function loadPublicAgent() {
    const page = (location.pathname.split('/').pop() || 'landing.html').toLowerCase();
    if (!['landing.html', ''].includes(page) || document.querySelector('script[data-public-agent]')) return;
    const script = document.createElement('script');
    script.src = 'js/public-agent.js?v=20260804-agent1';
    script.dataset.publicAgent = 'true';
    document.head.appendChild(script);
  }

  if (menus.length) {
    function closeAll(except = null) {
      menus.forEach((wrap) => {
        if (wrap === except) return;
        const button = wrap.querySelector('[data-guest-menu-button]');
        const menu = wrap.querySelector('[data-guest-menu]');
        if (!button || !menu) return;
        menu.classList.add('hidden');
        button.setAttribute('aria-expanded', 'false');
      });
    }

    menus.forEach((wrap) => {
      const button = wrap.querySelector('[data-guest-menu-button]');
      const menu = wrap.querySelector('[data-guest-menu]');
      if (!button || !menu) return;

      button.addEventListener('click', (event) => {
        event.stopPropagation();
        const opening = menu.classList.contains('hidden');
        closeAll(wrap);
        menu.classList.toggle('hidden', !opening);
        button.setAttribute('aria-expanded', String(opening));
      });

      menu.addEventListener('click', (event) => event.stopPropagation());
    });

    document.addEventListener('click', () => closeAll());
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeAll();
    });
  }

  installPublicLinks();
  loadPublicAgent();
})();
