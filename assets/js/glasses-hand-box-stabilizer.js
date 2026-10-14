/* Gelato 285D1 — display-only hand box stabilization.
 * Never use stabilized boxes as recognition, ingredient, or KDS evidence.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoHandBoxStabilizer=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const DEFAULT_HOLD_MS=180;
 const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
 const valid=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&
   b.x>=0&&b.y>=0&&b.width>0&&b.height>0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
 const box=b=>({x:b.x,y:b.y,width:b.width,height:b.height});
 const center=b=>({x:b.x+b.width/2,y:b.y+b.height/2});
 const mix=(a,b,g)=>({x:a.x+(b.x-a.x)*g,y:a.y+(b.y-a.y)*g,
   width:a.width+(b.width-a.width)*g,height:a.height+(b.height-a.height)*g});
 function create({holdMs=DEFAULT_HOLD_MS}={}){
  const hold=clamp(Number.isFinite(holdMs)?holdMs:DEFAULT_HOLD_MS,0,250);
  let previous=-Infinity;const seen=new Map();
  function reset(){previous=-Infinity;seen.clear();}
  function step(tracks=[],timestamp=Date.now()){
   if(!Number.isFinite(timestamp)||timestamp<=previous)return [];
   previous=timestamp;const output=[],active=new Set();
   for(const t of (Array.isArray(tracks)?tracks:[]).slice(0,2)){
    if(!Number.isInteger(t?.slot)||t.slot<1||t.slot>2||
      typeof t?.trackId!=='string'||!t.trackId||active.has(t.slot))continue;
    active.add(t.slot);const live=t.status==='tracking'||t.status==='identifying',old=seen.get(t.slot);
    if(live&&valid(t.bbox)&&Number.isFinite(t.seen)&&t.seen<=timestamp&&timestamp-t.seen<=250){
     const fresh=!old||old.trackId!==t.trackId||timestamp-old.lastSeen>hold||t.seen<old.lastSeen;
     let smooth=box(t.bbox);
     if(!fresh){
      const c1=center(old.bbox),c2=center(t.bbox),delta=Math.hypot(c2.x-c1.x,c2.y-c1.y);
      const gain=delta>.28?1:clamp(.25+(timestamp-old.at)/600+delta*1.7,.25,.94);
      smooth=mix(old.bbox,t.bbox,gain);
     }
     seen.set(t.slot,{trackId:t.trackId,bbox:smooth,at:timestamp,lastSeen:t.seen});
     output.push({slot:t.slot,trackId:t.trackId,bbox:box(smooth),status:t.status,held:false});
    }else if(t.status==='occluded'&&old&&old.trackId===t.trackId&&
       timestamp-old.lastSeen<=hold&&timestamp>=old.lastSeen){
     output.push({slot:t.slot,trackId:t.trackId,bbox:box(old.bbox),status:'reacquiring',held:true});
    }else seen.delete(t.slot);
   }
   for(const [slot,r] of seen)if(!active.has(slot)||timestamp-r.lastSeen>hold)seen.delete(slot);
   return output;
  }
  return {step,reset};
 }
 return {create,valid,DEFAULT_HOLD_MS};
});
