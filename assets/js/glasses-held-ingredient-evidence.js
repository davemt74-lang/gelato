/* Section 282: read-only, KDS-constrained evidence linking objects to real hand tracks. */
((root,factory)=>{'use strict';const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;if(root)root.GelatoHeldIngredientEvidence=api;})(typeof window!=='undefined'?window:null,()=>{'use strict';
const normalize=s=>String(s||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,' ').trim();
const inside=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&b.width>0&&b.height>0&&b.x>=0&&b.y>=0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
const mid=b=>({x:b.x+b.width/2,y:b.y+b.height/2});
const distance=(a,b)=>{const x=mid(a),y=mid(b);return Math.hypot(x.x-y.x,x.y-y.y);};
function choices(item,actions={}){
 if(!item?.kdsItemPublicId||['completed','cancelled','ready','held'].includes(item.status))return [];
 const list=Array.isArray(item?.menu?.ingredients)?item.menu.ingredients:[];
 return list.map((row,i)=>({id:i+':'+String(row?.canonicalName||row?.name||''),label:String(row?.name||'').trim(),canonical:normalize(row?.canonicalName||row?.name)}))
  .filter(x=>x.label&&x.canonical&&!['added','skipped'].includes(actions[x.id])).slice(0,3);
}
function create({minFrames=3,maxAgeMs=900,maxGapMs=1000,minConfidence=.65,ambiguityMargin=.065}={}){
 let lastItem=null,lastEpoch='',lastSeq=null,lastTime=-Infinity,history=new Map(),lastResults=[];
 function reset(){history.clear();lastItem=null;lastEpoch='';lastSeq=null;lastTime=-Infinity;lastResults=[];}
 function update({item,actions={},tracks=[],objects=[],objectSeq=null,objectTime=null,modelSha256='',now=Date.now()}={}){
  if(!Number.isFinite(now))return [];
  if(now<lastTime)reset();
  lastTime=now;
  const selected=choices(item,actions),itemKey=String(item?.kdsItemPublicId||'');
  const epoch=String(modelSha256||'');
  if(itemKey!==lastItem||epoch!==lastEpoch){history.clear();lastResults=[];lastSeq=null;lastItem=itemKey;lastEpoch=epoch;}
  const live=(Array.isArray(tracks)?tracks:[]).filter(t=>t?.trackId&&['tracking','identifying'].includes(t.status)&&inside(t.bbox));
  const identifiers=new Set(live.map(t=>t.trackId)),permissible=new Set(selected.map(x=>x.id));
  const counts=new Map();
  for(const t of live)counts.set(t.trackId,(counts.get(t.trackId)||0)+1);
  const ambiguous=new Set([...counts].filter(([,n])=>n>1).map(([id])=>id));
  // Any track overlap undermines nearest-hand attribution until a new clean history begins.
  for(let i=0;i<live.length;i++)for(let j=i+1;j<live.length;j++)
   if(distance(live[i].bbox,live[j].bbox)<ambiguityMargin*2){
    ambiguous.add(live[i].trackId);ambiguous.add(live[j].trackId);
   }
  for(const [key,v] of history)if(ambiguous.has(v.trackId))history.delete(key);
  for(const [key,v] of history)if(!identifiers.has(v.trackId)||!permissible.has(v.ingredientKey))history.delete(key);
  if(!selected.length||!live.length||!epoch||!/^[a-f0-9]{64}$/i.test(epoch)){lastResults=[];return [];}
  const distinct=Number.isSafeInteger(objectSeq)&&objectSeq>=0&&objectSeq!==lastSeq;
  const fresh=Number.isFinite(objectTime)&&objectTime<=now&&now-objectTime<=maxAgeMs;
  if(distinct&&!fresh){lastSeq=objectSeq;lastResults=[];return [];}
  if(!distinct)return lastResults.filter(r=>identifiers.has(r.trackId)&&!ambiguous.has(r.trackId)&&permissible.has(r.ingredientKey)&&now-r.lastSeen<=maxAgeMs);
  lastSeq=objectSeq;
  const eligible=(Array.isArray(objects)?objects:[]).filter(o=>inside(o?.bbox)&&Number(o.confidence)>=minConfidence&&Number(o.confidence)<=1);
  const proposals=[];
  for(const obj of eligible){
   const matches=selected.filter(c=>c.canonical===normalize(obj.label)||c.canonical===normalize(obj.detectorLabel));
   const chosen=matches.length===1?matches[0]:null;
   if(!chosen)continue;
   const scores=live.map(t=>({track:t,dist:distance(t.bbox,obj.bbox)})).sort((a,b)=>a.dist-b.dist);
   const nearest=scores[0];if(!nearest)continue;
   const proximity=Math.max(.13,Math.min(.26,.10+Math.max(nearest.track.bbox.width,nearest.track.bbox.height)*.6));
   if(nearest.dist>proximity||ambiguous.has(nearest.track.trackId))continue;
   if(scores.length>1&&scores[1].dist-nearest.dist<ambiguityMargin)continue;
   proposals.push({track:nearest.track,ingredient:chosen,object:obj,distance:nearest.dist});
  }
  proposals.sort((a,b)=>a.distance-b.distance||b.object.confidence-a.object.confidence);
  const usedHands=new Set(),next=[];
  for(const p of proposals){
   const key=p.track.trackId+'|'+p.ingredient.id;
   if(usedHands.has(p.track.trackId))continue;
   usedHands.add(p.track.trackId);
   // A hand cannot keep two ingredient identities in a continuous hold sequence.
   for(const [priorKey,v] of history)if(v.trackId===p.track.trackId&&priorKey!==key)history.delete(priorKey);
   const old=history.get(key),continuity=old&&now-old.lastSeen<=maxGapMs,count=continuity?old.frames+1:1;
   const record={slot:p.track.slot,trackId:p.track.trackId,ingredientKey:p.ingredient.id,
     label:p.ingredient.label,confidence:Number(p.object.confidence),frames:count,
     status:count>=minFrames?'review':'candidate',lastSeen:now,modelSha256:epoch,
     distance:p.distance,bbox:{...p.object.bbox}};
   history.set(key,record);next.push(record);
  }
  for(const [key,v] of history)if(now-v.lastSeen>maxGapMs)history.delete(key);
  lastResults=next;return next;
 }
 return {update,reset,snapshot:()=>lastResults.map(x=>({...x,bbox:{...x.bbox}}))};
}
return {create,choices,normalize};
});