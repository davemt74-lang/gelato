/* Gelato 285D5 — transient, read-only recognition reliability diagnostics.
 * Operator labels are not independently verified truth. No camera pixels,
 * customer data, identities, network calls, KDS writes or persistence.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoPlacementReliability=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const KNOWN_REASONS=new Set(['stale_hand_frame','stale_object_frame','duplicate_or_replay',
   'invalid_scope','scope_changed','rejected','invalid_frame']);
 const percentile95=a=>a.length?([...a].sort((x,y)=>x-y)[Math.ceil(.95*a.length)-1]):null;
 function create({now=()=>Date.now(),maxFrames=500,maxProposals=100}={}){
  let epoch=0,startedAt=now(),lastSeq=-1,frames=0,accepted=0,skipped=0,
    late=0,proposals=0,reviewed=0,rejected=0,confirmed=0,missed=0;
  let latency=[],reasons=Object.create(null),labels=new Map(),seen=new Map();
  const FRAME_CAP=Math.min(2000,Math.max(20,Number(maxFrames)||500));
  const PROPOSAL_CAP=Math.min(300,Math.max(10,Number(maxProposals)||100));
  function reset(){epoch++;startedAt=now();lastSeq=-1;frames=accepted=skipped=late=0;
    proposals=reviewed=rejected=confirmed=missed=0;latency=[];reasons=Object.create(null);
    labels.clear();seen.clear();}
  function frame({seq,frameAt,handAt,accepted:ok,reason,sourceSlot=1}={}){
   if(sourceSlot!==1||!Number.isSafeInteger(seq)||seq<0)return {ok:false,reason:'invalid_source'};
   if(seq<=lastSeq){skipped++;reasons.duplicate_or_replay=(reasons.duplicate_or_replay||0)+1;
     return {ok:false,reason:'duplicate_or_replay'};}
   lastSeq=seq;frames++;
   if(ok&&Number.isFinite(frameAt)&&Number.isFinite(handAt)&&
       frameAt>=handAt&&now()>=frameAt){
    accepted++;
    const delay=now()-frameAt;
    if(delay>500)late++;
    latency.push(Math.min(30000,delay));if(latency.length>FRAME_CAP)latency.shift();
   }else{skipped++;const key=KNOWN_REASONS.has(reason)?reason:'rejected';
    reasons[key]=(reasons[key]||0)+1;}
   return {ok:true};
  }
  function proposed(candidate){
   const id=candidate?.id;
   if(typeof id!=='string'||!id||seen.has(id))return false;
   proposals++;
   seen.set(id,{reviewed:false,label:null});
   if(seen.size>PROPOSAL_CAP){const oldest=seen.keys().next().value;seen.delete(oldest);labels.delete(oldest);}
   return true;
  }
  function reviewedProposal({id,decision}={}){
   const e=seen.get(id);if(!e||e.reviewed||!['confirm','reject'].includes(decision))return false;
   e.reviewed=true;reviewed++;if(decision==='confirm')confirmed++;else rejected++;
   return true;
  }
  function label({id,outcome,explicit=false}={}){
   if(explicit!==true||!['observed_placed','observed_not_placed','uncertain'].includes(outcome))
     return {ok:false,reason:'operator_label_required'};
   const p=seen.get(id);
   if(!p||!p.reviewed||p.label!==null)return {ok:false,reason:'missing_or_already_labeled'};
   p.label=outcome;
   if(outcome!=='uncertain')labels.set(id,outcome);
   return {ok:true};
  }
  function reportMiss({explicit=false}={}){
   if(explicit!==true)return {ok:false,reason:'operator_report_required'};
   missed++;return {ok:true};
  }
  function snapshot(){
   const list=[...labels.values()];const tp=list.filter(x=>x==='observed_placed').length;
   const fp=list.filter(x=>x==='observed_not_placed').length;
   const denom=tp+fp;
   return Object.freeze({version:'285D5',epoch,startedAt,frames,accepted,skipped,
    over500ms:late,p95FrameDelayMs:percentile95(latency),reasons:{...reasons},
    proposals,reviewed,confirmed,rejected,operatorLabeled:denom,
    operatorLabeledPlaced:tp,operatorLabeledFalseAlarms:fp,
    operatorReportedMisses:missed,
    labeledPrecisionProxy:denom?Math.round(1000*tp/denom)/1000:null,
    labeledRecallProxy:tp+missed?Math.round(1000*tp/(tp+missed))/1000:null,
    metricsAreOperatorReported:true,automaticProgressionEnabled:false,
    cameraPlacementCertified:false,fieldCertification:'required'});
  }
  return Object.freeze({frame,proposed,reviewedProposal,label,reportMiss,snapshot,reset});
 }
 return Object.freeze({create});
});
