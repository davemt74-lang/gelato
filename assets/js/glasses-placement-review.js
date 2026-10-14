/* Gelato 285D3 — non-authoritative placement hypothesis engine.
 * ONLY produces operator review suggestions. No image capture, KDS, POS,
 * auto Added, backend submission, network, tracking overlays or persistence.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoPlacementReview=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const SHA=/^[a-f0-9]{64}$/i;
 const normalize=s=>String(s||'').normalize('NFKC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');
 const validBox=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&
   b.x>=0&&b.y>=0&&b.width>0&&b.height>0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
 const center=b=>({x:b.x+b.width/2,y:b.y+b.height/2});
 const distance=(a,b)=>{const x=center(a),y=center(b);return Math.hypot(x.x-y.x,x.y-y.y);};
 const inside=(b,zone)=>{const p=center(b);return p.x>=zone.x&&p.y>=zone.y&&
   p.x<=zone.x+zone.width&&p.y<=zone.y+zone.height;};
 const scopeId=c=>[c.devicePublicId,c.locationId,c.stationPublicId,
   c.item?.kdsItemPublicId,c.modelSha256,[c.zone.x,c.zone.y,c.zone.width,c.zone.height].join(',')].join('|');
 function validScope(c){
  return c&&c.devicePublicId&&c.stationPublicId&&c.locationId&&SHA.test(String(c.modelSha256||''))&&
    validBox(c.zone)&&c.item?.kdsItemPublicId&&['queued','in_progress'].includes(c.item.status)&&
    Array.isArray(c.item.menu?.ingredients);
 }
 function create({now=()=>Date.now(),minHoldFrames=3,minHoldMs=200,maxHandAgeMs=400,
   maxFrameAgeMs=750,maxGapMs=650,minTravel=.14,releaseFrames=2,minZoneMs=130,
   reviewTtlMs=12000}={}){
  let scope='',previousSeq=-1,previousFrame=-Infinity,tracks=new Map(),pending=null,receipts=[],counter=0;
  function reset(){scope='';previousSeq=-1;previousFrame=-Infinity;tracks.clear();pending=null;receipts=[];counter=0;}
  function clearTracking(){tracks.clear();pending=null;}
  function summary(){
   return {status:pending?'review_required':tracks.size?'tracking_hypothesis':'waiting',
     candidate:pending?{...pending}:null,
     reviews:receipts.map(x=>({...x})),placementVerified:false,automatic:false,kdsChanged:false};
  }
  function observe({context,frame}={}){
   const at=now();
   if(!validScope(context)||!frame||!Number.isFinite(at)){
    reset();return {accepted:false,reason:'invalid_scope',...summary()};
   }
   const k=scopeId(context);
   if(scope&&scope!==k)reset();
   scope=k;
   if(!Number.isSafeInteger(frame.seq)||frame.seq<0||frame.seq<=previousSeq)
    return {accepted:false,reason:'duplicate_or_replay',...summary()};
   if(!Number.isFinite(frame.timestamp)||frame.timestamp>at||
      at-frame.timestamp>maxFrameAgeMs||frame.timestamp<=previousFrame){
    clearTracking();return {accepted:false,reason:'stale_object_frame',...summary()};
   }
   if(!Number.isFinite(frame.handTimestamp)||frame.handTimestamp>frame.timestamp||
      frame.timestamp-frame.handTimestamp>maxHandAgeMs){
    clearTracking();return {accepted:false,reason:'stale_hand_frame',...summary()};
   }
   previousSeq=frame.seq;previousFrame=frame.timestamp;
   const eligible=new Map();
   for(const ingredient of context.item.menu.ingredients){
    const label=normalize(ingredient?.canonicalName||ingredient?.name);
    const action=context.actions?.[String(context.item.menu.ingredients.indexOf(ingredient))+':'+String(ingredient?.canonicalName||ingredient?.name||'')];
    if(!label||action==='added'||action==='skipped')continue;
    eligible.set(label,(eligible.get(label)||0)+1);
   }
   const hands=(Array.isArray(frame.hands)?frame.hands:[]).filter(h=>
     h&&typeof h.trackId==='string'&&h.trackId.length>0&&
     ['tracking','identifying'].includes(h.status)&&validBox(h.bbox)&&
     Number.isFinite(h.confidence)&&h.confidence>=.5&&
     Number.isFinite(h.seen)&&Math.abs(h.seen-frame.handTimestamp)<=maxHandAgeMs).slice(0,2);
   const objects=(Array.isArray(frame.objects)?frame.objects:[]).filter(o=>
     o&&validBox(o.bbox)&&Number.isFinite(o.confidence)&&o.confidence>=.85&&o.confidence<=1&&
     eligible.get(normalize(o.label))===1).slice(0,16);
   const touched=new Set(),hypotheses=[];
   for(const hand of hands){
    touched.add(hand.trackId);
    const near=objects.map(o=>({o,dist:distance(o.bbox,hand.bbox)}))
      .filter(x=>x.dist<=Math.max(.13,Math.min(.26,.10+Math.max(hand.bbox.width,hand.bbox.height)*.6)))
      .sort((a,b)=>a.dist-b.dist);
    const conflict=near.length>1&&near[1].dist-near[0].dist<.065;
    const otherHand=hands.some(h=>h.trackId!==hand.trackId&&near[0]&&
       Math.abs(distance(near[0].o.bbox,h.bbox)-near[0].dist)<.065);
    if(conflict||otherHand){tracks.delete(hand.trackId);continue;}
    let entry=tracks.get(hand.trackId);
    if(entry&&frame.timestamp-entry.lastAt>maxGapMs){tracks.delete(hand.trackId);entry=null;}
    const chosen=near[0]?.o||null;
    if(chosen){
      const label=normalize(chosen.label);
      if(!entry||entry.label!==label){
        // Object must first be held outside the operator-calibrated target zone.
        if(inside(hand.bbox,context.zone)){tracks.delete(hand.trackId);continue;}
        entry={trackId:hand.trackId,label,displayName:chosen.label,firstAt:frame.timestamp,
          lastAt:frame.timestamp,holdFrames:1,origin:center(hand.bbox),
          carried:false,zoneAt:null,missing:0,confidence:chosen.confidence};
      }else{
        entry.holdFrames++;
        entry.lastAt=frame.timestamp;
        entry.confidence=Math.min(entry.confidence,chosen.confidence);
        if(entry.holdFrames>=minHoldFrames&&
           frame.timestamp-entry.firstAt>=minHoldMs)entry.carried=true;
        entry.missing=0;
      }
      if(entry.carried&&inside(hand.bbox,context.zone)&&
         Math.hypot(center(hand.bbox).x-entry.origin.x,center(hand.bbox).y-entry.origin.y)>=minTravel)
        entry.zoneAt??=frame.timestamp;
      tracks.set(hand.trackId,entry);
    }else if(entry){
      if(!entry.carried||!inside(hand.bbox,context.zone)||!entry.zoneAt||
         frame.timestamp-entry.zoneAt<minZoneMs){
        // A hand or ingredient disappearing before the zone is NOT release evidence.
        if(!inside(hand.bbox,context.zone))entry.zoneAt=null;
        entry.missing=0;entry.lastAt=frame.timestamp;
        tracks.set(hand.trackId,entry);continue;
      }
      entry.missing++;
      entry.lastAt=frame.timestamp;
      if(entry.missing>=releaseFrames){
        hypotheses.push({entry,hand});
        tracks.delete(hand.trackId);
      }else tracks.set(hand.trackId,entry);
    }
   }
   for(const id of [...tracks.keys()])if(!touched.has(id))tracks.delete(id);
   if(pending&&at-pending.createdAt>reviewTtlMs)pending=null;
   // Never auto-select between multiple simultaneous hand-release hypotheses.
   if(hypotheses.length===1&&!pending){
     const {entry}=hypotheses[0];
     pending={id:'local-'+(++counter),itemPublicId:context.item.kdsItemPublicId,
       label:entry.displayName,trackId:entry.trackId,modelSha256:context.modelSha256,
       createdAt:at,expiresAt:at+reviewTtlMs,
       evidence:{holdFrames:entry.holdFrames,minimumConfidence:entry.confidence,
         movementObserved:true,releaseHypothesis:true,zoneCalibrated:true},
       status:'operator_review_required',placementVerified:false,kdsChanged:false};
   }
   if(hypotheses.length>1){pending=null;clearTracking();}
   return {accepted:true,...summary()};
  }
  function review({id,decision,context}={}){
   const at=now();
   if(!pending||id!==pending.id||!validScope(context)||scopeId(context)!==scope||
      at>pending.expiresAt||!['confirm','reject'].includes(decision)){
     return {ok:false,reason:'missing_stale_or_mismatched_candidate'};
   }
   const candidate=pending;pending=null;tracks.clear();
   const receipt={id:candidate.id,itemPublicId:candidate.itemPublicId,label:candidate.label,
     decision,reviewedAt:at,operatorConfirmed:decision==='confirm',
     cameraPlacementVerified:false,ingredientAdded:false,kdsChanged:false};
   receipts.unshift(receipt);receipts=receipts.slice(0,10);
   return {ok:true,receipt:{...receipt}};
  }
  return Object.freeze({observe,review,summary,reset});
 }
 return Object.freeze({create,normalize,validBox});
});
