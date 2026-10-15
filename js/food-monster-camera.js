/* FM-G3 — consent-based, on-device face tracking + optional encrypted enrollment.
 * This never authenticates a session or grants permissions.
 * The signed-in Gelato account remains the only authority for Agent Brain actions.
 */
(() => {
'use strict';
if (window.GelatoFoodMonsterCamera) return;
function init(){
if (window.GelatoFoodMonsterCamera) return;
const fm=window.GelatoFoodMonster;
if (!fm?.root || !window.GelatoGlobalAgent) return;
const root=fm.root, actor=root.querySelector('.fm-actor');
const PREF='gelato.fm.camera.preferences.v1';
const ACCOUNT=String(window.GelatoGlobalAgent.user?.id||'');
const NAME=String(window.GelatoGlobalAgent.user?.firstName||window.GelatoGlobalAgent.user?.name||'').trim().split(/\s+/)[0].slice(0,48);
const TEMPLATE='gelato.fm.encrypted-face.v1.'+ACCOUNT;
if (!ACCOUNT) return;
const S={stream:null,video:null,epoch:0,busy:false,detecting:false,detectTimer:0,modelPromise:null,recognitionPromise:null,profile:null,seen:0,matchCount:0,lastMatch:0,lastGreet:0,follow:true,scale:true,greet:true,speak:false,tracking:false};
try {const saved=JSON.parse(localStorage.getItem(PREF)||'{}');S.follow=saved.follow!==false;S.scale=saved.scale!==false;S.greet=saved.greet!==false;S.speak=saved.speak===true;} catch {}
const clamp=(x,min,max)=>Math.min(max,Math.max(min,x));
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const panel=document.createElement('dialog');panel.id='fm-camera-dialog';
panel.setAttribute('aria-label','Food Monster camera and recognition settings');
panel.innerHTML='<div class="fm-camera-head"><strong>Food Monster · Camera</strong><button type="button" data-cam="close" aria-label="Close camera settings">×</button></div>'+
'<div class="fm-camera-preview"><video autoplay muted playsinline aria-label="Local camera preview"></video><div class="fm-camera-preview-note">Video remains on this device</div></div>'+
'<p class="fm-camera-message" role="status" aria-live="polite">Camera is off. Choose Start Camera to enable.</p>'+
'<div class="fm-camera-actions"><button type="button" data-cam="start">Start camera</button><button type="button" data-cam="stop" disabled>Stop camera</button></div>'+
'<div class="fm-camera-options"><label><input type="checkbox" data-pref="follow"> Float toward my position</label><label><input type="checkbox" data-pref="scale"> Grow/shrink with distance</label><label><input type="checkbox" data-pref="greet"> Personalized visual greeting</label><label><input type="checkbox" data-pref="speak"> Speak greeting (requires voice permission)</label></div>'+
'<div class="fm-camera-divider"></div><h3>Recognize this account</h3>'+
'<p class="fm-camera-note">Optional: enroll three face samples for local matching. Face recognition personalizes your greeting, not your login or permissions. Models download the first time you enable the camera.</p>'+
'<div class="fm-camera-actions"><button type="button" data-cam="enroll">Enroll my face</button><button type="button" data-cam="forget">Forget active face</button></div>'+
'<label class="fm-camera-password">Private profile passphrase<input type="password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters"></label>'+
'<div class="fm-camera-actions"><button type="button" data-cam="save">Save encrypted profile</button><button type="button" data-cam="unlock">Unlock saved profile</button><button type="button" data-cam="delete">Delete saved profile</button></div>'+
'<p class="fm-camera-note">Face descriptors are processed locally; only an encrypted template is saved in this browser when you choose Save. Never use face or voice recognition as account authentication.</p>';
document.body.append(panel);
S.video=panel.querySelector('video');
const statusEl=panel.querySelector('.fm-camera-message');
const say=(message,error=false)=>{statusEl.textContent=String(message);statusEl.dataset.error=error?'1':'0';};
const btn=(name)=>panel.querySelector('[data-cam="'+name+'"]');
function show(){panel.showModal?.();if(!panel.open)panel.setAttribute('open','');}
function close(){if(panel.open&&panel.close)panel.close();else panel.removeAttribute('open');}
function prefs(){try{localStorage.setItem(PREF,JSON.stringify({follow:S.follow,scale:S.scale,greet:S.greet,speak:S.speak}));}catch{}}
for(const item of panel.querySelectorAll('[data-pref]')){
 const key=item.dataset.pref;item.checked=S[key];
 item.onchange=()=>{S[key]=item.checked;prefs();};
}
function loadLibrary(){
 return new Promise((resolve,reject)=>{
   if(window.faceapi)return resolve(window.faceapi);
   const existing=document.querySelector('script[data-fm-face-library]');
   if(existing){
     if(existing.dataset.ready==='1')return resolve(window.faceapi);
     existing.addEventListener('load',()=>resolve(window.faceapi),{once:true});
     existing.addEventListener('error',()=>reject(Error('Face library unavailable')),{once:true});
     return;
   }
   const script=document.createElement('script');
   script.dataset.fmFaceLibrary='1';script.async=true;script.crossOrigin='anonymous';
   script.src='https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';
   script.onload=()=>{script.dataset.ready='1';if(!window.faceapi)reject(Error('Face library invalid'));else resolve(window.faceapi);};
   script.onerror=()=>reject(Error('Face library could not download. Check internet or content security policy.'));
   document.head.append(script);
 });
}
const MODEL_ROOT='https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights';
async function trackingModels(){
 if(S.modelPromise)return S.modelPromise;
 S.modelPromise=(async()=>{
   const api=await loadLibrary();
   await api.nets.tinyFaceDetector.loadFromUri(MODEL_ROOT);
   return api;
 })().catch(e=>{S.modelPromise=null;throw e;});
 return S.modelPromise;
}
async function recognitionModels(){
 if(S.recognitionPromise)return S.recognitionPromise;
 S.recognitionPromise=(async()=>{
   const api=await trackingModels();
   await Promise.all([api.nets.faceLandmark68Net.loadFromUri(MODEL_ROOT),api.nets.faceRecognitionNet.loadFromUri(MODEL_ROOT)]);
   return api;
 })().catch(e=>{S.recognitionPromise=null;throw e;});
 return S.recognitionPromise;
}
const detectorOptions=()=>new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:.55});
const dist=(a,b)=>{
 if(!a||!b||a.length!==128||b.length!==128)return Infinity;
 let sum=0;for(let k=0;k<128;k++)sum+=(a[k]-b[k])**2;return Math.sqrt(sum);
};
function publishCamera(active){
 window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:!!active}}));
}
function publishFaces(faces,ownVerified=false){
 const width=S.video.videoWidth||1,height=S.video.videoHeight||1;
 // Temporary presence boxes only; no descriptors, frames, images or names.
 window.dispatchEvent(new CustomEvent('gelato-food-monster-faces',{detail:{
  boxes:faces.slice(0,10).map(f=>({x:clamp(f.box.x/width,0,1),y:clamp(f.box.y/height,0,1),
    width:clamp(f.box.width/width,0,1),height:clamp(f.box.height/height,0,1),confidence:clamp(Number(f.score)||0,0,1)})),
  ownVerified:!!ownVerified&&faces.length===1
 }}));
}
function verifiedGreeting(){
 const value=window.GelatoGlobalAgent?.verifiedSpeaker;
 return value?.sessionUserMatch===true && Number(value.userId)===Number(ACCOUNT);
}
function greet(reason){
 if(!S.greet || !NAME || Date.now()-S.lastGreet<120000)return;
 if(reason==='face'&&!S.profile)return;
 if(reason==='voice'&&!verifiedGreeting())return;
 S.lastGreet=Date.now();
 const message='Welcome back, '+NAME+'!';
 fm.greet?.(message);
 if(S.speak&&window.GelatoGlobalAgent.permissions.includes('voice.agent')&&window.GelatoVoiceTurns?.speak)
   window.GelatoVoiceTurns.speak(message,{owner:'global-agent'});
}
async function scan(){
 if(S.busy||S.detecting||!S.stream||document.hidden||!S.video||S.video.readyState<2)return;
 S.detecting=true;const epoch=S.epoch;
 try{
   const api=await trackingModels();
   if(epoch!==S.epoch||!S.stream)return;
   // Avoid adopting an arbitrary face as an identity: multiple faces block matching.
   const faces=await api.detectAllFaces(S.video,detectorOptions());
   if(epoch!==S.epoch||!S.stream)return;
   publishFaces(faces,S.matchCount>=2&&!!S.profile);
   const face=faces.slice().sort((a,b)=>(b.box.width*b.box.height)-(a.box.width*a.box.height))[0];
   if(!face){
     S.matchCount=0;
     if(Date.now()-S.lastMatch>4500)S.seen=0;
     fm.setFaceTarget?.(null);
     return;
   }
   const box=face.box, width=S.video.videoWidth||1,height=S.video.videoHeight||1;
   const x=clamp(1-(box.x+box.width/2)/width,0,1); // selfie mirrored
   const y=clamp((box.y+box.height/2)/height,0,1);
   const area=clamp(box.width/width,.08,.9);
   fm.setFaceTarget?.({x,y,proximity:area,follow:S.follow,scale:S.scale});
   S.seen++;
   if(!S.profile||faces.length!==1){S.matchCount=0;return;}
   // Recheck a face descriptor at most once every ~2s rather than each animation frame.
   if(S.seen%5!==0)return;
   await recognitionModels();
   if(epoch!==S.epoch||!S.stream)return;
   const match=await api.detectSingleFace(S.video,detectorOptions()).withFaceLandmarks().withFaceDescriptor();
   if(epoch!==S.epoch||!S.stream)return;
   if(match && dist(Array.from(match.descriptor),S.profile.descriptor)<.48){
     S.matchCount=Math.min(4,S.matchCount+1);S.lastMatch=Date.now();
     if(S.matchCount>=2){publishFaces(faces,true);greet('face');}
   }else S.matchCount=0;
 }catch(e){
   if(epoch===S.epoch&&S.stream){say('Tracking paused: '+(e.message||String(e)),true);stop();}
 }finally{S.detecting=false;}
}
function stop(){
 S.epoch++;
 if(S.detectTimer){clearInterval(S.detectTimer);S.detectTimer=0;}
 if(S.stream){for(const t of S.stream.getTracks())t.stop();S.stream=null;}
 S.video.srcObject=null;S.tracking=false;S.seen=0;S.matchCount=0;
 fm.setFaceTarget?.(null);btn('start').disabled=false;btn('stop').disabled=true;
 publishFaces([]);publishCamera(false);
 say('Camera is off. No frames or face descriptors are uploaded.');
}
async function start(){
 if(S.stream)return true;
 if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia){say('Camera requires HTTPS or localhost, plus browser permission.',true);return false;}
 if(S.busy)return false;S.busy=true;btn('start').disabled=true;
 const epoch=++S.epoch;
 try{
  say('Requesting webcam permission…');
  const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
  if(epoch!==S.epoch){stream.getTracks().forEach(t=>t.stop());return false;}
  S.stream=stream;S.video.srcObject=stream;
  for(const track of stream.getVideoTracks())track.addEventListener('ended',stop,{once:true});
  await S.video.play();
  say('Loading the local face tracker…');
  await trackingModels();
  if(epoch!==S.epoch)return false;
  S.tracking=true;btn('stop').disabled=false;publishCamera(true);S.detectTimer=setInterval(scan,360);
  scan();say('Tracking active. Move left/right or closer/farther away. No video is uploaded.');
  return true;
 }catch(e){if(epoch===S.epoch){say('Could not start camera: '+(e.message||String(e)),true);stop();}return false;}
 finally{S.busy=false;if(!S.stream)btn('start').disabled=false;}
}
async function enroll(options={}){
 if(S.busy)return;
 if(!S.stream){say('Start your camera before enrolling a face.',true);return;}
 S.busy=true;btn('enroll').disabled=true;
 const epoch=S.epoch;
 try{
  say('Loading optional face-recognition model…');
  const api=await recognitionModels(),samples=[];
  for(let i=0;i<3;i++){
   if(epoch!==S.epoch)return;
   say('Face enrollment: hold still, sample '+(i+1)+' of 3…');
   const all=await api.detectAllFaces(S.video,detectorOptions());
   if(all.length!==1)throw Error('Exactly one visible face is required.');
   const sample=await api.detectSingleFace(S.video,detectorOptions()).withFaceLandmarks().withFaceDescriptor();
   if(!sample?.descriptor)throw Error('No consistent face found.');
   samples.push(Array.from(sample.descriptor));
   await sleep(550);
  }
  if(epoch!==S.epoch)return;
  if(Math.max(dist(samples[0],samples[1]),dist(samples[1],samples[2]),dist(samples[0],samples[2]))>.51)
   throw Error('Samples differed too much. Hold still and try again.');
  S.profile={account:ACCOUNT,name:NAME,descriptor:samples[0].map((_,i)=>(samples[0][i]+samples[1][i]+samples[2][i])/3)};
  S.matchCount=0;S.lastGreet=0;
  if(options.persist===true){try{await persistDeviceFace();say('Encrypted face profile saved for this account on this device.');}
   catch{say('Face enrolled for this session; secure device storage unavailable.');}}
  else say('Face enrolled locally for this signed-in account. Look away and return to test recognition.');
 }catch(e){say('Enrollment not completed: '+(e.message||String(e)),true);}
 finally{S.busy=false;btn('enroll').disabled=false;}
}
/* Encrypted local face descriptor, scoped to the authenticated user only. */
const DEVICE_DB='gelato-fm-onboard-face-v1';
function faceDb(){return new Promise((resolve,reject)=>{
 const q=indexedDB.open(DEVICE_DB,1);
 q.onupgradeneeded=()=>{const db=q.result;if(!db.objectStoreNames.contains('keys'))db.createObjectStore('keys');if(!db.objectStoreNames.contains('profiles'))db.createObjectStore('profiles');};
 q.onsuccess=()=>resolve(q.result);q.onerror=()=>reject(q.error);
});}
function faceTxn(db,store,mode,fn){return new Promise((resolve,reject)=>{
 const t=db.transaction(store,mode),q=fn(t.objectStore(store));t.oncomplete=()=>resolve(q.result);t.onerror=()=>reject(t.error);
});}
async function faceKey(db){let k=await faceTxn(db,'keys','readonly',o=>o.get('k'));
 if(!k){k=await crypto.subtle.generateKey({name:'AES-GCM',length:256},false,['encrypt','decrypt']);
 await faceTxn(db,'keys','readwrite',o=>o.put(k,'k'));}return k;}
