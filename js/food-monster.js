(() => {
'use strict';
if(window.GelatoFoodMonster)return;
const stage=document.body.classList.contains('food-monster-stage-page');
if(document.body.dataset.agentCanvas==='1'&&!stage)return;
if(!document.querySelector('link[rel="stylesheet"][href^="css/food-monster.css"]')){
 const stylesheet=document.createElement('link');stylesheet.rel='stylesheet';stylesheet.href='css/food-monster.css?v=20261015-fmg7d';document.head.append(stylesheet);
}
const root=document.createElement('div');root.id='food-monster-root';if(stage)root.classList.add('fm-is-stage');
root.innerHTML='<div class="fm-actor" role="group" aria-label="Floating Food Monster"><div class="fm-speech" role="status"></div><div class="fm-figure" aria-hidden="true"></div><div class="fm-actions"><button type="button" data-size="-1" aria-label="Make Food Monster smaller">−</button><button type="button" data-size="1" aria-label="Make Food Monster larger">+</button><button type="button" data-fm-roam aria-label="Pause autonomous floating" aria-pressed="true" title="Pause autonomous floating">◌</button>'+(stage?'':'<a href="food-monster.php" aria-label="Open Food Monster page">↗</a>')+'</div></div>';document.body.append(root);
const minimize=document.createElement('button');
minimize.className='fm-minimize';minimize.type='button';
minimize.title='Minimize Food Monster';minimize.setAttribute('aria-label','Minimize Food Monster');
minimize.textContent='−';root.querySelector('.fm-actor').append(minimize);
const actor=root.querySelector('.fm-actor'),figure=root.querySelector('.fm-figure'),bubble=root.querySelector('.fm-speech');
const state={size:stage?350:170,x:0,y:0,drag:null,pupil:null,targetX:0,targetY:0,px:0,py:0,last:0,timer:0,cameraTarget:null,cameraManualUntil:0,faceSeenUntil:0,baseSize:0,manualSizeUntil:0,roamUntil:0,roamX:0,roamY:0,roamEnabled:true,hovered:false,lastFrame:0,roamIndex:0,roamResumeAt:0};
const performanceState={voiceSpeaking:false,listening:false,thinking:false,replyUntil:0,alertUntil:0,lastInteraction:performance.now(),mood:'idle'};
const moods=['fm-speaking','fm-thinking','fm-listening','fm-alert','fm-happy','fm-sleepy'];
const reduced=matchMedia('(prefers-reduced-motion: reduce)');
function updateMood(){
 const now=performance.now();
 const mood=performanceState.voiceSpeaking?'speaking':
   performanceState.thinking?'thinking':
   performanceState.listening?'listening':
   performanceState.alertUntil>now?'alert':
   performanceState.replyUntil>now?'happy':
   now-performanceState.lastInteraction>45000?'sleepy':'idle';
 if(mood!==performanceState.mood){
   root.classList.remove(...moods);
   if(mood!=='idle')root.classList.add('fm-'+mood);
   root.dataset.mood=mood;performanceState.mood=mood;
 }
}
if(!stage){try{
 const saved=JSON.parse(localStorage.getItem('gelato-food-monster-layout')||'null');
 if(saved && Number.isFinite(saved.x)&&Number.isFinite(saved.y)&&Number.isFinite(saved.size)) state.saved=saved;
 root.classList.toggle('fm-minimized',localStorage.getItem('gelato-food-monster-minimized')==='1');
  state.roamEnabled=localStorage.getItem('gelato-food-monster-roam')!=='0';
}catch{}}
const clamp=(v,min,max)=>Math.min(max,Math.max(min,v));
const minSize=stage?190:90;
const maxSize=()=>Math.max(minSize,Math.min(stage?520:310,innerWidth*.83,innerHeight*.76));
function movementBounds(size){
  // Keep the mascot clear of the real composer, even when its height changes.
  const bar=document.getElementById('gelato-agent-bar');
  const rect=bar?.getBoundingClientRect();
  const composerTop=rect&&rect.top>innerHeight*.45&&rect.bottom>innerHeight-135?rect.top:innerHeight-(stage?110:70);
  const top=root.classList.contains('fm-has-speech')?Math.min(68,innerHeight*.09):8;
  return {minX:8,maxX:Math.max(8,innerWidth-size-8),minY:Math.min(top,Math.max(8,composerTop-size*1.2-12)),maxY:Math.max(8,composerTop-size*1.2-12)};
}
function layout(){
  state.size=clamp(state.size,minSize,maxSize());
  const b=movementBounds(state.size);
  state.x=clamp(state.x,b.minX,b.maxX);state.y=clamp(state.y,b.minY,b.maxY);
  root.style.setProperty('--fm-size',state.size+'px');root.style.setProperty('--fm-x',state.x+'px');root.style.setProperty('--fm-y',state.y+'px');
}

state.size=stage?Math.min(420,innerWidth*.72,innerHeight*.58):Math.min(175,innerWidth*.4);
state.x=stage?(innerWidth-state.size)/2:innerWidth-state.size-30;
state.y=stage?(innerHeight-state.size*1.2)/2-20:innerHeight-state.size*1.2-145;
if(state.saved){state.size=state.saved.size;state.x=state.saved.x*innerWidth;state.y=state.saved.y*innerHeight;}
state.baseSize=clamp(state.size,minSize,maxSize());
layout();
const persist=()=>{if(stage)return;try{localStorage.setItem('gelato-food-monster-layout',JSON.stringify({x:state.x/innerWidth,y:state.y/innerHeight,size:state.baseSize}));}catch{}};
minimize.addEventListener('click',()=>{
 const collapsed=root.classList.toggle('fm-minimized');
 minimize.textContent=collapsed?'+':'−';
 minimize.setAttribute('aria-label',collapsed?'Restore Food Monster':'Minimize Food Monster');
 try{localStorage.setItem('gelato-food-monster-minimized',collapsed?'1':'0');}catch{}
});
if(root.classList.contains('fm-minimized')){minimize.textContent='+';minimize.setAttribute('aria-label','Restore Food Monster');}
actor.addEventListener('pointerenter',event=>{if(event.pointerType!=='touch')state.hovered=true;});
actor.addEventListener('pointerleave',()=>{state.hovered=false;state.roamResumeAt=performance.now()+2500;});
actor.addEventListener('pointerdown',event=>{if(event.button||event.target.closest('button,a'))return;state.drag={id:event.pointerId,x:event.clientX,y:event.clientY,ox:state.x,oy:state.y};actor.setPointerCapture(event.pointerId);});
actor.addEventListener('pointermove',event=>{if(state.drag?.id!==event.pointerId)return;state.x=state.drag.ox+event.clientX-state.drag.x;state.y=state.drag.oy+event.clientY-state.drag.y;layout();});
for(const kind of ['pointerup','pointercancel','lostpointercapture'])actor.addEventListener(kind,()=>{
 if(state.drag){state.drag=null;state.cameraManualUntil=performance.now()+9000;state.roamUntil=0;state.roamResumeAt=performance.now()+9000;persist();}
});
root.addEventListener('click',event=>{
 const b=event.target.closest('[data-size]');if(!b)return;
 state.baseSize=clamp(state.baseSize+Number(b.dataset.size)*24,minSize,maxSize());
 state.size=state.baseSize;state.manualSizeUntil=performance.now()+12000;
 state.roamUntil=0;state.roamResumeAt=performance.now()+3500;layout();persist();
});
const roamButton=root.querySelector('[data-fm-roam]');
function syncRoamButton(){
 roamButton.setAttribute('aria-pressed',state.roamEnabled?'true':'false');
 roamButton.setAttribute('aria-label',state.roamEnabled?'Pause autonomous floating':'Resume autonomous floating');
 roamButton.title=roamButton.getAttribute('aria-label');
 root.dataset.autoRoam=state.roamEnabled?'on':'off';
}
syncRoamButton();
roamButton.addEventListener('click',()=>{
 state.roamEnabled=!state.roamEnabled;state.roamUntil=0;state.roamResumeAt=performance.now()+1500;
 try{if(!stage)localStorage.setItem('gelato-food-monster-roam',state.roamEnabled?'1':'0');}catch{}
 syncRoamButton();
});
window.addEventListener('resize',layout,{passive:true});
window.addEventListener('pointermove',event=>{performanceState.lastInteraction=performance.now();if(state.drag||state.cameraTarget)return;if(root.classList.contains('fm-minimized'))return;const r=actor.getBoundingClientRect();const dx=event.clientX-(r.left+r.width/2),dy=event.clientY-(r.top+r.height*.54),length=Math.hypot(dx,dy)||1,s=Math.min(length/(r.width*1.5),1);state.targetX=dx/length*s*13;state.targetY=dy/length*s*11;state.last=performance.now();},{passive:true});
// FM-G7A: adaptive presence; visual motion never issues a camera permission or Agent action.
function engagementSize(now){
  const face=state.cameraTarget&&now<state.faceSeenUntil&&state.cameraTarget.scale?state.cameraTarget:null;
  const factors={speaking:1.28,thinking:1.08,listening:.87,alert:1.17,happy:1.12,sleepy:.78,idle:.91};
  const moodFactor=factors[performanceState.mood]||1;
  // Distance affects size independently of positional camera-follow preference.
  const faceFactor=face?clamp(.72+face.proximity*.84,.72,1.48):0;
  const factor=face?(performanceState.voiceSpeaking?Math.max(moodFactor,faceFactor*1.04):
    Math.max(moodFactor*.85,faceFactor*(performanceState.listening?.87:1))):moodFactor;
  return clamp(state.baseSize*factor,minSize,maxSize());
}
function planRoam(now){
  const b=movementBounds(state.size);
  // Waypoints remain in the safe viewport; a gentle bounded path avoids bouncing.
  const horizontal=Math.max(30,Math.min(innerWidth*.29,stage?205:132));
  const vertical=Math.max(28,Math.min(innerHeight*.18,stage?135:95));
  const direction=state.roamIndex++%4;
  const angle=now/8200+direction*1.7;
  state.roamX=clamp(state.x+Math.cos(angle)*horizontal,b.minX,b.maxX);
  state.roamY=clamp(state.y+Math.sin(angle)*vertical,b.minY,b.maxY);
  if(Math.hypot(state.roamX-state.x,state.roamY-state.y)<20){
    state.roamX=clamp(state.x+(state.x>innerWidth*.5?-1:1)*horizontal,b.minX,b.maxX);
    state.roamY=clamp(state.y+(state.y>innerHeight*.37?-1:1)*vertical,b.minY,b.maxY);
  }
  state.roamUntil=now+14000+(direction%3)*1800;
}
function animate(){
 if(!root.isConnected)return;
 const now=performance.now();
 const dt=state.lastFrame?clamp((now-state.lastFrame)/1000,0,.05):0;
 state.lastFrame=now;
 updateMood();
 const cameraActive=!!(state.cameraTarget&&now<state.faceSeenUntil);
 const target=cameraActive?state.cameraTarget:null;
 const minimized=root.classList.contains('fm-minimized');
 const paused=!!(state.drag||minimized||document.hidden);
 if(!paused&&!reduced.matches&&now>=state.manualSizeUntil){
   const desired=engagementSize(now);
   state.size+=(desired-state.size)*(1-Math.exp(-dt*3.2));
 }
 if(target){
   // A camera can resize the mascot even if the user disabled follow.
   if(target.follow&&!paused&&now>=state.cameraManualUntil){
     const b=movementBounds(state.size);
     const wantedX=clamp(target.x*innerWidth-state.size*.5,b.minX,b.maxX);
     const wantedY=clamp(target.y*innerHeight-state.size*.6,b.minY,b.maxY);
     const follow=1-Math.exp(-dt*3.5);
     state.x+=(wantedX-state.x)*follow;state.y+=(wantedY-state.y)*follow;
   }
   state.targetX=clamp((target.x-.5)*24,-12,12);
   state.targetY=clamp((target.y-.5)*17,-9,9);
 }else if(now-state.last>10000){
   state.targetX=reduced.matches?0:Math.sin(now/2000)*3;state.targetY=0;
 }
 const dialogOpen=!!document.querySelector('#fm-camera-dialog[open],#fm-llm-dialog[open]');
 const canRoam=state.roamEnabled&&!paused&&!reduced.matches&&!state.hovered&&!dialogOpen&&
   !performanceState.voiceSpeaking&&!performanceState.thinking&&
   now>=state.cameraManualUntil&&now>=state.roamResumeAt&&!(target&&target.follow);
 if(canRoam){
   if(!state.roamUntil||now>=state.roamUntil)planRoam(now);
   const b=movementBounds(state.size);
   const dx=clamp(state.roamX,b.minX,b.maxX)-state.x;
   const dy=clamp(state.roamY,b.minY,b.maxY)-state.y;
   const length=Math.hypot(dx,dy);
   const travel=Math.min(length,(performanceState.listening?11:stage?23:17)*dt);
   if(length>1){state.x+=dx/length*travel;state.y+=dy/length*travel;}
 }else{
   // Resume from the current, user-approved location, not an old destination.
   state.roamUntil=0;
 }
 if(!paused)layout();
 // Gaze remains responsive while the Agent talks. Thinking adds a subtle upward glance.
 const goalY=state.targetY-(performanceState.thinking?2.8:0);
 state.px+=(state.targetX-state.px)*.15;state.py+=(goalY-state.py)*.15;
 if(state.pupil)state.pupil.setAttribute('transform','translate('+state.px.toFixed(2)+' '+state.py.toFixed(2)+')');
 if(performanceState.voiceSpeaking){
   const mouth=reduced.matches?.72:Math.min(1.22,Math.max(.32,.35+.60*Math.abs(Math.sin(now/73)+.31*Math.sin(now/119))));
   root.style.setProperty('--fm-mouth-open',mouth.toFixed(3));
 }
 requestAnimationFrame(animate);
}requestAnimationFrame(animate);
function blink(){if(reduced.matches||document.hidden)return;root.classList.add('fm-blink');setTimeout(()=>root.classList.remove('fm-blink'),165);}
(function tick(){setTimeout(()=>{blink();tick();},3300+Math.random()*3500);})();
let speechTimer=0;
window.addEventListener('gelato-agent-response',event=>{
 const text=String(event.detail?.answer||'');
 if(!text)return;
 bubble.textContent=text.slice(0,180)+(text.length>180?'…':'');
 root.classList.add('fm-has-speech');
 clearTimeout(speechTimer);
 speechTimer=setTimeout(()=>root.classList.remove('fm-has-speech'),Math.min(8500,1800+text.length*38));
 performanceState.thinking=false;performanceState.replyUntil=performance.now()+1800;
 const moves=event.detail?.result?.data?.nextMoves;
 if(Array.isArray(moves)&&moves.some(move=>['critical','high'].includes(String(move?.severity||''))))
   performanceState.alertUntil=performance.now()+3500;
 updateMood();
});
window.addEventListener('gelato-agent-status',event=>{
 const value=String(event.detail?.message||'');
 performanceState.thinking=/checking your restaurant context|thinking|working on/i.test(value);
 if(value)performanceState.lastInteraction=performance.now();
 updateMood();
});
window.addEventListener('gelato-agent-listening-change',event=>{
 performanceState.listening=!!event.detail?.microphoneActive&&!!event.detail?.listening;
 if(performanceState.listening)performanceState.lastInteraction=performance.now();
 updateMood();
});
window.addEventListener('gelato-voice-turn',event=>{
 const kind=String(event.detail?.kind||'');
 const owner=String(event.detail?.owner||'');
 if(kind==='speaking'&&(owner==='global-agent'||owner==='agent')){
   performanceState.voiceSpeaking=true;performanceState.thinking=false;
   performanceState.lastInteraction=performance.now();
 }else if((kind==='ended'||kind==='interrupted')&&performanceState.voiceSpeaking){
   performanceState.voiceSpeaking=false;
   root.style.removeProperty('--fm-mouth-open');
   performanceState.replyUntil=performance.now()+500;
 }else return;
 updateMood();
});
window.addEventListener('gelato-agent-ready',()=>{performanceState.replyUntil=performance.now()+1200;updateMood();});
fetch('assets/food-monster/chef-mascot.svg',{credentials:'same-origin'}).then(r=>{if(!r.ok)throw Error('artwork');return r.text()}).then(content=>{const doc=new DOMParser().parseFromString(content,'image/svg+xml');if(doc.documentElement.localName!=='svg'||doc.querySelector('parsererror'))throw Error('invalid svg');figure.append(document.importNode(doc.documentElement,true));state.pupil=figure.querySelector('[data-fm-pupil]');}).catch(()=>figure.textContent='👁️');
function setFaceTarget(target){
 if(!target){state.cameraTarget=null;state.faceSeenUntil=0;root.classList.remove('fm-face-tracking');return;}
 if(![target.x,target.y,target.proximity].every(Number.isFinite))return;
 state.cameraTarget={x:clamp(target.x,0,1),y:clamp(target.y,0,1),
   proximity:clamp(target.proximity,0,1),follow:target.follow===true,scale:target.scale===true};
 state.faceSeenUntil=performance.now()+2200;state.last=performance.now();
 root.classList.add('fm-face-tracking');
}
function greet(message){
 if(typeof message!=='string'||!message.trim())return;
 bubble.textContent=message.slice(0,180);root.classList.add('fm-has-speech');
 clearTimeout(speechTimer);speechTimer=setTimeout(()=>root.classList.remove('fm-has-speech'),5000);
 performanceState.replyUntil=performance.now()+1600;updateMood();
}
window.GelatoFoodMonster={root,blink,greet,setFaceTarget,get isStage(){return stage;},get mood(){return performanceState.mood;},get motion(){return {enabled:state.roamEnabled,baseSize:state.baseSize,size:state.size,x:state.x,y:state.y};}};
if(!document.querySelector('script[data-fm-camera-loader]')){
 const script=document.createElement('script');
 script.src='js/food-monster-camera.js?v=20261014-fmg3';script.async=true;
 script.dataset.fmCameraLoader='1';document.head.append(script);
}
if(!document.querySelector('script[data-fm-llm-loader]')){
 const script=document.createElement('script');
 script.src='js/food-monster-llm.js?v=20261015-fmg7d';script.async=true;
 script.dataset.fmLlmLoader='1';document.head.append(script);
}
if(!document.querySelector('script[data-fm-suggestions-loader]')){
 const script=document.createElement('script');
 script.src='js/food-monster-suggestions.js?v=20261014-fmg5';script.async=true;
 script.dataset.fmSuggestionsLoader='1';document.head.append(script);
}
})();