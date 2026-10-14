(()=>{'use strict';
const $=id=>document.getElementById(id);
const core=window.GelatoHandTracker;if(!core)return;
const tracker=core.createTracker();
let current={tracks:[{slot:1,status:'ready'},{slot:2,status:'ready'}],focused:[],activeItem:null};
let lastFrame=0;
const names=['Hand 1','Hand 2'];
function node(tag,txt){const el=document.createElement(tag);if(txt!==undefined)el.textContent=String(txt);return el;}
function render(){
 for(let i=0;i<2;i++){
  const t=current.tracks[i]||{status:'ready'},cell=$('glassesHandState'+(i+1));
  if(!cell)continue;
  const live=t.status==='tracking'||t.status==='identifying';
  cell.dataset.state=live?'active':t.status;
  const status=cell.querySelector('[data-hand-status]');
  if(status)status.textContent=live?'GREEN · '+(t.status==='identifying'?'IDENTIFYING':'TRACKING'):
   t.status==='lost'||t.status==='occluded'?'REACQUIRING':'READY';
  const detail=cell.querySelector('[data-hand-detail]');
  if(detail)detail.textContent=t.object?
    'In hand: '+t.object.label+' · '+Math.round(t.object.confidence*100)+'% (verify)':
    live?'Looking for active-item ingredients':t.status==='lost'||t.status==='occluded'?'Hand temporarily out of view':'Waiting for hand detector';
 }
 const title=$('glassesHandFocus');
 if(title)title.textContent=current.focused.length?
   'KDS focus: '+current.focused.map(c=>c.name).join(' · '):
   current.activeItem?'All configured ingredients accounted for':'Select an active KDS item to focus recognition';
 const layer=$('glassesHandBoxes');if(!layer)return;
 layer.replaceChildren();
 for(const t of current.tracks){
  if(!t.bbox||!['tracking','identifying'].includes(t.status))continue;
  const map=window.GelatoGlassesNavigation?.cameraBoxToStage?.(t.bbox);
  if(!map)continue;
  const box=node('div');box.className='glasses-hand-tracked-box';
  for(const [name,value] of Object.entries({left:map.left,top:map.top,width:map.width,height:map.height}))
    box.style[name]=(value*100)+'%';
  box.appendChild(node('span',names[t.slot-1]+' · '+(t.object?'IDENTIFYING '+t.object.label:'TRACKING')));
  layer.appendChild(box);
 }
}
function reset(){
 tracker.reset();lastFrame=0;
 current={tracks:[{slot:1,status:'ready'},{slot:2,status:'ready'}],focused:[],activeItem:null};
 render();
}
window.addEventListener('gelato-glasses-hand-frame',event=>{
 const d=event.detail||{};
 if(!d.cameraReady||!d.modelReady){reset();return;}
 lastFrame=Number(d.timestamp)||Date.now();
 current=tracker.step(d.hands||[],lastFrame,d.activeItem,d.actions||{},d.objects||[]);
 render();
});
window.addEventListener('gelato-glasses-hand-reset',reset);
window.setInterval?.(()=>{
 if(!lastFrame||Date.now()-lastFrame<350)return;
 current=tracker.step([],Date.now(),null,{},[]);render();
},250);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render,{once:true});else render();
window.GelatoHandTrackingUI={reset,state:()=>current};
})();