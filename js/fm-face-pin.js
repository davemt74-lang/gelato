/* FM-G8B-PIN: encrypted local face selection on trusted browsers; PIN is validated server-side. */
(()=>{'use strict';
const statusEl=document.getElementById('fm-pin-status');if(!statusEl)return;
const say=text=>{statusEl.textContent=String(text).slice(0,300)};
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const utf8=new TextEncoder(),decoder=new TextDecoder();const DB='gelato-fm-face-pin-v1';
let camera=null,api=null,csrf='';
const openDB=()=>new Promise((resolve,reject)=>{const r=indexedDB.open(DB,1);
 r.onupgradeneeded=()=>{const db=r.result;if(!db.objectStoreNames.contains('keys'))db.createObjectStore('keys');if(!db.objectStoreNames.contains('profiles'))db.createObjectStore('profiles')};
 r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);
});
const txn=(db,store,mode,fn)=>new Promise((resolve,reject)=>{const t=db.transaction(store,mode),o=t.objectStore(store);let req;
 try{req=fn(o)}catch(e){reject(e);return}t.oncomplete=()=>resolve(req?.result);t.onerror=()=>reject(t.error);
});
async function key(db){let v=await txn(db,'keys','readonly',o=>o.get('master'));if(v)return v;
 v=await crypto.subtle.generateKey({name:'AES-GCM',length:256},false,['encrypt','decrypt']);
 await txn(db,'keys','readwrite',o=>o.put(v,'master'));return v;
}
const b64=b=>btoa(String.fromCharCode(...new Uint8Array(b)));
const un64=s=>Uint8Array.from(atob(s),c=>c.charCodeAt(0));
async function profiles(){const db=await openDB();try{
 const k=await key(db),all=await txn(db,'profiles','readonly',o=>o.getAll()),out=[];
 for(const rec of (all||[])){try{
 const raw=await crypto.subtle.decrypt({name:'AES-GCM',iv:un64(rec.iv)},k,un64(rec.cipher));
 const p=JSON.parse(decoder.decode(raw));
 if(typeof p.email==='string'&&typeof p.label==='string'&&Array.isArray(p.vector)&&p.vector.length===128&&p.vector.every(Number.isFinite)&&Date.now()-p.createdAt<180*86400000)out.push(p);
 }catch{}}
 return out;
}finally{db.close()}}
async function storeProfile(p){const db=await openDB();try{
 const k=await key(db),iv=crypto.getRandomValues(new Uint8Array(12));
 const cipher=await crypto.subtle.encrypt({name:'AES-GCM',iv},k,utf8.encode(JSON.stringify(p)));
 await txn(db,'profiles','readwrite',o=>o.put({iv:b64(iv),cipher:b64(cipher)},p.id));
}finally{db.close()}}
async function removeProfile(email){const found=(await profiles()).filter(p=>p.email===email),db=await openDB();
 try{for(const p of found)await txn(db,'profiles','readwrite',o=>o.delete(p.id));}finally{db.close()}}
async function request(action,data={}){
 const res=await fetch('api/fm-pin.php',{method:'POST',credentials:'same-origin',cache:'no-store',
 headers:{'Content-Type':'application/json','Accept':'application/json'},
 body:JSON.stringify({action,csrf_token:csrf,...data})});
 const r=await res.json();if(!res.ok||!r.ok)throw Error(r.message||'Request unavailable');return r;
}
async function context(){
 const res=await fetch('api/fm-pin.php',{credentials:'same-origin',cache:'no-store'});
 const r=await res.json();if(!res.ok||!r.ok)throw Error(r.message||'PIN unavailable');csrf=r.csrf;return r;
}
async function faceLib(){if(api)return api;
 if(!window.faceapi)await new Promise((resolve,reject)=>{const script=document.createElement('script');
 script.src='https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';script.crossOrigin='anonymous';
 script.onload=resolve;script.onerror=()=>reject(Error('Face recognition library unavailable'));document.head.append(script);
 });
 api=window.faceapi;if(!api)throw Error('Face recognition library unavailable');
 const root='https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights';
 await Promise.all([api.nets.tinyFaceDetector.loadFromUri(root),api.nets.faceLandmark68Net.loadFromUri(root),api.nets.faceRecognitionNet.loadFromUri(root)]);return api;
}
function off(){if(camera){camera.getTracks().forEach(t=>t.stop());camera=null}
 for(const id of ['fm-face-preview','fm-face-pin-video']){const video=document.getElementById(id);if(video){video.pause();video.srcObject=null;video.hidden=true}}
}
async function start(video){
 if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia)throw Error('Camera sign-in needs HTTPS and permission');
 off();camera=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
 video.srcObject=camera;video.hidden=false;await video.play();await faceLib();
}
const opts=()=>new api.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:.55});
async function describe(video){
 const faces=await api.detectAllFaces(video,opts());if(faces.length!==1)return null;
 const result=await api.detectSingleFace(video,opts()).withFaceLandmarks().withFaceDescriptor();
 return result?.descriptor?Array.from(result.descriptor):null;
}
const distance=(a,b)=>Math.sqrt(a.reduce((sum,v,i)=>sum+(v-b[i])**2,0));
const average=v=>v[0].map((_,i)=>v.reduce((s,a)=>s+a[i],0)/v.length);
async function samples(video){
 const result=[];
 for(let n=0;n<16&&result.length<3;n++){
 if(!camera)throw Error('Camera stopped');
 const v=await describe(video);
 if(v&&(!result.length||distance(v,result[0])<.43)){result.push(v);say('Face samples '+result.length+' of 3 · hold still');}
 await wait(450);
 }
 if(result.length!==3)throw Error('Could not capture three consistent single-face samples; adjust lighting');
 return average(result);
}
function form(selector,action){const el=document.querySelector(selector);if(!el)return;
 el.addEventListener('submit',async event=>{event.preventDefault();const btn=el.querySelector('button[type=submit]');btn.disabled=true;
 try{const values=Object.fromEntries(new FormData(el));await request(action,values);el.reset();say(action==='pin_set'?'PIN updated successfully.':'This browser is trusted. Employees can enroll their face after password sign-in.');}
 catch(e){say(e.message)}finally{btn.disabled=false}
 });
}
async function enroll(){
 if(!document.getElementById('fm-face-consent')?.checked){say('Consent to local face enrollment first.');return}
 const btn=document.getElementById('fm-face-enroll');btn.disabled=true;
 try{const info=await context();if(!info.signedIn||!info.trustedDevice||!info.pinSet)throw Error('Use a registered terminal and set your PIN first.');
 say('Starting local camera…');const video=document.getElementById('fm-face-preview');await start(video);
 const vector=await samples(video),confirmed=await request('face_enroll');
 await storeProfile({id:crypto.randomUUID(),email:confirmed.email.toLowerCase(),label:confirmed.label.slice(0,80),vector,createdAt:Date.now()});
 say('Face enrolled on this terminal. At login, use Camera then enter your PIN.');
 }catch(e){say(e.message)}finally{off();btn.disabled=false}
}
async function recognize(){
 const btn=document.getElementById('fm-face-pin-start');btn.disabled=true;
 try{const info=await context();if(!info.trustedDevice)throw Error('This browser is not a Super Admin-approved terminal. Use password sign-in.');
 const enrolled=await profiles();if(!enrolled.length)throw Error('No employee faces enrolled here. Use password login and enroll first.');
 const video=document.getElementById('fm-face-pin-video');say('Look toward the camera…');await start(video);
 const vector=await samples(video);
 const ranking=enrolled.map(p=>({...p,d:distance(vector,p.vector)})).sort((a,b)=>a.d-b.d);
 if(!ranking.length||ranking[0].d>=.42||(ranking[1]&&ranking[1].d-ranking[0].d<.085))throw Error('Face match ambiguous or unavailable. Use your password.');
 const selected=ranking[0];off();
 document.getElementById('fm-face-pin-form').hidden=false;
 document.getElementById('fm-face-pin-identity').value=selected.label;
 document.getElementById('fm-face-pin-email').value=selected.email;
 document.getElementById('fm-face-pin-value').focus();
 say('Account selected locally. Enter your six-digit PIN to sign in.');
 }catch(e){say(e.message)}finally{off();btn.disabled=false}
}
function init(){
 form('#fm-pin-set','pin_set');form('#fm-pin-trust','trust_device');
 document.getElementById('fm-face-enroll')?.addEventListener('click',()=>void enroll());
 document.getElementById('fm-face-remove')?.addEventListener('click',async()=>{
 if(!confirm('Remove your face from this terminal?'))return;
 try{const info=await context();await request('face_revoke');if(info.email)await removeProfile(info.email);say('Face enrollment removed from this device.');}catch(e){say(e.message)}
 });
 document.getElementById('fm-pin-revoke')?.addEventListener('click',async()=>{
 if(!confirm('Revoke PIN sign-in on this terminal for every employee?'))return;
 try{await request('revoke_device');say('Terminal revoked. PIN login disabled.')}catch(e){say(e.message)}
 });
 document.getElementById('fm-face-pin-start')?.addEventListener('click',()=>void recognize());
 document.getElementById('fm-face-pin-form')?.addEventListener('submit',async event=>{
 event.preventDefault();const btn=event.target.querySelector('button');btn.disabled=true;
 try{const data=Object.fromEntries(new FormData(event.target)),answer=await request('pin_login',data);location.assign(answer.redirect||'workspace.php');}
 catch(e){say(e.message);document.getElementById('fm-face-pin-value').value='';btn.disabled=false}
 });
 context().then(info=>say(info.trustedDevice?'Registered terminal: '+info.deviceLabel:'Not a registered terminal; Super Admin approval needed.')).catch(e=>say(e.message));
 window.addEventListener('pagehide',off);document.addEventListener('visibilitychange',()=>{if(document.hidden)off()});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
