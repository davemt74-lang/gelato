(()=>{'use strict';
/* Section 266: operator-assigned runtimes. Each opened URL has its own isolated
 * browser camera, inference worker, checklist, audio and recovery state. */
const cfg=window.GELATO_GLASSES_SIMULATOR||{},byId=id=>document.getElementById(id);
let catalog={schemaReady:false,runtimes:[],employees:[],stations:[]},bound=null,devices=[],mounted=false,lost=false;
let adding=false;
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
 strip.hidden=false;
 if(label)label.hidden=true;
 strip.replaceChildren();
 // One preview workspace is visible before any saved glasses exist.
 // Only successfully saved server runtimes become numbered glasses tabs.
 const tabs=[{id:'',name:'Glasses 1',detail:'Live browser KDS and camera preview',number:1},
    ...choices.map((r,i)=>({id:r.publicId,name:'Glasses '+(i+2),
      detail:r.employeeName+' · '+r.deviceName,number:i+2}))];
 const activate=id=>{
   if(adding){adding=false;byId('glassesRuntimeAddCancel').hidden=true;}
   if(id&&id!==cfg.runtimePublicId){
     const url=new URL(location.href);url.searchParams.delete('addRuntime');url.searchParams.set('runtime',id);
     window.location.assign(url.toString());return;
   }
   if(!id&&cfg.runtimePublicId){
     const url=new URL(location.href);url.searchParams.delete('runtime');url.searchParams.delete('addRuntime');
     window.location.assign(url.toString());return;
   }
   const select=byId('glassesRuntimeSelect');select.value=id;render();
 };
 for(const tab of tabs){
   const button=document.createElement('button');button.type='button';button.setAttribute('role','tab');
   button.setAttribute('aria-label',tab.name+' · '+tab.detail);button.title=tab.detail;
   button.dataset.runtimeId=tab.id;button.setAttribute('aria-selected',!adding&&tab.id===current?'true':'false');
   button.tabIndex=!adding&&tab.id===current?0:-1;
   const number=document.createElement('span');number.textContent=String(tab.number);
   number.setAttribute('aria-hidden','true');
   button.append(number,document.createTextNode(tab.name));
   button.addEventListener('click',()=>activate(tab.id));
   strip.appendChild(button);
 }
 const plus=document.createElement('button');plus.type='button';plus.className='glasses-runtime-add';
 plus.textContent='+';plus.setAttribute('aria-label','Add glasses runtime');
 plus.title='Configure and save another pair of glasses';plus.setAttribute('aria-pressed',adding?'true':'false');
 plus.addEventListener('click',()=>{
   if(!cfg.canManageMedia){status('Glasses management permission is required to add a device.',true);return;}
   if(cfg.runtimePublicId){
     const url=new URL(location.href);url.searchParams.delete('runtime');url.searchParams.set('addRuntime','1');
     window.location.assign(url.toString());return;
   }
   adding=true;const select=byId('glassesRuntimeSelect');select.value='';
   render();byId('glassesRuntimePairSelect').focus();
 });
 strip.appendChild(plus);
}
function render(){
 const select=byId('glassesRuntimeSelect'),employee=byId('glassesRuntimeEmployee');
 if(!select||!employee)return;
 const previous=select.value;
 const choices=catalog.runtimes||[];
 select.innerHTML='<option value="">Preview / Add glasses…</option>'+
   choices.map(r=>'<option value="'+esc(r.publicId)+'">'+esc(r.employeeName)+' · '+esc(r.deviceName)+'</option>').join('');
 select.value=adding?'':cfg.runtimePublicId||'';
 const chosen=adding?null:choices.find(r=>r.publicId===select.value)||null;
 renderPairDevices();
 byId('glassesRuntimeDeviceGroup').hidden=adding;
 byId('glassesRuntimeEmployeeGroup').hidden=!adding&&!chosen;
 byId('glassesRuntimeSaveStation').hidden=!chosen;
 byId('glassesRuntimeNew').hidden=!adding;
 byId('glassesRuntimeAddCancel').hidden=!adding;
 byId('glassesRuntimeOpen').hidden=true;
 byId('glassesRuntimeEnd').hidden=!chosen;
 byId('glassesRuntimeConfirm').hidden=!(cfg.runtimePublicId&&bound&&!bound.profileConfirmedAt);
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
 employee.disabled=!adding||(catalog.employees||[]).length===0;
 renderStation(chosen);
 renderTraining(chosen);
 byId('glassesRuntimeNew').disabled=!adding||!!cfg.runtimePublicId||!!chosen||!catalog.schemaReady||
   !(catalog.employees||[]).length||!selectedDevice()||!!selectedDevice()?.virtualPreview||
   !byId('glassesRuntimeStation')?.value||!employee.value;
 byId('glassesRuntimeOpen').disabled=!chosen;
 byId('glassesRuntimeEnd').disabled=!chosen;
 if(!catalog.schemaReady){
   status('Browser preview works without a saved runtime. Run upgrade.php to enable new glasses assignments.',true);
   return;
 }
 status(cfg.runtimePublicId?
   (bound?'Connected workspace · '+bound.employeeName:'Runtime assignment unavailable'):
   (adding?'Choose a paired device, station and employee, then Save Runtime.':
    chosen?'Saved glasses · '+chosen.employeeName+' · '+chosen.deviceName:
       'Glasses 1 · Preview ready. Click + to add physical glasses.'));
}

function selectedDevice(chosen=null){
 return chosen?devices.find(d=>d.publicId===chosen.devicePublicId):
   devices.find(d=>d.publicId===(adding?byId('glassesRuntimePairSelect')?.value:byId('deviceSelect')?.value));
}
function renderPairDevices(){
 const field=byId('glassesRuntimePairSelect'),group=byId('glassesRuntimePairGroup');
 if(!field||!group)return;
 group.hidden=!adding;
 const old=field.value;
 const paired=devices.filter(d=>!d.virtualPreview&&d.status==='active'&&!d.revokedAt);
 field.replaceChildren(new Option(paired.length?'Choose paired glasses…':'No paired glasses. Use Manage devices to pair one.',''));
 for(const d of paired)field.add(new Option((d.displayName||'Glasses')+' · '+(d.locationName||'Restaurant'),d.publicId));
 if(paired.some(d=>d.publicId===old))field.value=old;
 field.disabled=!paired.length;
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
 field.disabled=!locationId||(cfg.runtimePublicId&&!bound);
 const changed=!!chosen&&!!field.value&&field.value!==assigned;
 button.disabled=!changed;button.dataset.dirty=changed?'1':'0';
 const create=byId('glassesRuntimeNew');
 if(!chosen)create.disabled=!adding||!!cfg.runtimePublicId||!device||!!device.virtualPreview||!field.value||
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
 byId('glassesRuntimeAddCancel').addEventListener('click',()=>{
   adding=false;
   const url=new URL(location.href);url.searchParams.delete('addRuntime');
   if((catalog.runtimes||[]).length){url.searchParams.set('runtime',catalog.runtimes[0].publicId);window.location.assign(url.toString());return;}
   render();
 });
 byId('glassesRuntimeConfirm').addEventListener('click',async()=>{
   if(!bound)return;
   try{await post('runtime.profile_confirm',{runtimePublicId:bound.publicId});await refresh();
      status('Your profile is confirmed for this runtime.');}
   catch(e){status(e.message,true);}
 });
 byId('deviceSelect').addEventListener('change',()=>renderStation(catalog.runtimes?.find(r=>r.publicId===select.value)||null));
 byId('glassesRuntimePairSelect').addEventListener('change',()=>{renderStation(null);render();});
 byId('glassesRuntimeEmployee').addEventListener('change',()=>{
   if(!select.value)renderStation(null);
 });
 byId('glassesRuntimeStation').addEventListener('change',()=>{
   const chosen=catalog.runtimes?.find(r=>r.publicId===select.value)||null;
   const station=byId('glassesRuntimeStation').value;
   if(!chosen&&!adding&&!cfg.runtimePublicId){
     const locationId=selectedDevice()?.locationId||null;
     const preview=devices.find(d=>d.virtualPreview&&
       (d.stationPublicId||'')===station&&d.locationId===locationId);
     if(preview){
       byId('deviceSelect').value=preview.publicId;
       byId('deviceSelect').dispatchEvent(new Event('change'));
     }
   }
   const changed=!!chosen&&!!station&&station!==chosen.stationPublicId;
   byId('glassesRuntimeSaveStation').disabled=!changed;
   byId('glassesRuntimeSaveStation').dataset.dirty=changed?'1':'0';
   if(!chosen)byId('glassesRuntimeNew').disabled=!adding||!station||!selectedDevice()||
     !!selectedDevice()?.virtualPreview||!byId('glassesRuntimeEmployee').value||!!cfg.runtimePublicId;
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
   const device=selectedDevice();
   const employeeId=Number(byId('glassesRuntimeEmployee').value);
   const stationPublicId=byId('glassesRuntimeStation').value;
   if(!device||device.virtualPreview){status('Select a paired physical glasses device in the Device list.',true);return;}
   if(!Number.isSafeInteger(employeeId)||employeeId<1){status('Choose an active employee first.',true);return;}
   if(!(catalog.stations||[]).some(s=>s.publicId===stationPublicId&&Number(s.locationId)===Number(device.locationId))){
     status('Select an active station at this glasses device location.',true);return;
   }
   try{
     const d=await post('runtime.create',{devicePublicId:device.publicId,employeeUserId:employeeId,stationPublicId});
     await refresh();adding=false;select.value=d.runtime.publicId;render();
     status('Runtime saved · '+d.runtime.employeeName+'. Opening your glasses workspace.');
     const url=new URL(location.href);url.searchParams.delete('addRuntime');url.searchParams.set('runtime',d.runtime.publicId);
     window.location.assign(url.toString());
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
   try{
     await post('runtime.end',{runtimePublicId:id});await refresh();
     const url=new URL(location.href);url.searchParams.delete('runtime');url.searchParams.delete('addRuntime');
     window.location.assign(url.toString());
   }
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
 devices=currentDevices||[];mount();
 adding=new URL(location.href).searchParams.get('addRuntime')==='1';
 try{await refresh();}catch(e){
   if(cfg.runtimePublicId)throw e;
   catalog={schemaReady:false,runtimes:[],employees:[],stations:[]};
   render();status('Glasses assignments unavailable: '+e.message+' · Preview remains usable.',true);
 }
 if(!cfg.runtimePublicId)return null; // Never redirect or create a runtime automatically.
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
window.GelatoGlassesRuntimes={bootstrap,refresh,binding:()=>bound,selected:()=>catalog.runtimes?.find(r=>r.publicId===byId('glassesRuntimeSelect')?.value)||null,syncDevices(next){devices=next||[];render();}};
})();
