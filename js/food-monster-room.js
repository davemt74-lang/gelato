/* FM-G9C: opt-in room presence; no biometric data is uploaded. */
(()=>{'use strict';
if(window.GelatoRoomPresence)return;
const endpoint='api/food-monster-presence.php',profileDB='gelato-fm-face-pin-v1';
const state={active:false,stream:null,video:null,faceApi:null,nativeDetector:null,profiles:[],tracker:null,timer:null,busy:false,csrf:'',room:null,changeKey:'',lastContext:0,lastStatus:'',generation:0,modelReady:false,idleCycles:0,failures:0,intervalMs:1700,lastInferenceMs:0};
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const $=s=>document.querySelector(s);
function ui(){
 const buttons=[...document.querySelectorAll('[data-fm-camera]')];
 for(const button of buttons){button.setAttribute('aria-pressed',state.active?'true':'false');button.setAttribute('aria-label',state.active?'Stop room camera':'Start room camera');button.title=button.getAttribute('aria-label');button.textContent=state.active?'◉':'◎';}
 let strip=$('#fm-room-presence');
 if(!strip){strip=document.createElement('div');strip.id='fm-room-presence';strip.setAttribute('role','status');strip.setAttribute('aria-live','polite');strip.style.cssText='position:fixed;right:12px;top:12px;z-index:2147483000;display:none;max-width:220px;border:1px solid #c9d2dc;background:#ffffffed;color:#14283d;border-radius:12px;padding:7px 10px;font:12px/1.5 system-ui,sans-serif;box-shadow:0 4px 18px #0002;pointer-events:none';document.body.append(strip);}
 strip.style.display=state.active?'block':'none';
 if(state.active){
  const people=(state.room?.people||[]);
  const known=people.filter(p=>p.identitySource==='local_face_profile').map(p=>p.displayName).filter(Boolean);
  strip.textContent=state.lastStatus||(people.length?people.length+' present'+(known.length?' · '+known.join(', '):''):'Camera on · watching');
 }
}
async function request(action,data){
 const response=await fetch(endpoint+(action==='status'?'?action=status':''),{
   method:action==='status'?'GET':'POST',cache:'no-store',credentials:'same-origin',
   headers:{'Accept':'application/json',...(action==='status'?{}:{'Content-Type':'application/json','X-CSRF-Token':state.csrf})},
   ...(action==='status'?{}:{body:JSON.stringify({...data,action,csrf_token:state.csrf})})
 });
 const result=await response.json().catch(()=>({ok:false,message:'Invalid room response'}));
 if(!response.ok||!result.ok)throw Error(result.message||'Room presence unavailable');
 return result;
}
async function dbProfiles(){
 if(!window.indexedDB||!window.crypto?.subtle)return [];
 const db=await new Promise((resolve,reject)=>{const r=indexedDB.open(profileDB,1);r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains('keys'))r.result.createObjectStore('keys');if(!r.result.objectStoreNames.contains('profiles'))r.result.createObjectStore('profiles');};r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});
 const get=(store,type,fn)=>new Promise((resolve,reject)=>{const tx=db.transaction(store,type),req=fn(tx.objectStore(store));tx.oncomplete=()=>resolve(req.result);tx.onerror=()=>reject(tx.error);});
 try{
  const k=await get('keys','readonly',o=>o.get('master'));
  if(!k)return [];
  const all=await get('profiles','readonly',o=>o.getAll());const profiles=[];
  for(const item of all||[])try{
   const buffer=await crypto.subtle.decrypt({name:'AES-GCM',iv:Uint8Array.from(atob(item.iv),c=>c.charCodeAt(0))},k,Uint8Array.from(atob(item.cipher),c=>c.charCodeAt(0)));
   const p=JSON.parse(new TextDecoder().decode(buffer));
   if(typeof p.email==='string'&&Array.isArray(p.vector)&&p.vector.length===128&&p.vector.every(Number.isFinite)
     &&typeof p.createdAt==='number'&&Date.now()-p.createdAt<180*86400000
     &&Date.now()>=p.createdAt-60000)profiles.push({email:p.email.toLowerCase(),vector:p.vector});
  }catch{}
  return profiles;
 }finally{db.close();}
}
async function faceModels(){
 if(state.modelReady&&state.faceApi)return;
 if(window.faceapi){state.faceApi=window.faceapi;}
 else{
  await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';script.onload=resolve;script.onerror=()=>reject(Error('Face models unavailable'));document.head.append(script);});
  state.faceApi=window.faceapi;
 }
 if(!state.faceApi)throw Error('Face models unavailable');
 const root='https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights';
 await Promise.all([state.faceApi.nets.tinyFaceDetector.loadFromUri(root),state.faceApi.nets.faceLandmark68Net.loadFromUri(root),state.faceApi.nets.faceRecognitionNet.loadFromUri(root)]);
 state.modelReady=true;
}
function normalizeBox(b,w,h){
 return window.GelatoRoomTracker.geometry([b.x/w,b.y/h,b.width/w,b.height/h]);
}
async function detect(){
 const video=state.video,w=video.videoWidth||640,h=video.videoHeight||480;
 if(state.faceApi){
  const d=await state.faceApi.detectAllFaces(video,new state.faceApi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:.55})).withFaceLandmarks().withFaceDescriptors();
  return d.map(p=>({box:normalizeBox(p.detection.box,w,h),descriptor:p.descriptor?Array.from(p.descriptor):null})).filter(x=>x.box);
 }
 if(state.nativeDetector){
  const faces=await state.nativeDetector.detect(video);
  return faces.map(x=>({box:normalizeBox(x.boundingBox,w,h),descriptor:null})).filter(x=>x.box);
 }
 return [];
}
async function observe(){
 if(!state.active||state.busy||document.hidden)return;
 state.busy=true;const generation=state.generation,started=Date.now();
 try{
  const faces=await detect();if(!state.active||generation!==state.generation)return;
  state.idleCycles=faces.length?0:Math.min(20,state.idleCycles+1);
  const tracks=state.tracker.update(faces,state.profiles);
  const result=await request('observe',{tracks});
  if(!state.active||generation!==state.generation)return;
  state.room=result.room;state.lastStatus='';state.failures=0;
  const key=JSON.stringify((result.room?.people||[]).map(p=>[p.trackId,p.personId,p.identitySource]));
  const now=Date.now();
  if(key!==state.changeKey||now-state.lastContext>10000){
   state.changeKey=key;state.lastContext=now;
   window.dispatchEvent(new CustomEvent('gelato-room-presence-updated',{detail:{room:result.room}}));
   void window.GelatoGlobalAgent?.refreshContext?.().catch(()=>{});
  }
 }catch(e){if(state.active){state.failures=Math.min(6,state.failures+1);state.lastStatus='Camera on · room connection unavailable';}}
 finally{
  state.lastInferenceMs=Math.max(0,Date.now()-started);
  state.busy=false;ui();
  if(state.active&&generation===state.generation&&!document.hidden){
   // No idle polling while camera is off. Never exceed room heartbeat TTL.
   state.intervalMs=state.failures?Math.min(6000,1800*Math.pow(2,Math.min(2,state.failures-1)))
     :(state.idleCycles>=3?3200:state.idleCycles>0?1900:1450);
   state.timer=setTimeout(observe,state.intervalMs);
  }
 }
}
async function stop(){
 const running=state.active;state.active=false;state.generation++;
 if(state.timer){clearTimeout(state.timer);state.timer=null;}
 if(state.stream){state.stream.getTracks().forEach(t=>t.stop());state.stream=null;}
 if(state.video){state.video.pause();state.video.srcObject=null;state.video.remove();state.video=null;}
 state.tracker?.clear();state.room=null;state.lastStatus='';state.changeKey='';state.profiles=[];
 state.idleCycles=0;state.failures=0;
 ui();
 if(running){try{await request('stop',{});}catch{}window.dispatchEvent(new CustomEvent('gelato-room-presence-updated',{detail:{room:{status:'not_connected',people:[],currentSpeaker:null}}}));void window.GelatoGlobalAgent?.refreshContext?.().catch(()=>{});}
}
async function start(){
 if(state.active)return;
 if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia)throw Error('Room camera requires HTTPS and camera permission.');
 if(!window.GelatoRoomTracker)throw Error('Room tracking component missing.');
 state.lastStatus='Starting camera…';state.active=true;state.generation++;ui();
 try{
  const res=await request('status');state.csrf=(document.querySelector('meta[name="gelato-csrf"]')?.content||window.GelatoGlobalAgent?.csrfToken||res.csrf||'');
  // GET never supplies credentials; all POSTs use the existing trusted Agent session's CSRF.
  if(!state.csrf){const a=await fetch('api/agent-workspace.php?action=bootstrap',{credentials:'same-origin',cache:'no-store'});const j=await a.json();state.csrf=j.csrf||'';}
  if(!state.csrf)throw Error('Agent session security token unavailable.');
  state.profiles=await dbProfiles().catch(()=>[]);
  const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
  state.stream=stream;
  const video=document.createElement('video');video.autoplay=true;video.playsInline=true;video.muted=true;video.setAttribute('aria-hidden','true');video.style.cssText='position:fixed;width:1px;height:1px;opacity:0;pointer-events:none;top:-10px;left:-10px';document.body.append(video);state.video=video;video.srcObject=stream;await video.play();
  try{await faceModels();}catch(e){
   if(typeof window.FaceDetector==='function'){state.faceApi=null;state.nativeDetector=new window.FaceDetector({fastMode:true,maxDetectedFaces:8});}
   else throw e;
  }
  state.tracker=window.GelatoRoomTracker.create();state.lastStatus='';
  await observe();
 }catch(e){await stop();throw e;}
 ui();
}
async function toggle(){
 if(state.active){await stop();return;}
 try{await start();}catch(e){
  window.dispatchEvent(new CustomEvent('gelato-room-camera-error',{detail:{message:e.message}}));
  const n=document.createElement('div');n.textContent=e.message;n.style.cssText='position:fixed;right:12px;top:12px;z-index:2147483000;background:#fff;color:#a11;padding:10px;border-radius:10px;font:12px system-ui';document.body.append(n);setTimeout(()=>n.remove(),4500);
 }
}
document.addEventListener('click',event=>{if(event.target.closest('[data-fm-camera]')){event.preventDefault();void toggle();}});
document.addEventListener('visibilitychange',()=>{if(document.hidden&&state.active)void stop();});
window.addEventListener('pagehide',()=>{if(state.active)void stop();});
function mount(){
 if(document.body.dataset.agentCanvas==='1'&&!document.querySelector('[data-fm-camera]')){
  const button=document.createElement('button');button.type='button';button.dataset.fmCamera='';button.title='Start room camera';button.setAttribute('aria-label','Start room camera');button.textContent='◎';document.getElementById('canvasMic')?.after(button);
 }
 ui();
}
window.GelatoRoomPresence={start,stop,toggle,get active(){return state.active;},get room(){return state.room;},
 get diagnostics(){return {active:state.active,intervalMs:state.intervalMs,
   lastInferenceMs:state.lastInferenceMs,modelCached:state.modelReady,failures:state.failures};}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
