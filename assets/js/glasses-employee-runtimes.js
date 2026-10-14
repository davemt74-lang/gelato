(()=>{'use strict';
/* Section 266: operator-assigned runtimes. Each opened URL has its own isolated
 * browser camera, inference worker, checklist, audio and recovery state. */
const cfg=window.GELATO_GLASSES_SIMULATOR||{},byId=id=>document.getElementById(id);
let catalog={schemaReady:false,runtimes:[],employees:[],stations:[]},bound=null,devices=[],mounted=false,lost=false,physical=[],pairing=null,pairTimer=null;
const adminApi='api/glasses-admin.php';
const msg=s=>{const e=byId('glassesConnectionMessage');if(e)e.textContent=s;};
const txt=(id,s)=>{const e=byId(id);if(e)e.textContent=String(s);};
function selectedRuntime(){return (catalog.runtimes||[]).find(r=>r.publicId===(byId('glassesRuntimeSelect')?.value||cfg.runtimePublicId))||null;}
function showPhysical(){
 const rt=selectedRuntime(),d=physical.find(d=>d.publicId===(rt?.devicePublicId||byId('deviceSelect')?.value));
 const age=typeof d?.heartbeatAgeSeconds==='number'?d.heartbeatAgeSeconds:Infinity;
 txt('glassesConnectionBadge',!d?'No device selected':d.status!=='active'||d.revokedAt?'Revoked':age<=60?'Physical glasses online':'Paired · Offline');
 txt('glassesConnectionHardware',d?d.displayName+' · '+(d.hardwareIdentifier||d.publicId):'Select a physical device');
 txt('glassesConnectionSoftware',d?.appVersion?'Gelato AR '+d.appVersion:'Version not reported');
 txt('glassesConnectionProfile',rt?rt.employeeName+(rt.profileConfirmedAt?' · Confirmed in browser':' · Awaiting sign-in'):'No employee assigned');
 txt('glassesConnectionLastSeen',d?.lastSeenAt?new Date(d.lastSeenAt).toLocaleString():'No device heartbeat');
 const confirm=byId('glassesConnectionConfirm');if(confirm)confirm.hidden=!(cfg.runtimePublicId&&rt&&!rt.profileConfirmedAt);
 const pair=byId('glassesConnectionPair');if(pair)pair.hidden=!!cfg.runtimePublicId||!cfg.canManageMedia;
}
async function refreshPhysical(){const d=await json(adminApi);physical=d.devices||[];showPhysical();}
function endPair(){if(pairTimer)clearInterval(pairTimer);pairTimer=null;pairing=null;byId('glassesPairPanel').hidden=true;txt('glassesPairCode','');}
async function cancelPair(){
 const id=pairing?.id;endPair();if(!id)return;
 try{await json(adminApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
   body:JSON.stringify({action:'pairing.cancel',csrf_token:cfg.csrf,pairingPublicId:id})});msg('Pairing code cancelled.');}
 catch(e){msg('Pairing code hidden. It expires automatically. '+e.message);}
}
async function pollPair(){
 if(!pairing)return;
 if(Date.now()>=pairing.until){endPair();msg('Pairing code expired.');return;}
 try{
  const r=await json(adminApi+'?action=pairing.status&pairingPublicId='+encodeURIComponent(pairing.id));
  if(r.pairing.state==='paired'){endPair();msg('Glasses paired. Reloading available devices.');window.location.reload();}
  else if(r.pairing.state!=='pending'){endPair();msg('Pairing ended.');}
 }catch(e){msg(e.message);}
}
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const status=(s,error=false)=>{const e=byId('glassesRuntimeStatus');if(e){e.textContent=s;e.dataset.error=error?'1':'0';}};
async function json(url,options={}){
 const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options}),d=await r.json().catch(()=>null);
 if(!r.ok||!d?.ok)throw Error(d?.message||'Runtime request failed ('+r.status+').');
 if(/^[0-9a-f]{64}$/i.test(d.csrfToken||''))cfg.csrf=d.csrfToken;
 return d;
}
function post(action,payload={}){
 return json(cfg.api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
   body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})});
}
async function refresh(){
 const d=await json(cfg.api+'?action=runtimes');catalog=d;render();return d;
}
function renderRuntimeTabs(choices,current){
 const strip=byId('glassesRuntimeTabs'),label=byId('glassesRuntimeSelectLabel');
 if(!strip)return;
 const multi=choices.length>1;
 strip.hidden=!multi;
 if(label)label.hidden=multi;
 strip.replaceChildren();
 if(!multi)return;
 const tabs=choices.map((runtime,i)=>({
   id:runtime.publicId,name:'Glasses '+(i+1),
   detail:runtime.employeeName+' · '+runtime.deviceName+
     (runtime.stationName?' · '+runtime.stationName:''),
   index:i+1
 }));
 if(!cfg.runtimePublicId)tabs.push({id:'',name:'New',detail:'Create another assigned glasses runtime',index:'+'});
 const select=byId('glassesRuntimeSelect');
 const activate=id=>{
   if(cfg.runtimePublicId){
     // Each paired glasses session has its own isolated camera, voice, station
     // and build state. Open another session instead of silently reassigning it.
     if(!id||id===cfg.runtimePublicId)return;
     const url=new URL(location.href);url.searchParams.set('runtime',id);
     window.open(url.toString(),'_blank','noopener');
     return;
   }
   select.value=id;render();
 };
 for(const [i,tab] of tabs.entries()){
   const btn=document.createElement('button');btn.type='button';btn.setAttribute('role','tab');
   const selected=(current===tab.id);
   btn.setAttribute('aria-selected',selected?'true':'false');btn.tabIndex=selected?0:-1;
   btn.setAttribute('aria-label',tab.name+' · '+tab.detail);
   btn.title=tab.detail;btn.dataset.runtimeId=tab.id;
   const number=document.createElement('span');number.textContent=String(tab.index);
   number.setAttribute('aria-hidden','true');
   const name=document.createTextNode(tab.name);btn.append(number,name);
   btn.addEventListener('click',()=>activate(tab.id));
   btn.addEventListener('keydown',event=>{
     const next=event.key==='ArrowRight'?i+1:event.key==='ArrowLeft'?i-1:
       event.key==='Home'?0:event.key==='End'?tabs.length-1:-1;
     if(next<0)return;
     event.preventDefault();
     const target=tabs[(next+tabs.length)%tabs.length];
     if(!cfg.runtimePublicId)activate(target.id);
     const sibling=Array.from(strip.querySelectorAll('[role="tab"]'))[(next+tabs.length)%tabs.length];
     sibling?.focus();
   });
   strip.appendChild(btn);
 }
}
function render(){
 const select=byId('glassesRuntimeSelect'),employee=byId('glassesRuntimeEmployee');
 if(!select||!employee)return;
 const previous=select.value;
 const choices=catalog.runtimes||[];
 select.innerHTML=(cfg.runtimePublicId?'':'<option value="">New runtime…</option>')+
   choices.map(r=>'<option value="'+esc(r.publicId)+'">'+esc(r.employeeName)+' · '+esc(r.deviceName)+'</option>').join('');
 select.value=cfg.runtimePublicId||(choices.some(x=>x.publicId===previous)?previous:'');
 if(cfg.runtimePublicId)select.value=cfg.runtimePublicId;
 const chosen=choices.find(r=>r.publicId===select.value)||null;
 renderRuntimeTabs(choices,select.value);
 const deviceSelect=byId('deviceSelect');
 if(chosen&&deviceSelect){deviceSelect.value=chosen.devicePublicId;deviceSelect.disabled=true;}
 else if(deviceSelect&&!cfg.runtimePublicId)deviceSelect.disabled=false;
 const previousEmployee=employee.value;
 employee.innerHTML='<option value="">Assign employee…</option>'+
   (catalog.employees||[]).map(e=>'<option value="'+e.id+'">'+esc(e.displayName)+(e.employeeNumber?' · '+esc(e.employeeNumber):'')+'</option>').join('');
 if(chosen&&!(catalog.employees||[]).some(e=>e.id===chosen.employeeUserId))
   employee.innerHTML+='<option value="'+chosen.employeeUserId+'">'+esc(chosen.employeeName)+'</option>';
 employee.value=chosen?String(chosen.employeeUserId):
   (catalog.employees||[]).some(e=>String(e.id)===previousEmployee)?previousEmployee:'';
 employee.disabled=!!chosen||(catalog.employees||[]).length===0;
 renderStation(chosen);
 renderTraining(chosen);
 showPhysical();
 window.GelatoGlassesSoftware?.followRuntime?.();
 byId('glassesRuntimeNew').disabled=!!cfg.runtimePublicId||!!chosen||!catalog.schemaReady||
   !(catalog.employees||[]).length||!selectedDevice()||!!selectedDevice()?.virtualPreview||
   !byId('glassesRuntimeStation')?.value||!employee.value;
 byId('glassesRuntimeOpen').disabled=!chosen;
 byId('glassesRuntimeEnd').disabled=!chosen||(catalog.employees||[]).length===0;
 if(!catalog.schemaReady){status('Install the multi-runtime migration with upgrade.php.',true);return;}
 status(cfg.runtimePublicId?
   (bound?'ACTIVE · '+bound.employeeName:'RUNTIME NOT AVAILABLE'):
   (chosen?'Assigned: '+chosen.employeeName+' · '+chosen.deviceName:'Choose paired glasses and an employee'));
}

