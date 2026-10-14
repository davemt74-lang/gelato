/* Section 285B: read-only Camera 1 hand+ingredient evidence for a KDS focus.
 * No frames, KDS writes, POS writes, biometrics or simulated detections.
 * A label common to multiple active items is never sufficient to auto-focus.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoProductionAutoFocus=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
const normalize=value=>String(value||'').normalize('NFKC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');
const validBox=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&
  b.width>0&&b.height>0&&b.x>=0&&b.y>=0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
const center=b=>({x:b.x+b.width/2,y:b.y+b.height/2});
const distance=(a,b)=>{const p=center(a),q=center(b);return Math.hypot(p.x-q.x,p.y-q.y);};
const goodHash=h=>/^[a-f0-9]{64}$/i.test(String(h||''));
function candidateItems(items){
 return (Array.isArray(items)?items:[]).filter(x=>x?.kdsItemPublicId&&
   x.ticket?.checkPublicId&&['queued','in_progress'].includes(x.status)&&
   Array.isArray(x.menu?.ingredients)&&x.menu.ingredients.length);
}
function labelsFor(item){
 return new Set(item.menu.ingredients.map(r=>normalize(r?.canonicalName||r?.name)).filter(Boolean));
}
function create({minFrames=4,minDurationMs=900,maxGapMs=800,maxAgeMs=550,minConfidence=.90,
   proximityMargin=.08}={}){
 let previous=null,previousSeq=null,previousTime=-Infinity,lastSuccess=null;
 function reset(){previous=null;previousSeq=null;previousTime=-Infinity;lastSuccess=null;}
 function update({items,objects,hands,seq,objectTime,handTime,now,modelSha256,
     devicePublicId,stationPublicId,sourceSlot,manualLocked=false,buildActive=false}={}){
   const invalid=()=>({status:'waiting',reason:'insufficient_evidence'});
   if(!Number.isFinite(now)||!goodHash(modelSha256)||!devicePublicId||
     !stationPublicId||sourceSlot!==1||manualLocked||buildActive){
     reset();return invalid();
   }
   if(!Number.isSafeInteger(seq)||seq<0||seq===previousSeq||
     !Number.isFinite(objectTime)||!Number.isFinite(handTime)||
     objectTime>now||handTime>now||now-objectTime>maxAgeMs||now-handTime>maxAgeMs||
     Math.abs(objectTime-handTime)>maxAgeMs){
     if(previousSeq===seq)return {status:'waiting',reason:'duplicate_frame'};
     reset();return invalid();
   }
   if(objectTime<=previousTime){reset();return invalid();}
   previousSeq=seq;previousTime=objectTime;
   const eligible=candidateItems(items);
   if(!eligible.length){reset();return {status:'waiting',reason:'no_ticket_candidates'};}
   const byLabel=new Map();
   for(const item of eligible)for(const label of labelsFor(item)){
     if(!byLabel.has(label))byLabel.set(label,[]);
     byLabel.get(label).push(item);
   }
   const trackable=(Array.isArray(hands)?hands:[]).filter(h=>
     validBox(h?.bbox)&&['left','right'].includes(h.handedness)&&Number(h.confidence)>=.5);
   const objectList=(Array.isArray(objects)?objects:[]).filter(o=>validBox(o?.bbox)&&
     Number.isFinite(Number(o.confidence))&&Number(o.confidence)>=minConfidence);
   const proposals=[];
   for(const obj of objectList){
     const label=normalize(obj.label||obj.detectorLabel),matches=byLabel.get(label)||[];
     if(!matches.length)continue;
     // Shared toppings (sauce/cheese) must never select one of two tickets.
     if(matches.length!==1){reset();return {status:'ambiguous',reason:'ingredient_shared_across_items'};}
     const near=trackable.map(h=>({hand:h,dist:distance(h.bbox,obj.bbox)}))
       .sort((a,b)=>a.dist-b.dist);
     if(!near.length)continue;
     const best=near[0],maxDistance=Math.max(.13,Math.min(.26,.10+
       Math.max(best.hand.bbox.width,best.hand.bbox.height)*.6));
     if(best.dist>maxDistance)continue;
     if(near.length>1&&near[1].dist-best.dist<proximityMargin){
       reset();return {status:'ambiguous',reason:'overlapping_hands'};
     }
     proposals.push({item:matches[0],label,hand:best.hand,confidence:Number(obj.confidence)});
   }
   if(proposals.length!==1){
     reset();return {status:proposals.length?'ambiguous':'waiting',
       reason:proposals.length?'multiple_items_or_objects':'no_unique_held_ingredient'};
   }
   const evidence=proposals[0];
   const key=[devicePublicId,stationPublicId,modelSha256,evidence.item.kdsItemPublicId,
     evidence.label,evidence.hand.handedness].join('|');
   const continued=previous&&previous.key===key&&now-previous.last<=maxGapMs;
   const record={key,frames:continued?previous.frames+1:1,
     since:continued?previous.since:now,last:now};
   previous=record;
   if(record.frames<minFrames||now-record.since<minDurationMs)
     return {status:'candidate',itemPublicId:evidence.item.kdsItemPublicId,
       frames:record.frames,confidence:evidence.confidence};
   const signature=key;
   if(lastSuccess===signature)return {status:'stable',reason:'already_selected',
      itemPublicId:evidence.item.kdsItemPublicId};
   lastSuccess=signature;
   return {status:'matched',reason:'unique_held_ingredient',
      itemPublicId:evidence.item.kdsItemPublicId,
      ticketNumber:String(evidence.item.ticket.checkNumber||''),
      ingredient:evidence.label,frames:record.frames,confidence:evidence.confidence,
      modelSha256};
 }
 return {update,reset};
}
return Object.freeze({create,candidateItems,labelsFor,normalize});
});
