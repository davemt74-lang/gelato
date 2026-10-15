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
const panel=document.createElement('aside');panel.className='fm-participants-panel';panel.setAttribute('aria-label','Live participant cards');
panel.innerHTML='<div class="fm-participant-cards" aria-live="polite"></div>';
host.append(panel);
const cards=panel.querySelector('.fm-participant-cards');
const status=el.querySelector('.fm-room-camera-state'),cameraToggle=el.querySelector('[data-fm-camera-toggle]'),empty=cameraArea.querySelector('.fm-room-camera-empty');
const esc=str=>String(str||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function cameraAPI(){return window.GelatoFoodMonsterCamera;}
function setMode(mode){
 state.mode=mode==='camera'?'camera':'agent';document.body.classList.toggle('fm-view-camera',state.mode==='camera');
 cameraArea.hidden=state.mode!=='camera';
 for(const button of el.querySelectorAll('[data-fm-view]'))button.setAttribute('aria-pressed',String(button.dataset.fmView===state.mode));
 if(state.mode==='camera')attachVideo();render();
}
function clearTracks(){state.tracks.length=0;state.nextId=1;render();drawOverlay();} // Camera-off ends the tracking session
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
function tracked(){return state.tracks.filter(t=>t.seen>=2&&Date.now()-t.at<1800);} // Hidden tracks retain ID longer than visible cards
function render(){
 const visible=tracked();
 panel.hidden=!state.camera||!visible.length;
 if(!state.camera||!visible.length){cards.innerHTML='';return;}
 const own=state.roster?.session?.displayName||window.GelatoGlobalAgent?.user?.name||'Signed-in account';
 cards.innerHTML=visible.map(t=>{
  const verified=t.verified&&visible.length===1,label=verified?own:'Person '+t.id.slice(1);
  return '<button type="button" class="fm-participant-card" data-fm-setup="'+esc(t.id)+'" data-track="'+esc(t.id)+'" data-fm-verified="'+String(verified)+'" aria-label="Open '+esc(label)+' participant">'+
   '<span class="fm-participant-photo">'+(t.photo&&state.thumbnails?'<img src="'+t.photo+'" alt="">':'<span aria-hidden="true">◯</span>')+'</span>'+
   '<span class="fm-participant-info"><strong>'+esc(label)+'</strong><span>'+(verified?'Recognized account':'Unverified participant')+'</span></span></button>';
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
  const res=await fetch('api/food-monster-participants.php',{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}});
  const data=await res.json();if(!res.ok||!data.ok)throw Error(data.message||'Staff context unavailable');
  state.roster=data;render();
  // Schedule roster informs the main agent; only live camera tracks get sidebar cards.
  window.dispatchEvent(new CustomEvent('gelato-food-monster-staff-context',{detail:{
   session:data.session,staff:data.staff||[],access:data.access||{},generatedAt:data.generatedAt
  }}));
 }catch{/* No roster data must not create participants. */}
}
function stopMeter(){}
for(const button of el.querySelectorAll('[data-fm-view]'))button.addEventListener('click',()=>setMode(button.dataset.fmView));
cameraToggle.addEventListener('click',async()=>{
 const camera=cameraAPI();if(!camera){status.textContent='Camera module is still loading.';return;}
 cameraToggle.disabled=true;
 try{if(camera.active)camera.stop();else await camera.start();setCamera(camera.active);}
 finally{cameraToggle.disabled=false;}
});
panel.addEventListener('click',event=>{
 const card=event.target.closest('[data-fm-setup]');if(!card)return;
 window.GelatoFoodMonsterGoals?.begin?.(card.dataset.fmSetup,{verified:card.dataset.fmVerified==='true'});
});
window.addEventListener('gelato-food-monster-camera',e=>setCamera(e.detail?.active));
window.addEventListener('gelato-food-monster-faces',e=>updateFaces(e.detail?.boxes||[],e.detail?.ownVerified===true));
window.addEventListener('resize',drawOverlay);
window.addEventListener('pagehide',()=>{stopMeter();clearTracks();});
document.addEventListener('visibilitychange',()=>{if(document.hidden){stopMeter();clearTracks();}});
setInterval(()=>{if(state.camera){state.tracks=state.tracks.filter(t=>Date.now()-t.at<18000);render();drawOverlay();}},1100);
loadRoster();setInterval(loadRoster,60000);
function captureGoalPhoto(trackId){
 const visible=tracked(),t=visible.find(track=>track.id===trackId)||(visible.length===1?visible[0]:null);
 if(!t||visible.length!==1||!state.camera||!video.videoWidth||!video.videoHeight||document.hidden)return '';
 const b=t.box;if(b.width<.10||b.height<.13||b.width>.73)return '';
 const c=document.createElement('canvas');c.width=320;c.height=360;
 const sx=Math.max(0,Math.floor(b.x*video.videoWidth)),sy=Math.max(0,Math.floor(b.y*video.videoHeight));
 const sw=Math.min(video.videoWidth-sx,Math.max(1,Math.floor(b.width*video.videoWidth)));
 const sh=Math.min(video.videoHeight-sy,Math.max(1,Math.floor(b.height*video.videoHeight)));
 const ctx=c.getContext('2d');ctx.drawImage(video,sx,sy,sw,sh,0,0,320,360);
 const pixels=ctx.getImageData(0,0,320,360).data;let sum=0,sum2=0,n=0;
 for(let i=0;i<pixels.length;i+=96){const v=pixels[i]*.2126+pixels[i+1]*.7152+pixels[i+2]*.0722;sum+=v;sum2+=v*v;n++;}
 const avg=sum/n,variance=sum2/n-avg*avg;
 if(avg<44||avg>226||variance<110)return '';
 return c.toDataURL('image/webp',.65);
}
window.GelatoFoodMonsterParticipants={setMode,updateFaces,captureGoalPhoto,
 get mode(){return state.mode},get count(){return tracked().length},
 get cameraActive(){return state.camera},get roster(){return state.roster},
 get activeTracks(){const active=tracked();return active.map(t=>({id:t.id,verified:active.length===1&&t.verified}));}};
})();
