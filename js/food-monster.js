(() => {
'use strict';
if(window.GelatoFoodMonster)return;
const stage=document.body.classList.contains('food-monster-stage-page');
if(document.body.dataset.agentCanvas==='1'&&!stage)return;
const stylesheet=document.createElement('link');stylesheet.rel='stylesheet';stylesheet.href='css/food-monster.css?v=20261014-fmg1';document.head.append(stylesheet);
const root=document.createElement('div');root.id='food-monster-root';if(stage)root.classList.add('fm-is-stage');
root.innerHTML='<div class="fm-actor" role="group" aria-label="Floating Food Monster"><div class="fm-speech" role="status"></div><div class="fm-figure" aria-hidden="true"></div><div class="fm-actions"><button type="button" data-size="-1" aria-label="Make Food Monster smaller">−</button><button type="button" data-size="1" aria-label="Make Food Monster larger">+</button>'+(stage?'':'<a href="food-monster.php" aria-label="Open Food Monster page">↗</a>')+'</div></div>';document.body.append(root);
const actor=root.querySelector('.fm-actor'),figure=root.querySelector('.fm-figure'),bubble=root.querySelector('.fm-speech');
const state={size:stage?350:170,x:0,y:0,drag:null,pupil:null,targetX:0,targetY:0,px:0,py:0,last:0,timer:0};
const clamp=(v,min,max)=>Math.min(max,Math.max(min,v));
function layout(){state.size=clamp(state.size,stage?190:90,Math.min(stage?520:310,innerWidth*.83));state.x=clamp(state.x,8,Math.max(8,innerWidth-state.size-8));state.y=clamp(state.y,8,Math.max(8,innerHeight-state.size*1.2-(stage?110:70)));root.style.setProperty('--fm-size',state.size+'px');root.style.setProperty('--fm-x',state.x+'px');root.style.setProperty('--fm-y',state.y+'px');}
state.size=stage?Math.min(420,innerWidth*.72,innerHeight*.58):Math.min(175,innerWidth*.4);
state.x=stage?(innerWidth-state.size)/2:innerWidth-state.size-30;
state.y=stage?(innerHeight-state.size*1.2)/2-20:innerHeight-state.size*1.2-145;layout();
actor.addEventListener('pointerdown',event=>{if(event.button||event.target.closest('button,a'))return;state.drag={id:event.pointerId,x:event.clientX,y:event.clientY,ox:state.x,oy:state.y};actor.setPointerCapture(event.pointerId);});
actor.addEventListener('pointermove',event=>{if(state.drag?.id!==event.pointerId)return;state.x=state.drag.ox+event.clientX-state.drag.x;state.y=state.drag.oy+event.clientY-state.drag.y;layout();});
for(const kind of ['pointerup','pointercancel'])actor.addEventListener(kind,()=>state.drag=null);
root.addEventListener('click',event=>{const b=event.target.closest('[data-size]');if(!b)return;state.size+=Number(b.dataset.size)*24;layout();});
window.addEventListener('resize',layout,{passive:true});
window.addEventListener('pointermove',event=>{if(state.drag)return;const r=actor.getBoundingClientRect();const dx=event.clientX-(r.left+r.width/2),dy=event.clientY-(r.top+r.height*.54),length=Math.hypot(dx,dy)||1,s=Math.min(length/(r.width*1.5),1);state.targetX=dx/length*s*13;state.targetY=dy/length*s*11;state.last=performance.now();},{passive:true});
function animate(){if(!root.isConnected)return;if(performance.now()-state.last>10000){state.targetX=Math.sin(performance.now()/2000)*3;state.targetY=0;}state.px+=(state.targetX-state.px)*.15;state.py+=(state.targetY-state.py)*.15;if(state.pupil)state.pupil.setAttribute('transform','translate('+state.px.toFixed(2)+' '+state.py.toFixed(2)+')');requestAnimationFrame(animate);}requestAnimationFrame(animate);
const reduced=matchMedia('(prefers-reduced-motion: reduce)');
function blink(){if(reduced.matches||document.hidden)return;root.classList.add('fm-blink');setTimeout(()=>root.classList.remove('fm-blink'),165);}
(function tick(){setTimeout(()=>{blink();tick();},3300+Math.random()*3500);})();
let speechTimer=0;
window.addEventListener('gelato-agent-response',event=>{const text=String(event.detail?.answer||'');if(!text)return;bubble.textContent=text.slice(0,180)+(text.length>180?'…':'');root.classList.add('fm-has-speech','fm-speaking');clearTimeout(speechTimer);speechTimer=setTimeout(()=>root.classList.remove('fm-has-speech','fm-speaking'),Math.min(8500,1800+text.length*38));});
window.addEventListener('gelato-agent-status',event=>{const value=String(event.detail?.message||'');root.classList.toggle('fm-thinking',/checking your restaurant context|thinking/i.test(value));});
window.addEventListener('gelato-agent-listening-change',event=>root.classList.toggle('fm-listening',!!event.detail?.listening&&!!event.detail?.microphoneActive));
fetch('assets/food-monster/chef-mascot.svg',{credentials:'same-origin'}).then(r=>{if(!r.ok)throw Error('artwork');return r.text()}).then(content=>{const doc=new DOMParser().parseFromString(content,'image/svg+xml');if(doc.documentElement.localName!=='svg'||doc.querySelector('parsererror'))throw Error('invalid svg');figure.append(document.importNode(doc.documentElement,true));state.pupil=figure.querySelector('[data-fm-pupil]');}).catch(()=>figure.textContent='👁️');
window.GelatoFoodMonster={root,blink,get isStage(){return stage;}};
})();