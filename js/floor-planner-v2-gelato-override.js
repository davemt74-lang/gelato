(() => {
  'use strict';
  if (document.getElementById('fpGelatoCurveOverride')) return;
  const style = document.createElement('style');
  style.id = 'fpGelatoCurveOverride';
  style.textContent = `
    html body #stage .structure.gelato-display{
      background:transparent!important;
      border:0!important;
      border-radius:0!important;
      box-shadow:none!important;
      overflow:visible!important;
    }
  `;
  document.head.appendChild(style);
})();
