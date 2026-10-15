/* FM-G8A: session-only participant tracking; not authentication or facial employee identification. */
(() => {
'use strict';
if(!document.body.classList.contains('food-monster-stage-page')||window.GelatoFoodMonsterParticipants)return;
const host=document.getElementById('food-monster-stage');if(!host)return;
const state={mode:'agent',camera:false,tracks:[],nextId:1,lastGreet:0,roster:null,
 thumbnails:false,roomMic:null,ctx:null,source:null,analyser:null,levelTimer:0,voiceLevel:0};
const el=document.createElement('section');el.className='fm-room-controls';el.setAttribute('aria-label','Food Monster camera controls');
el.innerHTML='<div class="fm-room-switch" role="group" aria-label="Food Monster view"><button type="button" data-fm-view="agent" aria-pressed="true">Agent</button><button type="button" data-fm-view="camera" aria-pressed="false">Camera</button></div>'+
 '<button type="button" data-fm-camera-toggle aria-pressed="false">Camera off</button><span class="fm-room-camera-state" role="status">Camera is off</span>';
host.append(el);
const cameraArea=document.createElement('section');cameraArea.className='fm-room-camera-view';cameraArea.hidden=true;
cameraArea.setAttribute('aria-label','Local tracked camera view');
cameraArea.innerHTML='<div class="fm-room-camera-frame"><video autoplay muted playsinline aria-label="Camera video"></video><canvas aria-hidden="true"></canvas><p class="fm-room-camera-empty">Camera is off. Enable it above to see tracked participants.</p></div>'+
 '<div class="fm-room-camera-legend">Tracking nodes show temporary, unverified people. Names require an authorized identity match. Frames stay on this device.</div>';
host.append(cameraArea);
const video=cameraArea.querySelector('video'),canvas=cameraArea.querySelector('canvas'),draw=canvas.getContext('2d');
const panel=document.createElement('aside');panel.className='fm-participants-panel';panel.setAttribute('aria-label','Active participants');
panel.innerHTML='<div class="fm-participants-top"><strong>Active participants</strong><span data-fm-count>0</span></div>'+
 '<p class="fm-participants-explain">Camera presence is not identity verification. Staff status comes from Gelato, not face appearance.</p>'+
 '<div class="fm-participant-options"><label><input type="checkbox" data-fm-photos> Capture local thumbnails</label><button type="button" data-fm-meter>Enable room voice meter</button></div>'+
 '<div class="fm-room-meter"><div class="fm-room-meter-bar"><i data-fm-level></i></div><small>Shared microphone · not attributed to a person</small></div>'+
 '<div class="fm-participant-cards" aria-live="polite"><p class="fm-participant-empty">Camera off · no active tracks</p></div>'+
 '<div class="fm-participant-roster"><strong>Gelato staff context</strong><p class="fm-staff-note">Checking authorized schedule and clock-in data…</p><div data-fm-roster></div></div>';
host.append(panel);
const cards=panel.querySelector('.fm-participant-cards'),count=panel.querySelector('[data-fm-count]'),roster=panel.querySelector('[data-fm-roster]');
const status=el.querySelector('.fm-room-camera-state'),cameraToggle=el.querySelector('[data-fm-camera-toggle]'),empty=cameraArea.querySelector('.fm-room-camera-empty');
const esc=str=>String(str||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function cameraAPI(){return window.GelatoFoodMonsterCamera;}
function setMode(mode){
 state.mode=mode==='camera'?'camera':'agent';document.body.classList.toggle('fm-view-camera',state.mode==='camera');
 cameraArea.hidden=state.mode!=='camera';
 for(const button of el.querySelectorAll('[data-fm-view]'))button.setAttribute('aria-pressed',String(button.dataset.fmView===state.mode));
 if(state.mode==='camera')attachVideo();render();
}
function clearTracks(){state.tracks.length=0;render();drawOverlay();} // Monotonic IDs for this page session
function attachVideo(){
 if(!state.camera)return;
 const stream=cameraAPI()?.stream;
 if(stream&&video.srcObject!==stream){video.srcObject=stream;void video.play().catch(()=>{});}
 else if(stream&&video.paused)void video.play().catch(()=>{});
}
function setCamera(active){
 state.camera=!!active;
 cameraToggle.setAttribute('aria-pressed',String(state.camera));cameraToggle.textContent=state.camera?'Camera on':'Camera off';
 status.textContent=state.camera?'Tracking active · local camera':'Camera is off';empty.hidden=state.camera;
 if(state.camera)attachVideo();else{video.pause();video.srcObject=null;clearTracks();}
 render();
}
function nearestTrack(box,used){
 let match=null,best=.38;
 for(const track of state.tracks){
  if(used.has(track))continue;
  const a=track.box,dx=(a.x+a.width/2)-(box.x+box.width/2),dy=(a.y+a.height/2)-(box.y+box.height/2),distance=Math.hypot(dx,dy);
  if(distance<best){match=track;best=distance;}
 }
 // Preserve a single stable participant across temporary model failures or
 // dramatic head motion. Do not merge two simultaneously visible people.
 if(!match&&state.tracks.length===1&&!used.size&&Date.now()-state.tracks[0].at<15000)match=state.tracks[0];
 return match;
}
function capture(track){
 // Explicit opt-in, memory-only 96px image. Never uploaded, persisted or sent to an LLM.
 if(!state.thumbnails||!state.camera||!video.videoWidth||!video.videoHeight)return;
 if(track.photo&&Date.now()-track.lastPhoto<10000)return;
 try{
  const b=track.box,c=document.createElement('canvas');c.width=96;c.height=110;
  const sx=Math.max(0,Math.floor(b.x*video.videoWidth)),sy=Math.max(0,Math.floor(b.y*video.videoHeight));
  const sw=Math.min(video.videoWidth-sx,Math.max(1,Math.floor(b.width*video.videoWidth))),sh=Math.min(video.videoHeight-sy,Math.max(1,Math.floor(b.height*video.videoHeight)));
  c.getContext('2d').drawImage(video,sx,sy,sw,sh,0,0,96,110);
  track.photo=c.toDataURL('image/webp',.66);track.lastPhoto=Date.now();
 }catch{/* No image captured if video frame is unavailable. */}
}
function greeting(track){
 if(!cameraAPI()?.preferences?.greet||track.greeted||Date.now()-state.lastGreet<20000)return;
 track.greeted=true;state.lastGreet=Date.now();
 if(track.verified)return; // Existing enrolled, verified self-face greeting is handled by camera module.
 window.GelatoFoodMonster?.greet?.('Hello! Welcome. What is your name?');
 window.dispatchEvent(new CustomEvent('gelato-food-monster-new-track',{detail:{id:track.id}}));
 // No unsolicited TTS for unknown bystanders: avoid self-audio echo loops.
}
function updateFaces(boxes,ownVerified=false){
 if(!state.camera)return;
 const now=Date.now(),used=new Set();
 for(const b of boxes.slice(0,10)){
  if(![b.x,b.y,b.width,b.height].every(Number.isFinite)||b.width<=0||b.height<=0)continue;
  const box={x:Math.max(0,Math.min(1,b.x)),y:Math.max(0,Math.min(1,b.y)),width:Math.max(0,Math.min(1,b.width)),height:Math.max(0,Math.min(1,b.height))};
  let track=nearestTrack(box,used);
  if(!track){track={id:'P'+state.nextId++,box,seen:0,at:now,photo:'',lastPhoto:0,verified:false,greeted:false};state.tracks.push(track);}
  track.box=box;track.seen++;track.at=now;track.confidence=Number.isFinite(b.confidence)?b.confidence:0;used.add(track);
  track.verified=ownVerified&&boxes.length===1;
  if(track.seen>=3)capture(track);
  // Give optional own-face enrollment time to verify before any generic greeting.
  if(track.seen>=(cameraAPI()?.enrolled?12:5))greeting(track);
 }
 state.tracks=state.tracks.filter(t=>now-t.at<18000);
 render();drawOverlay();
}
function tracked(){return state.tracks.filter(t=>t.seen>=2&&Date.now()-t.at<5000);}
function render(){
 const visible=tracked();count.textContent=String(visible.length);
 if(!state.camera){cards.innerHTML='<p class="fm-participant-empty">Camera off · no active tracks</p>';return;}
 if(!visible.length){cards.innerHTML='<p class="fm-participant-empty">No person detected yet</p>';return;}
 const own=state.roster?.session?.displayName||window.GelatoGlobalAgent?.user?.name||'Signed-in account';
 cards.innerHTML=visible.map(t=>{
  const verified=t.verified&&visible.length===1,label=verified?own:'Person '+t.id.slice(1);
  return '<article class="fm-participant-card" data-track="'+t.id+'">'+
    '<div class="fm-participant-photo">'+(t.photo&&state.thumbnails?'<img src="'+t.photo+'" alt="Temporary captured participant thumbnail">':'<span aria-label="No captured photo">◯</span>')+'</div>'+
    '<div class="fm-participant-info"><strong>'+esc(label)+'</strong><span>'+(verified?'Verified: signed-in account':'Identity unknown · tracked locally')+'</span>'+
    '<small>'+(verified?'Account session only · see staff context':'Employee/guest classification not verified')+'</small>'+
    '<div class="fm-participant-meter" title="Shared microphone activity; not speaker identification"><i style="width:'+Math.round(state.voiceLevel*100)+'%"></i></div><small>Shared room mic · cannot assign speaker</small>'+ '<button type="button" data-fm-setup="'+esc(t.id)+'">Start setup</button></div></article>';
 }).join('');
}
function drawOverlay(){
 if(state.mode!=='camera'||!state.camera||!draw)return;
 const rect=canvas.getBoundingClientRect(),ratio=Math.min(2,window.devicePixelRatio||1),w=Math.round(rect.width*ratio),h=Math.round(rect.height*ratio);
 if(!w||!h)return;if(canvas.width!==w||canvas.height!==h){canvas.width=w;canvas.height=h;}
 draw.clearRect(0,0,w,h);
 const vw=video.videoWidth||640,vh=video.videoHeight||480,fit=Math.max(w/vw,h/vh),innerW=vw*fit,innerH=vh*fit,left=(w-innerW)/2,top=(h-innerH)/2;
 draw.font='bold '+Math.max(11,Math.round(12*ratio))+'px system-ui';draw.lineWidth=2*ratio;
 for(const t of tracked()){
  const b=t.box,px=left+(1-b.x-b.width)*innerW,py=top+b.y*innerH,bw=b.width*innerW,bh=b.height*innerH;
  draw.strokeStyle=t.verified?'#27f3c9':'#28cfff';draw.fillStyle='#061d34c9';
  draw.strokeRect(px,py,bw,bh);
  const tag=t.verified?'Verified session':'Track '+t.id;
  const displayTag=tag+(t.verified||!t.confidence?'':' · '+Math.round(t.confidence*100)+'%');
  const tw=draw.measureText(displayTag).width+12*ratio;draw.fillRect(px,Math.max(0,py-22*ratio),tw,21*ratio);
  draw.fillStyle='#fff';draw.fillText(displayTag,px+6*ratio,Math.max(13*ratio,py-7*ratio));
  for(const [rx,ry]of [[px,py],[px+bw,py],[px,py+bh],[px+bw,py+bh]]){
   draw.beginPath();draw.arc(rx,ry,3*ratio,0,Math.PI*2);draw.fillStyle='#45f7e0';draw.fill();
  }
 }
}
async function loadRoster(){
 try{
  const res=await fetch('api/food-monster-participants.php',{cache:'no-store',headers:{Accept:'application/json'}});
  const data=await res.json();if(!res.ok||!data.ok)throw Error(data.message||'Roster unavailable');
  state.roster=data;
  const rows=(data.staff||[]).slice(0,35),access=data.access||{};
  roster.innerHTML=rows.map(row=>'<div class="fm-staff-item"><strong>'+esc(row.displayName)+'</strong><small>'+esc(row.status||'Status unavailable')+(row.signedInHere?' · Signed in here':'')+'</small></div>').join('')||'<p>No permitted staff records.</p>';
  panel.querySelector('.fm-staff-note').textContent=data.scopeNote||'Schedule and attendance context only';
  if(!access.scheduleReady&&!access.clockReady)panel.querySelector('.fm-staff-note').textContent+=' Schedule and clock-in details are unavailable to this account.';
  render();
 }catch{panel.querySelector('.fm-staff-note').textContent='Staff context unavailable; camera identities remain unverified.';roster.textContent='';}
}
function stopMeter(){
 if(state.levelTimer)clearInterval(state.levelTimer);state.levelTimer=0;
 try{state.source?.disconnect();}catch{}state.source=null;
 try{state.roomMic?.getTracks().forEach(t=>t.stop());}catch{}state.roomMic=null;
 if(state.ctx){void state.ctx.close().catch(()=>{});state.ctx=null;}
 state.analyser=null;state.voiceLevel=0;panel.querySelector('[data-fm-level]').style.width='0%';
 panel.querySelector('[data-fm-meter]').textContent='Enable room voice meter';render();
}
async function toggleMeter(){
 if(state.roomMic){stopMeter();return;}
 const button=panel.querySelector('[data-fm-meter]');button.textContent='Requesting microphone…';
 try{
  if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia)throw Error('HTTPS and microphone permission required');
  const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true},video:false});
  const Context=window.AudioContext||window.webkitAudioContext;
  if(!Context){stream.getTracks().forEach(t=>t.stop());throw Error('Audio meter unavailable');}
  state.roomMic=stream;state.ctx=new Context();state.source=state.ctx.createMediaStreamSource(stream);
  state.analyser=state.ctx.createAnalyser();state.analyser.fftSize=256;state.source.connect(state.analyser);
  const samples=new Uint8Array(state.analyser.frequencyBinCount);
  state.levelTimer=setInterval(()=>{
   if(!state.roomMic)return;
   state.analyser.getByteTimeDomainData(samples);
   let sum=0;for(const x of samples)sum+=(x-128)**2;
   state.voiceLevel=Math.min(1,Math.sqrt(sum/samples.length)/36);
   panel.querySelector('[data-fm-level]').style.width=Math.round(state.voiceLevel*100)+'%';
   for(const m of panel.querySelectorAll('.fm-participant-meter i'))m.style.width=Math.round(state.voiceLevel*100)+'%';
  },110);
  stream.getTracks().forEach(t=>t.addEventListener('ended',stopMeter,{once:true}));
  button.textContent='Disable room voice meter';
 }catch(e){stopMeter();panel.querySelector('.fm-staff-note').textContent='Room voice meter unavailable: '+(e.message||'permission denied');}
}
for(const button of el.querySelectorAll('[data-fm-view]'))button.addEventListener('click',()=>setMode(button.dataset.fmView));
cameraToggle.addEventListener('click',async()=>{
 const camera=cameraAPI();if(!camera){status.textContent='Camera module is still loading.';return;}
 cameraToggle.disabled=true;
 try{if(camera.active)camera.stop();else await camera.start();setCamera(camera.active);}
 finally{cameraToggle.disabled=false;}
});
panel.querySelector('[data-fm-meter]').addEventListener('click',()=>void toggleMeter());
panel.querySelector('[data-fm-photos]').addEventListener('change',e=>{
 state.thumbnails=e.target.checked;if(!state.thumbnails)for(const t of state.tracks)t.photo='';render();
});
panel.addEventListener('click',e=>{const button=e.target.closest('[data-fm-setup]');if(button)window.GelatoFoodMonsterGoals?.begin(button.dataset.fmSetup);});
window.addEventListener('gelato-food-monster-camera',e=>setCamera(e.detail?.active));
window.addEventListener('gelato-food-monster-faces',e=>updateFaces(e.detail?.boxes||[],e.detail?.ownVerified===true));
window.addEventListener('resize',drawOverlay);
window.addEventListener('pagehide',()=>{stopMeter();clearTracks();});
document.addEventListener('visibilitychange',()=>{if(document.hidden){stopMeter();clearTracks();}});
setInterval(()=>{if(state.camera){state.tracks=state.tracks.filter(t=>Date.now()-t.at<18000);render();drawOverlay();}},1100);
loadRoster();setInterval(loadRoster,60000);
function captureGoalPhoto(trackId){
 const t=tracked().find(track=>track.id===trackId);
 if(!t||!state.camera||!video.videoWidth||!video.videoHeight)return '';
 const b=t.box,c=document.createElement('canvas');c.width=320;c.height=360;
 const sx=Math.max(0,Math.floor(b.x*video.videoWidth)),sy=Math.max(0,Math.floor(b.y*video.videoHeight));
 const sw=Math.min(video.videoWidth-sx,Math.max(1,Math.floor(b.width*video.videoWidth)));
 const sh=Math.min(video.videoHeight-sy,Math.max(1,Math.floor(b.height*video.videoHeight)));
 c.getContext('2d').drawImage(video,sx,sy,sw,sh,0,0,320,360);
 return c.toDataURL('image/webp',.65);
}
window.GelatoFoodMonsterParticipants={setMode,updateFaces,captureGoalPhoto,
 get mode(){return state.mode},get count(){return tracked().length},
 get cameraActive(){return state.camera},get roster(){return state.roster}};
})();
