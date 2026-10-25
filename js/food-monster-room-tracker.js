/* FM-G10A: anonymous, ephemeral multi-person tracking; never infer who speaks. */
(function(root,factory){
 const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoRoomTracker=api;
})(typeof window!=='undefined'?window:null,function(){
 'use strict';
 const threshold=.42,margin=.085,ttl=5500;
 function validDescriptor(v){return Array.isArray(v)&&v.length===128&&v.every(Number.isFinite);}
 function distance(a,b){
  if(!validDescriptor(a)||!validDescriptor(b))return Infinity;
  let d=0;for(let i=0;i<128;i++)d+=(a[i]-b[i])**2;
  return Math.sqrt(d);
 }
 function candidate(descriptor,profiles){
  if(!validDescriptor(descriptor))return null;
  const rank=(profiles||[]).filter(p=>p&&typeof p.email==='string')
   .map(p=>({email:p.email,d:distance(descriptor,p.vector)})).sort((a,b)=>a.d-b.d);
  if(!rank.length||rank[0].d>=threshold||(rank[1]&&rank[1].d-rank[0].d<margin))return null;
  return rank[0].email;
 }
 function geometry(value){
  if(!Array.isArray(value)||value.length!==4)return null;
  const b=value.map(Number);
  if(b.some(x=>!Number.isFinite(x)||x<0||x>1)||b[2]<=0||b[3]<=0||b[0]+b[2]>1.001||b[1]+b[3]>1.001)return null;
  return b.map(x=>+x.toFixed(4));
 }
 function separation(a,b){
  const ax=a[0]+a[2]/2,ay=a[1]+a[3]/2,bx=b[0]+b[2]/2,by=b[1]+b[3]/2;
  return Math.hypot(ax-bx,ay-by);
 }
 // An appearance mismatch cannot steal an existing track by occupying its
 // old position. Without descriptors, associate only small spatial movement
 // and never carry a provisional identity through a missing observation.
 function predicted(track,at){
  const old=track.rawBox||track.box,elapsed=Math.max(0,Math.min(2500,at-track.seen));
  const vx=Number(track.velocity?.[0])||0,vy=Number(track.velocity?.[1])||0;
  const x=old[0]+vx*elapsed,y=old[1]+vy*elapsed;
  return [Math.max(0,Math.min(1-old[2],x)),Math.max(0,Math.min(1-old[3],y)),old[2],old[3]];
 }
 function cost(observation,track,at){
  const delta=separation(observation.box,predicted(track,at));
  const gap=Math.max(0,at-track.seen);
  if(validDescriptor(observation.descriptor)&&validDescriptor(track.descriptor)){
   const appearance=distance(observation.descriptor,track.descriptor);
   return appearance<.38&&delta<.72 ? appearance*.8+delta*.2 : Infinity;
  }
  return gap<2200&&delta<.16 ? .25+delta : Infinity;
 }
 // Bounded global assignment prevents order-dependent identity swaps.
 // Up to 8 people / 8*2^8 states; unmatched observations become new tracks.
 function associate(observations,active,at){
  const memo=new Map();
  function solve(index,used){
   if(index===observations.length)return {cost:0,assignments:[]};
   const key=index+':'+used;
   if(memo.has(key))return memo.get(key);
   const fresh=solve(index+1,used);
   let best={cost:.67+fresh.cost,assignments:[-1,...fresh.assignments]};
   for(let j=0;j<active.length;j++){
    if(used&(1<<j))continue;
    const edge=cost(observations[index],active[j],at);
    if(!Number.isFinite(edge))continue;
    const tail=solve(index+1,used|(1<<j));
    if(edge+tail.cost<best.cost-1e-9)
     best={cost:edge+tail.cost,assignments:[j,...tail.assignments]};
   }
   memo.set(key,best);return best;
  }
  return solve(0,0).assignments;
 }
 function create({now=()=>Date.now(),id=()=>('r-'+Array.from(crypto.getRandomValues(new Uint8Array(12)),x=>x.toString(16).padStart(2,'0')).join(''))}={}){
  const tracks=new Map();
  function update(observations,profiles=[]){
   const t=now();
   for(const [key,track] of tracks)if(t-track.seen>ttl)tracks.delete(key);
   const normalized=(observations||[]).slice(0,8).map(x=>({box:geometry(x?.box),descriptor:x?.descriptor}))
    .filter(x=>x.box);
   const active=[...tracks.values()];
   const assignment=associate(normalized,active,t);
   const rows=[];
   for(let i=0;i<normalized.length;i++){
    const x=normalized[i],previous=assignment[i]===-1?null:active[assignment[i]];
    const record=previous||{trackId:id(),box:x.box,rawBox:x.box,seen:t,descriptor:null,candidate:null,streak:0,velocity:[0,0]};
    if(previous&&t-record.seen>3500){record.candidate=null;record.streak=0;}
    const proposed=candidate(x.descriptor,profiles);
    if(proposed&&record.candidate===proposed)record.streak=Math.min(10,record.streak+1);
    else{record.candidate=proposed;record.streak=proposed?1:0;}
    if(previous){
     const dt=Math.max(1,t-record.seen),old=record.rawBox||record.box;
     const dx=x.box[0]-old[0],dy=x.box[1]-old[1];
     const step=separation(old,x.box);
     // Smooth only presentation geometry; use raw observations for association.
     record.box=step>.24?x.box:record.box.map((v,k)=>+(v*.48+x.box[k]*.52).toFixed(4));
     record.velocity=[Math.max(-.00025,Math.min(.00025,dx/dt)),Math.max(-.00025,Math.min(.00025,dy/dt))];
    }else record.box=x.box;
    record.rawBox=x.box;record.seen=t;
    record.descriptor=validDescriptor(x.descriptor)?x.descriptor.slice():null;
    tracks.set(record.trackId,record);rows.push(record);
   }
   const duplicate={};
   for(const record of rows)if(record.candidate)duplicate[record.candidate]=(duplicate[record.candidate]||0)+1;
   return rows.map(record=>({trackId:record.trackId,box:record.box,
    localProfileEmail:record.candidate&&record.streak>=3&&duplicate[record.candidate]===1?record.candidate:''}));
  }
  function clear(){tracks.clear();}
  return {update,clear,get trackCount(){return tracks.size;}};
 }
 return {create,geometry,candidate,distance};
});
