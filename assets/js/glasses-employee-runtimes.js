(()=>{'use strict';
/* Section 266: operator-assigned runtimes. Each opened URL has its own isolated
 * browser camera, inference worker, checklist, audio and recovery state. */
const cfg=window.GELATO_GLASSES_SIMULATOR||{},byId=id=>document.getElementById(id);
let catalog={schemaReady:false,runtimes:[],employees:[]},bound=null,devices=[],mounted=false,lost=false;
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
 renderTraining(chosen);
 employee.innerHTML='<option value="">Assign employee…</option>'+
   (catalog.employees||[]).map(e=>'<option value="'+e.id+'">'+esc(e.displayName)+(e.employeeNumber?' · '+esc(e.employeeNumber):'')+'</option>').join('');
 if(chosen&&!(catalog.employees||[]).some(e=>e.id===chosen.employeeUserId))
   employee.innerHTML+='<option value="'+chosen.employeeUserId+'">'+esc(chosen.employeeName)+'</option>';
 employee.value=chosen?String(chosen.employeeUserId):'';
 employee.disabled=!!chosen||(catalog.employees||[]).length===0;
 byId('glassesRuntimeNew').disabled=!!cfg.runtimePublicId||!!chosen||!catalog.schemaReady||(catalog.employees||[]).length===0;
 byId('glassesRuntimeOpen').disabled=!chosen;
 byId('glassesRuntimeEnd').disabled=!chosen||(catalog.employees||[]).length===0;
 if(!catalog.schemaReady){status('Install the multi-runtime migration with upgrade.php.',true);return;}
 status(cfg.runtimePublicId?
   (bound?'ACTIVE · '+bound.employeeName:'RUNTIME NOT AVAILABLE'):
   (chosen?'Assigned: '+chosen.employeeName+' · '+chosen.deviceName:'Choose paired glasses and an employee'));
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
 byId('glassesRuntimeTrainingSelect').addEventListener('change',()=>renderTraining(catalog.runtimes?.find(r=>r.publicId===select.value)||null));
 byId('glassesRuntimeNew').addEventListener('click',async()=>{
   const device=devices.find(d=>d.publicId===byId('deviceSelect')?.value);
   const employeeId=Number(byId('glassesRuntimeEmployee').value);
   if(!device||device.virtualPreview){status('Select a paired physical glasses device in the Device list.',true);return;}
   if(!Number.isSafeInteger(employeeId)||employeeId<1){status('Choose an active employee first.',true);return;}
   try{
     const d=await post('runtime.create',{devicePublicId:device.publicId,employeeUserId:employeeId});
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
   await post('runtime.heartbeat',{runtimePublicId:bound.publicId,devicePublicId:bound.devicePublicId});
 }catch(e){
   if(lost)return;lost=true;status('Runtime stopped: '+e.message,true);
   document.documentElement.classList.add('glasses-runtime-revoked');
   window.dispatchEvent(new CustomEvent('gelato-glasses-runtime-revoked',{detail:{runtimePublicId:bound.publicId,reason:e.message}}));
 }
}
async function bootstrap(currentDevices){
 devices=currentDevices||[];mount();await refresh();
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
window.GelatoGlassesRuntimes={bootstrap,refresh,binding:()=>bound};
})();
