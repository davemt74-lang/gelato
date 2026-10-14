/* Section 280: model-evidence-only two-hand tracker. No biometric identity and no KDS writes. */
((root,factory)=>{'use strict';const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;if(root)root.GelatoHandTracker=api;})(typeof window!=='undefined'?window:null,()=>{'use strict';
const DEFAULT_TTL=2200,LOST_MS=350;
const validBox=b=>b&&[b.x,b.y,b.width,b.height].every(x=>Number.isFinite(x))&&b.width>0&&b.height>0&&b.x>=0&&b.y>=0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
const center=b=>({x:b.x+b.width/2,y:b.y+b.height/2});
const distance=(a,b)=>{const x=center(a),y=center(b);return Math.hypot(x.x-y.x,x.y-y.y);};
function isHand(d){return d&&(/^(?:left hand|right hand|hand)$/i).test(String(d.kind==='hand'?'hand':d.detectorLabel||d.label||''))||d?.kind==='hand';}
const canonical=s=>String(s||'').trim().toLowerCase().replace(/[^a-z0-9]+/g,' ');
function ingredientCandidates(item,actions={}){
 if(!item?.kdsItemPublicId||['cancelled','completed','held','ready'].includes(item.status))return [];
 const ingredients=Array.isArray(item.menu?.ingredients)?item.menu.ingredients:[];
 return ingredients.map((row,i)=>({key:i+':'+String(row?.canonicalName||row?.name||''),
  name:String(row?.name||'').trim(),canonical:canonical(row?.canonicalName||row?.name)}))
  .filter(x=>x.name&&actions[x.key]!=='added'&&actions[x.key]!=='skipped').slice(0,5);
}
function createTracker({ttlMs=DEFAULT_TTL,lostMs=LOST_MS}={}){
 let seq=0,last=-Infinity,tracks=[null,null];
 function step(raw=[],time=Date.now(),item=null,actions={},objects=[]){
  if(!Number.isFinite(time)||time<last)throw Error('Hand frame timestamps must increase.');
  last=time;
  const observations=(Array.isArray(raw)?raw:[]).filter(isHand)
    .filter(d=>validBox(d.bbox)&&Number(d.confidence)>=.4)
    .slice(0,2).map(d=>({...d,handedness:/^left hand$/i.test(d.label)?'left':/^right hand$/i.test(d.label)?'right':(d.handedness||null)}));
  const usedTracks=new Set(),usedObservations=new Set(),pairs=[];
  for(let i=0;i<2;i++)for(let j=0;j<observations.length;j++){
   const old=tracks[i],obs=observations[j];
   if(!old||time-old.seen>ttlMs)continue;
   const diff=distance(old.bbox,obs.bbox);
   if(diff>.42+Math.min(.25,(time-old.seen)/ttlMs*.25))continue;
   const contrary=old.handedness&&obs.handedness&&old.handedness!==obs.handedness;
   if(contrary&&time-old.seen<lostMs)continue;
   pairs.push({i,j,score:diff+(contrary ? .40 : 0)+(time-old.seen)/ttlMs*.035});
  }
  // Two tracks: minimize global matching cost, not first-match greedily.
  const possibilities=[[],...pairs.map(p=>[p])];
  for(const a of pairs)for(const b of pairs)if(a.i!==b.i&&a.j!==b.j)possibilities.push([a,b]);
  possibilities.sort((a,b)=>b.length-a.length||a.reduce((s,p)=>s+p.score,0)-b.reduce((s,p)=>s+p.score,0));
  const events=[];
  for(const p of possibilities[0]){
    const old=tracks[p.i],obs=observations[p.j],gap=time-old.seen;
    tracks[p.i]={...old,bbox:obs.bbox,confidence:Number(obs.confidence),
      handedness:obs.handedness||old.handedness,seen:time,frames:old.frames+1,status:'tracking'};
    usedTracks.add(p.i);usedObservations.add(p.j);
    if(gap>lostMs)events.push({type:'reacquired',slot:p.i+1,trackId:old.trackId});
  }
  for(let j=0;j<observations.length;j++){
   if(usedObservations.has(j))continue;
   const obs=observations[j];
   const preferred=obs.handedness==='left'?0:obs.handedness==='right'?1:-1;
   const eligible=[0,1].filter(i=>!usedTracks.has(i)&&(!tracks[i]||time-tracks[i].seen>ttlMs));
   const i=eligible.includes(preferred)?preferred:eligible[0];
   if(i===undefined)continue;
   const trackId='hand-'+(++seq);
   tracks[i]={trackId,slot:i+1,bbox:obs.bbox,confidence:Number(obs.confidence),
    handedness:obs.handedness,seen:time,frames:1,status:'tracking'};
   usedTracks.add(i);events.push({type:'registered',slot:i+1,trackId});
  }
  for(let i=0;i<2;i++){
    if(!tracks[i]||usedTracks.has(i))continue;
    const gap=time-tracks[i].seen;
    if(gap>ttlMs){events.push({type:'expired',slot:i+1,trackId:tracks[i].trackId});tracks[i]=null;}
    else tracks[i].status=gap<=lostMs?'occluded':'lost';
  }
  const focused=ingredientCandidates(item,actions),mapped=(Array.isArray(objects)?objects:[])
   .filter(d=>d&&!isHand(d)&&validBox(d.bbox)&&Number(d.confidence)>=.4)
   .map(d=>({...d,canonical:canonical(d.label)}))
   .filter(d=>focused.some(c=>c.canonical===d.canonical));
  const state=tracks.map((t,i)=>{
   if(!t)return {slot:i+1,status:'ready',trackId:null,object:null};
   const hand=t.status==='tracking';
   const candidate=hand?mapped.filter(d=>distance(t.bbox,d.bbox)<.28)
    .sort((a,b)=>distance(t.bbox,a.bbox)-distance(t.bbox,b.bbox))[0]:null;
   return {...t,slot:i+1,status:hand?(candidate?'identifying':'tracking'):t.status,
      object:candidate?{label:candidate.label,confidence:Number(candidate.confidence),bbox:candidate.bbox}:null};
  });
  return {tracks:state,events,focused:focused.slice(0,3),
    activeItem:item?.kdsItemPublicId||null,live:state.filter(x=>['tracking','identifying'].includes(x.status)).length};
 }
 function reset(){tracks=[null,null];seq=0;last=-Infinity;}
 return {step,reset,snapshot:()=>tracks.map((t,i)=>t||{slot:i+1,status:'ready',trackId:null})};
}
return {createTracker,ingredientCandidates,isHand,validBox};
});