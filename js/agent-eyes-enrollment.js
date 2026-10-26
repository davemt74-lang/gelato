/* Agent Eyes AE2: in-person, consented local enrollment for existing Gelato people.
 * Provisional room recognition only; never authentication or authority.
 */
(()=>{'use strict';
if(window.GelatoEyesEnrollment)return;
const root=document.getElementById('fm-eyes-panel');if(!root)return;
const endpoint='api/food-monster-presence.php',dbName='gelato-fm-face-pin-v1';
let modal=null,select=null,consent=null,capture=null,status=null,step=0,vectors=[],people=[];
let invitation=null,invitationMessage=null,invitationTrack='',firstSeenAt=0,lastSpokenAt=0;
const declinedTracks=new Set(),spokenTracks=new Set();
const stableTrack=/^r-[a-f0-9]{16,32}$/;
const enrollmentOffer='Someone new is in view. Ask whether they want a face profile. Samples are collected only after their consent.';
const stages=['Front: look straight at the camera','Left angle: slowly turn your head left','Right angle: slowly turn your head right'];
const report=t=>{if(status)status.textContent=String(t).slice(0,300);};
const dist=(a,b)=>Math.sqrt(a.reduce((sum,v,i)=>sum+(v-b[i])**2,0));
async function csrf(){
 let token=document.querySelector('meta[name="gelato-csrf"]')?.content||window.GelatoGlobalAgent?.csrf||'';
 if(token)return token;
 const r=await fetch('api/agent-workspace.php?action=bootstrap',{credentials:'same-origin',cache:'no-store'});
 const d=await r.json();if(!r.ok||!d.csrf)throw Error('Agent session token unavailable');
 return d.csrf;
}
async function post(action,other){
 const token=await csrf();const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
 headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':token},
 body:JSON.stringify({action,...other,csrf_token:token})});
 const j=await r.json();if(!r.ok||!j.ok)throw Error(j.message||'Enrollment unavailable');return j;
}
async function candidates(){
 const r=await fetch(endpoint+'?action=enroll_candidates',{credentials:'same-origin',cache:'no-store'});
 const j=await r.json();if(!r.ok||!j.ok)throw Error(j.message||'Enrollment roster unavailable');
 return Array.isArray(j.people)?j.people:[];
}
function transaction(db,name,mode,fn){
 return new Promise((resolve,reject)=>{const tx=db.transaction(name,mode),request=fn(tx.objectStore(name));
 tx.oncomplete=()=>resolve(request?.result);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);});
}
async function openDB(){
 return new Promise((resolve,reject)=>{const r=indexedDB.open(dbName,1);
 r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains('keys'))r.result.createObjectStore('keys');
 if(!r.result.objectStoreNames.contains('profiles'))r.result.createObjectStore('profiles')};
 r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});
}
async function localEmails(){
 const db=await openDB();try{
 const key=await transaction(db,'keys','readonly',o=>o.get('master'));if(!key)return new Set();
 const rows=await transaction(db,'profiles','readonly',o=>o.getAll()),emails=new Set();
 for(const row of rows||[])try{
  const iv=Uint8Array.from(atob(row.iv),c=>c.charCodeAt(0));
  const data=Uint8Array.from(atob(row.cipher),c=>c.charCodeAt(0));
  const plain=await crypto.subtle.decrypt({name:'AES-GCM',iv},key,data);
  const profile=JSON.parse(new TextDecoder().decode(plain));
  if(typeof profile.email==='string')emails.add(profile.email.toLowerCase());
 }catch{}
 return emails;
 }finally{db.close();}
}
async function storeLocal(person,vector){
 const db=await openDB();try{
 let key=await transaction(db,'keys','readonly',o=>o.get('master'));
 if(!key){key=await crypto.subtle.generateKey({name:'AES-GCM',length:256},false,['encrypt','decrypt']);
 await transaction(db,'keys','readwrite',o=>o.put(key,'master'));}
 const iv=crypto.getRandomValues(new Uint8Array(12));
 const profile={id:crypto.randomUUID(),email:person.email.toLowerCase(),label:person.name.slice(0,80),
  vector,createdAt:Date.now(),source:'agent_eyes'};
 const bytes=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,new TextEncoder().encode(JSON.stringify(profile)));
 const b64=b=>btoa(String.fromCharCode(...new Uint8Array(b)));
 await transaction(db,'profiles','readwrite',o=>o.put({iv:b64(iv),cipher:b64(bytes)},profile.id));
 }finally{db.close();}
}
async function removeLocal(email){
 const db=await openDB();try{
 const key=await transaction(db,'keys','readonly',o=>o.get('master'));if(!key)return;
 const entries=await transaction(db,'profiles','readonly',o=>o.getAllKeys());
 for(const id of entries||[]){
  const row=await transaction(db,'profiles','readonly',o=>o.get(id));
  try{
   const iv=Uint8Array.from(atob(row.iv),c=>c.charCodeAt(0));
   const bytes=Uint8Array.from(atob(row.cipher),c=>c.charCodeAt(0));
   const profile=JSON.parse(new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv},key,bytes)));
   // An AE2 revocation must never delete a separate Face/PIN sign-in profile.
   if(profile.source==='agent_eyes'&&profile.email?.toLowerCase()===email.toLowerCase())
    await transaction(db,'profiles','readwrite',o=>o.delete(id));
  }catch{}
 }
 }finally{db.close();}
}
async function captureOne(){
 const room=window.GelatoRoomPresence;
 if(!room?.active)throw Error('Start the room camera first.');
 const video=root.querySelector('video');
 if(!video||!video.videoWidth||!window.faceapi)throw Error('Camera or local face model is not ready.');
 const results=await window.faceapi.detectAllFaces(video,new window.faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:.55}))
   .withFaceLandmarks().withFaceDescriptors();
 if(results.length!==1||!results[0].descriptor)throw Error('Exactly one visible face is required.');
 const vector=Array.from(results[0].descriptor);
 if(vector.length!==128||!vector.every(Number.isFinite))throw Error('Invalid face sample.');
 if(vectors.length&&dist(vector,vectors[0])>=.43)throw Error('Face samples were inconsistent; restart enrollment.');
 return vector;
}
function updateStage(){
 report(step<3?stages[step]+' · capture '+(step+1)+' of 3':'Three consistent samples collected. Save enrollment.');
 capture.textContent=step<3?'Capture sample '+(step+1):'Save enrollment';
}
function clear(){step=0;vectors=[];if(consent)consent.checked=false;updateStage();}
function hideInvitation(){
 invitationTrack='';firstSeenAt=0;
 if(invitation)invitation.hidden=true;
}
function onRoom(room){
 if(!window.GelatoRoomPresence?.active||room?.status!=='active'||modal&&!modal.hidden){hideInvitation();return;}
 // Shared camera/mic cannot reliably attribute a request when multiple people are visible.
 const present=Array.isArray(room.people)?room.people:[];
 const p=present.length===1?present[0]:null;
 const track=p?.identitySource==='unknown'&&p?.identityAssurance==='none'
   &&stableTrack.test(String(p?.trackId||''))?String(p.trackId):'';
 if(!track||declinedTracks.has(track)){hideInvitation();return;}
 if(invitationTrack!==track){invitationTrack=track;firstSeenAt=Date.now();invitation.hidden=true;return;}
 if(Date.now()-firstSeenAt<3000)return;
 invitation.hidden=false;invitationMessage.textContent=enrollmentOffer;
 if(!spokenTracks.has(track)&&Date.now()-lastSpokenAt>=60000
    &&window.GelatoGlobalAgent?.proactiveGreetingEnabled===true){
   if(window.GelatoGlobalAgent?.proactiveEnrollmentInvite?.()===true){
    lastSpokenAt=Date.now();spokenTracks.add(track);
   }
 }
}
function openForInvitation(){
 if(!invitationTrack||!window.GelatoRoomPresence?.active)return false;
 const track=invitationTrack;
 window.GelatoRoomPresence.openEyes?.();
 if(modal.hidden){modal.hidden=false;clear();void loadCandidates().catch(e=>report(e.message));}
 declinedTracks.add(track);hideInvitation();
 return true;
}
function resetRoom(){
 hideInvitation();declinedTracks.clear();spokenTracks.clear();
 if(modal&&!modal.hidden){clear();modal.hidden=true;}
}
async function loadCandidates(){
 people=await candidates();select.replaceChildren();
 const prompt=document.createElement('option');prompt.value='';prompt.textContent='Select an existing person';select.append(prompt);
 for(const person of people){const opt=document.createElement('option');opt.value=String(person.id);
 opt.textContent=person.name+' ('+person.email+')'+(person.enrolled?' · enrolled':'');select.append(opt);}
}
async function onCapture(){
 capture.disabled=true;
 try{
  if(!consent.checked)throw Error('Confirm this participant gave consent.');
  const selected=people.find(p=>String(p.id)===select.value);
  if(!selected)throw Error('Select the correct participant.');
  if(step<3){
   if(step===0&&(await localEmails()).has(selected.email.toLowerCase()))
    throw Error('A local face profile already exists for this account. Do not create a duplicate.');
   vectors.push(await captureOne());step++;updateStage();return;
  }
  if(vectors.length!==3)throw Error('Three samples are required.');
  const vector=vectors[0].map((_,i)=>(vectors[0][i]+vectors[1][i]+vectors[2][i])/3);
  const answer=await post('enroll_commit',{personId:Number(selected.id),consent:true});
  try{await storeLocal({...answer.person},vector);}
  catch(e){await post('enroll_revoke',{personId:Number(selected.id)}).catch(()=>{});throw e;}
  clear();report('Enrollment saved locally. Recognition remains provisional.');
  await loadCandidates();
  await window.GelatoRoomPresence?.refreshProfiles?.();
 }catch(e){report(e.message);}finally{capture.disabled=false;}
}
async function revoke(){
 const selected=people.find(p=>String(p.id)===select.value);
 if(!selected||!selected.enrolled){report('Select an AE2-enrolled participant.');return;}
 if(!consent.checked){report('Confirm participant consent or revocation request.');return;}
 try{const response=await post('enroll_revoke',{personId:Number(selected.id)});
 await removeLocal(response.email);clear();report('Agent Eyes enrollment revoked on this terminal.');
 await loadCandidates();await window.GelatoRoomPresence?.refreshProfiles?.();
 }catch(e){report(e.message);}
}
function mount(){
 invitation=document.createElement('section');invitation.id='fm-eyes-enrollment-invitation';invitation.hidden=true;
 invitation.setAttribute('role','status');invitation.setAttribute('aria-label','Optional participant enrollment');
 invitation.style.cssText='border:1px solid #6b8aa1;border-radius:12px;padding:10px;margin:10px 0;background:#15293b';
 invitationMessage=document.createElement('p');invitationMessage.id='fm-eyes-enrollment-invitation-text';
 invitationMessage.textContent=enrollmentOffer;invitation.append(invitationMessage);
 const accept=document.createElement('button');accept.type='button';accept.id='fm-eyes-invitation-enroll';accept.textContent='Start consented enrollment';
 accept.addEventListener('click',openForInvitation);
 const decline=document.createElement('button');decline.type='button';decline.id='fm-eyes-invitation-decline';decline.textContent='Not now';
 decline.addEventListener('click',()=>{if(invitationTrack)declinedTracks.add(invitationTrack);hideInvitation();});
 invitation.append(accept,decline);root.querySelector('.fm-eyes-list')?.before(invitation);
 const button=document.createElement('button');button.type='button';button.textContent='Enroll participant';
 button.id='fm-eyes-enroll-open';root.querySelector('.fm-eyes-head')?.append(button);
 modal=document.createElement('section');modal.id='fm-eyes-enrollment';modal.hidden=true;
 modal.setAttribute('role','region');modal.setAttribute('aria-label','Participant enrollment');
 modal.style.cssText='border:1px solid #6b8aa1;border-radius:12px;padding:10px;margin-top:10px;background:#15293b';
 const heading=document.createElement('strong');heading.textContent='Guided face enrollment';modal.append(heading);
 const note=document.createElement('p');note.textContent='A consenting participant must be present. Select their existing Gelato account. Face descriptors stay encrypted on this browser; enrollment never grants login or Agent permissions.';modal.append(note);
 select=document.createElement('select');select.id='fm-eyes-person';select.setAttribute('aria-label','Select participant');select.style.maxWidth='100%';modal.append(select);
 const consentLabel=document.createElement('label');consentLabel.style.display='block';consent=document.createElement('input');consent.type='checkbox';consent.id='fm-eyes-consent';
 consentLabel.append(consent,document.createTextNode(' Participant is present and explicitly consents to local face recognition.'));modal.append(consentLabel);
 status=document.createElement('p');status.id='fm-eyes-enroll-status';status.setAttribute('role','status');modal.append(status);
 capture=document.createElement('button');capture.type='button';capture.id='fm-eyes-capture';capture.addEventListener('click',()=>void onCapture());modal.append(capture);
 const revokeButton=document.createElement('button');revokeButton.type='button';revokeButton.id='fm-eyes-revoke';revokeButton.textContent='Revoke';revokeButton.addEventListener('click',()=>void revoke());modal.append(revokeButton);
 const close=document.createElement('button');close.type='button';close.textContent='Done';close.addEventListener('click',()=>{clear();modal.hidden=true;});modal.append(close);
 root.append(modal);updateStage();
 button.addEventListener('click',async()=>{modal.hidden=!modal.hidden;clear();if(!modal.hidden)try{await loadCandidates();}catch(e){report(e.message);}});
 select.addEventListener('change',clear);
}
mount();
window.GelatoEyesEnrollment={onRoom,resetRoom,openForInvitation,get open(){return !!modal&&!modal.hidden;}};
})();
