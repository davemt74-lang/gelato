(() => {
  'use strict';

  if (window.__STONEFELLOWS_PUBLIC_SHELL__) return;
  window.__STONEFELLOWS_PUBLIC_SHELL__ = true;

  const page = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
  const active = ({
    '': 'home',
    'index.php': 'home',
    'landing.html': 'home',
    'menu.php': 'menu',
    'online-order.php': 'menu',
    'gelato.php': 'gelato',
    'about.php': 'about',
    'locations.php': 'locations',
  })[page] || '';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[character]);

  function ensureStyles() {
    if (document.querySelector('link[data-public-shell-css]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'assets/css/public-shell.css?v=20260915-1';
    link.dataset.publicShellCss = 'true';
    document.head.appendChild(link);
  }

  function cartQuantity() {
    let total = 0;
    try {
      for (let index = 0; index < sessionStorage.length; index += 1) {
        const key = sessionStorage.key(index) || '';
        if (!key.startsWith('stonefellows.onlineCart.v1.') || key.endsWith('.note')) continue;
        const rows = JSON.parse(sessionStorage.getItem(key) || '[]');
        if (!Array.isArray(rows)) continue;
        total += rows.reduce((sum, row) => sum + Math.max(0, Number(row && row.quantity ? row.quantity : 0)), 0);
      }
    } catch (_) {}
    return total;
  }

  function navLink(key, href, label) {
    return `<a${active === key ? ' class="active"' : ''} href="${href}">${label}</a>`;
  }

  function headerHtml(name) {
    return `<header class="public-shell-header${active === 'home' ? ' is-home' : ''}" data-public-shell="header">
      <div class="public-shell-inner">
        <a class="public-shell-brand" href="index.php"><strong>${escapeHtml(name)}</strong><span>Pizzeria + Bar</span></a>
        <nav class="public-shell-nav" id="publicShellNav" aria-label="Primary navigation">
          ${navLink('home', 'index.php', 'Home')}
          ${navLink('menu', 'menu.php', 'Menu')}
          ${navLink('gelato', 'gelato.php', 'Gelato')}
          ${navLink('about', 'about.php', 'About')}
          ${navLink('locations', 'locations.php', 'Locations')}
        </nav>
        <div class="public-shell-actions">
          <a class="public-shell-cart" id="publicCartLink" href="online-order.php" aria-label="Cart, 0 items" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg>
            <span id="cartHeaderCount" class="public-shell-cart-count" hidden>0</span>
          </a>
          <a class="public-shell-account" href="customer-account.php">Account</a>
          <button class="public-shell-menu" id="publicShellMenu" type="button" aria-label="Open navigation" aria-expanded="false">☰</button>
        </div>
      </div>
    </header>`;
  }

  function footerHtml(shell) {
    const address = String(shell.address || '').trim() || 'Location details coming soon';
    const hours = String(shell.hoursText || '').trim() || 'Hours coming soon';
    const socials = shell.socials && typeof shell.socials === 'object' ? shell.socials : {};
    const socialHtml = Object.entries(socials).map(([label, href]) => `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(label)}</a>`).join('');
    return `<footer class="public-shell-footer" data-public-shell="footer">
      <div class="public-shell-footer-grid">
        <div class="public-shell-footer-brand"><strong>${escapeHtml(shell.restaurantName || 'Stonefellows')}</strong><span>Pizzeria + Bar</span></div>
        <div><strong class="public-shell-footer-title">Links</strong><nav class="public-shell-footer-links" aria-label="Footer links"><a href="contact.php">Contact</a><a href="wholesale.php">Wholesale</a><a href="catering.php">Catering</a><a href="jobs.html">Jobs</a><a href="login.php">Admin / Employee Login</a></nav></div>
        <div><strong class="public-shell-footer-title">Visit</strong><div class="public-shell-footer-copy">${escapeHtml(address)}</div></div>
        <div><strong class="public-shell-footer-title">Hours</strong><div class="public-shell-footer-copy public-shell-hours">${escapeHtml(hours)}</div></div>
        <div><strong class="public-shell-footer-title">Follow</strong><div class="public-shell-socials">${socialHtml}</div></div>
      </div>
    </footer>`;
  }

  function replaceHeader(markup) {
    const existing = document.querySelector('body > header[data-public-shell="header"], body > header.site-header, body > header.public-header');
    const template = document.createElement('template');
    template.innerHTML = markup.trim();
    const header = template.content.firstElementChild;
    if (!header) return null;
    if (existing) existing.replaceWith(header);
    else document.body.insertBefore(header, document.body.firstChild);
    return header;
  }

  function replaceFooter(markup) {
    const existing = document.querySelector('body > footer[data-public-shell="footer"], body > footer.sf-public-footer, body > footer.public-footer, body > footer');
    const template = document.createElement('template');
    template.innerHTML = markup.trim();
    const footer = template.content.firstElementChild;
    if (!footer) return null;
    if (existing) existing.replaceWith(footer);
    else document.body.appendChild(footer);
    return footer;
  }

  function updateCartBadge() {
    const quantity = cartQuantity();
    const badge = document.getElementById('cartHeaderCount');
    const cart = document.getElementById('publicCartLink');
    if (badge) {
      badge.textContent = String(quantity);
      badge.hidden = quantity < 1;
    }
    if (cart) cart.setAttribute('aria-label', `Cart, ${quantity} item${quantity === 1 ? '' : 's'}`);
  }

  function bindNavigation(header) {
    const menu = header && header.querySelector('#publicShellMenu');
    if (!menu || !header) return;
    menu.addEventListener('click', () => {
      const open = header.classList.toggle('open');
      menu.setAttribute('aria-expanded', String(open));
      menu.textContent = open ? '×' : '☰';
    });
    header.querySelectorAll('.public-shell-nav a').forEach((link) => link.addEventListener('click', () => {
      header.classList.remove('open');
      menu.setAttribute('aria-expanded', 'false');
      menu.textContent = '☰';
    }));
  }

  function bindOrderingDrawer() {
    if (page !== 'online-order.php') return;
    const cart = document.getElementById('publicCartLink');
    const drawer = document.getElementById('orderCartDrawer');
    const backdrop = document.getElementById('orderCartBackdrop');
    if (!cart || !drawer || !backdrop) return;

    cart.setAttribute('aria-controls', 'orderCartDrawer');
    const syncExpanded = () => cart.setAttribute('aria-expanded', String(document.body.classList.contains('order-cart-open')));
    const toggle = (event) => {
      event.preventDefault();
      const open = !document.body.classList.contains('order-cart-open');
      document.body.classList.toggle('order-cart-open', open);
      drawer.setAttribute('aria-hidden', String(!open));
      backdrop.setAttribute('aria-hidden', String(!open));
      syncExpanded();
      if (open) document.getElementById('orderCartClose')?.focus({ preventScroll: true });
    };
    cart.addEventListener('click', toggle);
    new MutationObserver(syncExpanded).observe(document.body, { attributes: true, attributeFilter: ['class'] });

    const drawerCount = document.getElementById('cartCount');
    if (drawerCount) new MutationObserver(updateCartBadge).observe(drawerCount, { childList: true, characterData: true, subtree: true });
  }

  async function loadShellData() {
    try {
      const response = await fetch('api/public-shell.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
      const payload = await response.json();
      if (response.ok && payload && payload.shell) return payload.shell;
    } catch (_) {}
    return { restaurantName: 'Stonefellows', address: '', hoursText: '', socials: {} };
  }

  async function mount() {
    ensureStyles();
    const shell = await loadShellData();
    const header = replaceHeader(headerHtml(shell.restaurantName || 'Stonefellows'));
    replaceFooter(footerHtml(shell));
    bindNavigation(header);
    updateCartBadge();
    bindOrderingDrawer();
    document.body.classList.add('public-shell-mounted');
    window.dispatchEvent(new CustomEvent('stonefellows:public-shell-ready'));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true });
  else mount();
})();
