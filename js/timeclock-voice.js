(() => {
'use strict';
const C=window.GELATO_TIME_CLOCK;if(!C)return;let state=null;let voiceEventId='';let voiceVerifiedUntil=0;let listening=false;let recognizer=null;let proactiveTimer=null;let savedListening=false;let wakeStandby=false;let microphoneActive=false;let voiceAlertsEnabled=false;
const $=s=>document.querySelector(s);const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function toast(m){const e=$('#toast');if(!e)return;e.textContent=m;e.classList.remove('hidden');setTimeout(()=>e.classList.add('hidden'),3000)}function status(m){const e=$('#agentStatus');if(!e)return;e.textContent=m;e.style.display=m?'block':'none'}
async function json(url,opt={}){const r=await fetch(url,{cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':C.csrf,...(opt.headers||{})},...opt});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Request failed.');return d}
async function post(url,body){return json(url,{method:'POST',body:JSON.stringify({...body,csrf_token:C.csrf})})}
const fmt=t=>t?new Intl.DateTimeFormat('en-US',{weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(String(t).replace(' ','T'))):'—';const duration=m=>`${Math.floor(m/60)}h ${m%60}m`;
async function load(){try{const date=$('#dateFilter')?.value||C.today||new Date().toISOString().slice(0,10);state=await json(`api/timeclock.php?action=bootstrap&date=${encodeURIComponent(date)}`);render()}catch(e){toast(e.message)}}
function render(){if(!state)return;const clock=state.self?.clock;$('#clockStatus')&&($('#clockStatus').textContent=clock?'Clocked in':'Off clock');$('#clockDetail')&&($('#clockDetail').textContent=clock?`Since ${fmt(clock.clocked_in_at)}${clock.shift_title?' · '+clock.shift_title:''}`:'Ready when your shift begins.');if($('#clockIn'))$('#clockIn').disabled=!!clock;if($('#clockOut'))$('#clockOut').disabled=!clock;const shifts=(state.self?.shifts||[]).filter(s=>new Date(String(s.ends_at).replace(' ','T'))>=new Date()).sort((a,b)=>String(a.starts_at).localeCompare(String(b.starts_at)));$('#nextShift')&&($('#nextShift').textContent=shifts[0]?new Intl.DateTimeFormat('en-US',{weekday:'short',hour:'numeric',minute:'2-digit'}).format(new Date(String(shifts[0].starts_at).replace(' ','T'))):'—');$('#nextShiftDetail')&&($('#nextShiftDetail').textContent=shifts[0]?`${shifts[0].title} · ${fmt(shifts[0].starts_at)}`:'No remaining shift this week.');$('#taskCount')&&($('#taskCount').textContent=String(state.self?.tasks?.length||0));$('#taskDetail')&&($('#taskDetail').textContent=(state.self?.tasks||[]).slice(0,3).map(t=>t.title).join(' · ')||'No open assigned tasks.');if(state.summary){const d=state.summary.actualMinutes-state.summary.scheduledMinutes;$('#laborDelta')&&($('#laborDelta').textContent=(d>=0?'+':'')+duration(Math.abs(d)));$('#laborDetail')&&($('#laborDetail').textContent=`${duration(state.summary.scheduledMinutes)} scheduled · ${duration(state.summary.actualMinutes)} actual · ${state.summary.clockedInEmployees} on clock`)}renderEntries();renderVoiceProfile()}
function renderEntries(){const root=$('#attendanceRows');if(!root)return;const rows=state.entries||[];root.innerHTML=rows.length?rows.map(r=>`<article class="row" data-timeclock-user-id="${Number(r.user_id)||0}"><strong>${esc(r.display_name)}</strong><span>${esc(r.shift_title||'Unscheduled')}</span><span>${fmt(r.clocked_in_at)}</span><span>${r.clocked_out_at?fmt(r.clocked_out_at):'<span class="pill good">On clock</span>'}</span><span>${esc(r.status)}</span></article>`).join(''):'<div class="muted" style="padding:18px">No time-clock entries for this date.</div>'}
function renderVoiceProfile(){const p=state.voiceProfile;const e=$('#voiceProfileStatus');if(!e)return;e.textContent=p?`${p.status==='active'?'Enrolled':'Disabled'} · ${p.sample_count||0} sample(s)${p.last_verified_at?' · last checked '+fmt(p.last_verified_at):''}`:'Not enrolled.'}
async function timeAction(action){try{await post('api/timeclock.php',{action});toast(action.replace('_',' ')+' complete');await load()}catch(e){toast(e.message)}}
async function captureFeatures(ms=3500,wantBlob=false){if(!navigator.mediaDevices?.getUserMedia)throw new Error('Microphone capture is not available in this browser.');const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:false}});const ctx=new (window.AudioContext||window.webkitAudioContext)();const src=ctx.createMediaStreamSource(stream);const an=ctx.createAnalyser();an.fftSize=1024;an.smoothingTimeConstant=.35;src.connect(an);const bins=new Uint8Array(an.frequencyBinCount);const bands=new Float64Array(24);let frames=0;let recorder=null,chunks=[];if(wantBlob&&window.MediaRecorder){recorder=new MediaRecorder(stream);recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};recorder.start(200)}const meter=$('#voiceMeter i');const timer=setInterval(()=>{an.getByteFrequencyData(bins);let energy=0;for(let b=0;b<24;b++){const lo=Math.floor(b*bins.length/24),hi=Math.floor((b+1)*bins.length/24);let s=0;for(let i=lo;i<hi;i++)s+=bins[i];const v=s/Math.max(1,hi-lo)/255;bands[b]+=v;energy+=v}frames++;if(meter)meter.style.width=`${Math.min(100,Math.round(energy/24*180))}%`},80);await new Promise(r=>setTimeout(r,ms));clearInterval(timer);if(recorder&&recorder.state!=='inactive'){await new Promise(resolve=>{recorder.onstop=resolve;recorder.stop()})}stream.getTracks().forEach(t=>t.stop());await ctx.close();if(meter)meter.style.width='0';let v=Array.from(bands,x=>x/Math.max(1,frames));const n=Math.sqrt(v.reduce((s,x)=>s+x*x,0));if(n<.03)throw new Error('Voice sample was too quiet. Try again closer to the microphone.');v=v.map(x=>x/n);return {features:v,blob:wantBlob&&chunks.length?new Blob(chunks,{type:chunks[0].type||'audio/webm'}):null,durationMs:ms}}
async function enrollVoice(){if(!confirm('Enroll your voice for Gelato personalization? Voice recognition will not replace your login, permissions, or manager approvals.'))return;try{status('Recording enrollment sample… speak naturally for a few seconds.');const cap=await captureFeatures(4500,true);if(!cap.blob)throw new Error('This browser cannot create a voice recording.');const f=new FormData();f.append('action','enroll');f.append('consent','1');f.append('features',JSON.stringify(cap.features));f.append('durationMs',String(cap.durationMs));f.append('transcript','Employee voice enrollment sample');f.append('csrf_token',C.csrf);f.append('audio',cap.blob,'voice-enrollment.webm');const r=await fetch('api/voice-identity.php',{method:'POST',headers:{'X-CSRF-Token':C.csrf,Accept:'application/json'},body:f});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Enrollment failed.');toast(d.message);await load()}catch(e){toast(e.message)}finally{status('')}}
async function verifyVoice(){status('Voice check… speak the phrase “Hey Gelato, ready to work.”');try{const cap=await captureFeatures(3200,false);const d=await post('api/voice-identity.php',{action:'verify',features:cap.features});if(!d.match.matched||!d.match.sessionUserMatch)throw new Error('Voice did not match your enrolled profile. No identity or permissions were changed.');voiceEventId=d.match.eventId;voiceVerifiedUntil=Date.now()+14*60*1000;toast(`Voice recognized (${Math.round(d.match.confidence*100)}% match).`);return true}catch(e){voiceEventId='';voiceVerifiedUntil=0;toast(e.message);return false}finally{status('')}}
function haveVoice(){return !!voiceEventId&&Date.now()<voiceVerifiedUntil}
async function sendAgent(text,voice=false){if(!text.trim())return;status('Gelato is checking your restaurant context…');try{const pageContext=window.GelatoTimeclockAgentContext?.transportSnapshot?.()||{module:'timeclock',selectedDate:$('#dateFilter')?.value||C.today||null};const d=await post('api/timeclock-agent.php',{message:text,voice,voiceEventId:voice?voiceEventId:'',pageContext});status(d.answer);if(voice&&$('#speakAlerts')?.checked)speak(d.answer);await load();setTimeout(()=>status(''),7000)}catch(e){status(e.message)}}
function speak(text){if(window.GelatoVoiceTurns?.speak)return window.GelatoVoiceTurns.speak(text,{owner:'timeclock'});if(!('speechSynthesis'in window))return;window.speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(text);u.rate=1;window.speechSynthesis.speak(u)}
function recognitionOnce(){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){toast('Browser speech recognition is not available.');return;}const r=new SR();r.lang='en-US';r.interimResults=false;r.continuous=false;status('Listening for your command…');r.onresult=e=>{const text=[...e.results].map(x=>x[0].transcript).join(' ');sendAgent(text,true)};r.onerror=e=>toast('Speech recognition: '+e.error);r.onend=()=>{if(!listening)setTimeout(()=>status(''),900)};r.start()}
async function talkOnce(){if(!haveVoice()){const ok=await verifyVoice();if(!ok)return;}recognitionOnce()}
function sharedAgentAvailable(){
 const permissions=window.GelatoGlobalAgent?.permissions||[];
 return C.canProactive&&(permissions.includes('*')||(permissions.includes('agent.proactive')&&permissions.includes('voice.agent')));
}
function syncSharedListening(detail){
 if(!detail)return;
 listening=!!(detail.listenActive??detail.listening);
 savedListening=!!detail.savedListening;wakeStandby=!!detail.wakeStandby;
 microphoneActive=!!detail.microphoneActive;voiceAlertsEnabled=!!detail.proactiveVoiceEnabled;
 if($('#speakAlerts'))$('#speakAlerts').checked=voiceAlertsEnabled;
 renderListening();
}
function renderListening(){
 const d=window.GelatoProactiveCommands?.display?.({
   saved:savedListening,active:listening&&microphoneActive,wake:wakeStandby&&microphoneActive,hidden:document.hidden
 })||{code:listening?'listening':'off',label:listening?'Actively listening':'Microphone off',message:''};
 const note=$('#proactiveListeningStatus');if(note){
   note.dataset.state=d.code;
   note.textContent=d.label+'. '+(d.message||'')+
     (savedListening&&!microphoneActive?' Listening preference saved for this user.':'');
 }
 const button=$('#listeningToggle');if(button){
   button.textContent=listening?'Stop Listening':savedListening?'Resume saved Listening':'Start Listening';
   button.setAttribute('aria-pressed',listening?'true':'false');
   button.disabled=!C.canProactive;
 }
 const standby=$('#wakeStandbyToggle');if(standby){
   standby.textContent=wakeStandby&&!listening?'Wake standby active':'Enable Hey Gelato Wake';
   standby.setAttribute('aria-pressed',wakeStandby&&!listening?'true':'false');
   standby.disabled=!C.canProactive;
 }
 const off=$('#microphoneOff');if(off)off.disabled=!C.canProactive||(!microphoneActive&&!listening&&!wakeStandby&&!savedListening);
 const alert=$('#speakAlerts');if(alert)alert.disabled=!C.canProactive;
}
async function loadProactivePreferences(){
 if(!C.canProactive){renderListening();return;}
 try{
   const d=await json('api/proactive-agent.php?action=preferences');
   savedListening=!!Number(d.preferences?.listening_enabled||0);
   voiceAlertsEnabled=!!Number(d.preferences?.proactive_voice_enabled||0);
   if($('#speakAlerts'))$('#speakAlerts').checked=voiceAlertsEnabled;
   // Saved preference is not microphone permission. No automatic start.
   renderListening();
 }catch(e){status('Could not load listening preferences: '+e.message);}
}
async function setListening(on,{keepWake=false}={}){
 if(!C.canProactive){toast('Proactive Agent permission is required.');return false;}
 if(sharedAgentAvailable()){
   stopContinuous();stopProactive();
   const ok=await window.GelatoGlobalAgent.setListening(!!on,{keepWake:!!keepWake});
   syncSharedListening(window.GelatoGlobalAgent.listeningStatus);
   return ok;
 }
 if(on){
   if(!(window.SpeechRecognition||window.webkitSpeechRecognition)){
     toast('Browser speech recognition is unsupported. Saved preference unchanged.');return false;
   }
   if(!haveVoice()){
     stopContinuous();
     if(!await verifyVoice()){renderListening();return false;}
   }
 }
 try{
   const d=await post('api/proactive-agent.php',{action:'preferences',
     listeningEnabled:!!on,proactiveVoiceEnabled:!!$('#speakAlerts')?.checked,wakePhrase:'Hey Gelato'});
   savedListening=!!Number(d.preferences?.listening_enabled??Number(!!on));
   voiceAlertsEnabled=!!Number(d.preferences?.proactive_voice_enabled||0);
   listening=!!on;wakeStandby=!!(on||keepWake&&recognizer);
   if(on){if(!recognizer)startContinuous();startProactive();}
   else{stopProactive();if(!keepWake||!recognizer){wakeStandby=false;stopContinuous();}}
   renderListening();
   status(on?'Listening active. Say “Hey Gelato, stop listening”.':
     wakeStandby?'Wake standby. Say “Hey Gelato, start listening” or Microphone Off.':'Microphone off.');
   return true;
 }catch(error){toast(error.message);return false;}
}
async function enableWakeStandby(){
 if(!C.canProactive){toast('Proactive Agent permission required.');return false;}
 if(sharedAgentAvailable()){
   stopContinuous();stopProactive();
   const ok=await window.GelatoGlobalAgent.enableWakeStandby();
   syncSharedListening(window.GelatoGlobalAgent.listeningStatus);return ok;
 }
 if(!(window.SpeechRecognition||window.webkitSpeechRecognition)){toast('Speech recognition not supported.');return false;}
 if(!haveVoice()){stopContinuous();if(!await verifyVoice())return false;}
 listening=false;wakeStandby=true;stopProactive();
 if(!recognizer)startContinuous();renderListening();
 status('Wake standby · microphone on. Say “Hey Gelato, start listening”.');
 return true;
}
async function microphoneOff(){return setListening(false,{keepWake:false});}
function diag(kind,details){window.GelatoVoiceDiagnostics?.record?.('timeclock',kind,details)}
function startContinuous(){
 const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
 if(!SR||document.hidden||(!listening&&!wakeStandby))return false;
 stopContinuous();
 const r=new SR();recognizer=r;diag('starting');r.lang='en-US';r.continuous=true;r.interimResults=true;r.maxAlternatives=1;
 r.onstart=()=>{if(recognizer!==r)return;microphoneActive=true;diag('active');renderListening();};
 r.onresult=e=>{
   if(recognizer!==r)return;
   for(let i=e.resultIndex;i<e.results.length;i++){
     const result=e.results[i],text=String(result[0]?.transcript||'').trim();
     if(!text)continue;
     const evaluation=window.GelatoVoiceTurns?.evaluate?.(text,result[0]?.confidence,{wakeRequired:true});
     if(evaluation?.kind==='ignored'){if(['tts-echo','post-tts-echo'].includes(evaluation.reason))diag('echo-ignored');continue}if(!result.isFinal)continue;
     const command=window.GelatoProactiveCommands?.parse?.(text,'Hey Gelato');
     if(command==='start'){if(!listening)void setListening(true);return;}
     if(command==='stop'){if(listening)void setListening(false,{keepWake:true});return;}
     if(command==='off'){void microphoneOff();return;}
     if(evaluation?.kind==='interrupt'){window.GelatoVoiceTurns?.interrupt?.('timeclock-barge-in');diag('barge-in');continue;}
     if(!listening||!/^hey\s+gelato\b/i.test(text))continue;
     if(!haveVoice()){
       void microphoneOff();
       status('Voice verification expired. Click Start Listening to verify again.');
       return;
     }
     void sendAgent(text.replace(/^hey\s+gelato\s*/i,''),true);
   }
 };
 r.onerror=e=>{
   if(recognizer!==r)return;diag('error',{error:String(e.error||'unknown')});
   if(['not-allowed','service-not-allowed','audio-capture'].includes(e.error)){
     stopContinuous();listening=false;wakeStandby=false;stopProactive();diag('blocked',{error:String(e.error||'unknown')});
     renderListening();status('Microphone blocked: '+e.error+'. Check browser permissions.');
   }else if(!['no-speech','aborted'].includes(e.error))status('Listening: '+e.error);
 };
 r.onend=()=>{
   if(recognizer!==r)return;
   microphoneActive=false;diag('ended');renderListening();
   if((listening||wakeStandby)&&!document.hidden)
     setTimeout(()=>{if(recognizer===r&&(listening||wakeStandby)&&!document.hidden){try{diag('retry');r.start()}catch{diag('failed',{error:'start-failed'});listening=false;wakeStandby=false;stopProactive();stopContinuous();}}},650);
 };
 try{r.start();return true;}catch(error){diag('failed',{error:'start-failed'});stopContinuous();toast(error.message);return false;}
}
function stopContinuous(){
 if(recognizer){const old=recognizer;recognizer=null;old.onend=null;old.onerror=null;old.onresult=null;
   try{if(typeof old.abort==='function')old.abort();else old.stop();}catch{}}
 microphoneActive=false;diag('off');renderListening();
}
function startProactive(){stopProactive();pollProactive();proactiveTimer=setInterval(pollProactive,45000)}function stopProactive(){if(proactiveTimer)clearInterval(proactiveTimer);proactiveTimer=null}
async function pollProactive(){if(!listening||!C.canProactive||sharedAgentAvailable())return;try{const d=await json('api/proactive-agent.php');for(const ev of d.events||[]){status('Gelato: '+ev.message);if(voiceAlertsEnabled)speak(ev.message)}}catch(e){console.warn('Proactive Agent',e)}}
async function voiceSimple(action){try{const d=await post('api/voice-identity.php',{action});toast(d.message);voiceEventId='';voiceVerifiedUntil=0;if(listening||wakeStandby)await microphoneOff();await load()}catch(e){toast(e.message)}}
async function loadVoiceRoster(){try{const d=await json('api/voice-identity.php?action=roster');const root=$('#voiceRoster');root.innerHTML=d.staff.length?d.staff.map(s=>`<article class="row"><strong>${esc(s.display_name)}</strong><span class="pill ${s.voice_status==='active'?'good':''}">${esc(s.voice_status)}</span><span>${s.sample_count||0} samples</span><span>${s.enrolled_at?fmt(s.enrolled_at):'—'}</span><span>${s.last_verified_at?fmt(s.last_verified_at):'Never checked'}</span></article>`).join(''):'<div class="muted" style="padding:18px">No staff records.</div>'}catch(e){toast(e.message)}}
$('#refresh')?.addEventListener('click',load);$('#dateFilter')?.addEventListener('change',load);$('#clockIn')?.addEventListener('click',()=>timeAction('clock_in'));$('#clockOut')?.addEventListener('click',()=>timeAction('clock_out'));$('#breakToggle')?.addEventListener('click',()=>timeAction('break_start'));$('#enrollVoice')?.addEventListener('click',enrollVoice);$('#verifyVoice')?.addEventListener('click',verifyVoice);$('#disableVoice')?.addEventListener('click',()=>voiceSimple('disable'));$('#deleteVoice')?.addEventListener('click',()=>{if(confirm('Delete your enrolled voice profile and stored voice recordings?'))voiceSimple('delete')});$('#listeningToggle')?.addEventListener('click',()=>setListening(!listening,{keepWake:listening}));
$('#wakeStandbyToggle')?.addEventListener('click',enableWakeStandby);
$('#microphoneOff')?.addEventListener('click',microphoneOff);
$('#speakAlerts')?.addEventListener('change',async()=>{
 if(sharedAgentAvailable()){
   await window.GelatoGlobalAgent.updateProactiveVoice(!!$('#speakAlerts').checked);
   syncSharedListening(window.GelatoGlobalAgent.listeningStatus);
 }else if(C.canProactive){
   try{const d=await post('api/proactive-agent.php',{action:'preferences',
     listeningEnabled:savedListening,proactiveVoiceEnabled:!!$('#speakAlerts').checked,wakePhrase:'Hey Gelato'});
     voiceAlertsEnabled=!!Number(d.preferences?.proactive_voice_enabled||0);
   }catch(error){toast(error.message);$('#speakAlerts').checked=voiceAlertsEnabled;}
 }
});$('#talkButton')?.addEventListener('click',talkOnce);$('#loadVoiceRoster')?.addEventListener('click',loadVoiceRoster);window.addEventListener('gelato-agent-listening-change',event=>{
 if(sharedAgentAvailable()){stopContinuous();stopProactive();syncSharedListening(event.detail);}
});
window.addEventListener('gelato-agent-ready',()=>{
 if(sharedAgentAvailable())syncSharedListening(window.GelatoGlobalAgent.listeningStatus);
});
document.addEventListener('visibilitychange',()=>{
 if(document.hidden){stopContinuous();if(listening||wakeStandby)status('Microphone paused · tab hidden.');}
 else if((listening||wakeStandby)&&!sharedAgentAvailable())startContinuous();
});
window.addEventListener('beforeunload',()=>{stopContinuous();stopProactive()});
load();void loadProactivePreferences();
})();