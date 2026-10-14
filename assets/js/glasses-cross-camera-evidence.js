/* Gelato 285D2: read-only, class-level evidence from independent cameras.
 * Same label is NOT same object, placement verification or permission for a KDS write.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoCrossCameraEvidence=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const sha=/^[0-9a-f]{64}$/i;
 const norm=s=>String(s||'').normalize('NFKC').toLowerCase()
   .replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');
 const box=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&
   b.x>=0&&b.y>=0&&b.width>0&&b.height>0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
 function create({now=()=>Date.now(),maxAgeMs=1400,maxSkewMs=900,minConfidence=.85}={}){
  const frames=new Map();let key='';
  const fresh=(t,n)=>Number.isFinite(t)&&n-t>=0&&n-t<=maxAgeMs;
  const scope=c=>[c.devicePublicId,c.locationId,c.stationPublicId,c.modelSha256].join('|');
  const valid=c=>c?.devicePublicId&&c?.locationId&&c?.stationPublicId&&sha.test(String(c?.modelSha256||''));
  function reset(){frames.clear();key='';}
  function clearSlot(slot){frames.delete(Number(slot));}
  function summary(items=[],timestamp=now()){
   const validFrames=[...frames.values()].filter(f=>fresh(f.at,timestamp));
   const classes=new Map();
   for(const f of validFrames)for(const [label,confidence] of f.labels){
    if(!classes.has(label))classes.set(label,[]);
    classes.get(label).push({slot:f.slot,at:f.at,confidence});
   }
   const evidence=[];
   for(const [label,all] of classes){
    all.sort((a,b)=>a.at-b.at);
    let best=[];
    for(let i=0;i<all.length;i++){
     const group=all.slice(i).filter(x=>x.at-all[i].at<=maxSkewMs);
     if(group.length>best.length)best=group;
    }
    if(best.length<2)continue;
    const ticketCandidateCount=(Array.isArray(items)?items:[]).filter(item=>
      item?.kdsItemPublicId&&item?.ticket?.checkPublicId&&
      ['queued','in_progress'].includes(item.status)&&
      Array.isArray(item.menu?.ingredients)&&item.menu.ingredients.some(row=>
        norm(row?.canonicalName||row?.name)===label)).length;
    evidence.push({
     label,sourceSlots:best.map(x=>x.slot).sort((a,b)=>a-b),
     cameraCount:best.length,minimumConfidence:Math.min(...best.map(x=>x.confidence)),
     capturedAtMin:Math.min(...best.map(x=>x.at)),
     capturedAtMax:Math.max(...best.map(x=>x.at)),
     ticketCandidateCount,ticketAmbiguous:ticketCandidateCount!==1,
     status:'class_agreement_only',sameObjectVerified:false,placementVerified:false,
     authoritative:false
    });
   }
   evidence.sort((a,b)=>b.cameraCount-a.cameraCount||a.label.localeCompare(b.label));
   return {status:evidence.length?'correlated_read_only':'waiting_for_independent_sources',
    activeSourceCount:validFrames.length,corroboratedClassCount:evidence.length,
    evidence:evidence.slice(0,12)};
  }
  function ingest(frame,context={}){
   const timestamp=now();
   if(!valid(context)||!frame)return {accepted:false,reason:'invalid_context'};
   const newKey=scope(context);
   if(key&&key!==newKey)reset();
   key=newKey;
   const slot=Number(frame.cameraSlot??frame.slot);
   if(!Number.isInteger(slot)||slot<1||slot>5)return {accepted:false,reason:'invalid_slot'};
   const at=slot===1?frame.timestamp:frame.capturedAt;
   if(!fresh(at,timestamp))return {accepted:false,reason:'stale_frame'};
   if(frame.devicePublicId!==context.devicePublicId||frame.stationPublicId!==context.stationPublicId||
     Number(frame.locationId)!==Number(context.locationId)||frame.modelSha256!==context.modelSha256)
    return {accepted:false,reason:'scope_mismatch'};
   if(slot>1&&(frame.sourceKind!=='stationary'||frame.scope!=='assigned_station'))
    return {accepted:false,reason:'unscoped_camera'};
   if(slot===1&&frame.sourceKind==='stationary')
    return {accepted:false,reason:'invalid_primary_role'};
   if(frames.has(slot)&&at<=frames.get(slot).at)
    return {accepted:false,reason:'duplicate_or_replay'};
   const labels=new Map();
   for(const d of (Array.isArray(frame.objects)?frame.objects:[]).slice(0,32)){
    const label=norm(d?.label);
    if(!label||label.length>100||!Number.isFinite(d?.confidence)||
       d.confidence<minConfidence||d.confidence>1||!box(d?.bbox))continue;
    labels.set(label,Math.max(labels.get(label)||0,d.confidence));
   }
   frames.set(slot,{slot,at,labels});
   for(const [id,record] of frames)if(!fresh(record.at,timestamp))frames.delete(id);
   return {accepted:true,summary:summary(context.items,timestamp)};
  }
  return Object.freeze({ingest,summary,clearSlot,reset});
 }
 return Object.freeze({create,norm});
});
