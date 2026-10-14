/* Gelato 285D5 — manual, volatile production-readiness evidence panel.
 * No silent video capture, data upload, or background persistence.
 */
(()=>{'use strict';
 const factory=window.GelatoPlacementReliability;
 if(!factory)return;
 const engine=factory.create(),$=id=>document.getElementById(id);
 let lastReviewId=null;
 function render(){
  const s=engine.snapshot(),node=$('glassesReliabilityStatus');
  if(node)node.textContent='FIELD VALIDATION REQUIRED · Unattended progression OFF · '+
   s.accepted+'/'+s.frames+' eligible frames · '+s.proposals+' hypotheses · '+
   s.reviewed+' reviewed · '+s.operatorLabeled+' explicitly labeled · '+
   s.operatorReportedMisses+' operator-reported misses · p95 delay '+
   (s.p95FrameDelayMs===null?'—':s.p95FrameDelayMs+' ms');
  const detail=$('glassesReliabilityDetail');
  if(detail)detail.textContent=s.operatorLabeled?
   'Operator-labeled precision proxy: '+Math.round(100*(s.labeledPrecisionProxy||0))+
     '%; recall proxy: '+(s.labeledRecallProxy===null?'unknown':Math.round(100*s.labeledRecallProxy)+'%')+
     '. Labels are not independent ground truth.':
   'No labeled field samples. Accuracy and recall are unknown.';
  const labelButton=$('glassesReliabilityLabel');
  if(labelButton)labelButton.disabled=!lastReviewId;
 }
 function reset(){engine.reset();lastReviewId=null;render();}
 window.addEventListener('gelato-glasses-placement-observation',e=>{
  const d=e.detail||{};
  engine.frame(d.frame||{});
  if(d.candidate)engine.proposed(d.candidate);
  render();
 });
 window.addEventListener('gelato-glasses-placement-outcome',e=>{
  const d=e.detail||{};
  if(engine.reviewedProposal(d)){lastReviewId=d.id;render();}
 });
 for(const name of ['gelato-glasses-hand-reset','gelato-glasses-runtime-revoked',
   'gelato-glasses-location-change','gelato-glasses-camera-source-change',
   'gelato-camera-configuration-changed','pagehide'])window.addEventListener(name,reset);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)reset();});
 function eligibleForManualLabel(){return !!lastReviewId&&!document.hidden&&
  !!window.GelatoGlassesNavigation?.primaryCamera?.()?.srcObject;}
 function mount(){
  $('glassesReliabilityLabel')?.addEventListener('click',()=>{
   if(!eligibleForManualLabel())return;
   const v=$('glassesReliabilityOutcome')?.value;
   const res=engine.label({id:lastReviewId,outcome:v,explicit:true});
   const status=$('glassesReliabilityLabelStatus');
   if(status)status.textContent=res.ok?'Operator label captured locally; not independent ground truth.':
     'Label rejected: '+res.reason;
   if(res.ok)lastReviewId=null;
   render();
  });
  $('glassesReliabilityMissed')?.addEventListener('click',()=>{
   if(!window.GelatoGlassesNavigation?.primaryCamera?.()?.srcObject||document.hidden)return;
   engine.reportMiss({explicit:true});render();
  });
  $('glassesReliabilityExport')?.addEventListener('click',()=>{
   const report=JSON.stringify({generatedAt:new Date().toISOString(),...engine.snapshot()},null,2)+'\n';
   const url=URL.createObjectURL(new Blob([report],{type:'application/json'}));
   const link=document.createElement('a');link.href=url;link.download='gelato-placement-readiness.json';
   link.click();URL.revokeObjectURL(url);
  });
  render();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
 window.GelatoPlacementReliabilityUI={summary:()=>engine.snapshot(),reset};
})();
