(() => {
  'use strict';

  if (!document.querySelector('script[data-public-shell-script],script[src*="assets/js/public-shell.js"]')) {
    const shell = document.createElement('script');
    shell.src = 'assets/js/public-shell.js?v=20260915-1';
    shell.dataset.publicShellScript = 'true';
    document.head.appendChild(shell);
  }

  const nav=document.getElementById('nav');const toggle=document.getElementById('menuToggle');
  if(nav&&toggle){toggle.addEventListener('click',()=>{const open=nav.classList.toggle('open');toggle.setAttribute('aria-expanded',String(open));toggle.textContent=open?'×':'☰';});nav.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{nav.classList.remove('open');toggle.setAttribute('aria-expanded','false');toggle.textContent='☰';}));}
  document.querySelectorAll('img').forEach(img=>img.addEventListener('error',()=>img.classList.add('image-missing'),{once:true}));
  if('IntersectionObserver' in window){const reveal=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('visible');reveal.unobserve(entry.target);}}),{threshold:.12});document.querySelectorAll('[data-reveal]').forEach(el=>reveal.observe(el));}else document.querySelectorAll('[data-reveal]').forEach(el=>el.classList.add('visible'));
})();
