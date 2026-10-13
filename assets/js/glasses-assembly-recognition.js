(()=>{'use strict';
/* Section 266: read-only recognition decision for the local assembly HUD.
 * A camera label alone NEVER means an ingredient was placed. Automatic
 * acknowledgment requires a matching, governed, confirmed build component. */
function normalize(value){return String(value||'').normalize('NFKC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');}
function evaluate(input){
  const mode=input?.mode||'manual';
  const outcome={status:'manual',suggestion:null,automatic:false,reason:'Manual entry selected'};
  if(mode==='manual')return outcome;
  outcome.status='waiting';outcome.reason='Waiting for an active camera and a governed ONNX model';
  if(input?.adapter!=='onnx'||!input?.modelReady||!input?.cameraReady||!input?.runtimeReady)return outcome;
  if(!input?.item||!input?.ingredient||!input?.build||String(input.build.kdsItemPublicId)!==String(input.item.kdsItemPublicId)){
    outcome.reason='Start a governed build for the selected KDS item';return outcome;
  }
  const wanted=normalize(input.ingredient.canonicalName||input.ingredient.name);
  const components=(input.build.components||[]).filter(c=>normalize(c.displayName)===wanted);
  if(!wanted||components.length!==1){outcome.reason='Unique ingredient-to-build mapping required';return outcome;}
  const component=components[0];
  const matches=(input.tracks||[]).filter(t=>t&&t.present===true&&t.stable===true&&t.profileMatched===true&&
    String(t.componentKey||'')===String(component.componentKey||'')&&
    normalize(t.label)===normalize(component.displayName)&&
    Number(t.frames||0)>=4&&
    Number(input.now)-Number(t.lastSeen||0)<=1200&&Number(input.now)>=Number(t.lastSeen||0)&&
    Number(t.firstSeen||0)>=Number(input.barrierAt||0));
  if(matches.length!==1){outcome.reason='Waiting for a fresh, stable camera observation';return outcome;}
  const t=matches[0],threshold=Math.max(.4,Math.min(.99,Number(input.threshold)||.75));
  if(!Number.isFinite(t.confidence)||Number(t.confidence)<threshold){outcome.reason='Camera confidence below threshold';return outcome;}
  outcome.status='suggested';
  outcome.suggestion={name:input.ingredient.name,confidence:Number(t.confidence),trackId:String(t.trackId||''),componentKey:String(t.componentKey||''),modelSha256:String(input.modelSha256||'')};
  outcome.reason='Stable camera match; operator review is required';
  if(mode!=='automatic')return outcome;
  if(input.allowAutomatic!==true){outcome.reason='Automatic observations are paused';return outcome;}
  if(Number(t.confidence)<Math.max(.90,threshold)){outcome.reason='Automatic confirmation requires at least 90% camera confidence';return outcome;}
  if(component.status!=='confirmed'||!Number.isFinite(Number(component.confidence))||Number(component.confidence)<Math.max(.90,threshold)){
    outcome.reason='Waiting for confirmed governed build evidence';return outcome;
  }
  if(!t.submitted||!t.trackId||!input.modelSha256){outcome.reason='Traceable model and track evidence required';return outcome;}
  outcome.status='qualified';outcome.automatic=true;outcome.reason='Confirmed governed build component and fresh stable camera match';
  return outcome;
}
window.GelatoGlassesAssemblyRecognition={normalize,evaluate};
})();
