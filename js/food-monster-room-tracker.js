/* FM-G9C: anonymous short-lived face tracks with conservative local matching. */
(function(root,factory){
 const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoRoomTracker=api;
})(typeof window!=='undefined'?window:null,function(){
 'use strict';
 const threshold=.42,margin=.085;
 function distance(a,b){if(!Array.isArray(a)||!Array.isArray(b)||a.length!==128||b.length!==128)return Infinity;let d=0;for(let i=0;i<128;i++){if(!Number.isFinite(a[i])||!Number.isFinite(b[i]))return Infinity;d+=(a[i]-b[i])**2;}return Math.sqrt(d);}
 function candidate(descriptor,profiles){
  if(!Array.isArray(descriptor)||descriptor.length!==128)return null;
  const rank=(profiles||[]).filter(p=>p&&typeof p.email==='string').map(p=>({email:p.email,d:distance(descriptor,p.vector)})).sort((a,b)=>a.d-b.d);
  if(!rank.length||rank[0].d>=threshold||(rank[1]&&rank[1].d-rank[0].d<margin))return null;
  return rank[0].email;
 }
 function geometry(value){
  if(!Array.isArray(value)||value.length!==4)return null;
  const b=value.map(Number);
  if(b.some(x=>!Number.isFinite(x)||x<0||x>1)||b[2]<=0||b[3]<=0||b[0]+b[2]>1.001||b[1]+b[3]>1.001)return null;
  return b.map(x=>+x.toFixed(4));
 }
 function separation(a,b){const ax=a[0]+a[2]/2,ay=a[1]+a[3]/2,bx=b[0]+b[2]/2,by=b[1]+b[3]/2;return Math.hypot(ax-bx,ay-by);}
 function create({now=()=>Date.now(),id=()=>('r-'+Array.from(crypto.getRandomValues(new Uint8Array(12)),x=>x.toString(16).padStart(2,'0')).join(''))}={}){
  const tracks=new Map();
  function update(observations,profiles=[]){
   const t=now(),rows=[],observed=new Set();
   for(const x of (observations||[]).slice(0,8)){
    const b=geometry(x.box);if(!b)continue;
    let best=null,bestDistance=.19;
    for(const track of tracks.values()){
     if(observed.has(track.trackId)||t-track.seen>5500)continue;
     const delta=separation(track.box,b);
     if(delta<bestDistance){best=track;bestDistance=delta;}
    }
    let record=best;
    if(!record){record={trackId:id(),box:b,seen:t,candidate:null,streak:0};tracks.set(record.trackId,record);}
    record.box=b;record.seen=t;observed.add(record.trackId);
    const proposed=candidate(x.descriptor,profiles);
    if(proposed&&record.candidate===proposed)record.streak=Math.min(10,record.streak+1);
    else {record.candidate=proposed;record.streak=proposed?1:0;}
    rows.push(record);
   }
   for(const [key,track] of tracks)if(t-track.seen>5500)tracks.delete(key);
   const duplicate={};for(const row of rows)if(row.candidate)duplicate[row.candidate]=(duplicate[row.candidate]||0)+1;
   return rows.map(r=>({trackId:r.trackId,box:r.box,localProfileEmail:r.candidate&&r.streak>=3&&duplicate[r.candidate]===1?r.candidate:''}));
  }
  function clear(){tracks.clear();}
  return {update,clear,get trackCount(){return tracks.size;}};
 }
 return {create,geometry,candidate,distance};
});
