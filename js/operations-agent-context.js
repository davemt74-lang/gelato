(() => {
  'use strict';
  if (window.GelatoOperationsAgentContext) return;
  const clean=(value,max=100)=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  function snapshot(){
    const taskPublicId=clean(document.querySelector('[data-task].active,[data-task][aria-selected="true"]')?.dataset?.task||'',100);
    return {module:'operations',route:'operations.php',pageTitle:'Restaurant Operations',taskPublicId};
  }
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,taskPublicId:c.taskPublicId};}
  function description(c=snapshot()){return c.taskPublicId?'Operations · Selected task':'Restaurant Operations';}
  function placeholder(){return 'Ask Gelato about tasks, inventory, prep, shortages, or operations…';}
  const provider={module:'operations',snapshot,transportSnapshot,description,placeholder};
  function register(){return !!window.GelatoAgentPageContext?.register?.(provider);}
  window.addEventListener('gelato-agent-ready',register);document.addEventListener('click',()=>setTimeout(()=>window.GelatoAgentPageContext?.publish?.(),0),true);document.addEventListener('DOMContentLoaded',register,{once:true});
  window.GelatoOperationsAgentContext={snapshot,transportSnapshot,description,placeholder,register};register();
})();
