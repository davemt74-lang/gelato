/* Gelato 285D3 — read-only camera review UX. No API writes or auto-Added. */
(()=>{'use strict';
 const factory=window.GelatoPlacementReview;
 if(!factory)return;
 const $=id=>document.getElementById(id),engine=factory.create({now:()=>Date.now()});
 const motion=window.GelatoHandToFoodEvidence?.create?.({now:()=>Date.now()});
 let motionNote='';
 let zone=null,lastHandAt=0,lastHands=[],lastDecision='',lastScope='';
 const settings=['glassesPlacementX','glassesPlacementY','glassesPlacementW','glassesPlacementH'];
 const validBox=factory.validBox;
 function ctx(){
  const assembly=window.GelatoGlassesAssemblyContext?.current?.();
  const nav=window.GelatoGlassesNavigation;
  const active=nav?.handContext?.()||{};
  const global=nav?.context?.()||{};
  return assembly&&zone&&global.mode==='live'&&global.cameraLive&&
    !document.hidden&&nav?.primaryCamera?.()?.srcObject?{
    devicePublicId:assembly.devicePublicId,locationId:global.locationId,
    stationPublicId:assembly.stationPublicId,item:active.activeItem,
    actions:active.actions||{},modelSha256:nav.autoFocus?.().modelSha256||'',
    zone
  }:null;
 }
 function render(){
  const snapshot=engine.summary(),status=$('glassesPlacementStatus'),list=$('glassesPlacementCandidate');
  const accept=$('glassesPlacementConfirm'),reject=$('glassesPlacementReject');
  const c=snapshot.candidate;
  if(list)list.textContent=c?
    c.label+' · hand '+c.trackId+' · '+c.evidence.holdFrames+
    ' held-object frames · release hypothesis only · review required':
    'No placement suggestion. Calibrate a work zone and load the approved model.';
  if(status)status.textContent=!zone?
    'UNCONFIGURED · Select a camera zone to enable read-only hypotheses.':
    c?'REVIEW REQUIRED · No ingredient or KDS change will occur.':
    'ZONE SET · Watching verified hand + ingredient observations. No automatic Added.';
  if(status&&motionNote)status.textContent+=' · '+motionNote;
  if(accept)accept.disabled=!c;
  if(reject)reject.disabled=!c;
  const receipt=$('glassesPlacementReceipt');if(receipt&&lastDecision)receipt.textContent=lastDecision;
 }
 function reset({clearZone=false}={}){
  engine.reset();motion?.reset();motionNote='';lastHandAt=0;lastHands=[];lastScope='';lastDecision='';
  if(clearZone){zone=null;cameraSignature='';cameraGeneration++;}
  render();
 }
 function setZone(){
  const vals=settings.map(id=>Number($(id)?.value));
  const next={x:vals[0],y:vals[1],width:vals[2],height:vals[3]};
  const note=$('glassesPlacementReceipt');
  if(!settings.every(id=>$(id)?.value?.trim()!=='')||!validBox(next)){
   if(note)note.textContent='Invalid work-zone coordinates: use normalized 0–1 values inside the camera image.';
   return;
  }
  if(!window.GelatoGlassesNavigation?.primaryCamera?.()?.srcObject){
   if(note)note.textContent='Start Camera 1 before calibrating its preparation area.';
   return;
  }
  zone=next;reset();lastDecision='Work zone selected manually for this camera session. No KDS change.';
  render();
 }
 function review(decision){
  const candidate=engine.summary().candidate;if(!candidate)return;
  const result=engine.review({id:candidate.id,decision,context:ctx()});
  if(result.ok){
   window.dispatchEvent(new CustomEvent('gelato-glasses-placement-outcome',{detail:{id:result.receipt.id,decision}}));
   if(decision==='confirm')window.dispatchEvent(new CustomEvent('gelato-glasses-placement-reviewed',{detail:{receipt:result.receipt}}));
  }
  lastDecision=result.ok?
   (decision==='confirm'?'Operator confirmed':'Operator rejected')+
   ' the '+result.receipt.label+' placement hypothesis · local review only; ingredient NOT Added.':
   'Review expired or context changed. No kitchen changes.';
  render();
 }
 function handleHand(event){
  const d=event.detail||{};
  if(!d.cameraReady||!d.modelReady){reset();return;}
  const timestamp=Number(d.timestamp)||0;
  if(!timestamp||timestamp<=lastHandAt)return;
  lastHandAt=timestamp;
  // Tracker UI's raw, unsmoothed track boxes only. Visual interpolation is excluded.
  lastHands=(window.GelatoHandTrackingUI?.state?.().tracks||[]).filter(
    t=>t&&['tracking','identifying'].includes(t.status)).map(t=>({...t,bbox:{...t.bbox}}));
 }
 let cameraSignature='',cameraGeneration=0;
 function readonlyCameraGeneration(){
   const stream=window.GelatoGlassesNavigation?.primaryCamera?.()?.srcObject;
   const track=stream?.getVideoTracks?.()?.[0];
   const signature=String(stream?.id||'')+'|'+String(track?.id||'');
   if(signature!==cameraSignature){cameraSignature=signature;cameraGeneration++;motion?.reset();}
   return cameraGeneration;
 }
 function handleObjects(event){
  const d=event.detail||{},context=ctx();
  if(!context||d.cameraSlot!==1||d.devicePublicId!==context.devicePublicId||
    d.stationPublicId!==context.stationPublicId||
    d.modelSha256!==context.modelSha256||Number(d.locationId)!==Number(context.locationId)){
   reset();return;
  }
  const signature=[context.devicePublicId,context.locationId,context.stationPublicId,
    context.item?.kdsItemPublicId,context.modelSha256,...Object.values(zone||{})].join('|');
  if(lastScope&&signature!==lastScope){engine.reset();window.GelatoPlacementReliabilityUI?.reset?.();}
  lastScope=signature;
  const observation=engine.observe({context,frame:{seq:d.seq,timestamp:d.timestamp,
    handTimestamp:lastHandAt,hands:lastHands,objects:d.objects||[]}});
  // 285E2: independent review-only movement evidence on the same raw observations.
  // Never substitute its hypotheses for the governed placement approval pathway.
  if(motion&&context.item?.kdsItemPublicId&&context.item?.ticket?.checkPublicId){
    const video=window.GelatoGlassesNavigation?.primaryCamera?.();
    const stream=video?.srcObject,track=stream?.getVideoTracks?.()?.[0];
    const sourceId=String(track?.getSettings?.()?.deviceId||track?.id||'');
    const motionContext={stationPublicId:String(context.stationPublicId),
      ticketPublicId:String(context.item.ticket.checkPublicId),
      itemPublicId:String(context.item.kdsItemPublicId),cameraSlot:1,sourceId,
      generation:readonlyCameraGeneration(),modelSha256:String(context.modelSha256),
      zone:{...context.zone},expectedIngredients:context.item.menu.ingredients.map(
        i=>String(i?.canonicalName||i?.name||'')).filter(Boolean)};
    const outcome=motion.observe({context:motionContext,frame:{seq:d.seq,at:d.timestamp,
      hands:lastHands,objects:d.objects||[]}});
    if(outcome.suggestions.length){
      motionNote='Potential hand-to-food placement; operator review required. No KDS change.';
      window.dispatchEvent(new CustomEvent('gelato-glasses-hand-to-food-review',{
        detail:{suggestions:outcome.suggestions,reviewOnly:true,kdsChanged:false}}));
    }
  }
  window.dispatchEvent(new CustomEvent('gelato-glasses-placement-observation',{detail:{
   frame:{seq:d.seq,frameAt:d.timestamp,handAt:lastHandAt,accepted:observation.accepted,
    reason:observation.reason,sourceSlot:1},candidate:observation.candidate||null
  }}));
  render();
 }
 function mount(){
  $('glassesPlacementSetZone')?.addEventListener('click',setZone);
  $('glassesPlacementConfirm')?.addEventListener('click',()=>review('confirm'));
  $('glassesPlacementReject')?.addEventListener('click',()=>review('reject'));
  render();
 }
 window.addEventListener('gelato-glasses-hand-frame',handleHand);
 window.addEventListener('gelato-glasses-production-object-frame',handleObjects);
 window.addEventListener('gelato-glasses-hand-reset',()=>reset());
 window.addEventListener('gelato-glasses-runtime-revoked',()=>reset({clearZone:true}));
 window.addEventListener('gelato-glasses-location-change',()=>reset({clearZone:true}));
 window.addEventListener('gelato-glasses-camera-source-change',()=>reset({clearZone:true}));
 window.addEventListener('gelato-camera-configuration-changed',()=>reset({clearZone:true}));
 window.addEventListener('pagehide',()=>reset({clearZone:true}));
 document.addEventListener('visibilitychange',()=>{if(document.hidden)reset();});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
 else mount();
 window.GelatoPlacementReviewUI={summary:()=>engine.summary(),reset};
})();
