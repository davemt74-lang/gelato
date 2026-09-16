(() => {
  'use strict';
  if (window.GelatoKdsAgentContext) return;
  const clean=(value,max=100)=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  const asId=(value)=>Math.max(0,Number.parseInt(String(value??'0'),10)||0);
  const state={checkPublicId:'',kitchenItemPublicId:'',stationPublicId:''};

  function snapshot(){
    const cfg=window.KDS_CONFIG||window.KDS_DASHBOARD_CONFIG||{};
    const locationId=asId(cfg.locationId||document.querySelector('[data-location-id]')?.dataset?.locationId||document.querySelector('#location')?.value);
    const activeStation=document.querySelector('[data-station].active')?.dataset?.station;
    const selectedTicket=document.querySelector('[data-ticket].selected')?.dataset?.ticket;
    return {
      module:'kds',
      route:(location.pathname.split('/').pop()||'kds.php'),
      pageTitle:'Kitchen Display System',
      locationId,
      stationPublicId:clean(activeStation??state.stationPublicId,100),
      checkPublicId:clean(selectedTicket??state.checkPublicId,100),
      kitchenItemPublicId:clean(state.kitchenItemPublicId,100),
    };
  }
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,locationId:c.locationId,stationPublicId:c.stationPublicId,checkPublicId:c.checkPublicId,kitchenItemPublicId:c.kitchenItemPublicId};}
  function description(c=snapshot()){return ['KDS',c.stationPublicId?'Station view':'Expo / All',c.checkPublicId?'Selected order':''].filter(Boolean).join(' · ');}
  function placeholder(){return 'Ask Gelato about kitchen load, orders, ready items, stations, or ticket status…';}
  function publish(){window.GelatoAgentPageContext?.publish?.();}
  const provider={module:'kds',snapshot,transportSnapshot,description,placeholder};
  function register(){return !!window.GelatoAgentPageContext?.register?.(provider);}

  document.addEventListener('click',(event)=>{
    const station=event.target.closest('[data-station]');
    if(station)state.stationPublicId=clean(station.dataset.station,100);
    const ticket=event.target.closest('[data-ticket]');
    if(ticket){state.checkPublicId=clean(ticket.dataset.ticket,100);state.kitchenItemPublicId='';}
    const item=event.target.closest('[data-item]');
    if(item)state.kitchenItemPublicId=clean(item.dataset.item,100);
    setTimeout(publish,0);
  },true);
  document.addEventListener('change',(event)=>{if(event.target?.id==='location'){state.checkPublicId='';state.kitchenItemPublicId='';state.stationPublicId='';}setTimeout(publish,0);},true);
  window.addEventListener('gelato-agent-ready',register);
  document.addEventListener('DOMContentLoaded',register,{once:true});
  window.GelatoKdsAgentContext={snapshot,transportSnapshot,description,placeholder,register,publish};register();
})();