function selectedDevice(chosen=null){
 return chosen?devices.find(d=>d.publicId===chosen.devicePublicId):
   devices.find(d=>d.publicId===byId('deviceSelect')?.value);
}
function renderStation(chosen){
 const field=byId('glassesRuntimeStation'),button=byId('glassesRuntimeSaveStation');
 if(!field)return;
 const device=selectedDevice(chosen),locationId=chosen?.locationId||device?.locationId||null;
 const prior=field.value,assigned=chosen?.stationPublicId||device?.stationPublicId||'';
 const stations=(catalog.stations||[]).filter(s=>Number(s.locationId)===Number(locationId));
 field.innerHTML='<option value="">'+(locationId?'Choose kitchen station…':'Choose glasses first…')+'</option>'+
   stations.map(s=>'<option value="'+esc(s.publicId)+'">'+esc(s.name)+'</option>').join('');
 const allowed=v=>stations.some(s=>s.publicId===v);
 field.value=chosen?(allowed(assigned)?assigned:''):(allowed(prior)?prior:allowed(assigned)?assigned:'');
 field.disabled=!locationId||!(catalog.employees||[]).length||(cfg.runtimePublicId&&!bound);
 const changed=!!chosen&&!!field.value&&field.value!==assigned;
 button.disabled=!changed;button.dataset.dirty=changed?'1':'0';
 const create=byId('glassesRuntimeNew');
 if(!chosen)create.disabled=!!cfg.runtimePublicId||!device||!!device.virtualPreview||!field.value||
   !(catalog.employees||[]).length||!catalog.schemaReady||!byId('glassesRuntimeEmployee').value;
}
function renderTraining(chosen){
 const training=byId('glassesRuntimeTrainingSelect');
 if(!training)return;
 const old=training.value;
 const assignments=(catalog.trainingAssignments||[]).filter(a=>chosen&&a.employeeUserId===chosen.employeeUserId&&
   (a.deviceId==null||a.deviceId===chosen.deviceId)&&
   (a.locationId==null||a.locationId===chosen.locationId)&&
   (a.stationId==null||a.stationId===chosen.stationId));
 const active=(catalog.activeTrainingSessions||[]).find(s=>chosen&&s.employeeUserId===chosen.employeeUserId&&s.deviceId===chosen.deviceId)||null;
 training.innerHTML='<option value="">Choose assigned program…</option>'+
   assignments.map(a=>'<option value="'+esc(a.publicId)+'">'+esc(a.programName)+' · '+esc(a.mode)+' · '+esc(a.status)+'</option>').join('');
 training.value=assignments.some(a=>a.publicId===old)?old:(active?.assignmentPublicId||'');
 const privileged=(catalog.employees||[]).length>0,inside=!!cfg.runtimePublicId&&!!bound&&!lost;
 training.disabled=!inside||!!active||!privileged;
 byId('glassesRuntimeTrainingStart').disabled=!inside||!!active||!privileged||!training.value;
 byId('glassesRuntimeTrainingEnd').disabled=!inside||!active||!privileged;
 const label=byId('glassesRuntimeTrainingStatus');
 if(label)label.textContent=active
   ?'ACTIVE · Existing V11 training session '+active.publicId
   :assignments.length?'Existing V11 training assignments available; select a program to start.'
      :'No eligible training assignment. Assign one through Vision Lab.';
}
function mount(){
 if(mounted)return;mounted=true;
 const select=byId('glassesRuntimeSelect');
 select.addEventListener('change',render);
 byId('glassesConnectionRefresh').addEventListener('click',async()=>{try{await refreshPhysical();await refresh();msg('Connection status refreshed.');}catch(e){msg(e.message);}});
 byId('glassesConnectionConfirm').addEventListener('click',async()=>{
  if(!bound)return;
  try{await post('runtime.profile_confirm',{runtimePublicId:bound.publicId});await refresh();msg('Profile confirmed for this runtime.');}
  catch(e){msg(e.message);}
 });
 byId('glassesConnectionPair').addEventListener('click',async()=>{
  const dev=selectedDevice(selectedRuntime());
  const station=(catalog.stations||[]).find(s=>s.publicId===byId('glassesRuntimeStation')?.value);
  const locationId=station?.locationId||dev?.locationId;
  if(!locationId){msg('Select a station or a paired device for the restaurant location first.');return;}
  await cancelPair();
  try{
   const r=await json(adminApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
    body:JSON.stringify({action:'pairing.create',csrf_token:cfg.csrf,locationId,stationPublicId:station?.publicId||null,ttlMinutes:10})});
   pairing={id:r.pairing.publicId,until:Date.now()+r.pairing.expiresInSeconds*1000};
   txt('glassesPairCode',r.pairing.pairingCode);byId('glassesPairPanel').hidden=false;
   msg('One-time pairing code ready.');pairTimer=setInterval(pollPair,3000);
  }catch(e){msg(e.message);}
 });
 byId('glassesPairCancel').addEventListener('click',cancelPair);
 byId('deviceSelect').addEventListener('change',()=>renderStation(catalog.runtimes?.find(r=>r.publicId===select.value)||null));
 byId('glassesRuntimeEmployee').addEventListener('change',()=>{
   if(!select.value)renderStation(null);
 });
 byId('glassesRuntimeStation').addEventListener('change',()=>{
   const chosen=catalog.runtimes?.find(r=>r.publicId===select.value)||null;
   const station=byId('glassesRuntimeStation').value;
   const changed=!!chosen&&!!station&&station!==chosen.stationPublicId;
   byId('glassesRuntimeSaveStation').disabled=!changed;
   byId('glassesRuntimeSaveStation').dataset.dirty=changed?'1':'0';
   if(!chosen)byId('glassesRuntimeNew').disabled=!station||!selectedDevice()||
     !byId('glassesRuntimeEmployee').value||!!cfg.runtimePublicId;
 });
 byId('glassesRuntimeSaveStation').addEventListener('click',async()=>{
   const selected=catalog.runtimes?.find(r=>r.publicId===select.value)||null;
   const stationPublicId=byId('glassesRuntimeStation').value;
   if(!selected||!stationPublicId)return;
   if(!window.confirm('Move '+selected.deviceName+' to this kitchen station? Its open runtime will reload with new tickets.'))return;
   try{
     const result=await post('runtime.station_update',{runtimePublicId:selected.publicId,stationPublicId});
     const device=devices.find(d=>d.publicId===selected.devicePublicId);
     if(device){device.stationPublicId=result.runtime.stationPublicId;device.stationName=result.runtime.stationName;}
     if(cfg.runtimePublicId===selected.publicId){window.location.reload();return;}
     await refresh();status('Station saved · '+result.runtime.deviceName+' · '+result.runtime.stationName);
   }catch(error){status(error.message,true);}
 });
 byId('glassesRuntimeTrainingSelect').addEventListener('change',()=>renderTraining(catalog.runtimes?.find(r=>r.publicId===select.value)||null));
 byId('glassesRuntimeNew').addEventListener('click',async()=>{
   const device=devices.find(d=>d.publicId===byId('deviceSelect')?.value);
   const employeeId=Number(byId('glassesRuntimeEmployee').value);
   const stationPublicId=byId('glassesRuntimeStation').value;
   if(!device||device.virtualPreview){status('Select a paired physical glasses device in the Device list.',true);return;}
   if(!Number.isSafeInteger(employeeId)||employeeId<1){status('Choose an active employee first.',true);return;}
   if(!(catalog.stations||[]).some(s=>s.publicId===stationPublicId&&Number(s.locationId)===Number(device.locationId))){
     status('Select an active station at this glasses device location.',true);return;
   }
   try{
     const d=await post('runtime.create',{devicePublicId:device.publicId,employeeUserId:employeeId,stationPublicId});
     await refresh();select.value=d.runtime.publicId;render();
     status('Created · '+d.runtime.employeeName+'. Click Open to launch isolated runtime.');
   }catch(e){status(e.message,true);}
 });
 byId('glassesRuntimeTrainingStart').addEventListener('click',async()=>{
   const chosen=catalog.runtimes?.find(r=>r.publicId===select.value);
   if(!cfg.runtimePublicId||!bound||!chosen||chosen.publicId!==bound.publicId)return;
   const assignmentPublicId=byId('glassesRuntimeTrainingSelect').value;
   if(!assignmentPublicId)return;
   try{
     const result=await post('runtime.training_start',{runtimePublicId:bound.publicId,assignmentPublicId});
     await refresh();const label=byId('glassesRuntimeTrainingStatus');
     if(label)label.textContent='Started existing training session '+result.session.public_id;
   }catch(e){const label=byId('glassesRuntimeTrainingStatus');if(label)label.textContent=e.message;}
 });
 byId('glassesRuntimeTrainingEnd').addEventListener('click',async()=>{
   if(!cfg.runtimePublicId||!bound)return;
   const active=(catalog.activeTrainingSessions||[]).find(s=>s.employeeUserId===bound.employeeUserId&&s.deviceId===bound.deviceId);
   if(!active)return;
   if(!window.confirm('Mark this existing training session complete? Cancel to leave it active.'))return;
   try{
     await post('runtime.training_end',{runtimePublicId:bound.publicId,sessionPublicId:active.publicId,status:'completed'});
     await refresh();
   }catch(e){const label=byId('glassesRuntimeTrainingStatus');if(label)label.textContent=e.message;}
 });
 byId('glassesRuntimeOpen').addEventListener('click',()=>{
   const id=select.value;
   if(!catalog.runtimes?.some(r=>r.publicId===id))return;
   const url=new URL(location.href);url.searchParams.set('runtime',id);
   window.open(url.toString(),'_blank','noopener');
 });
 byId('glassesRuntimeEnd').addEventListener('click',async()=>{
   const id=select.value,selected=catalog.runtimes?.find(r=>r.publicId===id);
   if(!selected||!window.confirm('End '+selected.employeeName+' on '+selected.deviceName+'? The open runtime will stop when its next heartbeat runs.'))return;
   try{await post('runtime.end',{runtimePublicId:id});await refresh();status('Runtime ended. Device and employee available for reassignment.');}
   catch(e){status(e.message,true);}
 });
}
async function heartbeat(){
 if(!bound||lost)return;
 try{
   const result=await post('runtime.heartbeat',{runtimePublicId:bound.publicId,devicePublicId:bound.devicePublicId});
   if(result.runtime?.stationPublicId!==bound.stationPublicId){
     lost=true;status('Station updated. Reloading assigned glasses workspace.');
     window.location.reload();return;
   }
 }catch(e){
   if(lost)return;lost=true;status('Runtime stopped: '+e.message,true);
   document.documentElement.classList.add('glasses-runtime-revoked');
   window.dispatchEvent(new CustomEvent('gelato-glasses-runtime-revoked',{detail:{runtimePublicId:bound.publicId,reason:e.message}}));
 }
}
async function bootstrap(currentDevices){
 devices=currentDevices||[];mount();await refresh();try{await refreshPhysical();}catch(e){msg('Connection diagnostics unavailable: '+e.message);}
 if(!cfg.runtimePublicId)return null;
 bound=catalog.runtimes?.find(r=>r.publicId===cfg.runtimePublicId)||null;
 if(!bound||!devices.some(d=>d.publicId===bound.devicePublicId&&!d.virtualPreview)){
   status('Assigned runtime is unavailable, revoked or not accessible. Return to the runtime manager.',true);
   throw Error('Assigned runtime is unavailable. Open a current runtime from the manager.');
 }
 byId('deviceSelect').disabled=true;byId('modeSelect').value='live';byId('modeSelect').disabled=true;
 render();status('ACTIVE · '+bound.employeeName+' / '+bound.deviceName);
 await heartbeat();if(lost)throw Error('Runtime is no longer active.');
 setInterval(heartbeat,15000);
 return bound;
}
window.GelatoGlassesRuntimes={bootstrap,refresh,binding:()=>bound,selected:()=>selectedRuntime(),syncDevices(next){devices=next||[];render();}};
})();
