(() => {
  'use strict';
  if (window.GelatoPrepAgentContext) return;
  const clean = (value, max = 100) => String(value ?? '').replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, max);
  function snapshot() {
    const date = clean(document.querySelector('[data-prep-date]')?.value || document.querySelector('#date')?.value || '', 20);
    const service = clean(document.querySelector('[data-prep-service]')?.value || document.querySelector('#service')?.value || '', 30);
    return {module:'prep',route:'prep-intelligence.php',pageTitle:'Prep + Inventory Intelligence',date,service};
  }
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,date:c.date,service:c.service};}
  function description(c=snapshot()){return ['Prep Intelligence',c.date,c.service].filter(Boolean).join(' · ');}
  function placeholder(){return 'Ask Gelato about prep plans, recommendations, shortages, or prep actions…';}
  const provider={module:'prep',snapshot,transportSnapshot,description,placeholder};
  function register(){return !!window.GelatoAgentPageContext?.register?.(provider);}
  window.addEventListener('gelato-agent-ready',register);document.addEventListener('change',()=>window.GelatoAgentPageContext?.publish?.(),true);document.addEventListener('DOMContentLoaded',register,{once:true});
  window.GelatoPrepAgentContext={snapshot,transportSnapshot,description,placeholder,register};register();
})();
