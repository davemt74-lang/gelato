(()=>{'use strict';
const cfg=window.GELATO_GLASSES_SIMULATOR||{};
const $=id=>document.getElementById(id);
const state={mode:'mock',devices:[],device:null,work:null,selectedKdsItemPublicId:'',build:null,validation:null,seq:1,logs:[],autoPlayTimer:null,syncTimer:null,syncBusy:false,syncErrors:0,syncFingerprint:'',syncAbort:null,syncEpoch:0,lastSyncAt:null,cameraStream:null,cameraTrack:null,cameraTarget:null,cameraDevices:[],cameraSource:'image'};
const mock={
  work:{assignmentRequired:false,station:{publicId:'station-mock',name:'Sandwich / Pizza Line'},revision:'mock-revision',focusItem:{kdsItemPublicId:'kds-mock-1',status:'queued',ticket:{checkNumber:'1042',serviceMode:'dine_in',tableName:'Table 12',guestCount:2},posLine:{id:1,menuItemId:1,name:'Club Sandwich + Fries',optionName:'Regular',quantity:1,specialInstructions:'NO TOMATO · EXTRA BACON',modifiers:[{name:'Extra Bacon'}]},menu:{preparationNotes:'Build, slice and plate with fries.'},recipeSource:{status:'exact_name',recipe:{instructions:['Toast bread','Add mayo','Add turkey','Add bacon','Add lettuce','Add tomato','Top and slice','Plate with fries']}}},items:[],metrics:{queued:1,inProgress:0,ready:0,held:0}},
  components:['Toasted Bread','Mayo','Turkey','Bacon','Lettuce','Tomato','Fries'].map((name,i)=>({componentKey:'mock:'+i,displayName:name,expectedQuantity:i===0?3:1,detectedQuantity:0,unit:i===0?'slices':'portion',optional:false,status:'waiting',sortOrder:i+1})),
};
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function log(type,msg){const line={at:new Date(),type,msg};state.logs.unshift(line);state.logs=state.logs.slice(0,80);renderLog();}
function renderLog(){$('eventLog').innerHTML=state.logs.map(x=>'<div class="event-line"><b>'+escapeHtml(x.type)+'</b> '+escapeHtml(x.at.toLocaleTimeString())+' · '+escapeHtml(x.msg)+'</div>').join('')||'<span class="sim-muted">No events yet.</span>';}
async function api(action,payload={}){const r=await fetch(cfg.api,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf:cfg.csrf,action,devicePublicId:state.device?.publicId||'',...payload})});const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));if(!r.ok||!d.ok)throw new Error(d.message||'Simulator request failed.');return d;}
const LIVE_SYNC_BASE_MS=1500;
const LIVE_SYNC_MAX_BACKOFF_MS=10000;
function setSyncBadge(status,label){
  const badge=$('syncBadge');if(!badge)return;
  badge.className='sim-sync-badge '+status;badge.textContent=label;
}
function liveWorkFingerprint(work){
  const items=(work?.items||[]).map(x=>({
    id:x.kdsItemPublicId||'',status:x.status||'',ticket:x.ticket?.checkNumber||'',
    table:x.ticket?.tableName||'',service:x.ticket?.serviceMode||'',
    name:x.posLine?.name||'',option:x.posLine?.optionName||'',
    qty:x.posLine?.quantity??null,special:x.posLine?.specialInstructions||'',
    modifiers:(x.posLine?.modifiers||[]).map(m=>m.name||m.label||String(m))
  }));
  return JSON.stringify({revision:work?.revision||'',station:work?.station?.publicId||'',focus:work?.focusItem?.kdsItemPublicId||'',items});
}
function stopLiveStationSync(reason='paused'){
  state.syncEpoch+=1;
  if(state.syncTimer){clearTimeout(state.syncTimer);state.syncTimer=null;}
  if(state.syncAbort){state.syncAbort.abort();state.syncAbort=null;}
  state.syncBusy=false;
  if(reason==='mock')setSyncBadge('paused','SYNC OFF');
  else if(reason==='hidden')setSyncBadge('paused','SYNC PAUSED');
  else setSyncBadge('paused','SYNC IDLE');
}
function scheduleLiveStationSync(delay=LIVE_SYNC_BASE_MS){
  if(state.syncTimer)clearTimeout(state.syncTimer);
  if(state.mode!=='live'||!state.device||document.hidden)return;
  state.syncTimer=setTimeout(()=>{state.syncTimer=null;syncLiveStationWork(false);},delay);
}
function applyLiveWork(work,{announce=false}={}){
  const previous=state.syncFingerprint,next=liveWorkFingerprint(work);
  const items=work?.items||[],ids=items.map(x=>x.kdsItemPublicId);
  const activeBuildItem=state.build?.kdsItemPublicId||'';
  state.work=work;
  if(!ids.includes(state.selectedKdsItemPublicId)){
    if(activeBuildItem&&ids.includes(activeBuildItem))state.selectedKdsItemPublicId=activeBuildItem;
    else state.selectedKdsItemPublicId=work?.focusItem?.kdsItemPublicId||ids[0]||'';
  }
  state.syncFingerprint=next;state.lastSyncAt=Date.now();render();
  if(announce&&previous&&previous!==next)log('SYNC','Station changed — '+items.length+' active KDS item(s).');
  return previous!==next;
}
async function fetchLiveStationWork(devicePublicId){
  if(!devicePublicId)throw new Error('Choose a device first.');
  if(state.syncAbort)state.syncAbort.abort();
  const controller=new AbortController();state.syncAbort=controller;
  const timeout=setTimeout(()=>controller.abort(),4500);
  try{
    const url=new URL(cfg.api,window.location.href);
    url.searchParams.set('action','work');url.searchParams.set('devicePublicId',devicePublicId);
    const r=await fetch(url.toString(),{method:'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal});
    const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));
    if(!r.ok||!d.ok)throw new Error(d.message||'Live station synchronization failed.');
    return d;
  }finally{
    clearTimeout(timeout);if(state.syncAbort===controller)state.syncAbort=null;
  }
}
async function syncLiveStationWork(announce=true){
  if(state.mode!=='live'||!state.device){stopLiveStationSync(state.mode==='mock'?'mock':'idle');return false;}
  if(document.hidden){stopLiveStationSync('hidden');return false;}
  if(state.syncBusy)return false;
  const epoch=state.syncEpoch,devicePublicId=state.device.publicId;
  state.syncBusy=true;setSyncBadge('syncing','SYNCING');
  try{
    const d=await fetchLiveStationWork(devicePublicId);
    if(epoch!==state.syncEpoch||state.mode!=='live'||state.device?.publicId!==devicePublicId)return false;
    const changed=applyLiveWork(d.work,{announce});
    const recovered=state.syncErrors>0;state.syncErrors=0;
    setSyncBadge('live','LIVE SYNC');
    if(recovered)log('SYNC','Live station synchronization recovered.');
    return changed;
  }catch(e){
    if(epoch!==state.syncEpoch||state.mode!=='live'||state.device?.publicId!==devicePublicId)return false;
    state.syncErrors+=1;
    const message=e?.name==='AbortError'?'Live station synchronization timed out.':(e?.message||'Live station synchronization failed.');
    setSyncBadge('error','SYNC RETRY');
    if(state.syncErrors===1||state.syncErrors%4===0)log('SYNC',message);
    return false;
  }finally{
    if(epoch===state.syncEpoch){
      state.syncBusy=false;
      const delay=state.syncErrors?Math.min(LIVE_SYNC_MAX_BACKOFF_MS,LIVE_SYNC_BASE_MS*Math.pow(2,Math.min(state.syncErrors,3))):LIVE_SYNC_BASE_MS;
      scheduleLiveStationSync(delay);
    }
  }
}
function startLiveStationSync({immediate=true}={}){
  stopLiveStationSync('idle');state.syncErrors=0;state.syncFingerprint='';
  if(state.mode!=='live'||!state.device){setSyncBadge('paused',state.mode==='mock'?'SYNC OFF':'SYNC IDLE');return;}
  if(document.hidden){setSyncBadge('paused','SYNC PAUSED');return;}
  if(immediate)syncLiveStationWork(false);else scheduleLiveStationSync();
}
async function loadDevices(){try{const r=await fetch(cfg.api,{credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Device list failed.');state.devices=d.devices||[];$('deviceSelect').innerHTML='<option value="">Choose device…</option>'+state.devices.map(x=>'<option value="'+escapeHtml(x.publicId)+'">'+escapeHtml(x.displayName)+' · '+escapeHtml(x.stationName||'Unassigned')+'</option>').join('');if(state.devices[0]){$('deviceSelect').value=state.devices[0].publicId;state.device=state.devices[0];}log('SYSTEM','Loaded '+state.devices.length+' accessible glasses device(s).');}catch(e){$('deviceSelect').innerHTML='<option value="">No devices</option>';log('ERROR',e.message);}}
const CAMERA_PREF_KEY='gelato.webGlassesSimulator.cameraPrefs.v1';
function cameraSupported(){return !!(navigator.mediaDevices&&navigator.mediaDevices.getUserMedia);}
function cameraPrefs(){try{return JSON.parse(localStorage.getItem(CAMERA_PREF_KEY)||'{}');}catch{return {};}}
function saveCameraPrefs(){
  localStorage.setItem(CAMERA_PREF_KEY,JSON.stringify({
    deviceId:$('cameraDeviceSelect').value||'',resolution:$('cameraResolution').value||'1280x720',
    fit:$('cameraFit').value||'cover',mirror:$('cameraMirror').checked
  }));
}
function setCameraHealth(stateName,label){
  const el=$('cameraHealth');if(!el)return;el.textContent=label;el.dataset.state=stateName;
}
function applyCameraPresentation(){
  const video=$('cameraVideo');video.classList.toggle('fit-contain',$('cameraFit').value==='contain');
  video.classList.toggle('mirrored',$('cameraMirror').checked);saveCameraPrefs();
}
async function enumerateCameras(){
  if(!cameraSupported()){$('cameraDeviceSelect').innerHTML='<option value="">Camera API unavailable</option>';return;}
  const devices=await navigator.mediaDevices.enumerateDevices();state.cameraDevices=devices.filter(d=>d.kind==='videoinput');
  const saved=cameraPrefs().deviceId||'';
  $('cameraDeviceSelect').innerHTML='<option value="">Default camera</option>'+state.cameraDevices.map((d,i)=>'<option value="'+escapeHtml(d.deviceId)+'">'+escapeHtml(d.label||('Camera '+(i+1)))+'</option>').join('');
  if(saved&&state.cameraDevices.some(d=>d.deviceId===saved))$('cameraDeviceSelect').value=saved;
}
function stopCamera(reason='stopped'){
  if(state.cameraStream)state.cameraStream.getTracks().forEach(t=>t.stop());
  state.cameraStream=null;state.cameraTrack=null;
  const video=$('cameraVideo');video.srcObject=null;video.hidden=true;
  $('startCamera').disabled=false;$('stopCamera').disabled=true;$('captureFrame').disabled=true;
  setCameraHealth(reason==='error'?'error':'off',reason==='error'?'ERROR':'OFF');
  if(state.cameraSource==='camera')$('scenePlaceholder').hidden=false;
}
async function startCamera(){
  if(!cameraSupported()){setCameraHealth('error','UNSUPPORTED');log('CAMERA','Browser camera API is unavailable.');return;}
  stopCamera();
  const [w,h]=($('cameraResolution').value||'1280x720').split('x').map(Number),deviceId=$('cameraDeviceSelect').value;
  const videoConstraints={width:{ideal:w},height:{ideal:h},frameRate:{ideal:30,max:30}};
  if(deviceId)videoConstraints.deviceId={exact:deviceId};else videoConstraints.facingMode={ideal:'environment'};
  setCameraHealth('starting','STARTING');
  try{
    const stream=await navigator.mediaDevices.getUserMedia({video:videoConstraints,audio:false});
    state.cameraStream=stream;state.cameraTrack=stream.getVideoTracks()[0]||null;
    const video=$('cameraVideo');video.srcObject=stream;video.hidden=false;await video.play();
    $('sceneImage').hidden=true;$('scenePlaceholder').hidden=true;
    $('startCamera').disabled=true;$('stopCamera').disabled=false;$('captureFrame').disabled=false;
    state.cameraSource='camera';$('sceneSourceSelect').value='camera';applyCameraPresentation();await enumerateCameras();
    const settings=state.cameraTrack?.getSettings?.()||{};
    $('cameraMeta').innerHTML='<span>'+escapeHtml(state.cameraTrack?.label||'Camera')+'</span><span>'+escapeHtml(String(settings.width||w))+'×'+escapeHtml(String(settings.height||h))+' · '+escapeHtml(String(Math.round(settings.frameRate||30)))+' fps</span>';
    setCameraHealth('ready','READY');log('CAMERA','Browser camera preview started.');
    state.cameraTrack?.addEventListener('ended',()=>{stopCamera('error');log('CAMERA','Camera stream ended.');},{once:true});
  }catch(e){
    stopCamera('error');
    const name=e?.name||'CameraError',message=name==='NotAllowedError'?'Camera permission was denied.':name==='NotFoundError'?'No camera device was found.':(e?.message||'Camera could not start.');
    $('cameraMeta').innerHTML='<span>'+escapeHtml(message)+'</span>';log('CAMERA',message);
  }
}
function captureCameraFrame(){
  const video=$('cameraVideo'),canvas=$('cameraCaptureCanvas');
  if(!state.cameraStream||!video.videoWidth||!video.videoHeight){log('CAMERA','No live camera frame is available.');return;}
  canvas.width=video.videoWidth;canvas.height=video.videoHeight;
  const ctx=canvas.getContext('2d');if($('cameraMirror').checked){ctx.translate(canvas.width,0);ctx.scale(-1,1);}
  ctx.drawImage(video,0,0,canvas.width,canvas.height);
  canvas.toBlob(blob=>{
    if(!blob)return;
    const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='gelato-camera-frame-'+Date.now()+'.png';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    log('CAMERA','Captured a local camera frame.');
  },'image/png');
}
function clearCameraTarget(){
  state.cameraTarget=null;document.querySelector('.camera-target-marker')?.remove();$('submitCameraTarget').disabled=true;$('clearCameraTarget').disabled=true;
}
function setCameraTargetFromPointer(event){
  if(!$('cameraManualTarget').checked||state.cameraSource!=='camera'||!state.cameraStream)return;
  const stage=$('glassesStage'),rect=stage.getBoundingClientRect(),x=clamp((event.clientX-rect.left)/rect.width,0,1),y=clamp((event.clientY-rect.top)/rect.height,0,1);
  state.cameraTarget={x,y,width:.12,height:.12};
  document.querySelector('.camera-target-marker')?.remove();
  const marker=document.createElement('div');marker.className='camera-target-marker';marker.style.left=(x*100)+'%';marker.style.top=(y*100)+'%';marker.innerHTML='<span>VISION TARGET</span>';stage.appendChild(marker);
  $('submitCameraTarget').disabled=false;$('clearCameraTarget').disabled=false;
}
async function submitCameraTargetObservation(){
  try{
    if(!state.cameraTarget)throw new Error('Choose a target on the camera image first.');
    if(!state.build)throw new Error('Start a build first.');
    const selected=$('cameraTargetComponent').value,current=currentComponent();
    const c=state.build.components.find(x=>x.componentKey===selected)||current;
    if(!c)throw new Error('No build component is available for this target.');
    const confidence=Number($('cameraTargetConfidence').value)/100,qty=Math.max(.001,Number(c.expectedQuantity||1)-Number(c.detectedQuantity||0));
    flashDetection(c,{confidence,state:confidence<.75?'low-confidence':'confirmed'});
    if(state.mode==='mock'){
      c.detectedQuantity=Number(c.detectedQuantity||0)+qty;c.confidence=confidence;c.status=confidence<.75?'verify':'confirmed';state.validation=null;render();log('VISION','Submitted camera target for '+c.displayName+' in mock mode.');return;
    }
    const d=await api('build.observe',{buildSessionPublicId:state.build.publicId,observationKey:'camera-websim-'+Date.now()+'-'+state.seq,componentKey:c.componentKey,displayName:c.displayName,observationAction:'added',quantity:qty,confidence,trackingId:'camera-websim-'+state.seq,bbox:state.cameraTarget,metadata:{source:'web_glasses_simulator_camera',manualTarget:true}});
    state.build=d.buildSession;state.validation=null;render();log('LIVE','Submitted camera-target observation for '+c.displayName+'.');
  }catch(e){log('ERROR',e.message);}
}
function renderCameraTargetComponents(){
  const comps=state.build?.components||[];
  $('cameraTargetComponent').innerHTML='<option value="">Current required component</option>'+comps.filter(c=>c.status!=='unexpected'&&c.status!=='ignored').map(c=>'<option value="'+escapeHtml(c.componentKey)+'">'+escapeHtml(c.displayName)+'</option>').join('');
}
function mockBuild(){return {publicId:'build-mock-1',status:'active',kdsItemPublicId:'kds-mock-1',kdsStatus:'queued',context:{itemName:'Club Sandwich + Fries',buildDefinition:{steps:mock.work.focusItem.recipeSource.recipe.instructions.map((text,i)=>({stepKey:'step-'+(i+1),order:i+1,text,componentKeys:[]}))}},summary:{required:mock.components.length,confirmed:0,verify:0,unexpected:0,accounted:false,currentComponentKey:mock.components[0].componentKey},components:structuredClone(mock.components)};}
function selectedItem(){const items=state.work?.items||[];if(state.selectedKdsItemPublicId){const chosen=items.find(x=>x.kdsItemPublicId===state.selectedKdsItemPublicId);if(chosen)return chosen;}return state.work?.focusItem||items[0]||null;}
function deriveSteps(){const item=selectedItem();const fromBuild=state.build?.context?.buildDefinition?.steps;if(Array.isArray(fromBuild)&&fromBuild.length)return fromBuild.map(x=>typeof x==='string'?x:(x.text||x.label||''));const rx=item?.recipeSource?.recipe?.instructions;return Array.isArray(rx)&&rx.length?rx:['Load work','Build item','Validate','Send to Expo'];}
function itemName(){return selectedItem()?.posLine?.name||state.build?.context?.itemName||'No focused item';}
function currentComponent(){return state.build?.components?.find(c=>!c.optional&&!['confirmed','ignored'].includes(c.status))||null;}
function orderItems(){const items=state.work?.items||[];return items.length?items:(state.work?.focusItem?[state.work.focusItem]:[]);}
function elapsedLabel(item,index){const source=item?.startedAt||item?.createdAt||item?.ticket?.openedAt;if(source){const ms=Date.now()-new Date(source).getTime();if(Number.isFinite(ms)&&ms>=0){const m=Math.floor(ms/60000),s=Math.floor(ms/1000)%60;return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');}}return state.mode==='mock'?(index===0?'04:12':'02:38'):'--:--';}
function renderTopStatus(){
  const now=new Date(),live=state.mode==='live';
  $('hudClock').textContent=now.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
  $('hudStatusTemp').textContent=(state.work?.station?.name||state.device?.stationName||'KITCHEN').toUpperCase()+' · 74°F';
  $('hudRuntimeState').textContent=state.build?'BUILDING':'READY';
  $('hudConnectivity').textContent=live?'BT · LIVE':'SIM · LOCAL';
  $('hudBattery').textContent='87%';
}
function renderOrdersQueue(item){
  const items=orderItems();
  $('hudOrdersCount').textContent=String(items.length);
  $('hudOrders').innerHTML=items.slice(0,5).map((x,i)=>'<div class="hud-order-row '+(x.kdsItemPublicId===item?.kdsItemPublicId?'active':'')+'"><span class="ticket">#'+escapeHtml(x.ticket?.checkNumber||String(i+1).padStart(3,'0'))+'</span><span class="item">'+escapeHtml(x.posLine?.name||'Kitchen item')+'</span><span class="age">'+escapeHtml(elapsedLabel(x,i))+'</span></div>').join('')||'<span class="sim-muted">No active orders</span>';
}
function buildProgressIndex(steps,comps){
  const confirmed=comps.filter(c=>c.status==='confirmed'||(c.optional&&c.status==='ignored')).length;
  if(!steps.length)return 0;
  if(!comps.length)return 0;
  return Math.min(steps.length-1,Math.floor((confirmed/Math.max(1,comps.length))*steps.length));
}
function renderBuildRail(item,steps,comps){
  $('hudBuildTicket').textContent='#'+(item?.ticket?.checkNumber||'—');
  $('hudBuildItem').textContent=itemName();
  const meta=[item?.ticket?.tableName||item?.ticket?.serviceMode,item?.posLine?.optionName,item?.posLine?.specialInstructions].filter(Boolean);
  $('hudBuildMeta').textContent=meta.join(' · ')||'No modifiers';
  const idx=buildProgressIndex(steps,comps);
  $('hudBuildSteps').innerHTML=steps.slice(0,8).map((s,i)=>'<li class="'+(i<idx?'done':i===idx?'current':'')+'">'+escapeHtml(s)+'</li>').join('');
}
function renderValidationPanel(comps,validation){
  const unexpected=comps.filter(c=>c.status==='unexpected').length;
  const missing=comps.filter(c=>!c.optional&&!['confirmed','ignored','unexpected'].includes(c.status)).length;
  const accounted=comps.filter(c=>c.status==='confirmed'||(c.optional&&c.status==='ignored')).length;
  const required=comps.filter(c=>!c.optional&&c.status!=='unexpected').length;
  const stateName=validation?.status==='ready_for_finishing'?'READY':validation?.status==='blocked'?'BLOCKED':comps.some(c=>c.status==='verify')?'VERIFY':'BUILDING';
  $('hudValidationState').textContent=stateName;
  $('hudValidationCount').textContent=accounted+' / '+required+' accounted';
  $('hudValidationMissing').textContent=String(missing);
  $('hudValidationUnexpected').textContent=String(unexpected);
  $('hudValidationDetail').textContent=validation?.summary?.next?.message||validation?.status||'Build in progress';
  $('validationCard').className='hud-card hud-validation-panel '+stateName.toLowerCase();
}
function renderNextInstruction(comp,validation){
  const next=comp?'Add '+comp.displayName:(validation?.nextStage==='expo_finishing'?'Send to Expo / Finishing':'Validate build');
  $('hudNextCenter').textContent=next;
  $('hudNextTarget').textContent=comp?(Number(comp.detectedQuantity||0)+' / '+Number(comp.expectedQuantity||0)+' '+(comp.unit||'')):(validation?.status||'Product validation');
}
function render(){
  const live=state.mode==='live',item=selectedItem(),build=state.build,validation=state.validation;
  $('connectionBadge').textContent=live?'LIVE GELATO':'MOCK';
  $('connectionBadge').style.color=live?'var(--good)':'var(--blue)';
  const station=state.work?.station?.name||state.device?.stationName||'Unassigned station';
  $('stationBadge').textContent=station;
  $('buildBadge').textContent=build?'Build '+build.publicId:'No active build';
  $('consoleItem').textContent=itemName();
  $('workStatus').textContent=item?.status||'idle';
  const workItems=orderItems();
  $('workItemSelect').innerHTML=workItems.length?workItems.map(x=>'<option value="'+escapeHtml(x.kdsItemPublicId)+'" '+(x.kdsItemPublicId===item?.kdsItemPublicId?'selected':'')+'>'+escapeHtml(x.posLine?.name||'Kitchen item')+' · '+escapeHtml(x.status||'')+' · #'+escapeHtml(x.ticket?.checkNumber||'')+'</option>').join(''):'<option value="">No station work</option>';
  $('workItemSelect').disabled=!!build||workItems.length===0;
  $('workMeta').innerHTML=[
    ['Ticket',item?.ticket?.checkNumber||'—'],['Table',item?.ticket?.tableName||'—'],
    ['Guests',item?.ticket?.guestCount||'—'],['Special',item?.posLine?.specialInstructions||'None']
  ].map(([a,b])=>'<div><small>'+escapeHtml(a)+'</small><strong>'+escapeHtml(b)+'</strong></div>').join('');
  const steps=deriveSteps(),comp=currentComponent(),comps=build?.components||[];
  renderTopStatus();renderOrdersQueue(item);renderNextInstruction(comp,validation);renderBuildRail(item,steps,comps);renderValidationPanel(comps,validation);
  $('componentCount').textContent=String(comps.length);renderCameraTargetComponents();
  $('componentControls').innerHTML=comps.filter(c=>c.status!=='unexpected'&&c.status!=='ignored').map(c=>'<div class="component-row '+escapeHtml(c.status)+'"><div class="copy"><strong>'+escapeHtml(c.displayName)+'</strong><small>'+escapeHtml(c.status)+' · '+Number(c.detectedQuantity||0)+' / '+Number(c.expectedQuantity||0)+' '+escapeHtml(c.unit||'')+'</small></div><button type="button" data-detect="'+escapeHtml(c.componentKey)+'" '+(c.status==='confirmed'?'disabled':'')+'>Detect</button></div>').join('')||'<span class="sim-muted">Start a build to simulate detections.</span>';
  const unexpected=comps.filter(c=>c.status==='unexpected');
  $('exceptions').innerHTML=unexpected.map(c=>'<div class="exception-row"><span>'+escapeHtml(c.displayName)+'</span><button data-resolve="'+escapeHtml(c.componentKey)+'" type="button">Resolve</button></div>').join('')||'<span class="sim-muted">No build exceptions.</span>';
  $('handoffExpo').disabled=validation?.status!=='ready_for_finishing';
  $('startBuild').disabled=!!build||!item||(live&&(!state.device||!state.device.simulatorWritable));
  $('autoPlayBuild').disabled=live;
  $('nextDetection').disabled=live;
  $('lowConfidenceDetection').disabled=live;
  $('completeMockBuild').disabled=live;
}
function flashDetection(component,options={}){
  const layer=$('detectionLayer'),box=document.createElement('div'),i=(state.seq++%6),confidence=Number(options.confidence??component.confidence??.96);
  let visual=options.state||component.status||'confirmed';
  if(confidence<.75)visual='low-confidence';
  box.className='detection-box '+visual;
  box.style.left=(18+i*7)+'%';box.style.top=(44+(i%3)*6)+'%';box.style.width=(11+(i%3)*3)+'%';box.style.height=(10+(i%2)*5)+'%';
  box.innerHTML='<span>'+escapeHtml(component.displayName)+' · '+Math.round(confidence*100)+'%</span>';
  layer.appendChild(box);setTimeout(()=>box.remove(),2200);
}
function mockValidate(){const comps=state.build?.components||[],verify=comps.filter(c=>c.status==='verify').length,unexpected=comps.filter(c=>c.status==='unexpected').length,missing=comps.filter(c=>!c.optional&&!['confirmed','ignored','unexpected'].includes(c.status)).length,ready=missing===0&&verify===0&&unexpected===0;state.validation={status:ready?'ready_for_finishing':(verify||unexpected?'blocked':'pending'),nextStage:ready?'expo_finishing':null,summary:{allIngredientsAccountedFor:ready,requiredComponents:comps.filter(c=>!c.optional&&c.status!=='unexpected').length,passedComponents:comps.filter(c=>c.status==='confirmed').length,verifyComponents:verify,unexpectedComponents:unexpected,missingComponents:missing,next:{available:ready,label:'Expo / Finishing',message:ready?'All ingredients accounted for.':'Resolve build validation before finishing.'}}};}
async function refreshWork(){
  try{
    if(state.mode==='mock'){
      state.work=structuredClone(mock.work);state.selectedKdsItemPublicId=state.work.focusItem?.kdsItemPublicId||'';
      setSyncBadge('paused','SYNC OFF');log('MOCK','Loaded sample POS/KDS work.');render();return;
    }
    if(!state.device)throw new Error('Choose a device first.');
    await syncLiveStationWork(false);
    log('LIVE','Refreshed current KDS station work.');
  }catch(e){log('ERROR',e.message);}
}
async function startBuild(){try{if(state.mode==='mock'){state.build=mockBuild();state.validation=null;log('MOCK','Started mock build session.');render();return;}const item=selectedItem();if(!item)throw new Error('No selected KDS item.');const d=await api('build.start',{kdsItemPublicId:item.kdsItemPublicId,sourceRevision:state.work.revision});state.build=d.buildSession;state.validation=null;log('LIVE','Started build '+state.build.publicId+' against real KDS item.');render();}catch(e){log('ERROR',e.message);}}
async function detect(key){try{if(!state.build)throw new Error('Start a build first.');const c=state.build.components.find(x=>x.componentKey===key);if(!c)return;const qty=Math.max(.001,Number(c.expectedQuantity||1)-Number(c.detectedQuantity||0));flashDetection(c,{confidence:.96,state:'confirmed'});if(state.mode==='mock'){c.detectedQuantity=Number(c.detectedQuantity||0)+qty;c.confidence=.96;c.status=c.detectedQuantity+.0001>=Number(c.expectedQuantity||1)?'confirmed':'detected';state.build.summary.currentComponentKey=currentComponent()?.componentKey||null;state.validation=null;log('VISION','Detected '+c.displayName+' in mock mode.');render();return;}const obs='websim-'+state.build.publicId+'-'+Date.now()+'-'+state.seq;const d=await api('build.observe',{buildSessionPublicId:state.build.publicId,observationKey:obs,componentKey:c.componentKey,displayName:c.displayName,observationAction:'added',quantity:qty,confidence:.96,trackingId:'websim-'+state.seq,bbox:{x:.18,y:.46,width:.18,height:.16},metadata:{source:'web_glasses_simulator'}});state.build=d.buildSession;state.validation=null;log('LIVE','Submitted simulated detection for '+c.displayName+'.');render();}catch(e){log('ERROR',e.message);}}
function ensureMockBuild(){if(state.mode!=='mock')throw new Error('Demo controls are available only in Mock mode.');if(!state.build)state.build=mockBuild();}
async function playNextMockDetection(){
  try{ensureMockBuild();const c=currentComponent();if(!c){mockValidate();render();log('DEMO','All required ingredients are accounted for.');return;}
  const qty=Math.max(.001,Number(c.expectedQuantity||1)-Number(c.detectedQuantity||0));c.detectedQuantity=Number(c.detectedQuantity||0)+qty;c.confidence=.96;c.status='confirmed';flashDetection(c,{confidence:.96,state:'confirmed'});state.validation=null;render();log('DEMO','Detected '+c.displayName+'.');}
  catch(e){log('ERROR',e.message);}
}
function injectLowConfidenceDetection(){
  try{ensureMockBuild();const c=currentComponent();if(!c)throw new Error('No remaining component.');c.confidence=.61;c.status='verify';flashDetection(c,{confidence:.61,state:'low-confidence'});state.validation=null;render();log('DEMO','Low-confidence '+c.displayName+' requires verification.');}
  catch(e){log('ERROR',e.message);}
}
async function completeMockScenario(){
  try{ensureMockBuild();for(const c of state.build.components){if(c.status==='unexpected')continue;if(!c.optional){c.detectedQuantity=Number(c.expectedQuantity||1);c.confidence=.98;c.status='confirmed';}}
  mockValidate();render();log('DEMO','Completed mock build and validation.');}
  catch(e){log('ERROR',e.message);}
}
function stopAutoPlay(){if(state.autoPlayTimer){clearInterval(state.autoPlayTimer);state.autoPlayTimer=null;$('autoPlayBuild').textContent='Auto Play';log('DEMO','Auto Play stopped.');}}
function toggleAutoPlay(){
  if(state.mode!=='mock'){log('ERROR','Auto Play is available only in Mock mode.');return;}
  if(state.autoPlayTimer){stopAutoPlay();return;}
  ensureMockBuild();$('autoPlayBuild').textContent='Stop Auto';state.autoPlayTimer=setInterval(()=>{const c=currentComponent();if(!c){mockValidate();render();stopAutoPlay();return;}playNextMockDetection();},1100);log('DEMO','Auto Play started.');
}
async function injectUnexpected(){try{if(!state.build)throw new Error('Start a build first.');const key='websim:unexpected-'+Date.now();if(state.mode==='mock'){state.build.components.push({componentKey:key,displayName:'Unexpected Ingredient',expectedQuantity:0,detectedQuantity:1,unit:'',optional:false,status:'unexpected',confidence:.94});flashDetection({displayName:'Unexpected Ingredient',status:'unexpected',confidence:.94},{confidence:.94,state:'unexpected'});state.validation=null;log('VISION','Injected unexpected ingredient.');render();return;}const d=await api('build.observe',{buildSessionPublicId:state.build.publicId,observationKey:'websim-unexpected-'+Date.now(),componentKey:key,displayName:'Unexpected Ingredient',observationAction:'added',quantity:1,confidence:.94,trackingId:key,bbox:{x:.28,y:.52,width:.16,height:.14},metadata:{source:'web_glasses_simulator',unexpected:true}});state.build=d.buildSession;state.validation=null;log('LIVE','Submitted unexpected ingredient evidence.');render();}catch(e){log('ERROR',e.message);}}
async function resolveUnexpected(key){try{if(state.mode==='mock'){const c=state.build?.components.find(x=>x.componentKey===key);if(c)c.status='ignored';state.validation=null;log('MOCK','Resolved unexpected ingredient.');render();return;}const d=await api('build.resolve_unexpected',{buildSessionPublicId:state.build.publicId,componentKey:key});state.build=d.buildSession;state.validation=null;log('LIVE','Resolved unexpected ingredient.');render();}catch(e){log('ERROR',e.message);}}
async function validate(){try{if(!state.build)throw new Error('Start a build first.');if(state.mode==='mock'){mockValidate();log('VALIDATION','Mock result: '+state.validation.status);render();return;}const d=await api('validation.evaluate',{buildSessionPublicId:state.build.publicId});state.validation=d.validation;log('LIVE','Validation result: '+state.validation.status);render();}catch(e){log('ERROR',e.message);}}
async function handoff(){try{if(!state.validation||state.validation.status!=='ready_for_finishing')throw new Error('Validation is not ready for Expo.');if(state.mode==='mock'){log('MOCK','Simulated Expo handoff.');state.build=null;state.validation=null;render();return;}const d=await api('handoff.expo',{buildSessionPublicId:state.build.publicId});log('LIVE','Expo handoff completed; KDS status '+(d.handoff.kdsStatus||'ready')+'.');state.build=null;state.validation=null;await refreshWork();}catch(e){log('ERROR',e.message);}}
function resetSimulator(){state.build=null;state.validation=null;state.selectedKdsItemPublicId='';clearCameraTarget();$('detectionLayer').innerHTML='';log('SYSTEM','Simulator build state reset; POS/KDS records were not changed.');refreshWork();}
$('modeSelect').addEventListener('change',async e=>{stopAutoPlay();stopLiveStationSync('idle');state.mode=e.target.value;state.work=null;state.selectedKdsItemPublicId='';state.build=null;state.validation=null;state.syncFingerprint='';log('MODE','Switched to '+state.mode+'.');if(state.mode==='live')startLiveStationSync({immediate:true});else await refreshWork();});
$('deviceSelect').addEventListener('change',async e=>{stopLiveStationSync('idle');state.device=state.devices.find(x=>x.publicId===e.target.value)||null;state.work=null;state.selectedKdsItemPublicId='';state.build=null;state.validation=null;state.syncFingerprint='';if(state.mode==='live')startLiveStationSync({immediate:true});else render();});
$('workItemSelect').addEventListener('change',e=>{state.selectedKdsItemPublicId=e.target.value;state.validation=null;render();});
$('refreshWork').addEventListener('click',refreshWork);$('startBuild').addEventListener('click',startBuild);$('resetSimulator').addEventListener('click',resetSimulator);$('injectUnexpected').addEventListener('click',injectUnexpected);$('evaluateBuild').addEventListener('click',validate);$('autoPlayBuild').addEventListener('click',toggleAutoPlay);$('nextDetection').addEventListener('click',playNextMockDetection);$('lowConfidenceDetection').addEventListener('click',injectLowConfidenceDetection);$('completeMockBuild').addEventListener('click',completeMockScenario);$('handoffExpo').addEventListener('click',handoff);$('clearLog').addEventListener('click',()=>{state.logs=[];renderLog();});
$('sceneSourceSelect').addEventListener('change',async e=>{state.cameraSource=e.target.value;if(state.cameraSource==='camera'){await startCamera();}else{stopCamera();$('sceneImage').hidden=!$('sceneImage').src;$('scenePlaceholder').hidden=!!$('sceneImage').src;}});
$('startCamera').addEventListener('click',startCamera);$('stopCamera').addEventListener('click',()=>stopCamera());$('captureFrame').addEventListener('click',captureCameraFrame);$('clearCameraTarget').addEventListener('click',clearCameraTarget);$('submitCameraTarget').addEventListener('click',submitCameraTargetObservation);
$('cameraFit').addEventListener('change',applyCameraPresentation);$('cameraMirror').addEventListener('change',applyCameraPresentation);
$('cameraDeviceSelect').addEventListener('change',async()=>{saveCameraPrefs();if(state.cameraStream)await startCamera();});
$('cameraResolution').addEventListener('change',async()=>{saveCameraPrefs();if(state.cameraStream)await startCamera();});
$('cameraManualTarget').addEventListener('change',e=>{$('glassesStage').classList.toggle('camera-targeting',e.target.checked);if(!e.target.checked)clearCameraTarget();});
$('glassesStage').addEventListener('click',setCameraTargetFromPointer);
$('cameraTargetConfidence').addEventListener('input',e=>{$('cameraTargetConfidenceValue').textContent=e.target.value+'%';});
document.addEventListener('visibilitychange',()=>{if(document.hidden)stopLiveStationSync('hidden');else if(state.mode==='live'&&state.device)startLiveStationSync({immediate:true});});
window.addEventListener('offline',()=>{stopLiveStationSync('idle');setSyncBadge('error','OFFLINE');});
window.addEventListener('online',()=>{if(state.mode==='live'&&state.device)startLiveStationSync({immediate:true});});
window.addEventListener('beforeunload',()=>stopLiveStationSync('idle'));
const FRAME_MODE_KEY='gelato.webGlassesSimulator.frameMode.v1';
const OPTICAL_MASK_KEY='gelato.webGlassesSimulator.opticalMask.v1';
let frameMode=['svg','image','none'].includes(localStorage.getItem(FRAME_MODE_KEY))?localStorage.getItem(FRAME_MODE_KEY):'svg';
let opticalMaskEnabled=localStorage.getItem(OPTICAL_MASK_KEY)!=='0';
function applyFrameMode(){
  const stage=$('glassesStage');
  stage.classList.remove('frame-svg','frame-image','frame-none','mask-disabled');
  stage.classList.add('frame-'+frameMode);
  if(!opticalMaskEnabled||frameMode==='none')stage.classList.add('mask-disabled');
  $('frameModeSelect').value=frameMode;
  $('opticalMaskToggle').checked=opticalMaskEnabled;
  $('opticalMaskToggle').disabled=frameMode==='none';
}
function setFrameMode(mode){
  if(!['svg','image','none'].includes(mode))mode='svg';
  if(mode==='image'&&!$('glassesImage').src){log('ERROR','Load a glasses image before selecting Uploaded frame mode.');mode='svg';}
  frameMode=mode;localStorage.setItem(FRAME_MODE_KEY,frameMode);applyFrameMode();log('OPTICS','Frame mode: '+frameMode+'.');
}
$('frameModeSelect').addEventListener('change',e=>setFrameMode(e.target.value));
$('opticalMaskToggle').addEventListener('change',e=>{opticalMaskEnabled=!!e.target.checked;localStorage.setItem(OPTICAL_MASK_KEY,opticalMaskEnabled?'1':'0');applyFrameMode();log('OPTICS','Lens mask '+(opticalMaskEnabled?'enabled.':'disabled.'));});
function bindLocalImage(inputId,imageId,onLoaded){let currentUrl='';$(inputId).addEventListener('change',e=>{const file=e.target.files?.[0];if(!file)return;if(currentUrl)URL.revokeObjectURL(currentUrl);currentUrl=URL.createObjectURL(file);const image=$(imageId);image.src=currentUrl;image.hidden=false;if(onLoaded)onLoaded();log('ASSET','Previewing local '+file.name+'.');});}
bindLocalImage('sceneFile','sceneImage',()=>{$('scenePlaceholder').hidden=true;});
bindLocalImage('glassesFile','glassesImage',()=>{frameMode='image';localStorage.setItem(FRAME_MODE_KEY,frameMode);applyFrameMode();});
$('componentControls').addEventListener('click',e=>{const b=e.target.closest('[data-detect]');if(b)detect(b.dataset.detect);});

const CAL_KEY='gelato.webGlassesSimulator.calibration.v1';
const PRESET_KEY='gelato.webGlassesSimulator.presets.v1';
const calibrationDefaults={
  opacity:100,brightness:100,scale:100,safeWidth:80,safeHeight:66,
  leftEyeX:0,leftEyeY:0,rightEyeX:0,rightEyeY:0,
  regions:{hudRight:{x:71,y:16,w:27,h:67},hudStatus:{x:2,y:1,w:96,h:12},hudOrdersRegion:{x:1,y:17,w:23,h:61},hudNextRegion:{x:34,y:29,w:31,h:25}}
};
function deepClone(v){return JSON.parse(JSON.stringify(v));}
function clamp(n,min,max){return Math.max(min,Math.min(max,Number(n)));}
function normalizeCalibration(input){
  const next=deepClone(calibrationDefaults),src=input&&typeof input==='object'?input:{};
  for(const key of ['opacity','brightness','scale','safeWidth','safeHeight','leftEyeX','leftEyeY','rightEyeX','rightEyeY']){
    if(Number.isFinite(Number(src[key])))next[key]=Number(src[key]);
  }
  for(const name of Object.keys(next.regions)){
    const region=src.regions&&src.regions[name]?src.regions[name]:{};
    for(const key of ['x','y','w','h'])if(Number.isFinite(Number(region[key])))next.regions[name][key]=Number(region[key]);
  }
  next.opacity=clamp(next.opacity,20,100);next.brightness=clamp(next.brightness,50,180);next.scale=clamp(next.scale,60,140);
  next.safeWidth=clamp(next.safeWidth,45,95);next.safeHeight=clamp(next.safeHeight,35,90);
  for(const key of ['leftEyeX','leftEyeY','rightEyeX','rightEyeY'])next[key]=clamp(next[key],-20,20);
  for(const r of Object.values(next.regions)){r.x=clamp(r.x,0,92);r.y=clamp(r.y,0,92);r.w=clamp(r.w,8,100-r.x);r.h=clamp(r.h,8,100-r.y);}
  return next;
}
function loadStored(key,fallback){try{const raw=localStorage.getItem(key);return raw?JSON.parse(raw):deepClone(fallback);}catch{return deepClone(fallback);}}
let calibration=normalizeCalibration(loadStored(CAL_KEY,calibrationDefaults));
let calibrationEnabled=false;
let presets=loadStored(PRESET_KEY,{});if(!presets['Pizza Line Reference'])presets['Pizza Line Reference']=deepClone(calibrationDefaults);
function saveCalibration(){calibration=normalizeCalibration(calibration);localStorage.setItem(CAL_KEY,JSON.stringify(calibration));applyCalibration();}
function applyCalibration(){
  calibration=normalizeCalibration(calibration);
  const stage=$('glassesStage'),projection=$('lensProjection');
  projection.style.setProperty('--hud-opacity',String(calibration.opacity/100));
  projection.style.setProperty('--hud-brightness',String(calibration.brightness/100));
  projection.style.setProperty('--hud-scale',String(calibration.scale/100));
  stage.style.setProperty('--safe-width',calibration.safeWidth+'%');stage.style.setProperty('--safe-height',calibration.safeHeight+'%');
  stage.style.setProperty('--left-eye-x',calibration.leftEyeX+'%');stage.style.setProperty('--left-eye-y',calibration.leftEyeY+'%');
  stage.style.setProperty('--right-eye-x',calibration.rightEyeX+'%');stage.style.setProperty('--right-eye-y',calibration.rightEyeY+'%');
  for(const [name,r] of Object.entries(calibration.regions)){const el=$(name);if(!el)continue;el.style.setProperty('--region-x',r.x+'%');el.style.setProperty('--region-y',r.y+'%');el.style.setProperty('--region-w',r.w+'%');el.style.setProperty('--region-h',r.h+'%');}
  const controls={hudOpacity:['opacity','%'],hudBrightness:['brightness','%'],hudScale:['scale','%'],safeWidth:['safeWidth','%'],safeHeight:['safeHeight','%'],leftEyeX:['leftEyeX','%'],leftEyeY:['leftEyeY','%'],rightEyeX:['rightEyeX','%'],rightEyeY:['rightEyeY','%']};
  for(const [id,[key,suffix]] of Object.entries(controls)){if($(id)){$(id).value=String(calibration[key]);$(id+'Value').textContent=String(calibration[key])+suffix;}}
}
function setCalibrationEnabled(enabled){
  calibrationEnabled=!!enabled;$('glassesStage').classList.toggle('calibrating',calibrationEnabled);
  $('calibrationToggle').setAttribute('aria-pressed',calibrationEnabled?'true':'false');
  $('calibrationToggle').textContent=calibrationEnabled?'Lock layout':'Calibrate';
  $('calibrationState').textContent=calibrationEnabled?'EDITING':'LOCKED';
  log('CALIBRATION',calibrationEnabled?'Layout editing enabled.':'Layout locked.');
}
function refreshPresets(){
  const names=Object.keys(presets).sort((a,b)=>a.localeCompare(b));
  $('presetSelect').innerHTML='<option value="">Choose preset…</option>'+names.map(n=>'<option value="'+escapeHtml(n)+'">'+escapeHtml(n)+'</option>').join('');
}
function savePreset(){
  const name=$('presetName').value.trim().slice(0,48);if(!name){log('ERROR','Enter a preset name.');return;}
  presets[name]=deepClone(normalizeCalibration(calibration));localStorage.setItem(PRESET_KEY,JSON.stringify(presets));refreshPresets();$('presetSelect').value=name;log('CALIBRATION','Saved preset '+name+'.');
}
function loadPreset(name){if(!name||!presets[name])return;calibration=normalizeCalibration(presets[name]);saveCalibration();log('CALIBRATION','Loaded preset '+name+'.');}
function deletePreset(){
  const name=$('presetSelect').value;if(!name||!presets[name])return;delete presets[name];localStorage.setItem(PRESET_KEY,JSON.stringify(presets));refreshPresets();$('presetName').value='';log('CALIBRATION','Deleted preset '+name+'.');
}
function factoryCalibration(){calibration=deepClone(calibrationDefaults);saveCalibration();log('CALIBRATION','Restored factory projection geometry.');}
for(const [id,key] of Object.entries({hudOpacity:'opacity',hudBrightness:'brightness',hudScale:'scale',safeWidth:'safeWidth',safeHeight:'safeHeight',leftEyeX:'leftEyeX',leftEyeY:'leftEyeY',rightEyeX:'rightEyeX',rightEyeY:'rightEyeY'})){
  $(id).addEventListener('input',e=>{calibration[key]=Number(e.target.value);saveCalibration();});
}
$('calibrationToggle').addEventListener('click',()=>setCalibrationEnabled(!calibrationEnabled));
$('savePreset').addEventListener('click',savePreset);
$('deletePreset').addEventListener('click',deletePreset);
$('resetCalibration').addEventListener('click',factoryCalibration);
$('presetSelect').addEventListener('change',e=>loadPreset(e.target.value));

function bindRegionEditor(regionName){
  const el=$(regionName),stage=$('glassesStage'),move=el.querySelector('.region-move'),resize=el.querySelector('.region-resize');
  const begin=(event,kind)=>{
    if(!calibrationEnabled)return;
    event.preventDefault();event.stopPropagation();
    const pointer=event.touches?event.touches[0]:event;
    const stageRect=stage.getBoundingClientRect(),startX=pointer.clientX,startY=pointer.clientY,start=deepClone(calibration.regions[regionName]);
    const onMove=e=>{
      const p=e.touches?e.touches[0]:e,dx=(p.clientX-startX)/stageRect.width*100,dy=(p.clientY-startY)/stageRect.height*100,r=calibration.regions[regionName];
      if(kind==='move'){r.x=clamp(start.x+dx,0,100-start.w);r.y=clamp(start.y+dy,0,100-start.h);}
      else{r.w=clamp(start.w+dx,8,100-r.x);r.h=clamp(start.h+dy,8,100-r.y);}
      applyCalibration();
    };
    const end=()=>{window.removeEventListener('pointermove',onMove);window.removeEventListener('pointerup',end);window.removeEventListener('touchmove',onMove);window.removeEventListener('touchend',end);saveCalibration();log('CALIBRATION','Updated '+regionName+' region.');};
    window.addEventListener('pointermove',onMove);window.addEventListener('pointerup',end,{once:true});
    window.addEventListener('touchmove',onMove,{passive:false});window.addEventListener('touchend',end,{once:true});
  };
  move.addEventListener('pointerdown',e=>begin(e,'move'));move.addEventListener('touchstart',e=>begin(e,'move'),{passive:false});
  resize.addEventListener('pointerdown',e=>begin(e,'resize'));resize.addEventListener('touchstart',e=>begin(e,'resize'),{passive:false});
}
bindRegionEditor('hudRight');bindRegionEditor('hudStatus');bindRegionEditor('hudOrdersRegion');bindRegionEditor('hudNextRegion');
refreshPresets();applyCalibration();applyFrameMode();
$('exceptions').addEventListener('click',e=>{const b=e.target.closest('[data-resolve]');if(b)resolveUnexpected(b.dataset.resolve);});
(async()=>{await loadDevices();const cp=cameraPrefs();if(cp.resolution)$('cameraResolution').value=cp.resolution;if(cp.fit)$('cameraFit').value=cp.fit;$('cameraMirror').checked=!!cp.mirror;applyCameraPresentation();await enumerateCameras();if(!cameraSupported())setCameraHealth('error','UNSUPPORTED');state.work=structuredClone(mock.work);state.selectedKdsItemPublicId=state.work.focusItem?.kdsItemPublicId||'';refreshPresets();applyCalibration();setSyncBadge('paused','SYNC OFF');render();setInterval(renderTopStatus,30000);log('SYSTEM','Simulator ready. Pizza Line Reference HUD loaded.');})();
})();