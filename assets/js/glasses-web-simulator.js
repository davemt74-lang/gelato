(()=>{'use strict';
const cfg=window.GELATO_GLASSES_SIMULATOR||{};
const $=id=>document.getElementById(id);
const state={mode:'mock',devices:[],device:null,work:null,selectedKdsItemPublicId:'',build:null,validation:null,seq:1,logs:[]};
const mock={
  work:{assignmentRequired:false,station:{publicId:'station-mock',name:'Sandwich / Pizza Line'},revision:'mock-revision',focusItem:{kdsItemPublicId:'kds-mock-1',status:'queued',ticket:{checkNumber:'1042',serviceMode:'dine_in',tableName:'Table 12',guestCount:2},posLine:{id:1,menuItemId:1,name:'Club Sandwich + Fries',optionName:'Regular',quantity:1,specialInstructions:'NO TOMATO · EXTRA BACON',modifiers:[{name:'Extra Bacon'}]},menu:{preparationNotes:'Build, slice and plate with fries.'},recipeSource:{status:'exact_name',recipe:{instructions:['Toast bread','Add mayo','Add turkey','Add bacon','Add lettuce','Add tomato','Top and slice','Plate with fries']}}},items:[],metrics:{queued:1,inProgress:0,ready:0,held:0}},
  components:['Toasted Bread','Mayo','Turkey','Bacon','Lettuce','Tomato','Fries'].map((name,i)=>({componentKey:'mock:'+i,displayName:name,expectedQuantity:i===0?3:1,detectedQuantity:0,unit:i===0?'slices':'portion',optional:false,status:'waiting',sortOrder:i+1})),
};
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function log(type,msg){const line={at:new Date(),type,msg};state.logs.unshift(line);state.logs=state.logs.slice(0,80);renderLog();}
function renderLog(){$('eventLog').innerHTML=state.logs.map(x=>'<div class="event-line"><b>'+escapeHtml(x.type)+'</b> '+escapeHtml(x.at.toLocaleTimeString())+' · '+escapeHtml(x.msg)+'</div>').join('')||'<span class="sim-muted">No events yet.</span>';}
async function api(action,payload={}){const r=await fetch(cfg.api,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf:cfg.csrf,action,devicePublicId:state.device?.publicId||'',...payload})});const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));if(!r.ok||!d.ok)throw new Error(d.message||'Simulator request failed.');return d;}
async function loadDevices(){try{const r=await fetch(cfg.api,{credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Device list failed.');state.devices=d.devices||[];$('deviceSelect').innerHTML='<option value="">Choose device…</option>'+state.devices.map(x=>'<option value="'+escapeHtml(x.publicId)+'">'+escapeHtml(x.displayName)+' · '+escapeHtml(x.stationName||'Unassigned')+'</option>').join('');if(state.devices[0]){$('deviceSelect').value=state.devices[0].publicId;state.device=state.devices[0];}log('SYSTEM','Loaded '+state.devices.length+' accessible glasses device(s).');}catch(e){$('deviceSelect').innerHTML='<option value="">No devices</option>';log('ERROR',e.message);}}
function mockBuild(){return {publicId:'build-mock-1',status:'active',kdsItemPublicId:'kds-mock-1',kdsStatus:'queued',context:{itemName:'Club Sandwich + Fries',buildDefinition:{steps:mock.work.focusItem.recipeSource.recipe.instructions.map((text,i)=>({stepKey:'step-'+(i+1),order:i+1,text,componentKeys:[]}))}},summary:{required:mock.components.length,confirmed:0,verify:0,unexpected:0,accounted:false,currentComponentKey:mock.components[0].componentKey},components:structuredClone(mock.components)};}
function selectedItem(){const items=state.work?.items||[];if(state.selectedKdsItemPublicId){const chosen=items.find(x=>x.kdsItemPublicId===state.selectedKdsItemPublicId);if(chosen)return chosen;}return state.work?.focusItem||items[0]||null;}
function deriveSteps(){const item=selectedItem();const fromBuild=state.build?.context?.buildDefinition?.steps;if(Array.isArray(fromBuild)&&fromBuild.length)return fromBuild.map(x=>typeof x==='string'?x:(x.text||x.label||''));const rx=item?.recipeSource?.recipe?.instructions;return Array.isArray(rx)&&rx.length?rx:['Load work','Build item','Validate','Send to Expo'];}
function itemName(){return selectedItem()?.posLine?.name||state.build?.context?.itemName||'No focused item';}
function currentComponent(){return state.build?.components?.find(c=>!c.optional&&!['confirmed','ignored'].includes(c.status))||null;}
function render(){
  const live=state.mode==='live', item=selectedItem(), build=state.build, validation=state.validation;
  $('connectionBadge').textContent=live?'LIVE GELATO':'MOCK';
  $('connectionBadge').style.color=live?'var(--good)':'var(--blue)';
  $('hudConnection').textContent=live?'LIVE':'SIM';
  const station=state.work?.station?.name||state.device?.stationName||'Unassigned station';
  $('stationBadge').textContent=station;$('hudStation').textContent=station.toUpperCase();
  $('buildBadge').textContent=build?'Build '+build.publicId:'No active build';
  $('hudItem').textContent=itemName();$('consoleItem').textContent=itemName();
  $('hudTicket').textContent=item?.ticket?('#'+(item.ticket.checkNumber||'')+' · '+(item.ticket.tableName||item.ticket.serviceMode||'')):'No live ticket';
  $('workStatus').textContent=item?.status||'idle';
  const workItems=state.work?.items?.length?state.work.items:(state.work?.focusItem?[state.work.focusItem]:[]);
  $('workItemSelect').innerHTML=workItems.length?workItems.map(x=>'<option value="'+escapeHtml(x.kdsItemPublicId)+'" '+(x.kdsItemPublicId===item?.kdsItemPublicId?'selected':'')+'>'+escapeHtml(x.posLine?.name||'Kitchen item')+' · '+escapeHtml(x.status||'')+' · #'+escapeHtml(x.ticket?.checkNumber||'')+'</option>').join(''):'<option value="">No station work</option>';
  $('workItemSelect').disabled=!!build||workItems.length===0;
  $('workMeta').innerHTML=[
    ['Ticket',item?.ticket?.checkNumber||'—'],['Table',item?.ticket?.tableName||'—'],
    ['Guests',item?.ticket?.guestCount||'—'],['Special',item?.posLine?.specialInstructions||'None']
  ].map(([a,b])=>'<div><small>'+escapeHtml(a)+'</small><strong>'+escapeHtml(b)+'</strong></div>').join('');
  const steps=deriveSteps(), comp=currentComponent();
  $('hudSteps').innerHTML=steps.slice(0,8).map((s,i)=>'<li class="'+(i===0&&!build?'current':'')+'">'+escapeHtml(s)+'</li>').join('');
  $('hudNext').textContent=comp?'Add '+comp.displayName:(validation?.nextStage==='expo_finishing'?'Expo / Finishing':'Validate build');
  const comps=build?.components||[];
  const confirmed=comps.filter(c=>c.status==='confirmed'||c.status==='ignored'&&c.optional).length;
  const required=comps.filter(c=>!c.optional&&c.status!=='unexpected'&&c.status!=='ignored').length;
  $('hudValidation').textContent=build?(confirmed+' / '+required+' accounted'):'Waiting for build';
  $('hudValidationDetail').textContent=validation?.summary?.next?.message||validation?.status||'Build in progress';
  $('validationCard').className='hud-card validation-card '+(validation?.status==='ready_for_finishing'?'ready':validation?.status==='blocked'?'blocked':'');
  $('componentCount').textContent=String(comps.length);
  $('componentControls').innerHTML=comps.filter(c=>c.status!=='unexpected'&&c.status!=='ignored').map(c=>'<div class="component-row '+escapeHtml(c.status)+'"><div class="copy"><strong>'+escapeHtml(c.displayName)+'</strong><small>'+escapeHtml(c.status)+' · '+Number(c.detectedQuantity||0)+' / '+Number(c.expectedQuantity||0)+' '+escapeHtml(c.unit||'')+'</small></div><button type="button" data-detect="'+escapeHtml(c.componentKey)+'" '+(c.status==='confirmed'?'disabled':'')+'>Detect</button></div>').join('')||'<span class="sim-muted">Start a build to simulate detections.</span>';
  const unexpected=comps.filter(c=>c.status==='unexpected');
  $('exceptions').innerHTML=unexpected.map(c=>'<div class="exception-row"><span>'+escapeHtml(c.displayName)+'</span><button data-resolve="'+escapeHtml(c.componentKey)+'" type="button">Resolve</button></div>').join('')||'<span class="sim-muted">No build exceptions.</span>';
  $('handoffExpo').disabled=validation?.status!=='ready_for_finishing';
  $('startBuild').disabled=!!build||!item||(live&&(!state.device||!state.device.simulatorWritable));
}
function flashDetection(component){const layer=$('detectionLayer'),box=document.createElement('div');box.className='detection-box';const i=(state.seq++%5);box.style.left=(10+i*8)+'%';box.style.top=(42+(i%2)*10)+'%';box.style.width=(15+(i%3)*3)+'%';box.style.height=(14+(i%2)*4)+'%';box.innerHTML='<span>'+escapeHtml(component.displayName)+' · 96%</span>';layer.appendChild(box);setTimeout(()=>box.remove(),1600);}
function mockValidate(){const comps=state.build?.components||[],verify=comps.filter(c=>c.status==='verify').length,unexpected=comps.filter(c=>c.status==='unexpected').length,missing=comps.filter(c=>!c.optional&&!['confirmed','ignored','unexpected'].includes(c.status)).length,ready=missing===0&&verify===0&&unexpected===0;state.validation={status:ready?'ready_for_finishing':(verify||unexpected?'blocked':'pending'),nextStage:ready?'expo_finishing':null,summary:{allIngredientsAccountedFor:ready,requiredComponents:comps.filter(c=>!c.optional&&c.status!=='unexpected').length,passedComponents:comps.filter(c=>c.status==='confirmed').length,verifyComponents:verify,unexpectedComponents:unexpected,missingComponents:missing,next:{available:ready,label:'Expo / Finishing',message:ready?'All ingredients accounted for.':'Resolve build validation before finishing.'}}};}
async function refreshWork(){try{if(state.mode==='mock'){state.work=structuredClone(mock.work);state.selectedKdsItemPublicId=state.work.focusItem?.kdsItemPublicId||'';log('MOCK','Loaded sample POS/KDS work.');render();return;}if(!state.device)throw new Error('Choose a device first.');const d=await api('work');state.work=d.work;const ids=(state.work.items||[]).map(x=>x.kdsItemPublicId);if(!ids.includes(state.selectedKdsItemPublicId))state.selectedKdsItemPublicId=state.work.focusItem?.kdsItemPublicId||ids[0]||'';log('LIVE','Loaded current KDS station work from Gelato.');render();}catch(e){log('ERROR',e.message);}}
async function startBuild(){try{if(state.mode==='mock'){state.build=mockBuild();state.validation=null;log('MOCK','Started mock build session.');render();return;}const item=selectedItem();if(!item)throw new Error('No selected KDS item.');const d=await api('build.start',{kdsItemPublicId:item.kdsItemPublicId,sourceRevision:state.work.revision});state.build=d.buildSession;state.validation=null;log('LIVE','Started build '+state.build.publicId+' against real KDS item.');render();}catch(e){log('ERROR',e.message);}}
async function detect(key){try{if(!state.build)throw new Error('Start a build first.');const c=state.build.components.find(x=>x.componentKey===key);if(!c)return;const qty=Math.max(.001,Number(c.expectedQuantity||1)-Number(c.detectedQuantity||0));flashDetection(c);if(state.mode==='mock'){c.detectedQuantity=Number(c.detectedQuantity||0)+qty;c.confidence=.96;c.status=c.detectedQuantity+.0001>=Number(c.expectedQuantity||1)?'confirmed':'detected';state.build.summary.currentComponentKey=currentComponent()?.componentKey||null;state.validation=null;log('VISION','Detected '+c.displayName+' in mock mode.');render();return;}const obs='websim-'+state.build.publicId+'-'+Date.now()+'-'+state.seq;const d=await api('build.observe',{buildSessionPublicId:state.build.publicId,observationKey:obs,componentKey:c.componentKey,displayName:c.displayName,observationAction:'added',quantity:qty,confidence:.96,trackingId:'websim-'+state.seq,bbox:{x:.18,y:.46,width:.18,height:.16},metadata:{source:'web_glasses_simulator'}});state.build=d.buildSession;state.validation=null;log('LIVE','Submitted simulated detection for '+c.displayName+'.');render();}catch(e){log('ERROR',e.message);}}
async function injectUnexpected(){try{if(!state.build)throw new Error('Start a build first.');const key='websim:unexpected-'+Date.now();if(state.mode==='mock'){state.build.components.push({componentKey:key,displayName:'Unexpected Ingredient',expectedQuantity:0,detectedQuantity:1,unit:'',optional:false,status:'unexpected',confidence:.94});state.validation=null;log('VISION','Injected unexpected ingredient.');render();return;}const d=await api('build.observe',{buildSessionPublicId:state.build.publicId,observationKey:'websim-unexpected-'+Date.now(),componentKey:key,displayName:'Unexpected Ingredient',observationAction:'added',quantity:1,confidence:.94,trackingId:key,bbox:{x:.28,y:.52,width:.16,height:.14},metadata:{source:'web_glasses_simulator',unexpected:true}});state.build=d.buildSession;state.validation=null;log('LIVE','Submitted unexpected ingredient evidence.');render();}catch(e){log('ERROR',e.message);}}
async function resolveUnexpected(key){try{if(state.mode==='mock'){const c=state.build?.components.find(x=>x.componentKey===key);if(c)c.status='ignored';state.validation=null;log('MOCK','Resolved unexpected ingredient.');render();return;}const d=await api('build.resolve_unexpected',{buildSessionPublicId:state.build.publicId,componentKey:key});state.build=d.buildSession;state.validation=null;log('LIVE','Resolved unexpected ingredient.');render();}catch(e){log('ERROR',e.message);}}
async function validate(){try{if(!state.build)throw new Error('Start a build first.');if(state.mode==='mock'){mockValidate();log('VALIDATION','Mock result: '+state.validation.status);render();return;}const d=await api('validation.evaluate',{buildSessionPublicId:state.build.publicId});state.validation=d.validation;log('LIVE','Validation result: '+state.validation.status);render();}catch(e){log('ERROR',e.message);}}
async function handoff(){try{if(!state.validation||state.validation.status!=='ready_for_finishing')throw new Error('Validation is not ready for Expo.');if(state.mode==='mock'){log('MOCK','Simulated Expo handoff.');state.build=null;state.validation=null;render();return;}const d=await api('handoff.expo',{buildSessionPublicId:state.build.publicId});log('LIVE','Expo handoff completed; KDS status '+(d.handoff.kdsStatus||'ready')+'.');state.build=null;state.validation=null;await refreshWork();}catch(e){log('ERROR',e.message);}}
function resetSimulator(){state.build=null;state.validation=null;state.selectedKdsItemPublicId='';$('detectionLayer').innerHTML='';log('SYSTEM','Simulator build state reset; POS/KDS records were not changed.');refreshWork();}
$('modeSelect').addEventListener('change',async e=>{state.mode=e.target.value;state.work=null;state.selectedKdsItemPublicId='';state.build=null;state.validation=null;log('MODE','Switched to '+state.mode+'.');await refreshWork();});
$('deviceSelect').addEventListener('change',async e=>{state.device=state.devices.find(x=>x.publicId===e.target.value)||null;state.work=null;state.selectedKdsItemPublicId='';state.build=null;state.validation=null;if(state.mode==='live')await refreshWork();else render();});
$('workItemSelect').addEventListener('change',e=>{state.selectedKdsItemPublicId=e.target.value;state.validation=null;render();});
$('refreshWork').addEventListener('click',refreshWork);$('startBuild').addEventListener('click',startBuild);$('resetSimulator').addEventListener('click',resetSimulator);$('injectUnexpected').addEventListener('click',injectUnexpected);$('evaluateBuild').addEventListener('click',validate);$('handoffExpo').addEventListener('click',handoff);$('clearLog').addEventListener('click',()=>{state.logs=[];renderLog();});
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
  regions:{hudRight:{x:68,y:0,w:32,h:100},hudStatus:{x:0,y:0,w:26,h:12}}
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
let presets=loadStored(PRESET_KEY,{});
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
bindRegionEditor('hudRight');bindRegionEditor('hudStatus');
refreshPresets();applyCalibration();applyFrameMode();
$('exceptions').addEventListener('click',e=>{const b=e.target.closest('[data-resolve]');if(b)resolveUnexpected(b.dataset.resolve);});
(async()=>{await loadDevices();state.work=structuredClone(mock.work);state.selectedKdsItemPublicId=state.work.focusItem?.kdsItemPublicId||'';render();log('SYSTEM','Simulator ready. Mock mode is isolated from POS/KDS writes.');})();
})();