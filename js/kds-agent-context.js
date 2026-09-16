(() => {
  'use strict';
  if (window.GelatoKdsAgentContext) return;
  const clean=(value,max=100)=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  const asId=(value)=>Math.max(0,Number.parseInt(String(value??'0'),10)||0);
  function snapshot(){
    const cfg=window.GELATO_KDS_CONFIG||{};
    const locationId=asId(cfg.locationId||document.querySelector('[data-location-id]')?.dataset?.locationId||document.querySelector('#location')?.value);
    const stationPublicId=clean(document.querySelector('[data-station].active,[data-station][aria-selected="true"]')?.dataset?.station||'',100);
    const kitchenItemPublicId=clean(document.querySelector('[data-kds-item].active,[data-kds-item][aria-selected="true"]')?.dataset?.kdsItem||'',100);
    return {module:'kds',route:(location.pathname.split('/').pop()||'kds.php'),pageTitle:'Kitchen Display System',locationId,stationPublicId,kitchenItemPublicId};
  }
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,locationId:c.locationId,stationPublicId:c.stationPublicId,kitchenItemPublicId:c.kitchenItemPublicId};}
  function description(c=snapshot()){return ['KDS',c.stationPublicId?'Selected station':'',c.kitchenItemPublicId?'Selected ticket item':''].filter(Boolean).join(' · ');}
  function placeholder(){return 'Ask Gelato about kitchen load, ready items, stations, or ticket status…';}
  const provider={module:'kds',snapshot,transportSnapshot,description,placeholder};
  function register(){return !!window.GelatoAgentPageContext?.register?.(provider);}
  window.addEventListener('gelato-agent-ready',register);document.addEventListener('click',()=>setTimeout(()=>window.GelatoAgentPageContext?.publish?.(),0),true);document.addEventListener('DOMContentLoaded',register,{once:true});
  window.GelatoKdsAgentContext={snapshot,transportSnapshot,description,placeholder,register};register();
})();