async function persistDeviceFace(){
 if(!S.profile||S.profile.account!==ACCOUNT)throw Error('Wrong face enrollment account.');
 const db=await faceDb();try{
  const key=await faceKey(db),iv=crypto.getRandomValues(new Uint8Array(12));
  const raw=new TextEncoder().encode(JSON.stringify(S.profile));
  const encrypted=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,raw);
  await faceTxn(db,'profiles','readwrite',o=>o.put({iv:Array.from(iv),cipher:Array.from(new Uint8Array(encrypted)),savedAt:Date.now()},ACCOUNT));
 }finally{db.close();}
}
async function loadDeviceFace(){try{
 const db=await faceDb();try{
  const stored=await faceTxn(db,'profiles','readonly',o=>o.get(ACCOUNT));if(!stored||S.profile)return;
  const key=await faceKey(db);
  const raw=await crypto.subtle.decrypt({name:'AES-GCM',iv:new Uint8Array(stored.iv)},key,new Uint8Array(stored.cipher));
  const profile=JSON.parse(new TextDecoder().decode(raw));
  if(profile.account===ACCOUNT&&Array.isArray(profile.descriptor)&&profile.descriptor.length===128&&profile.descriptor.every(Number.isFinite))S.profile=profile;
 }finally{db.close();}
 }catch{/* Browser keys may be deleted or storage may be unavailable. */}}
