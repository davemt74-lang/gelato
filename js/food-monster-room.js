/* FM-G9C: opt-in room presence; no biometric data is uploaded. */
(()=>{'use strict';
if(window.GelatoRoomPresence)return;
const endpoint='api/food-monster-presence.php',profileDB='gelato-fm-face-pin-v1';
const state={active:false,stream:null,video:null,faceApi:null,nativeDetector:null,profiles:[],tracker:null,timer:null,busy:false,csrf:'',room:null,changeKey:'',lastContext:0,lastStatus:'',generation:0,modelReady:false,idleCycles:0,failures:0,intervalMs:1700,lastInferenceMs:0,lastTimeline:0,timelineBusy:false,lastGreeting:''};
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const eyes={panel:null,frame:null,overlay:null,video:null,button:null,open:false};
const speakerLabel=room=>window.GelatoEyesSpeaker?.resolve(room)||null;
function eyesEnsure(){
 if(eyes.panel)return;
 const style=document.createElement('style');style.dataset.fmEyesStyle='1';
 style.textContent=`#fm-eyes-panel{position:fixed;right:16px;bottom:86px;width:min(440px,calc(100vw - 24px));max-height:calc(100dvh - 110px);overflow:auto;z-index:2147482990;background:#0d1825;color:#f3f7fb;border:1px solid #3b536a;border-radius:16px;box-shadow:0 18px 58px #0008;padding:12px;font:13px/1.5 system-ui,sans-serif}#fm-eyes-panel[hidden]{display:none}#fm-eyes-panel button{border:1px solid #607992;border-radius:8px;background:#22374b;color:inherit;padding:5px 9px;cursor:pointer}#fm-eyes-panel .fm-eyes-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}#fm-eyes-panel .fm-eyes-frame{position:relative;background:#050b12;overflow:hidden;border-radius:12px;aspect-ratio:4/3}#fm-eyes-panel video{display:block;width:100%;height:100%;object-fit:fill}#fm-eyes-panel .fm-eyes-overlay{position:absolute;inset:0;pointer-events:none}#fm-eyes-panel .fm-eyes-box{position:absolute;border:2px solid #60dfa3;border-radius:5px;box-sizing:border-box}#fm-eyes-panel .fm-eyes-box.unknown{border-color:#efc87a}#fm-eyes-panel .fm-eyes-box.speaking{border-color:#5b9bff;box-shadow:0 0 0 2px #5b9bff55}#fm-eyes-panel .fm-eyes-speaker{margin-top:7px;padding:7px 9px;background:#192d42;border:1px solid #355678;border-radius:9px}#fm-eyes-panel .fm-eyes-speaker[hidden]{display:none}#fm-eyes-panel .fm-eyes-tag{position:absolute;bottom:100%;left:-2px;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#163a38;color:#fff;padding:1px 5px;border-radius:4px;font:11px system-ui}#fm-eyes-panel .fm-eyes-box.unknown .fm-eyes-tag{background:#5a421e}#fm-eyes-panel .fm-eyes-timeline{margin-top:10px;border-top:1px solid #38516a;padding-top:8px}#fm-eyes-panel .fm-eyes-timeline ul{padding:4px 0 0;margin:0;list-style:none}#fm-eyes-panel .fm-eyes-timeline li{font-size:11px;padding:3px 0;color:#bcd0e2}#fm-eyes-panel .fm-eyes-greeting{color:#a4f0c7;margin:5px 0;font-size:12px}#fm-eyes-panel .fm-eyes-list{padding:8px 0 0;margin:0;list-style:none}#fm-eyes-panel .fm-eyes-list li{padding:3px 0;border-bottom:1px solid #263f56}#fm-eyes-panel .fm-eyes-note{font-size:11px;color:#a8bece;margin-top:8px}#fm-eyes-open{margin-left:5px}@media(max-width:600px){#fm-eyes-panel{right:6px;bottom:70px;width:calc(100vw - 12px);max-height:calc(100dvh - 80px)}}`;
 document.head.append(style);
 const panel=document.createElement('section');panel.id='fm-eyes-panel';panel.hidden=true;panel.setAttribute('role','region');panel.setAttribute('aria-label','Agent Eyes live camera');
 const head=document.createElement('div');head.className='fm-eyes-head';
 const title=document.createElement('strong');title.textContent='Agent Eyes · Room camera';
 const close=document.createElement('button');close.type='button';close.textContent='Close';close.setAttribute('aria-label','Close Agent Eyes');close.addEventListener('click',()=>eyesShow(false));
 head.append(title,close);panel.append(head);
 const status=document.createElement('div');status.id='fm-eyes-status';status.setAttribute('role','status');panel.append(status);
 const frame=document.createElement('div');frame.className='fm-eyes-frame';const overlay=document.createElement('div');overlay.className='fm-eyes-overlay';frame.append(overlay);panel.append(frame);
 const speaker=document.createElement('div');speaker.id='fm-eyes-speaker';speaker.className='fm-eyes-speaker';speaker.setAttribute('role','status');speaker.setAttribute('aria-live','polite');panel.append(speaker);
 const greeting=document.createElement('div');greeting.id='fm-eyes-greeting';greeting.className='fm-eyes-greeting';greeting.setAttribute('role','status');panel.append(greeting);
 const list=document.createElement('ul');list.className='fm-eyes-list';list.id='fm-eyes-people';panel.append(list);
 const timeline=document.createElement('details');timeline.className='fm-eyes-timeline';
 const timelineTitle=document.createElement('summary');timelineTitle.textContent='Participant timeline';timeline.append(timelineTitle);
 const events=document.createElement('ul');events.id='fm-eyes-events';timeline.append(events);panel.append(timeline);
 const note=document.createElement('div');note.className='fm-eyes-note';note.textContent='Visual identities are provisional. Camera is local; no video frames or face vectors are uploaded.';panel.append(note);
 document.body.append(panel);eyes.panel=panel;eyes.frame=frame;eyes.overlay=overlay;
 if(!document.querySelector('script[data-fm-eyes-speaker]')){
  const script=document.createElement('script');script.dataset.fmEyesSpeaker='1';script.src='js/agent-eyes-speaker.js?v=20261025-ae3';script.onload=eyesRender;document.head.append(script);
 }
 if(!document.querySelector('script[data-fm-eyes-enrollment]')){
  const script=document.createElement('script');script.dataset.fmEyesEnrollment='1';
  script.src='js/agent-eyes-enrollment.js?v=20261025-ae2';document.head.append(script);
 }
 const button=document.createElement('button');button.type='button';button.id='fm-eyes-open';button.textContent='Agent Eyes';button.setAttribute('aria-controls','fm-eyes-panel');button.setAttribute('aria-expanded','false');button.addEventListener('click',()=>eyesShow(!eyes.open));
 const anchor=document.querySelector('[data-fm-camera]');if(anchor)anchor.after(button);else document.body.append(button);eyes.button=button;
 eyesRender();
}
function eyesShow(open){
 eyesEnsure();eyes.open=!!open;eyes.panel.hidden=!eyes.open;eyes.button.setAttribute('aria-expanded',String(eyes.open));
 if(eyes.open&&state.video){eyes.frame.prepend(state.video);state.video.style.cssText='';}
 else if(state.video){document.body.append(state.video);state.video.style.cssText='position:fixed;width:1px;height:1px;opacity:0;pointer-events:none;top:-10px;left:-10px';}
 eyesRender();if(eyes.open&&state.active)void refreshTimeline();
}
function eyesRender(){
 if(!eyes.panel)return;
 const status=eyes.panel.querySelector('#fm-eyes-status');
 status.textContent=state.active?(state.lastStatus||'Camera active · '+(state.room?.people?.length||0)+' tracked'):'Camera off · enable using the camera control';
 const greeting=eyes.panel.querySelector('#fm-eyes-greeting');if(greeting)greeting.textContent=state.active?state.lastGreeting:'';
 eyes.overlay.replaceChildren();
 const speaker=speakerLabel(state.active?state.room:null);
 const speakerStatus=eyes.panel.querySelector('#fm-eyes-speaker');
 if(speakerStatus)speakerStatus.textContent=speaker?.message||(state.active?'Speaker not attributed · awaiting a verified voice turn':'Speaker tracking off');
 const list=eyes.panel.querySelector('#fm-eyes-people');list.replaceChildren();
 if(!eyes.open||!state.active)return;
 for(const person of state.room?.people||[]){
  if(!Array.isArray(person.box)||person.box.length!==4)continue;
  const box=person.box.map(Number);if(box.some(x=>!Number.isFinite(x)||x<0||x>1))continue;
  const known=person.identitySource==='local_face_profile'&&!!person.displayName;
  const label=known?String(person.displayName).slice(0,70)+' · provisional':'Unknown participant';
  const isSpeaker=known&&speaker?.trackId===person.trackId;
  const outline=document.createElement('div');outline.className='fm-eyes-box'+(known?'':' unknown')+(isSpeaker?' speaking':'');
  outline.style.left=(box[0]*100)+'%';outline.style.top=(box[1]*100)+'%';outline.style.width=(box[2]*100)+'%';outline.style.height=(box[3]*100)+'%';
  const tag=document.createElement('span');tag.className='fm-eyes-tag';tag.textContent=isSpeaker?'Speaking · '+label:label;outline.append(tag);eyes.overlay.append(outline);
  const item=document.createElement('li');item.textContent=isSpeaker?'Speaking · '+label:label;list.append(item);
 }
}

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
const nowTime=()=>Date.now();
async function refreshTimeline(){
 if(state.timelineBusy||!state.active||!eyes.open)return;
 state.timelineBusy=true;
 try{
  const response=await fetch(endpoint+'?action=timeline',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
  const data=await response.json();if(!response.ok||!data.ok||!Array.isArray(data.events))return;
  const list=eyes.panel?.querySelector('#fm-eyes-events');if(!list)return;
  list.replaceChildren();for(const event of data.events.slice(0,30)){
   const li=document.createElement('li');li.textContent=String(event.occurredAt||'')+' · '+String(event.displayName||'Participant')+' · '+String(event.type||'event').replaceAll('_',' ');list.append(li);
  }
  state.lastTimeline=nowTime();
 }catch{}finally{state.timelineBusy=false;}
}
async function observe(){
 if(!state.active||state.busy||document.hidden)return;
 state.busy=true;const generation=state.generation,started=Date.now();
 try{
  const faces=await detect();if(!state.active||generation!==state.generation)return;
  state.idleCycles=faces.length?0:Math.min(20,state.idleCycles+1);
  const tracks=state.tracker.update(faces,state.profiles);
  const result=await request('observe',{tracks,allowGreeting:window.GelatoGlobalAgent?.proactiveGreetingEnabled===true});
  if(!state.active||generation!==state.generation)return;
  state.room=result.room;state.lastStatus='';state.failures=0;
   window.GelatoEyesEnrollment?.onRoom?.(result.room);
  for(const greeting of result.room?.proactiveGreetings||[]){
   if(typeof greeting?.text==='string'&&greeting.provisional===true){
    state.lastGreeting='Greeting offered · '+greeting.text;
    window.GelatoGlobalAgent?.proactiveGreet?.(greeting.text);
   }
  }
  if(eyes.open&&nowTime()-state.lastTimeline>12000)void refreshTimeline();
  const key=JSON.stringify({people:(result.room?.people||[]).map(p=>[p.trackId,p.personId,p.identitySource]),speaker:window.GelatoEyesSpeaker?.key(result.room)||result.room?.currentSpeaker||null});
  const now=Date.now();
  if(key!==state.changeKey||now-state.lastContext>10000){
   state.changeKey=key;state.lastContext=now;
   window.dispatchEvent(new CustomEvent('gelato-room-presence-updated',{detail:{room:result.room}}));
   void window.GelatoGlobalAgent?.refreshContext?.().catch(()=>{});
  }
 }catch(e){if(state.active){state.failures=Math.min(6,state.failures+1);state.lastStatus='Camera on · room connection unavailable';}}
 finally{
  eyesRender();
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
 state.tracker?.clear();state.room=null;state.lastStatus='';state.changeKey='';state.profiles=[];state.lastGreeting='';state.lastTimeline=0;
 window.GelatoEyesEnrollment?.resetRoom?.();
 state.idleCycles=0;state.failures=0;
 ui();eyesRender();
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
  if(eyes.open)eyesShow(true);
  state.tracker=window.GelatoRoomTracker.create();state.lastStatus='';
  await observe();
 }catch(e){await stop();throw e;}
 ui();eyesRender();
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
 eyesEnsure();ui();
}
window.GelatoRoomPresence={start,stop,toggle,openEyes:()=>eyesShow(true),closeEyes:()=>eyesShow(false),refreshProfiles:async()=>{state.profiles=await dbProfiles();return state.profiles.length;},get active(){return state.active;},get room(){return state.room;},
 get diagnostics(){return {active:state.active,intervalMs:state.intervalMs,
   lastInferenceMs:state.lastInferenceMs,modelCached:state.modelReady,failures:state.failures};}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
