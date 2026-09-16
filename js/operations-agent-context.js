(() => {
  'use strict';
  if (window.GelatoOperationsAgentContext) return;
  const clean=(value,max=100)=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  const state={taskPublicId:'',inventoryPublicId:'',activeTab:'tasks'};

  function snapshot(){
    return {
      module:'operations',
      route:'operations.php',
      pageTitle:'Restaurant Operations',
      taskPublicId:clean(state.taskPublicId,100),
      inventoryPublicId:clean(state.inventoryPublicId,100),
      activeTab:clean(state.activeTab,30),
    };
  }
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,taskPublicId:c.taskPublicId,inventoryPublicId:c.inventoryPublicId,activeTab:c.activeTab};}
  function description(c=snapshot()){return c.taskPublicId?'Operations · Selected task':c.inventoryPublicId?'Operations · Selected inventory item':'Restaurant Operations';}
  function placeholder(){return 'Ask Gelato about tasks, inventory, prep, shortages, or operations…';}
  function publish(){window.GelatoAgentPageContext?.publish?.();}
  const provider={module:'operations',snapshot,transportSnapshot,description,placeholder};
  function register(){return !!window.GelatoAgentPageContext?.register?.(provider);}
  function removeLegacyAgent(){document.getElementById('agentStatus')?.remove();document.getElementById('agentInput')?.closest('.agent-bar')?.remove();}

  document.addEventListener('click',(event)=>{
    const tab=event.target.closest('[data-tab]');if(tab?.dataset.tab)state.activeTab=clean(tab.dataset.tab,30);
    const task=event.target.closest('[data-task-open]');if(task?.dataset.taskOpen){state.taskPublicId=clean(task.dataset.taskOpen,100);state.inventoryPublicId='';}
    const inventory=event.target.closest('[data-inventory-open]');if(inventory?.dataset.inventoryOpen){state.inventoryPublicId=clean(inventory.dataset.inventoryOpen,100);state.taskPublicId='';}
    setTimeout(publish,0);
  },true);
  window.addEventListener('gelato-agent-ready',register);
  document.addEventListener('DOMContentLoaded',()=>{removeLegacyAgent();register();publish();},{once:true});
  window.GelatoOperationsAgentContext={snapshot,transportSnapshot,description,placeholder,register,publish};
  removeLegacyAgent();register();
})();
