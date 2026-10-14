/* Gelato 285E2 — conservative hand-to-food movement evidence.
 * No camera pixels, storage, network, KDS mutations or automatic approvals.
 * A review hypothesis requires held ingredient outside target, hand transport,
 * object disappearance inside the zone, plus a subsequent empty-hand frame.
 */
((root,factory)=>{'use strict';const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoHandToFoodEvidence=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const SHA=/^[a-f0-9]{64}$/i,box=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&
 b.width>0&&b.height>0&&b.x>=0&&b.y>=0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
 const center=b=>({x:b.x+b.width/2,y:b.y+b.height/2});
 const distance=(a,b)=>{const x=center(a),y=center(b);return Math.hypot(x.x-y.x,x.y-y.y);};
 const inside=(b,z)=>{const c=center(b);return c.x>=z.x&&c.x<=z.x+z.width&&c.y>=z.y&&c.y<=z.y+z.height;};
 const ingredient=s=>String(s||'').normalize('NFKC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');
 function create({now=()=>Date.now(),minFrames=3,minTravel=.13,maxAgeMs=500,maxGapMs=650,minConfidence=.85}={}){
  let scope='',seq=-1,lastAt=-Infinity,tracks=new Map(),proposals=[],serial=0;
  function reset(){scope='';seq=-1;lastAt=-Infinity;tracks.clear();proposals=[];serial=0;}
  function observe({context,frame}={}){
   const time=now();
   const valid= context&&typeof context.stationPublicId==='string'&&context.stationPublicId&&
      typeof context.ticketPublicId==='string'&&context.ticketPublicId&&
      typeof context.itemPublicId==='string'&&context.itemPublicId&&SHA.test(context.modelSha256||'')&&
      Number.isSafeInteger(context.cameraSlot)&&context.cameraSlot>=1&&context.cameraSlot<=5&&
      typeof context.sourceId==='string'&&context.sourceId&&Number.isSafeInteger(context.generation)&&
      box(context.zone)&&Array.isArray(context.expectedIngredients)&&context.expectedIngredients.length>0;
   if(!valid){reset();return {accepted:false,reason:'invalid_scope',suggestions:[]};}
   const key=[context.stationPublicId,context.ticketPublicId,context.itemPublicId,context.cameraSlot,
      context.sourceId,context.generation,context.modelSha256,JSON.stringify(context.zone)].join('|');
   if(scope!==key){reset();scope=key;}
   if(!frame||!Number.isSafeInteger(frame.seq)||frame.seq<=seq)return {accepted:false,reason:'replay',suggestions:[]};
   if(!Number.isFinite(frame.at)||frame.at>time||time-frame.at>maxAgeMs||frame.at<=lastAt){
      tracks.clear();return {accepted:false,reason:'stale_frame',suggestions:[]};
   }
   if(lastAt!==-Infinity&&frame.at-lastAt>maxGapMs)tracks.clear();
   seq=frame.seq;lastAt=frame.at;
   const allowed=new Set(context.expectedIngredients.map(ingredient).filter(Boolean));
   const hands=(Array.isArray(frame.hands)?frame.hands:[]).filter(h=>h&&
      typeof h.trackId==='string'&&h.trackId&&box(h.bbox)&&
      ['tracking','identifying'].includes(h.status)&&Number.isFinite(h.confidence)&&h.confidence>=.5);
   const objects=(Array.isArray(frame.objects)?frame.objects:[]).filter(o=>o&&box(o.bbox)&&
      Number.isFinite(o.confidence)&&o.confidence>=minConfidence&&o.confidence<=1&&allowed.has(ingredient(o.label)));
   // 285E3: Never infer release from missing detections while hands overlap.
   // Ambiguous identity is discarded rather than bridged across occlusion.
   const conflicted=new Set(),identities=new Set();
   for(const h of hands){
     if(identities.has(h.trackId))conflicted.add(h.trackId);
     identities.add(h.trackId);
   }
   for(let i=0;i<hands.length;i++)for(let j=i+1;j<hands.length;j++){
     if(distance(hands[i].bbox,hands[j].bbox)<.18){
       conflicted.add(hands[i].trackId);conflicted.add(hands[j].trackId);
     }
   }
   for(const id of conflicted)tracks.delete(id);
   const emitted=[];
   for(const h of hands){
     if(conflicted.has(h.trackId))continue;
     const close=objects.map(o=>({object:o,d:distance(o.bbox,h.bbox)})).filter(x=>x.d<=.22).sort((a,b)=>a.d-b.d);
     if(close.length>1&&close[1].d-close[0].d<.065){tracks.delete(h.trackId);continue;}
     if(hands.some(other=>other.trackId!==h.trackId&&close[0]&&
          Math.abs(distance(close[0].object.bbox,other.bbox)-close[0].d)<.065)){
       tracks.delete(h.trackId);continue;
     }
     const match=close[0]?.object,old=tracks.get(h.trackId);
     if(match){
       const label=ingredient(match.label);
       if(!old||old.label!==label){
         if(inside(h.bbox,context.zone)){tracks.delete(h.trackId);continue;}
         tracks.set(h.trackId,{label,first:frame.at,last:frame.at,frames:1,origin:h.bbox,
           inZone:false,disappeared:0});
       }else{
         old.last=frame.at;old.frames++;
         if(old.frames>=minFrames&&distance(old.origin,h.bbox)>=minTravel&&inside(h.bbox,context.zone))old.inZone=true;
         old.disappeared=0;
       }
     }else if(old){
       if(!inside(h.bbox,context.zone)||!old.inZone){tracks.delete(h.trackId);continue;}
       old.disappeared++;
       if(old.disappeared>=2){
         const suggestion=Object.freeze({id:'e285e2-'+(++serial),label:old.label,trackId:h.trackId,
           stationPublicId:context.stationPublicId,ticketPublicId:context.ticketPublicId,
           itemPublicId:context.itemPublicId,cameraSlot:context.cameraSlot,modelSha256:context.modelSha256,
           observedAt:frame.at,reviewRequired:true,verifiedPlacement:false,automatic:false});
         emitted.push(suggestion);proposals.push(suggestion);tracks.delete(h.trackId);
       }
     }
   }
   const live=new Set(hands.map(h=>h.trackId));
   for(const [id,t] of tracks)if(!live.has(id)||frame.at-t.last>maxGapMs)tracks.delete(id);
   if(proposals.length>32)proposals=proposals.slice(-32);
   return {accepted:true,suggestions:emitted,reviewRequired:emitted.length>0,kdsChanged:false};
  }
  return Object.freeze({observe,reset,snapshot:()=>proposals.slice()});
 }
 return Object.freeze({create});
});
