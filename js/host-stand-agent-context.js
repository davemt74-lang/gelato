(() => {
  'use strict';
  if (window.GelatoHostStandAgentContext) return;

  const baseFetch = window.fetch.bind(window);
  const state = {locationId:0,date:'',activeTab:'bookings',reservationPublicId:'',tablePublicId:'',reservations:[],tables:[]};
  const clean=(v,max=120)=>String(v??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  const asId=v=>Math.max(0,Number.parseInt(String(v??'0'),10)||0);

  function reservation(){return state.reservations.find(r=>clean(r?.publicId,100)===state.reservationPublicId)||null;}
  function table(){return state.tables.find(t=>clean(t?.publicId,100)===state.tablePublicId)||null;}
  function snapshot(){const r=reservation(),t=table();return {module:'host_stand',route:'host-stand.php',pageTitle:'Host Stand',locationId:asId(state.locationId),date:clean(state.date,10),activeTab:clean(state.activeTab,30),selectedReservationPublicId:clean(state.reservationPublicId,100),selectedReservationType:clean(r?.type,30),selectedReservationStatus:clean(r?.status,30),selectedTablePublicId:clean(state.tablePublicId,100),selectedTableName:clean(t?.name,100),selectedTableState:clean(t?.state,40)};}
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,locationId:c.locationId,date:c.date,activeTab:c.activeTab,selectedReservationPublicId:c.selectedReservationPublicId,selectedTablePublicId:c.selectedTablePublicId};}
  function description(c=snapshot()){const bits=['Host Stand'];if(c.activeTab)bits.push(c.activeTab);if(c.selectedReservationPublicId)bits.push('selected party');if(c.selectedTableName)bits.push(c.selectedTableName);return bits.join(' · ');}
  function placeholder(c=snapshot()){if(c.selectedReservationPublicId)return 'Ask Gelato about this reservation/waitlist party, assignment, arrival, seating, or status…';if(c.selectedTablePublicId)return 'Ask Gelato about this table, readiness, reservations, cleaning, or seating availability…';return 'Ask Gelato about reservations, waitlist, available tables, seating, or front-of-house priorities…';}
  function publish(){return window.GelatoAgentPageContext?.publish?.()||snapshot();}

  function apply(data){
    if(!data||typeof data!=='object'||!data.ok)return;
    if(data.dashboard?.location?.id!==undefined)state.locationId=asId(data.dashboard.location.id);
    if(data.date!==undefined)state.date=clean(data.date,10);
    if(Array.isArray(data.dashboard?.reservations))state.reservations=data.dashboard.reservations;
    if(Array.isArray(data.dashboard?.tables))state.tables=data.dashboard.tables;
    if(state.reservationPublicId&&!reservation())state.reservationPublicId='';
    if(state.tablePublicId&&!table())state.tablePublicId='';
    publish();
  }

  function targetFile(input){let value='';if(typeof input==='string')value=input;else if(input instanceof URL)value=input.toString();else if(typeof Request!=='undefined'&&input instanceof Request)value=input.url;try{return new URL(value,window.location.href).pathname.split('/').pop()||'';}catch{return value.split('?')[0].split('/').pop()||'';}}
  window.fetch=async function gelatoHostStandContextFetch(input,init){const response=await baseFetch(input,init);if(targetFile(input)==='host-stand.php'){try{apply(await response.clone().json());}catch{}}return response;};

  document.addEventListener('click',event=>{
    const booking=event.target.closest('[data-confirm],[data-arrive],[data-edit],[data-assign],[data-seat],[data-noshow],[data-cancel]');
    if(booking){state.reservationPublicId=clean(booking.dataset.confirm||booking.dataset.arrive||booking.dataset.edit||booking.dataset.assign||booking.dataset.seat||booking.dataset.noshow||booking.dataset.cancel,100);state.tablePublicId='';publish();return;}
    const manage=event.target.closest('[data-table]');
    if(manage?.dataset.table){state.tablePublicId=clean(manage.dataset.table,100);state.reservationPublicId='';publish();return;}
    const floor=event.target.closest('.table');
    if(floor){const name=clean(floor.querySelector('strong')?.textContent,100);const matches=state.tables.filter(t=>clean(t?.name,100)===name);if(matches.length===1){state.tablePublicId=clean(matches[0].publicId,100);state.reservationPublicId='';publish();}}
    const tab=event.target.closest('[data-tab]');if(tab?.dataset.tab){state.activeTab=clean(tab.dataset.tab,30);publish();}
  },true);

  document.getElementById('location')?.addEventListener('change',event=>{state.locationId=asId(event.target.value);state.reservationPublicId='';state.tablePublicId='';publish();});
  document.getElementById('date')?.addEventListener('change',event=>{state.date=clean(event.target.value,10);state.reservationPublicId='';state.tablePublicId='';publish();});
  window.addEventListener('gelato-agent-response',event=>{if(event.detail?.result?.skill==='front_of_house.action_confirmed')setTimeout(()=>window.location.reload(),250);});

  async function hydrate(){try{const q=new URLSearchParams();const loc=asId(document.getElementById('location')?.value);const date=clean(document.getElementById('date')?.value,10);if(loc)q.set('locationId',String(loc));if(date)q.set('date',date);const r=await baseFetch('api/host-stand.php?'+q.toString(),{headers:{Accept:'application/json'},cache:'no-store'});apply(await r.json());}catch{publish();}}
  const provider={module:'host_stand',snapshot,transportSnapshot,description,placeholder};
  window.GelatoAgentPageContext?.register?.(provider);
  window.GelatoHostStandAgentContext={snapshot,transportSnapshot,description,placeholder,publish,apply};
  hydrate();
})();
