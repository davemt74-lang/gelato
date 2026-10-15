/* FM-G8D1: consented short voice/face capture, grounded in Gelato's existing
   authenticated participant goal and Time Clock voice profile system. */
(()=>{'use strict';
if(!document.body.classList.contains('food-monster-stage-page')||window.GelatoFoodMonsterFastEnrollment)return;
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let busy=false;const csrf=()=>document.querySelector('meta[name="gelato-csrf"]')?.content||'';
const current=()=>window.GelatoFoodMonsterGoals?.goal;
function self(){
 const g=current();if(!g||g.status!=='in_progress'||g.employeeUserId!==Number(window.GelatoGlobalAgent?.user?.id))
  throw Error('The employee must sign into their own Gelato account to enroll voice or face.');
 return g;
}
function message(s){const el=document.querySelector('.fm-goal-prompt');if(el)el.textContent=s;
 const status=document.querySelector('.fm-speaker-result');if(status&&!status.closest('[hidden]'))status.textContent=s;}
function speechBlocked(){return !!(window.GelatoVoiceTurns?.speaking||window.speechSynthesis?.speaking);}
async function captureVoice(ms,record){
 if(speechBlocked())throw Error('Wait for Food Monster to finish speaking.');
 if(!navigator.mediaDevices?.getUserMedia||!(window.AudioContext||window.webkitAudioContext))
  throw Error('HTTPS and microphone access are required.');
 if(record&&!window.MediaRecorder)throw Error('Voice recording is unavailable in this browser.');
 const agent=window.GelatoGlobalAgent;
 if(agent?.conversationMode)await agent.setConversationMode(false);
 if(agent?.listening)await agent.setListening(false,{keepWake:false});
 const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true},video:false});
 let context,recorder,timer,meter;
 try{
  context=new (window.AudioContext||window.webkitAudioContext)();
  const analyser=context.createAnalyser();analyser.fftSize=1024;
  context.createMediaStreamSource(stream).connect(analyser);
  const bins=new Uint8Array(analyser.frequencyBinCount),bands=new Float64Array(24);
  let frames=0,chunks=[];
  meter=document.createElement('div');meter.className='fm-goal-samples';
  meter.innerHTML='<span>Microphone level</span><i style="width:0%"></i>';
  document.querySelector('.fm-goal-body')?.prepend(meter);
  if(record){
   recorder=new MediaRecorder(stream);recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
   recorder.start(200);
  }
  timer=setInterval(()=>{
   analyser.getByteFrequencyData(bins);
   let energy=0;
   for(let band=0;band<24;band++){
    const lo=Math.floor(band*bins.length/24),hi=Math.floor((band+1)*bins.length/24);
    let sum=0;for(let j=lo;j<hi;j++)sum+=bins[j];
    const v=sum/Math.max(1,hi-lo)/255;bands[band]+=v;energy+=v;
   }frames++;
   if(meter?.isConnected)meter.querySelector('i').style.width=Math.min(100,Math.round(energy/24*170))+'%';
  },80);
  message('Say: "Hello Gelato, I am ready to work."');
  await wait(ms);
  if(document.hidden||!stream.active||speechBlocked())throw Error('Voice capture was interrupted; retry.');
  clearInterval(timer);timer=null;
  if(recorder?.state==='recording')await new Promise(resolve=>{recorder.onstop=resolve;recorder.stop()});
  const vector=Array.from(bands,x=>x/Math.max(frames,1));
  const norm=Math.sqrt(vector.reduce((s,x)=>s+x*x,0));
  if(norm<.035)throw Error('Voice too quiet; come closer to the microphone.');
  const blob=record?new Blob(chunks,{type:recorder.mimeType||'audio/webm'}):null;
  if(record&&(!blob||blob.size<1000))throw Error('Voice recording too short.');
  return {features:vector.map(v=>v/norm),blob,durationMs:ms};
 }finally{
  if(timer)clearInterval(timer);
  if(recorder?.state==='recording')try{recorder.stop()}catch{}
  stream.getTracks().forEach(t=>t.stop());
  await context?.close?.().catch(()=>{});
  meter?.remove();
 }
}
async function capturePhotos(){
 const g=self();if(!g.photoConsent)throw Error('First confirm permission to save photos.');
 if(busy)throw Error('Capture is already running.');busy=true;
 try{
  const camera=window.GelatoFoodMonsterCamera,parts=window.GelatoFoodMonsterParticipants;
  if(!camera||!parts)throw Error('Camera tracker unavailable.');
  if(!camera.active&&!await camera.start())throw Error('Enable camera permission.');
  for(let i=g.photos.length;i<3;i++){
   message(['Look straight ahead','Turn slightly left','Turn slightly right'][i]+': capturing '+(i+1)+' of 3');
   let picture='',end=Date.now()+4200;
   while(Date.now()<end&&!picture){
    if(document.hidden||!camera.active)throw Error('Camera stopped.');
    if(parts.count===1)picture=parts.captureGoalPhoto(window.GelatoFoodMonsterGoals.trackId);
    if(!picture)await wait(220);
   }
   if(!picture)return {ok:false,reason:'lighting_or_multiple_people'};
   await window.GelatoFoodMonsterGoals.request('photo',{goal:current().id,image:picture});
   if(i<2)await wait(650);
  }
  message('Three photos saved securely.');
  return {ok:true};
 }finally{busy=false;}
}
async function enrollVoice(){
 const g=self();if(!g.voiceConsent)throw Error('Confirm voice consent first.');
 if(busy)throw Error('Capture already running.');busy=true;
 try{
  const v=await captureVoice(3600,true),form=new FormData();
  form.append('action','enroll');form.append('consent','1');
  form.append('features',JSON.stringify(v.features));form.append('durationMs',String(v.durationMs));
  form.append('transcript','Short participant enrollment phrase');form.append('csrf_token',csrf());
  form.append('audio',v.blob,'participant.webm');
  const response=await fetch('api/voice-identity.php',{method:'POST',credentials:'same-origin',cache:'no-store',
   headers:{Accept:'application/json'},body:form});
  const result=await response.json().catch(()=>({}));
  if(!response.ok||!result.ok)throw Error(result.message||'Voice enrollment did not complete.');
  await window.GelatoFoodMonsterGoals.load();message('Voice profile saved to your Gelato account.');
  return {ok:true};
 }finally{busy=false;}
}
async function verifyVoice(){
 const g=self();if(!g.voiceVerified)throw Error('Enroll voice before verifying.');
 if(busy)throw Error('Microphone already in use.');busy=true;
 try{
  const v=await captureVoice(3000,false);
  const response=await fetch('api/voice-identity.php',{method:'POST',credentials:'same-origin',
   headers:{Accept:'application/json','Content-Type':'application/json'},
   body:JSON.stringify({action:'verify',features:v.features,matchAny:false,csrf_token:csrf()})});
  const result=await response.json().catch(()=>({}));
  if(!response.ok||!result.ok)throw Error(result.message||'Could not verify voice.');
  const match=result.match||{},ok=match.matched&&match.sessionUserMatch&&match.userId===g.employeeUserId;
  message(ok?'Enrolled account voice matched (not login or visual ID).':'Voice could not be verified; speaker remains unknown.');
  return {ok:!!ok};
 }finally{busy=false;}
}
async function identifySpeaker(){
 if(busy)throw Error('Capture already running.');
 if(!document.querySelector('[data-fm-speaker-consent]')?.checked)
  throw Error('Ask the speaker for consent and check the box first.');
 busy=true;
 try{
  const v=await captureVoice(3000,false);
  const res=await fetch('api/food-monster-speaker.php',{method:'POST',credentials:'same-origin',cache:'no-store',
   headers:{Accept:'application/json','Content-Type':'application/json'},
   body:JSON.stringify({action:'identify',features:v.features,consent:true,csrf_token:csrf()})});
  const payload=await res.json().catch(()=>({}));
  if(!res.ok||!payload.ok)throw Error(payload.message||'Speaker matching unavailable.');
  const match=payload.match||{};
  window.dispatchEvent(new CustomEvent('gelato-food-monster-speaker-candidate',{detail:
    match.matched&&match.speaker?{name:match.speaker.name,userId:match.speaker.userId,score:match.score,
      expiresAt:Date.now()+15000,visualTrackAssociated:false}:{name:'',reason:match.reason||'unverified'}
   }));
  message(match.matched?'Possible speaker: '+match.speaker.name+' (voice-only cue, not face ID).':
   match.reason==='ambiguous'?'Multiple similar voice profiles; speaker unverified.':'No confident voice match.');
  return match;
 }finally{busy=false;}
}
async function runQuickSetup(){
 const g=self();if(!g.photoConsent||!g.voiceConsent)throw Error('Consent to both photo and voice enrollment first.');
 const start=Date.now();let photos={ok:true};
 if(g.photos.length<3){
  photos=await capturePhotos();
  if(!photos.ok)await window.GelatoFoodMonsterGoals.request('defer_photos',{goal:current().id,lowLight:true});
  else if(!window.GelatoFoodMonsterCamera?.enrolled)
   await window.GelatoFoodMonsterCamera.enroll({persist:true});
 }
 if(!current().voiceVerified)await enrollVoice();
 const elapsedMs=Date.now()-start;
 message((photos.ok?'Photos and voice captured. ':'Voice-first setup complete; photos deferred. ')+
  'Elapsed '+Math.round(elapsedMs/1000)+'s'+(elapsedMs>20000?' (above the 20s target).':'.'));
 return {ok:true,photos:photos.ok,elapsedMs};
}
window.GelatoFoodMonsterFastEnrollment={capturePhotos,enrollVoice,verifyVoice,identifySpeaker,runQuickSetup,get busy(){return busy;}};
})();
