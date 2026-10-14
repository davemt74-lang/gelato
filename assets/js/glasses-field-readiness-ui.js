/* Gelato 285E5 — opt-in independent field trial review, session memory only.
 * The label checkbox records a reviewer attestation, not proof of independence.
 * No persistence, camera recording, or KDS side effects.
 */
(()=>{'use strict';
 const create=window.GelatoFieldReadiness?.create;
 if(!create)return;
 const engine=create(),$=id=>document.getElementById(id);
 const field=id=>$(id)?.value?.trim()||'';
 let lastMessage='';
 function render(){
  const s=engine.evaluate(),status=$('glassesFieldStatus');
  if(!status)return;
  status.textContent=(lastMessage?lastMessage+' · ':'')+
    'Local independent trials: '+s.samples+' · sessions '+s.sessions+
    ' · TP '+s.counts.tp+' / FP '+s.counts.fp+' / FN '+s.counts.fn+' / TN '+s.counts.tn+
    ' · precision lower 95% '+(s.precisionLower95===null?'unknown':(100*s.precisionLower95).toFixed(1)+'%')+
    ' · recall lower 95% '+(s.recallLower95===null?'unknown':(100*s.recallLower95).toFixed(1)+'%')+
    ' · p95 latency '+(s.p95LatencyMs===null?'unknown':s.p95LatencyMs+'ms')+
    ' · criteria '+(s.criteriaMet?'met (human certification still required)':'NOT MET')+
    ' · '+s.blockingReasons.join(', ')+' · Automatic advancement OFF.';
 }
 function record(){
  if(!$('#glassesFieldIndependent')?.checked){
   lastMessage='Independent review attestation required';render();return;
  }
  const latency=field('glassesFieldLatency');
  if(!latency){lastMessage='A measured latency is required';render();return;}
  const result=engine.record({id:field('glassesFieldTrialId'),
   session:field('glassesFieldSession'),cameraSlot:Number(field('glassesFieldCamera')),
   outcome:field('glassesFieldOutcome'),at:Date.now(),latencyMs:Number(latency),
   independentlyAdjudicated:true});
  lastMessage=result.ok?'Trial accepted locally; field accuracy still uncertified':'Trial rejected: '+result.reason;
  if(result.ok){$('#glassesFieldTrialId').value='';$('#glassesFieldIndependent').checked=false;}
  render();
 }
 function exportReport(){
  const data=JSON.stringify({generatedAt:new Date().toISOString(),...engine.evaluate()},null,2)+'\n';
  const url=URL.createObjectURL(new Blob([data],{type:'application/json'}));
  const a=document.createElement('a');a.href=url;a.download='gelato-field-readiness-aggregate.json';a.click();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
 }
 function reset(){engine.reset();lastMessage='Local trial records cleared';render();}
 function mount(){
  $('glassesFieldAdd')?.addEventListener('click',record);
  $('glassesFieldReset')?.addEventListener('click',reset);
  $('glassesFieldExport')?.addEventListener('click',exportReport);
  render();
 }
 for(const type of ['gelato-glasses-runtime-revoked','gelato-glasses-location-change',
  'gelato-glasses-camera-source-change','pagehide'])window.addEventListener(type,reset);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
 window.GelatoFieldReadinessUI=Object.freeze({summary:()=>engine.evaluate(),reset});
})();
