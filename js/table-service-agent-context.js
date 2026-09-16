(() => {
  'use strict';
  if (window.GelatoTableServiceAgentContext) return;

  const baseFetch = window.fetch.bind(window);
  const state = {locationId:0, tablePublicId:'', checkPublicId:'', focusedLineId:0, tables:[]};
  const clean=(v,max=120)=>String(v??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  const asId=v=>Math.max(0,Number.parseInt(String(v??'0'),10)||0);

  function selectedTable(){return state.tables.find(t=>clean(t?.publicId)===state.tablePublicId)||null;}
  function snapshot(){const table=selectedTable();return {module:'table_service',route:'table-service.php',pageTitle:'Table Service',locationId:asId(state.locationId),tablePublicId:clean(state.tablePublicId,100),tableName:clean(table?.name,100),checkPublicId:clean(state.checkPublicId||table?.checkPublicId,100),focusedLineId:asId(state.focusedLineId)};}
  function transportSnapshot(){const c=snapshot();return {module:c.module,route:c.route,locationId:c.locationId,tablePublicId:c.tablePublicId,checkPublicId:c.checkPublicId,focusedLineId:c.focusedLineId};}
  function description(c=snapshot()){return ['Table Service',c.tableName||'',c.checkPublicId?'active check':'',].filter(Boolean).join(' · ');}
  function placeholder(c=snapshot()){return c.checkPublicId?'Ask Gelato to run this table/check, send or hold items, move it, assign a server, or explain what needs attention…':'Ask Gelato about the live floor, ready food, service priorities, or seat a party…';}
  function publish(){return window.GelatoAgentPageContext?.publish?.()||snapshot();}

  function apply(data){
    if(!data||typeof data!=='object'||!data.ok)return;
    if(data.locationId!==undefined)state.locationId=asId(data.locationId);
    if(Array.isArray(data.map?.tables))state.tables=data.map.tables;
    if(data.check!==undefined){state.checkPublicId=clean(data.check?.publicId,100);if(data.check?.serviceContext?.tablePublicId)state.tablePublicId=clean(data.check.serviceContext.tablePublicId,100);}
    if(state.tablePublicId){const t=selectedTable();if(t?.checkPublicId&&!state.checkPublicId)state.checkPublicId=clean(t.checkPublicId,100);if(t&&!t.checkPublicId&&state.checkPublicId)state.checkPublicId='';}
    publish();
  }
  function targetFile(input){let value='';if(typeof input==='string')value=input;else if(input instanceof URL)value=input.toString();else if(typeof Request!=='undefined'&&input instanceof Request)value=input.url;try{return new URL(value,window.location.href).pathname.split('/').pop()||'';}catch{return value.split('?')[0].split('/').pop()||'';}}
  window.fetch=async function gelatoTableServiceContextFetch(input,init){const response=await baseFetch(input,init);if(targetFile(input)==='table-service.php'){try{apply(await response.clone().json());}catch{}}return response;};

  document.addEventListener('click',event=>{
    const table=event.target.closest('[data-table]');if(table?.dataset.table){state.tablePublicId=clean(table.dataset.table,100);const row=selectedTable();state.checkPublicId=clean(row?.checkPublicId,100);state.focusedLineId=0;publish();}
    const line=event.target.closest('[data-save-line],[data-seat],[data-course]');if(line){const id=asId(line.dataset.saveLine||line.dataset.seat||line.dataset.course);if(id){state.focusedLineId=id;publish();}}
  },true);
  document.getElementById('locationSelect')?.addEventListener('change',event=>{state.locationId=asId(event.target.value);state.tablePublicId='';state.checkPublicId='';state.focusedLineId=0;publish();});

  async function hydrate(){try{const loc=asId(document.getElementById('locationSelect')?.value);const q=new URLSearchParams();if(loc)q.set('locationId',String(loc));const r=await baseFetch('api/table-service.php?'+q.toString(),{headers:{Accept:'application/json'},cache:'no-store'});apply(await r.json());}catch{publish();}}
  const provider={module:'table_service',snapshot,transportSnapshot,description,placeholder};
  window.GelatoAgentPageContext?.register?.(provider);
  window.GelatoTableServiceAgentContext={snapshot,transportSnapshot,description,placeholder,publish,apply};
  hydrate();
})();