async function deleteDeviceFace(){try{const db=await faceDb();try{await faceTxn(db,'profiles','readwrite',o=>o.delete(ACCOUNT));}finally{db.close();}}catch{}}
const enc=new TextEncoder(),dec=new TextDecoder();
const b64=b=>btoa(String.fromCharCode(...new Uint8Array(b)));
const un64=s=>Uint8Array.from(atob(s),c=>c.charCodeAt(0));
async function cipherKey(phrase,salt){
 const base=await crypto.subtle.importKey('raw',enc.encode(phrase),'PBKDF2',false,['deriveKey']);
 return crypto.subtle.deriveKey({name:'PBKDF2',salt,iterations:240000,hash:'SHA-256'},base,{name:'AES-GCM',length:256},false,['encrypt','decrypt']);
}
async function save(){
 if(!S.profile){say('Enroll your face first.',true);return;}
 const p=panel.querySelector('.fm-camera-password input'),phrase=p.value;
 if(phrase.length<8){say('Use a passphrase of at least 8 characters.',true);return;}
 try{
   const salt=crypto.getRandomValues(new Uint8Array(16)),iv=crypto.getRandomValues(new Uint8Array(12));
   const key=await cipherKey(phrase,salt);
   const ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,enc.encode(JSON.stringify(S.profile)));
   localStorage.setItem(TEMPLATE,JSON.stringify({v:1,salt:b64(salt),iv:b64(iv),ciphertext:b64(ciphertext)}));
   p.value='';say('Encrypted face profile saved on this browser only. Unlock it after reloading.');
 }catch(e){say('Encrypted save failed: '+(e.message||String(e)),true);}
}
async function unlock(){
 const input=panel.querySelector('.fm-camera-password input'),phrase=input.value;
 if(!phrase){say('Enter your profile passphrase to unlock.',true);return;}
 try{
   const item=JSON.parse(localStorage.getItem(TEMPLATE)||'null');
   if(item?.v!==1)throw Error('No saved profile');
   const key=await cipherKey(phrase,un64(item.salt));
   const plain=await crypto.subtle.decrypt({name:'AES-GCM',iv:un64(item.iv)},key,un64(item.ciphertext));
   const p=JSON.parse(dec.decode(plain));
   if(p.account!==ACCOUNT||p.name!==NAME||!Array.isArray(p.descriptor)||p.descriptor.length!==128||!p.descriptor.every(Number.isFinite))
     throw Error('Saved profile does not belong to this account');
   S.profile=p;S.matchCount=0;say('Face profile unlocked. Start your camera to recognize '+NAME+'.');
 }catch{say('Could not unlock profile. Check your passphrase and account.',true);}
 finally{input.value='';}
}
function forget(deleteSaved=false){
 S.profile=null;S.matchCount=0;S.lastMatch=0;
 if(deleteSaved)try{localStorage.removeItem(TEMPLATE);}catch{}
 say(deleteSaved?'Encrypted profile deleted for this account.':'Active face recognition cleared. Camera tracking may continue.');
}
for(const control of panel.querySelectorAll('[data-cam]')){
 control.addEventListener('click',()=>{
  const action=control.dataset.cam;
  if(action==='close')close();
  if(action==='start')start();
  if(action==='stop')stop();
  if(action==='enroll')enroll();
  if(action==='save')save();
  if(action==='unlock')unlock();
  if(action==='forget')forget(false);
  if(action==='delete')forget(true);
 });
}
const trigger=document.createElement('button');trigger.type='button';trigger.className='fm-camera-trigger';
trigger.setAttribute('aria-label','Camera and identity settings');trigger.title='Camera and identity';trigger.textContent='◉';
actor.querySelector('.fm-actions')?.append(trigger);trigger.addEventListener('click',show);
panel.addEventListener('cancel',event=>{event.preventDefault();close();});
window.addEventListener('pagehide',stop);
document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});
window.addEventListener('gelato-verified-speaker',event=>{
 const d=event.detail||{};
 if(d.sessionUserMatch===true&&Number(d.userId)===Number(ACCOUNT))greet('voice');
});
void loadDeviceFace();
window.GelatoFoodMonsterCamera={open:show,start,stop,enroll,forget,get active(){return!!S.stream},get stream(){return S.stream},get enrolled(){return!!S.profile},get account(){return ACCOUNT},get preferences(){return {greet:S.greet,speak:S.speak}},scanOnce:scan};
}
if(window.GelatoGlobalAgent?.user?.id) init();
else window.addEventListener('gelato-agent-ready',init,{once:true});
})();