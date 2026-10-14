(()=>{'use strict';
const $=id=>document.getElementById(id);
const core=window.GelatoHandTracker;if(!core)return;
const tracker=core.createTracker();
const visual=window.GelatoHandBoxStabilizer?.create?.()||null;
let visualBoxes=[];
const held=window.GelatoHeldIngredientEvidence?.create?.()||null;
let heldCandidates=[],lastModel='';
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
  const evidence=heldCandidates.find(r=>r.trackId===t.trackId);
  if(detail)detail.textContent=evidence?
    (evidence.status==='review'?'Review suggested: ':'Possible: ')+evidence.label+' · '+Math.round(evidence.confidence*100)+'% · '+evidence.frames+' distinct frames':
    live?'Tracking hand · waiting for ingredient model evidence':t.status==='lost'||t.status==='occluded'?'Hand temporarily out of view':'Waiting for hand detector';
 }
 const model=$('glassesHeldModelStatus');
 if(model)model.textContent=lastModel||'Ingredient recognition needs a verified governed ONNX model; hand tracking works independently.';
 const suggestion=$('glassesHeldSuggestions');
 if(suggestion){suggestion.replaceChildren();
   for(const r of heldCandidates){
     const line=node('li');line.appendChild(node('strong','Hand '+r.slot+' · '+r.label));
     line.appendChild(node('span',r.status==='review'?'REVIEW · '+r.frames+' detections':'POSSIBLE · '+r.frames+' detections'));
     suggestion.appendChild(line);
   }
 }
 const title=$('glassesHandFocus');
 if(title)title.textContent=current.focused.length?
   'KDS focus: '+current.focused.map(c=>c.name).join(' · '):
   current.activeItem?'All configured ingredients accounted for':'Select an active KDS item to focus recognition';
 const layer=$('glassesHandBoxes');if(!layer)return;
 layer.replaceChildren();
 for(const t of visualBoxes){
  if(!t.bbox)continue;
  const map=window.GelatoGlassesNavigation?.cameraBoxToStage?.(t.bbox);
  if(!map)continue;
  const box=node('div');box.className='glasses-hand-tracked-box'+(t.held?' is-reacquiring':'');
  for(const [name,value] of Object.entries({left:map.left,top:map.top,width:map.width,height:map.height}))
    box.style[name]=(value*100)+'%';
  const evidence=t.held?null:heldCandidates.find(r=>r.trackId===t.trackId);
  box.appendChild(node('span',names[t.slot-1]+' · '+(t.held?'REACQUIRING':evidence?(evidence.status==='review'?'REVIEW ':'POSSIBLE ')+evidence.label:'TRACKING')));
  layer.appendChild(box);
 }
}
function reset(){
 tracker.reset();visual?.reset?.();visualBoxes=[];held?.reset();heldCandidates=[];lastModel='';lastFrame=0;
 current={tracks:[{slot:1,status:'ready'},{slot:2,status:'ready'}],focused:[],activeItem:null};
 render();
}
window.addEventListener('gelato-glasses-hand-frame',event=>{
 const d=event.detail||{};
 if(!d.cameraReady||!d.modelReady){reset();return;}
 lastFrame=Number(d.timestamp)||Date.now();
 current=tracker.step(d.hands||[],lastFrame,d.activeItem,d.actions||{},[]);
 visualBoxes=visual?visual.step(current.tracks,lastFrame):current.tracks.filter(t=>t.bbox&&['tracking','identifying'].includes(t.status));
 heldCandidates=held?.update({item:d.activeItem,actions:d.actions||{},tracks:current.tracks,
   objects:d.objects||[],objectSeq:d.objectSeq,objectTime:d.objectTime,
   modelSha256:d.modelSha256,now:lastFrame})||[];
 lastModel=/^[a-f0-9]{64}$/i.test(String(d.modelSha256||''))?
   'Verified ingredient model available · suggestions require three distinct detections and operator review.':
   'Hand tracking active · governed ingredient model not loaded; capture and review examples to train recognition.';
 render();
});
window.addEventListener('gelato-glasses-hand-reset',reset);
window.addEventListener('pagehide',reset);
window.setInterval?.(()=>{
 if(!lastFrame||Date.now()-lastFrame<350)return;
 const at=Date.now();current=tracker.step([],at,null,{},[]);
 visualBoxes=visual?visual.step(current.tracks,at):[];render();
},250);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render,{once:true});else render();
window.GelatoHandTrackingUI={reset,state:()=>current,evidence:()=>heldCandidates.slice()};
const capture=$('glassesCaptureHeldObject'),review=$('glassesReviewHeldObject');
if(capture)capture.addEventListener('click',async()=>{
  const note=$('glassesHeldCaptureStatus');
  if(!current.activeItem){if(note)note.textContent='Select a live KDS item first.';return;}
  if(!window.GelatoCameraConfiguration?.isEnabled?.(1)){
    if(note)note.textContent='Enable Camera 1 in the Runtime camera settings before capturing.';return;
  }
  if(note)note.textContent='Capturing a local frame for human labeling…';
  try{
    const photo=await window.GelatoCameraConfiguration.capture(1);
    if(note)note.textContent=photo?'Saved locally for review. Use Open Training to label the ingredient; uploading requires explicit consent.':'No photo captured; check the camera status.';
  }catch(e){if(note)note.textContent='Capture failed: '+String(e?.message||e);}
});
if(review)review.addEventListener('click',()=>{
  window.GelatoGlassesTabs?.select?.('camera');
  window.GelatoCameraWorkspace?.select?.('training');
});
})();