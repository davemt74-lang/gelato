(function(){
'use strict';

var boot=window.GELATO_VISION_MODELS||{};
var catalog=boot.catalog||{};
var packages=Array.isArray(catalog.packages)?catalog.packages:[];
var rollouts=Array.isArray(catalog.rollouts)?catalog.rollouts:[];
var metrics=catalog.metricsByRollout||{};
var locations=Array.isArray(catalog.locations)?catalog.locations:[];
var stationsByLocation=catalog.stationsByLocation||{};

var el={
 detector:document.getElementById('gvmDetector'),
 modelName:document.getElementById('gvmModelName'),
 modelVersion:document.getElementById('gvmModelVersion'),
 runtime:document.getElementById('gvmRuntime'),
 platform:document.getElementById('gvmPlatform'),
 bytes:document.getElementById('gvmBytes'),
 url:document.getElementById('gvmUrl'),
 sha:document.getElementById('gvmSha'),
 minSdk:document.getElementById('gvmMinSdk'),
 minApp:document.getElementById('gvmMinApp'),
 packageNotes:document.getElementById('gvmPackageNotes'),
 browserInputName:document.getElementById('gvmBrowserInputName'),browserOutputName:document.getElementById('gvmBrowserOutputName'),browserWidth:document.getElementById('gvmBrowserWidth'),browserHeight:document.getElementById('gvmBrowserHeight'),browserInputLayout:document.getElementById('gvmBrowserInputLayout'),browserOutputLayout:document.getElementById('gvmBrowserOutputLayout'),browserBoxScale:document.getElementById('gvmBrowserBoxScale'),browserNms:document.getElementById('gvmBrowserNms'),browserMaxDetections:document.getElementById('gvmBrowserMaxDetections'),browserLabels:document.getElementById('gvmBrowserLabels'),preflightBrowserModel:document.getElementById('gvmPreflightBrowserModel'),browserPreflight:document.getElementById('gvmBrowserPreflight'),
 packageValidation:document.getElementById('gvmPackageValidation'),
 createPackage:document.getElementById('gvmCreatePackage'),
 target:document.getElementById('gvmTargetPackage'),
 baseline:document.getElementById('gvmBaselinePackage'),
 location:document.getElementById('gvmLocation'),
 station:document.getElementById('gvmStation'),
 canary:document.getElementById('gvmCanary'),
 rolloutNotes:document.getElementById('gvmRolloutNotes'),
 rolloutValidation:document.getElementById('gvmRolloutValidation'),
 createRollout:document.getElementById('gvmCreateRollout'),
 packageRows:document.getElementById('gvmPackageRows'),
 rolloutRows:document.getElementById('gvmRolloutRows'),
 packageSearch:document.getElementById('gvmPackageSearch'),
 refresh:document.getElementById('gvmRefresh'),
 toast:document.getElementById('gvmToast')
};

function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function toast(message,error){el.toast.textContent=String(message||'');el.toast.classList.toggle('error',!!error);el.toast.hidden=false;clearTimeout(toast.t);toast.t=setTimeout(function(){el.toast.hidden=true;},3400);}
function pct(v){return Number(v||0).toFixed(Number(v||0)%1===0?0:2)+'%';}
function shortSha(v){v=String(v||'');return v.length>16?v.slice(0,8)+'…'+v.slice(-8):v;}
function packageLabel(p){return (p.detectorName||'')+' · '+(p.modelName||'')+' '+(p.modelVersion||'');}
function readyPackages(){return packages.filter(function(p){return p.status==='ready';});}
var browserPreflight={ok:false,fingerprint:''},ORT_WEB_VERSION='1.30.0',ORT_WEB_BASE='https://cdn.jsdelivr.net/npm/onnxruntime-web@'+ORT_WEB_VERSION+'/dist/',ORT_WEB_MAX_BYTES=512*1024*1024,ortPromise=null;
function browserLabels(){return String(el.browserLabels.value||'').split(/\r?\n/).map(function(x){return x.trim();}).filter(Boolean);}
function browserMetadata(){
 return {browserInference:{schema:'gelato.browser_onnx_detector.v1',decoder:'yolo_v8',input:{name:String(el.browserInputName.value||'').trim(),width:Number(el.browserWidth.value),height:Number(el.browserHeight.value),layout:el.browserInputLayout.value},output:{name:String(el.browserOutputName.value||'').trim(),layout:el.browserOutputLayout.value,boxScale:el.browserBoxScale.value},labels:browserLabels(),nmsIou:Number(el.browserNms.value),maxDetections:Number(el.browserMaxDetections.value)}};
}
function browserFingerprint(){
 return JSON.stringify({url:String(el.url.value||'').trim(),sha:String(el.sha.value||'').trim().toLowerCase(),bytes:String(el.bytes.value||'').trim(),metadata:browserMetadata()});
}
function resetBrowserPreflight(){
 browserPreflight={ok:false,fingerprint:''};
 if(el.browserPreflight){el.browserPreflight.className='gvm-preflight';el.browserPreflight.innerHTML='<strong>Not verified</strong><span>Runs in this browser only; no model bytes are stored by Gelato.</span>';}
}
function browserConfigErrors(){
 var errors=[],m=browserMetadata().browserInference;
 if(el.runtime.value!=='onnx')return errors;
 if(!m.input.name||!m.output.name)errors.push('Browser input and output names are required.');
 if(!Number.isInteger(m.input.width)||m.input.width<32||m.input.width>4096||!Number.isInteger(m.input.height)||m.input.height<32||m.input.height>4096)errors.push('Browser input dimensions must be whole numbers between 32 and 4096.');
 if(!m.labels.length)errors.push('Add at least one model label.');
 if(new Set(m.labels).size!==m.labels.length)errors.push('Model labels must be unique.');
 if(!Number.isFinite(m.nmsIou)||m.nmsIou<.05||m.nmsIou>.95)errors.push('NMS IoU must be between 0.05 and 0.95.');
 if(!Number.isInteger(m.maxDetections)||m.maxDetections<1||m.maxDetections>100)errors.push('Max detections must be 1–100.');
 return errors;
}
function hex(buffer){return Array.from(new Uint8Array(buffer)).map(function(b){return b.toString(16).padStart(2,'0');}).join('');}
async function ensureOrt(){
 if(window.ort)return window.ort;if(ortPromise)return ortPromise;
 ortPromise=new Promise(function(resolve,reject){var s=document.createElement('script');s.src=ORT_WEB_BASE+'ort.min.js';s.async=true;s.crossOrigin='anonymous';s.onload=function(){if(!window.ort){reject(new Error('ONNX Runtime Web loaded without ort.'));return;}window.ort.env.wasm.wasmPaths=ORT_WEB_BASE;resolve(window.ort);};s.onerror=function(){reject(new Error('ONNX Runtime Web could not be loaded.'));};document.head.appendChild(s);});
 return ortPromise;
}
async function preflightBrowserModel(){
 resetBrowserPreflight();
 var errors=browserConfigErrors();if(errors.length){el.browserPreflight.innerHTML='<strong>Needs attention</strong><span>'+esc(errors.join(' '))+'</span>';return;}
 if(!validatePackage())return;
 el.preflightBrowserModel.disabled=true;el.browserPreflight.className='gvm-preflight running';el.browserPreflight.innerHTML='<strong>Verifying</strong><span>Downloading artifact through browser CORS…</span>';
 try{
   var response=await fetch(String(el.url.value||'').trim(),{mode:'cors',credentials:'omit',cache:'no-store'});
   if(!response.ok)throw new Error('Artifact download returned HTTP '+response.status+'.');
   var declared=Number(response.headers.get('content-length')||0);if(declared>ORT_WEB_MAX_BYTES)throw new Error('Artifact exceeds 512 MiB browser ceiling.');
   var bytes=await response.arrayBuffer();if(bytes.byteLength>ORT_WEB_MAX_BYTES)throw new Error('Artifact exceeds 512 MiB browser ceiling.');
   if(String(el.bytes.value||'').trim()!==''&&Number(el.bytes.value)!==bytes.byteLength)throw new Error('Downloaded byte count does not match Artifact bytes.');
   var digest=hex(await crypto.subtle.digest('SHA-256',bytes));if(digest!==String(el.sha.value||'').trim().toLowerCase())throw new Error('Downloaded SHA-256 does not match the package form.');
   el.browserPreflight.innerHTML='<strong>Hash verified</strong><span>Opening ONNX model and checking declared inputs/outputs…</span>';
   var ort=await ensureOrt(),session=await ort.InferenceSession.create(bytes,{executionProviders:['wasm']});
   try{
     var m=browserMetadata().browserInference;
     if((session.inputNames||[]).indexOf(m.input.name)<0)throw new Error('Declared input "'+m.input.name+'" was not found. Available: '+(session.inputNames||[]).join(', '));
     if((session.outputNames||[]).indexOf(m.output.name)<0)throw new Error('Declared output "'+m.output.name+'" was not found. Available: '+(session.outputNames||[]).join(', '));
   }finally{if(session&&typeof session.release==='function')await session.release();}
   browserPreflight={ok:true,fingerprint:browserFingerprint()};el.browserPreflight.className='gvm-preflight ready';el.browserPreflight.innerHTML='<strong>Browser-ready</strong><span>CORS · '+bytes.byteLength.toLocaleString()+' bytes · SHA-256 · ONNX session · declared I/O all verified.</span>';
   toast('Browser ONNX artifact preflight passed.');
 }catch(e){browserPreflight={ok:false,fingerprint:''};el.browserPreflight.className='gvm-preflight error';el.browserPreflight.innerHTML='<strong>Preflight failed</strong><span>'+esc(e.message)+'</span>';toast(e.message,true);}
 finally{el.preflightBrowserModel.disabled=false;validatePackage();}
}


async function request(payload){
 var response=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':String(boot.csrfToken||'')},body:JSON.stringify(payload)});
 var data=await response.json();
 if(!response.ok||!data.ok)throw new Error(data.message||'Vision model request failed.');
 return data;
}

async function reload(){
 el.refresh.disabled=true;
 try{
   var response=await fetch(boot.apiUrl,{credentials:'same-origin',headers:{'Accept':'application/json'}});
   var data=await response.json();
   if(!response.ok||!data.ok)throw new Error(data.message||'Could not refresh model rollout telemetry.');
   catalog=data.catalog||{};
   packages=Array.isArray(catalog.packages)?catalog.packages:[];
   rollouts=Array.isArray(catalog.rollouts)?catalog.rollouts:[];
   metrics=catalog.metricsByRollout||{};
   locations=Array.isArray(catalog.locations)?catalog.locations:[];
   stationsByLocation=catalog.stationsByLocation||{};
   renderAll();
 }catch(e){toast(e.message,true);}
 finally{el.refresh.disabled=false;}
}

function validatePackage(){
 var errors=[];
 var detector=String(el.detector.value||'').trim();
 var name=String(el.modelName.value||'').trim();
 var version=String(el.modelVersion.value||'').trim();
 var url=String(el.url.value||'').trim();
 var sha=String(el.sha.value||'').trim().toLowerCase();
 var bytes=String(el.bytes.value||'').trim();
 if(!detector||detector==='*')errors.push('Use a specific detector name.');
 if(!name)errors.push('Model name is required.');
 if(!/^[A-Za-z0-9][A-Za-z0-9._+-]{0,79}$/.test(version))errors.push('Model version is invalid.');
 if(!/^https:\/\//i.test(url))errors.push('Artifact URL must use HTTPS.');
 if(!/^[a-f0-9]{64}$/.test(sha))errors.push('Artifact SHA-256 must contain exactly 64 hexadecimal characters.');
 if(bytes!==''&&(!Number.isFinite(Number(bytes))||Number(bytes)<1))errors.push('Artifact bytes must be a positive integer.');
 errors=errors.concat(browserConfigErrors());
 if(el.runtime.value==='onnx'&&browserPreflight.fingerprint&&browserPreflight.fingerprint!==browserFingerprint())resetBrowserPreflight();
 el.packageValidation.innerHTML=errors.length
   ?'<strong>Needs attention</strong><ul>'+errors.map(function(x){return '<li>'+esc(x)+'</li>';}).join('')+'</ul>'
   :'<strong>Ready to register</strong><span>Package identity is immutable after registration.</span>';
 el.packageValidation.classList.toggle('ready',errors.length===0);
 el.createPackage.disabled=!catalog.canManage||!catalog.ready||errors.length>0;
 return errors.length===0;
}

function validateRollout(){
 var errors=[];
 var target=packages.find(function(p){return p.publicId===el.target.value;});
 var baseline=packages.find(function(p){return p.publicId===el.baseline.value;});
 var canary=Number(el.canary.value);
 if(!target)errors.push('Choose a target package.');
 if(!baseline)errors.push('Choose a baseline package.');
 if(target&&baseline){
   if(target.publicId===baseline.publicId)errors.push('Target and baseline must be different packages.');
   if(target.detectorName!==baseline.detectorName||target.platform!==baseline.platform||target.runtimeType!==baseline.runtimeType)
     errors.push('Target and baseline must use the same detector, platform, and runtime.');
 }
 if(!Number.isFinite(canary)||canary<0||canary>100)errors.push('Canary percentage must be between 0 and 100.');
 if(el.station.value&&!el.location.value)errors.push('Station scope requires a location.');
 el.rolloutValidation.innerHTML=errors.length
   ?'<strong>Needs attention</strong><ul>'+errors.map(function(x){return '<li>'+esc(x)+'</li>';}).join('')+'</ul>'
   :'<strong>Ready to create draft</strong><span>Activation remains a separate explicit action.</span>';
 el.rolloutValidation.classList.toggle('ready',errors.length===0);
 el.createRollout.disabled=!catalog.canManage||!catalog.ready||errors.length>0;
 return errors.length===0;
}

function populatePackageSelects(){
 var currentTarget=el.target.value,currentBaseline=el.baseline.value;
 var opts='<option value="">Choose package</option>';
 readyPackages().forEach(function(p){opts+='<option value="'+esc(p.publicId)+'">'+esc(packageLabel(p))+'</option>';});
 el.target.innerHTML=opts;el.baseline.innerHTML=opts;
 if(packages.some(function(p){return p.publicId===currentTarget&&p.status==='ready';}))el.target.value=currentTarget;
 if(packages.some(function(p){return p.publicId===currentBaseline&&p.status==='ready';}))el.baseline.value=currentBaseline;
}

function populateLocations(){
 var current=el.location.value;
 var html='<option value="">Organization-wide</option>';
 locations.forEach(function(l){html+='<option value="'+Number(l.id)+'">'+esc(l.name)+'</option>';});
 el.location.innerHTML=html;
 if(locations.some(function(l){return String(l.id)===current;}))el.location.value=current;
 populateStations();
}
function populateStations(){
 var current=el.station.value;
 var list=stationsByLocation[String(el.location.value||'')]||[];
 var html='<option value="">All stations in scope</option>';
 list.forEach(function(s){html+='<option value="'+esc(s.publicId)+'">'+esc(s.name)+'</option>';});
 el.station.innerHTML=html;
 if(list.some(function(s){return s.publicId===current;}))el.station.value=current;
}

function renderPackages(){
 var q=String(el.packageSearch.value||'').trim().toLowerCase();
 var rows=packages.filter(function(p){return !q||[p.detectorName,p.modelName,p.modelVersion,p.runtimeType,p.platform,p.status].join(' ').toLowerCase().includes(q);});
 if(!rows.length){el.packageRows.innerHTML='<tr><td colspan="6" class="gvm-empty">No model packages found.</td></tr>';return;}
 el.packageRows.innerHTML=rows.map(function(p){
   var floor=[];
   if(p.minimumSdkVersion)floor.push('SDK ≥ '+esc(p.minimumSdkVersion));
   if(p.minimumAppVersion)floor.push('App ≥ '+esc(p.minimumAppVersion));
   return '<tr>'+
    '<td><strong>'+esc(p.modelName)+' '+esc(p.modelVersion)+'</strong><small>'+esc(p.detectorName)+' · '+esc(p.platform)+'</small>'+(p.metadata&&p.metadata.browserInference&&p.metadata.browserInference.schema==='gelato.browser_onnx_detector.v1'?'<span class="gvm-browser-pill">Browser ONNX</span>':'')+'</td>'+
    '<td>'+esc(p.runtimeType)+'</td>'+
    '<td>'+(floor.length?floor.join('<br>'):'None')+'</td>'+
    '<td><code title="'+esc(p.artifactSha256)+'">'+esc(shortSha(p.artifactSha256))+'</code></td>'+
    '<td><span class="gvm-pill '+esc(p.status)+'">'+esc(p.status)+'</span></td>'+
    '<td>'+(catalog.canManage&&p.status==='ready'?'<button type="button" class="admin-button quiet" data-retire="'+esc(p.publicId)+'">Retire</button>':'')+'</td>'+
   '</tr>';
 }).join('');
}

function scopeLabel(r){
 if(r.stationName)return (r.locationName||'Location')+' · '+r.stationName;
 if(r.locationName)return r.locationName+' · all stations';
 return 'Organization-wide';
}
function reportsLabel(r){
 var m=metrics[r.publicId]&&metrics[r.publicId].byType?metrics[r.publicId].byType:{};
 var activated=m.activated?m.activated.devices:0;
 var failed=m.failed?m.failed.devices:0;
 var seen=m.assignment_seen?m.assignment_seen.devices:0;
 return '<span>'+Number(seen)+' seen</span><span>'+Number(activated)+' activated</span><span'+(failed?' class="danger"':'')+'>'+Number(failed)+' failed</span>';
}
function rolloutControls(r){
 if(!catalog.canManage)return '';
 var html='<div class="gvm-actions">';
 if(r.status==='draft')html+='<button type="button" class="admin-button quiet" data-rollout-action="activate" data-id="'+esc(r.publicId)+'">Activate</button>';
 if(r.status==='active'){
   if(Number(r.canaryPercent)<100)html+='<button type="button" class="admin-button quiet" data-rollout-action="advance" data-id="'+esc(r.publicId)+'">Advance</button>';
   html+='<button type="button" class="admin-button quiet" data-rollout-action="pause" data-id="'+esc(r.publicId)+'">Pause</button>';
   html+='<button type="button" class="admin-button danger" data-rollout-action="rollback" data-id="'+esc(r.publicId)+'">Rollback</button>';
 }
 if(r.status==='paused'){
   html+='<button type="button" class="admin-button quiet" data-rollout-action="activate" data-id="'+esc(r.publicId)+'">Resume</button>';
   html+='<button type="button" class="admin-button danger" data-rollout-action="rollback" data-id="'+esc(r.publicId)+'">Rollback</button>';
 }
 html+='</div>';
 return html;
}
function renderRollouts(){
 if(!rollouts.length){el.rolloutRows.innerHTML='<tr><td colspan="7" class="gvm-empty">No rollout plans yet.</td></tr>';return;}
 el.rolloutRows.innerHTML=rollouts.map(function(r){
   return '<tr>'+
    '<td><strong>'+esc(scopeLabel(r))+'</strong><small>'+esc(r.detectorName)+'</small></td>'+
    '<td>'+esc(r.targetLabel)+'</td>'+
    '<td>'+esc(r.baselineLabel||'—')+'</td>'+
    '<td><strong>'+esc(pct(r.canaryPercent))+'</strong></td>'+
    '<td><span class="gvm-pill '+esc(r.status)+'">'+esc(r.status)+'</span></td>'+
    '<td><div class="gvm-reports">'+reportsLabel(r)+'</div></td>'+
    '<td>'+rolloutControls(r)+'</td>'+
   '</tr>';
 }).join('');
}

function renderAll(){populatePackageSelects();populateLocations();renderPackages();renderRollouts();validatePackage();validateRollout();}

async function createPackage(){
 if(!validatePackage())return;
 el.createPackage.disabled=true;
 try{
   var data=await request({
     action:'package.create',csrf_token:String(boot.csrfToken||''),
     detectorName:el.detector.value,modelName:el.modelName.value,modelVersion:el.modelVersion.value,
     runtimeType:el.runtime.value,platform:el.platform.value,artifactUrl:el.url.value,
     artifactSha256:el.sha.value,artifactBytes:el.bytes.value===''?null:Number(el.bytes.value),
     minimumSdkVersion:el.minSdk.value,minimumAppVersion:el.minApp.value,notes:el.packageNotes.value,metadata:el.runtime.value==='onnx'?browserMetadata():{}
   });
   packages.unshift(data.package);
   el.modelName.value='';el.modelVersion.value='';el.url.value='';el.sha.value='';el.bytes.value='';el.minSdk.value='';el.minApp.value='';el.packageNotes.value='';el.browserLabels.value='';resetBrowserPreflight();
   renderAll();toast('Immutable model package registered.');
 }catch(e){toast(e.message,true);}
 finally{validatePackage();}
}
async function createRollout(){
 if(!validateRollout())return;
 el.createRollout.disabled=true;
 try{
   var data=await request({
     action:'rollout.create',csrf_token:String(boot.csrfToken||''),
     targetPackagePublicId:el.target.value,baselinePackagePublicId:el.baseline.value,
     locationId:el.location.value===''?null:Number(el.location.value),stationPublicId:el.station.value,
     canaryPercent:Number(el.canary.value),notes:el.rolloutNotes.value
   });
   rollouts.unshift(data.rollout);metrics[data.rollout.publicId]={byType:{}};
   el.rolloutNotes.value='';renderAll();toast('Draft rollout created. Activate it explicitly when ready.');
 }catch(e){toast(e.message,true);}
 finally{validateRollout();}
}
async function retire(id){
 if(!window.confirm('Retire this package? It cannot be used by a new active rollout.'))return;
 try{
   var data=await request({action:'package.retire',csrf_token:String(boot.csrfToken||''),publicId:id});
   var i=packages.findIndex(function(p){return p.publicId===id;});if(i>=0)packages[i]=data.package;
   renderAll();toast('Model package retired.');
 }catch(e){toast(e.message,true);}
}
async function rolloutAction(action,id){
 var payload={action:'rollout.'+action,csrf_token:String(boot.csrfToken||''),publicId:id};
 if(action==='advance'){
   var current=rollouts.find(function(r){return r.publicId===id;});
   var next=window.prompt('Advance canary percentage. Current: '+pct(current?current.canaryPercent:0),current?String(Math.min(100,Number(current.canaryPercent)+10)):'20');
   if(next===null)return;
   payload.canaryPercent=Number(next);
 }
 if(action==='rollback'){
   var reason=window.prompt('Rollback reason (recorded in the audit event):','');
   if(reason===null)return;
   payload.reason=reason;
   if(!window.confirm('Roll back this rollout to its declared baseline package?'))return;
 }
 try{
   var data=await request(payload);
   var i=rollouts.findIndex(function(r){return r.publicId===id;});if(i>=0)rollouts[i]=data.rollout;
   renderAll();toast('Rollout '+action+' complete.');
 }catch(e){toast(e.message,true);}
}

[el.detector,el.modelName,el.modelVersion,el.runtime,el.platform,el.bytes,el.url,el.sha,el.minSdk,el.minApp,el.packageNotes,el.browserInputName,el.browserOutputName,el.browserWidth,el.browserHeight,el.browserInputLayout,el.browserOutputLayout,el.browserBoxScale,el.browserNms,el.browserMaxDetections,el.browserLabels].forEach(function(x){x.addEventListener('input',function(){resetBrowserPreflight();validatePackage();});x.addEventListener('change',function(){resetBrowserPreflight();validatePackage();});});
el.preflightBrowserModel.addEventListener('click',preflightBrowserModel);
[el.target,el.baseline,el.location,el.station,el.canary,el.rolloutNotes].forEach(function(x){x.addEventListener('input',validateRollout);x.addEventListener('change',validateRollout);});
el.location.addEventListener('change',function(){populateStations();validateRollout();});
el.createPackage.addEventListener('click',createPackage);
el.createRollout.addEventListener('click',createRollout);
el.packageSearch.addEventListener('input',renderPackages);
el.refresh.addEventListener('click',reload);
el.packageRows.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-retire]');if(b)retire(b.getAttribute('data-retire'));});
el.rolloutRows.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-rollout-action]');if(b)rolloutAction(b.getAttribute('data-rollout-action'),b.getAttribute('data-id'));});

renderAll();
})();