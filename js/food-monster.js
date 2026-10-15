(() => {
'use strict';
if(window.GelatoFoodMonster)return;
const stage=document.body.classList.contains('food-monster-stage-page');
if(document.body.dataset.agentCanvas==='1'&&!stage)return;
const stylesheet=document.createElement('link');stylesheet.rel='stylesheet';stylesheet.href='css/food-monster.css?v=20261014-fmg1';document.head.append(stylesheet);
const root=document.createElement('div');root.id='food-monster-root';if(stage)root.classList.add('fm-is-stage');
root.innerHTML='<div class="fm-actor" role="group" aria-label="Floating Food Monster"><div class="fm-speech" role="status"></div><div class="fm-figure" aria-hidden="true"></div><div class="fm-actions"><button type="button" data-size="-1" aria-label="Make Food Monster smaller">−</button><button type="button" data-size="1" aria-label="Make Food Monster larger">+</button>'+(stage?'':'<a href="food-monster.php" aria-label="Open Food Monster page">↗</a>')+'</div></div>';document.body.append(root);
const minimize=document.createElement('button');
minimize.className='fm-minimize';minimize.type='button';
minimize.title='Minimize Food Monster';minimize.setAttribute('aria-label','Minimize Food Monster');
minimize.textContent='−';root.querySelector('.fm-actor').append(minimize);
const actor=root.querySelector('.fm-actor'),figure=root.querySelector('.fm-figure'),bubble=root.querySelector('.fm-speech');
const state={size:stage?350:170,x:0,y:0,drag:null,pupil:null,targetX:0,targetY:0,px:0,py:0,last:0,timer:0,cameraTarget:null,cameraManualUntil:0,faceSeenUntil:0};
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
}catch{}}
const clamp=(v,min,max)=>Math.min(max,Math.max(min,v));
function layout(){state.size=clamp(state.size,stage?190:90,Math.min(stage?520:310,innerWidth*.83));state.x=clamp(state.x,8,Math.max(8,innerWidth-state.size-8));state.y=clamp(state.y,8,Math.max(8,innerHeight-state.size*1.2-(stage?110:70)));root.style.setProperty('--fm-size',state.size+'px');root.style.setProperty('--fm-x',state.x+'px');root.style.setProperty('--fm-y',state.y+'px');}
state.size=stage?Math.min(420,innerWidth*.72,innerHeight*.58):Math.min(175,innerWidth*.4);
state.x=stage?(innerWidth-state.size)/2:innerWidth-state.size-30;
state.y=stage?(innerHeight-state.size*1.2)/2-20:innerHeight-state.size*1.2-145;
if(state.saved){state.size=state.saved.size;state.x=state.saved.x*innerWidth;state.y=state.saved.y*innerHeight;}
layout();
const persist=()=>{if(stage)return;try{localStorage.setItem('gelato-food-monster-layout',JSON.stringify({x:state.x/innerWidth,y:state.y/innerHeight,size:state.size}));}catch{}};
minimize.addEventListener('click',()=>{
 const collapsed=root.classList.toggle('fm-minimized');
 minimize.textContent=collapsed?'+':'−';
 minimize.setAttribute('aria-label',collapsed?'Restore Food Monster':'Minimize Food Monster');
 try{localStorage.setItem('gelato-food-monster-minimized',collapsed?'1':'0');}catch{}
});
if(root.classList.contains('fm-minimized')){minimize.textContent='+';minimize.setAttribute('aria-label','Restore Food Monster');}
actor.addEventListener('pointerdown',event=>{if(event.button||event.target.closest('button,a'))return;state.drag={id:event.pointerId,x:event.clientX,y:event.clientY,ox:state.x,oy:state.y};actor.setPointerCapture(event.pointerId);});
actor.addEventListener('pointermove',event=>{if(state.drag?.id!==event.pointerId)return;state.x=state.drag.ox+event.clientX-state.drag.x;state.y=state.drag.oy+event.clientY-state.drag.y;layout();});
for(const kind of ['pointerup','pointercancel'])actor.addEventListener(kind,()=>{if(state.drag){state.drag=null;state.cameraManualUntil=performance.now()+1800;persist();}});
root.addEventListener('click',event=>{const b=event.target.closest('[data-size]');if(!b)return;state.size+=Number(b.dataset.size)*24;layout();persist();});
window.addEventListener('resize',layout,{passive:true});
window.addEventListener('pointermove',event=>{performanceState.lastInteraction=performance.now();if(state.drag||state.cameraTarget)return;if(root.classList.contains('fm-minimized'))return;const r=actor.getBoundingClientRect();const dx=event.clientX-(r.left+r.width/2),dy=event.clientY-(r.top+r.height*.54),length=Math.hypot(dx,dy)||1,s=Math.min(length/(r.width*1.5),1);state.targetX=dx/length*s*13;state.targetY=dy/length*s*11;state.last=performance.now();},{passive:true});
function animate(){
 if(!root.isConnected)return;
 const now=performance.now();
 if(state.cameraTarget&&now<state.faceSeenUntil){
   const target=state.cameraTarget;
   // Follow at comfortable velocity, staying clear of the fixed chat composer.
   if(target.follow&&!state.drag&&!root.classList.contains('fm-minimized')&&now>=state.cameraManualUntil){
     const nextSize=target.scale?
       clamp((stage?235:98)+target.proximity*(stage?310:165),stage?190:90,stage?520:310):state.size;
     state.size+=(nextSize-state.size)*.055;
     const wantedX=clamp(target.x*innerWidth-state.size*.5,8,Math.max(8,innerWidth-state.size-8));
     const wantedY=clamp(target.y*(innerHeight-(stage?125:95))-state.size*.5,8,Math.max(8,innerHeight-state.size*1.2-(stage?110:70)));
     state.x+=(wantedX-state.x)*.085;state.y+=(wantedY-state.y)*.085;
     layout();
   }
   state.targetX=clamp((target.x-.5)*24,-12,12);
   state.targetY=clamp((target.y-.5)*17,-9,9);
 }else if(now-state.last>10000){
   state.targetX=Math.sin(now/2000)*3;state.targetY=0;
 }
 // Gaze remains responsive while the Agent talks. Thinking adds a subtle upward glance.
 const goalY=state.targetY-(performanceState.thinking?2.8:0);
 state.px+=(state.targetX-state.px)*.15;state.py+=(goalY-state.py)*.15;
 if(state.pupil)state.pupil.setAttribute('transform','translate('+state.px.toFixed(2)+' '+state.py.toFixed(2)+')');
 if(performanceState.voiceSpeaking){
   // Browser TTS exposes speech lifecycle, not reliable phonemes. Animate a natural
   // approximate speech rhythm ONLY while GelatoVoiceTurns says the Agent is speaking.
   const mouth=reduced.matches?.72:Math.min(1.22,Math.max(.32,.35+.60*Math.abs(Math.sin(now/73)+.31*Math.sin(now/119))));
   root.style.setProperty('--fm-mouth-open',mouth.toFixed(3));
 }
 updateMood();
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
window.GelatoFoodMonster={root,blink,greet,setFaceTarget,get isStage(){return stage;},get mood(){return performanceState.mood;}};
if(!document.querySelector('script[data-fm-camera-loader]')){
 const script=document.createElement('script');
 script.src='js/food-monster-camera.js?v=20261014-fmg3';script.async=true;
 script.dataset.fmCameraLoader='1';document.head.append(script);
}
})();