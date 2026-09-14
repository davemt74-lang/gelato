(() => {
  function installStonefellowsTheme() {
    if (document.querySelector('link[data-stonefellows-public-theme]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'css/stonefellows-public.css?v=20260914-1';
    link.dataset.stonefellowsPublicTheme = 'true';
    document.head.appendChild(link);
  }

  function normalizeLegacyLinks() {
    document.querySelectorAll('a[href="landing.html"]').forEach((link) => {
      link.setAttribute('href', 'index.php');
    });
  }

  function installServiceLinks() {
    const services = [
      { href: 'wholesale.php', label: 'Wholesale Gelato', menuLabel: '◇ Wholesale Gelato' },
      { href: 'catering.php', label: 'Catering', menuLabel: '◈ Catering' },
    ];
    document.querySelectorAll('.public-nav').forEach((nav) => {
      const account = nav.querySelector('[data-public-account-menu]');
      services.forEach((service) => {
        if (nav.querySelector(`a[href="${service.href}"]`)) return;
        const link = document.createElement('a');
        link.href = service.href;
        link.textContent = service.label;
        if (account) nav.insertBefore(link, account); else nav.appendChild(link);
      });
    });
    document.querySelectorAll('.guest-menu-list').forEach((list) => {
      services.forEach((service) => {
        if (list.querySelector(`a[href="${service.href}"]`)) return;
        const link = document.createElement('a');
        link.href = service.href;
        link.textContent = service.menuLabel;
        list.appendChild(link);
      });
      if (!list.querySelector('a[href="signup.php"]')) {
        const link = document.createElement('a');
        link.href = 'signup.php';
        link.textContent = '＋ Account access / Sign up';
        list.appendChild(link);
      }
    });
  }

  function installUnifiedFooter() {
    if (document.querySelector('.auth-shell') || document.querySelector('.sf-public-footer')) return;
    const oldFooter = document.querySelector('footer.public-footer');
    if (oldFooter) oldFooter.remove();
    const footer = document.createElement('footer');
    footer.className = 'sf-public-footer';
    footer.innerHTML = `
      <div class="sf-footer-grid">
        <div class="sf-brand"><strong>Stonefellows</strong><span>Pizzeria + Bar</span></div>
        <div><strong>Links</strong><nav><a href="menu.php">Menu</a><a href="gelato.php">Gelato</a><a href="about.php">About</a><a href="locations.php">Locations</a><a href="contact.php">Contact</a><a href="jobs.html">Jobs</a><a href="catering.php">Catering</a><a href="wholesale.php">Wholesale</a></nav></div>
        <div><strong>Accounts</strong><nav><a href="login.php">Login</a><a href="signup.php">Sign Up / Access</a><a href="forgot-password.php">Forgot Password</a></nav></div>
        <div><strong>Visit</strong><nav><a href="locations.php">Location details</a><a href="contact.php">Hours + contact</a></nav></div>
        <div><strong>Explore</strong><nav><a href="index.php">Home</a><a href="jobs.html">Careers</a><a href="apply.html">Apply</a></nav></div>
      </div>`;
    document.body.appendChild(footer);
  }

  function loadPublicAgent() {
    const page = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
    if (!['index.php', 'landing.html', ''].includes(page) || document.querySelector('script[data-public-agent]')) return;
    const script = document.createElement('script');
    script.src = 'js/public-agent.js?v=20260804-agent1';
    script.dataset.publicAgent = 'true';
    document.head.appendChild(script);
  }

  installStonefellowsTheme();
  normalizeLegacyLinks();

  const menus = document.querySelectorAll('[data-public-account-menu]');
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

  installServiceLinks();
  installUnifiedFooter();
  loadPublicAgent();
})();
