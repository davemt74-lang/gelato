(() => {
'use strict';
if(window.GelatoCameraRuntime)return;
const S={slots:new Map(),samples:new Map(),monitor:false,session:null,lastPoll:0};
const STAGES={2:'pre_oven',3:'post_oven'};
const sleep=[750,1500,3000];
const calibration=window.GelatoCameraCalibrationGate?.create?.();
const calibrationKeys=new Map();
const calibrationScope=()=>String(conf()?.locationId||0);
const now=()=>Date.now();
const conf=()=>window.GelatoCameraConfiguration;
const aux=()=>window.GelatoGlassesMultiCamera;
function row(slot) {
  if(!S.slots.has(slot))S.slots.set(slot,{slot,desired:false,retries:0,nextRetry:0,lastFrame:0,
    lastCapture:0,lastError:'',state:'off',startPending:false,fps:0,frameCount:0,lastFrameAt:0,
    activity:'unknown',quality:0,ready:false,activitySince:0,stability:0,detector:'not_configured',
    detectorConfidence:null,modelName:null});
  return S.slots.get(slot);
}
function status(slot){const v=row(slot);return {...v,active:!!conf()?.isStreaming?.(slot),enabled:!!conf()?.isEnabled?.(slot)};}
function stop(slot,reason='stopped'){
  const s=row(slot);s.desired=false;s.nextRetry=0;s.retries=0;s.state=reason;
  S.samples.delete(slot);calibration?.clear(slot);calibrationKeys.delete(slot);if(slot>1)aux()?.stop?.(slot,{reason:reason.toUpperCase()});
  else window.GelatoGlassesPrimaryCamera?.stop?.();
}
async function start(slot){
  const s=row(slot);
  if(document.hidden||!conf()?.isEnabled?.(slot)){
    if(!conf()?.isEnabled?.(slot)&&conf()?.isStreaming?.(slot))stop(slot,'disabled');
    s.state='disabled';return false;
  }
  if(slot===1){s.state=conf()?.isStreaming?.(1)?'ready':'manual_start_required';return s.state==='ready';}
  if(s.startPending)return false;
  s.desired=true;s.state='starting';s.startPending=true;
  try{
    await aux()?.start?.(slot);
    const ok=!!conf()?.isStreaming?.(slot);
    s.state=ok?'ready':'unavailable';
    if(ok){s.retries=0;s.nextRetry=0;s.lastError='';return true;}
    s.lastError='camera_unavailable';
  }catch(e){s.lastError=String(e?.name||'camera_failure');s.state='failed';}
  finally{s.startPending=false;}
  return false;
}
function trackStopped(slot,reason) {
  const s=row(slot);
  if(!s.desired||s.startPending||document.hidden||!conf()?.isEnabled?.(slot))return;
  if(/permission|not.allowed|security/i.test(reason)){s.desired=false;s.state='permission_blocked';return;}
  s.state='recovering';s.lastError=reason;
  if(s.retries>=3){s.desired=false;s.state='retry_exhausted';return;}
  s.nextRetry=now()+sleep[s.retries++];
}
function sample(slot){
  const s=row(slot),video=slot===1?document.getElementById('cameraVideo'):aux()?.video?.(slot);
  if(!conf()?.isEnabled?.(slot)||!video||!video.videoWidth||!video.videoHeight)return;
  const canvas=document.createElement('canvas');canvas.width=20;canvas.height=20;
  const ctx=canvas.getContext('2d',{willReadFrequently:true});if(!ctx)return;
  try{
    ctx.drawImage(video,0,0,20,20);
    const rgba=ctx.getImageData(0,0,20,20).data,luma=new Uint8Array(400);
    let sum=0,contrast=0;
    for(let i=0;i<400;i++){
      const off=i*4,v=(rgba[off]*.2126+rgba[off+1]*.7152+rgba[off+2]*.0722);
      luma[i]=v;sum+=v;contrast+=v*v;
    }
    const brightness=sum/400,variance=Math.max(0,contrast/400-brightness*brightness);
    const quality=Math.round(Math.max(0,Math.min(100,
      (1-Math.min(1,Math.abs(brightness-130)/130))*.4*100+Math.min(1,Math.sqrt(variance)/60)*60)));
    const previous=S.samples.get(slot);let diff=0;
    if(previous)for(let i=0;i<400;i++)diff+=Math.abs(luma[i]-previous[i]);
    diff=previous?diff/(400*255):0;
    S.samples.set(slot,luma);
    if(calibration){
      const track=video.srcObject?.getVideoTracks?.()?.[0], settings=track?.getSettings?.()||{};
      const sourceId=String(settings.deviceId||track?.id||video.currentSrc||'unknown:'+slot);
      const scope=calibrationScope(), identity=scope+'|'+sourceId+'|'+String(video.srcObject?.id||'');
      const prior=calibrationKeys.get(slot);
      if(!prior||prior.identity!==identity){
        calibrationKeys.set(slot,{identity,generation:(prior?.generation||0)+1});
        calibration.clear(slot);
      }
      const metric=window.GelatoCameraCalibrationGate.analyzeRgba(rgba,20,20);
      calibration.sample(slot,{...metric,at:now(),scope,sourceId,generation:calibrationKeys.get(slot).generation,drift:previous?diff:0});
    }
    s.quality=quality;s.frameCount++;s.lastFrame=now();
    s.fps=s.lastFrameAt?Math.min(60,Math.round(1000/(s.lastFrame-s.lastFrameAt))):0;
    s.lastFrameAt=s.lastFrame;
    if(slot===2||slot===3){
      if(diff>.075){s.activity='moving';s.activitySince=now();s.stability=0;s.ready=false;}
      else if(s.activitySince&&now()-s.activitySince>1800&&quality>=45){
        const wasReady=s.ready;
        s.stability++;s.activity='stable_scene_candidate';s.ready=s.stability>=2;
        if(s.ready&&!wasReady)window.dispatchEvent?.(new CustomEvent('gelato-camera-runtime-ready',{detail:{slot,quality}}));
      }else if(!s.activitySince){s.activity='awaiting_scene_change';s.ready=false;}
      else{s.activity='settling';s.ready=false;}
    }
    s.state='ready';
    // This is deliberately a scene-activity heuristic, not an ML pizza classification.
    s.detector='not_configured';s.detectorConfidence=null;
    return {diff,quality,ready:s.ready,activity:s.activity};
  }catch(e){s.lastError=String(e?.name||'frame_unavailable');s.state='frame_error';return null;}
}
function consumeCapture(slot){
  const s=row(slot);
  if(!s.ready||s.quality<45||(s.lastCapture>0&&now()-s.lastCapture<12000))return false;
  s.ready=false;s.lastCapture=now();s.activitySince=0;s.stability=0;
  return true;
}
async function poll(){
  if(document.hidden)return;
  const location=conf()?.locationId;
  if(!location)return;
  for(let slot=1;slot<=5;slot++){
    const s=row(slot);
    if(!conf()?.isEnabled?.(slot)){if(s.desired||conf()?.isStreaming?.(slot))stop(slot,'disabled');continue;}
    const active=!!conf()?.isStreaming?.(slot);
    if(active){
      if(s.state!=='ready')s.state='ready';
      sample(slot);
    }else if(s.desired&&slot>1&&s.state==='ready'){trackStopped(slot,'disconnected');}
    if(!active&&s.desired&&!s.startPending&&s.nextRetry&&now()>=s.nextRetry){
      s.nextRetry=0;
      if(!await start(slot))trackStopped(slot,s.lastError||'camera_unavailable');
    }
  }
  S.lastPoll=now();
}
function policy(slot) {
  const s=row(slot);
  return {cameraReady:!!conf()?.isEnabled?.(slot)&&!!conf()?.isStreaming?.(slot),sceneCandidate:!!s.ready&&!!conf()?.isEnabled?.(slot),
    quality:s.quality,activity:s.activity,confidence:s.detectorConfidence,
    source:'scene_activity_heuristic',trainedPizzaDetection:false};
}
function renderDiagnostics(){
  const root=document.getElementById('gcrDiagnostics');if(!root)return;
  root.replaceChildren();
  for(let slot=1;slot<=5;slot++){
    const c=status(slot),article=document.createElement('article');
    article.className='gcr-camera';
    const title=document.createElement('strong');title.textContent='Camera '+slot+' · '+(c.enabled?'ENABLED':'OFF');
    const health=document.createElement('span');
    const age=c.lastFrame?Math.max(0,Math.floor((now()-c.lastFrame)/1000)):null;
    health.textContent=(c.active?'LIVE':'NO STREAM')+' · '+c.state+
      ' · last sample '+(age===null?'never':age+'s ago')+
      ' · image quality '+(c.quality||'—')+'/100'+
      (c.retries?' · recoveries '+c.retries+'/3':'');
    const calibr=calibration?.status(slot);
    const calibrationLine=document.createElement('span');
    calibrationLine.textContent='Calibration: '+(!c.active?'NO STREAM':calibr?.ready?'PRELIMINARY READY':(calibr?.reasonCodes?.join(', ')||'not_calibrated'))+
      ' · '+(calibr?.samples||0)+' samples · advisory only';
    const evidence=document.createElement('span');
    evidence.textContent=(slot===2||slot===3?
      'Scene: '+c.activity+' · automatic candidate '+(c.ready?'ready':'waiting'):'No oven matching')+
      ' · trained pizza detector: '+c.detector;
    article.append(title,health,calibrationLine,evidence);root.append(article);
  }
  const note=document.getElementById('gcrRuntimeStatus');
  if(note)note.textContent='Session-bound: camera feeds require this browser page open and visible. '+
    'Stage detection currently uses scene activity and image quality; no trained pizza confidence is implied.';
}
function mount(){
  if(S.monitor)return;S.monitor=true;
  const overview=document.getElementById('sgv-panel-overview');
  if(overview){
    const panel=document.createElement('section');panel.className='console-card gcr-panel';
    panel.innerHTML='<div class="console-heading"><div><small>SECTION 261 · CAMERA HEALTH</small><strong>Production Camera Runtime</strong></div></div>'+
      '<p id="gcrRuntimeStatus" class="sim-muted"></p><div id="gcrDiagnostics" class="gcr-grid"></div>';
    overview.append(panel);
  }
  for(let slot=1;slot<=5;slot++)row(slot);
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden)for(let slot=2;slot<=5;slot++){
      const s=row(slot);
      if(s.desired)stop(slot,'tab_hidden');
    }
  });
  window.addEventListener('pagehide',()=>{for(let slot=1;slot<=5;slot++)stop(slot,'page_closed');});
  window.addEventListener('gelato-glasses-location-change',()=>{
    for(let slot=1;slot<=5;slot++)stop(slot,'location_changed');
  });
  navigator.mediaDevices?.addEventListener?.('devicechange',()=>{
    for(let slot=2;slot<=5;slot++){
      const s=row(slot);
      if(s.desired&&!conf()?.isStreaming?.(slot))trackStopped(slot,'device_changed');
    }
  });
  setInterval(()=>{void poll();renderDiagnostics();},1500);
  renderDiagnostics();
}
window.GelatoCameraRuntime={mount,start,stop,trackStopped,status,policy,consumeCapture,poll,
  calibrationStatus:slot=>calibration?.status(slot)||null,
  snapshot:()=>({sessionBound:true,backgroundSupported:false,locationId:conf()?.locationId||0,
    lastPoll:S.lastPoll,cameras:[1,2,3,4,5].map(status)})};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();