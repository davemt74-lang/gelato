/* Gelato 285E1 — advisory multi-camera calibration quality gate.
 * Pure local statistics: no storage, uploads, approval, ingredient, or KDS writes.
 * Frame samples contain aggregate image features only, never camera pixels.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoCameraCalibrationGate=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const LIMIT=36, MIN_FRAMES=8, MAX_GAP_MS=1800, MAX_AGE_MS=2500;
 const clamp=(n,min,max)=>Math.max(min,Math.min(max,n));
 const finite=n=>typeof n==='number'&&Number.isFinite(n);
 const validBox=b=>b&&[b.x,b.y,b.width,b.height].every(finite)&&b.x>=0&&b.y>=0&&b.width>0&&b.height>0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
 const percentile=(a,p)=>{if(!a.length)return null;const s=[...a].sort((x,y)=>x-y);return s[Math.ceil(s.length*p)-1];};
 const median=a=>percentile(a,.5);
 function analyzeRgba(rgba,width,height){
  if(!Number.isSafeInteger(width)||!Number.isSafeInteger(height)||width<4||height<4||
     !rgba||rgba.length<width*height*4)throw new TypeError('Invalid frame');
  const v=new Float64Array(width*height);let sum=0,sum2=0,grad=0,edges=0;
  for(let i=0;i<v.length;i++){const k=i*4,y=.2126*rgba[k]+.7152*rgba[k+1]+.0722*rgba[k+2];v[i]=y;sum+=y;sum2+=y*y;}
  for(let y=1;y<height;y++)for(let x=1;x<width;x++){const p=y*width+x;grad+=Math.abs(v[p]-v[p-1])+Math.abs(v[p]-v[p-width]);edges+=2;}
  const brightness=sum/v.length,contrast=Math.sqrt(Math.max(0,sum2/v.length-brightness*brightness));
  return Object.freeze({brightness,contrast,detail:edges?grad/edges:0});
 }
 function create({now=()=>Date.now(),minFrames=MIN_FRAMES}={}){
  const cameras=new Map(),required=Math.max(4,Math.min(36,Math.floor(minFrames)||MIN_FRAMES));
  function clear(slot){if(slot===undefined)cameras.clear();else cameras.delete(slot);}
  function validate(slot,sample){
   if(!Number.isInteger(slot)||slot<1||slot>5)return 'invalid_camera';
   if(!sample||typeof sample!=='object'||typeof sample.scope!=='string'||!sample.scope||
      typeof sample.sourceId!=='string'||!sample.sourceId||!Number.isSafeInteger(sample.generation)||sample.generation<0)return 'invalid_scope';
   if(!finite(sample.at)||sample.at<0||sample.at>now()+250||now()-sample.at>MAX_AGE_MS)return 'stale_frame';
   if(![sample.brightness,sample.contrast,sample.detail].every(finite)||
      sample.brightness<0||sample.brightness>255||sample.contrast<0||sample.contrast>128||sample.detail<0||sample.detail>255)return 'invalid_metrics';
   if(sample.roi!==undefined&&!validBox(sample.roi))return 'invalid_roi';
   return null;
  }
  function sample(slot,observation){
   const problem=validate(slot,observation);
   if(problem)return {ok:false,reason:problem};
   let state=cameras.get(slot);
   const key=observation.scope+'\u0000'+observation.sourceId+'\u0000'+observation.generation;
   if(!state||state.key!==key){state={key,sourceId:observation.sourceId,scope:observation.scope,generation:observation.generation,history:[],roi:null};cameras.set(slot,state);}
   const last=state.history[state.history.length-1];
   if(last&&observation.at<=last.at)return {ok:false,reason:'duplicate_or_reordered'};
   if(last&&observation.at-last.at>MAX_GAP_MS)state.history=[];
   if(observation.roi){if(state.roi&&JSON.stringify(state.roi)!==JSON.stringify(observation.roi))state.history=[];state.roi={...observation.roi};}
   const previous=state.history[state.history.length-1];
   state.history.push({at:observation.at,brightness:observation.brightness,contrast:observation.contrast,
      detail:observation.detail,drift:finite(observation.drift)?clamp(observation.drift,0,1):null,
      gap:previous?observation.at-previous.at:null});
   if(state.history.length>LIMIT)state.history.shift();
   return {ok:true,status:status(slot)};
  }
  function status(slot){
   const state=cameras.get(slot),latest=state?.history[state.history.length-1];
   if(!latest)return Object.freeze({slot,ready:false,reasonCodes:['not_calibrated'],samples:0,advisoryOnly:true});
   const h=state.history,age=now()-latest.at,brightness=median(h.map(x=>x.brightness)),
     contrast=median(h.map(x=>x.contrast)),detail=median(h.map(x=>x.detail)),
     drift=percentile(h.map(x=>x.drift).filter(finite),.95),
     gap=percentile(h.map(x=>x.gap).filter(finite),.95),reasons=[];
   if(age>MAX_AGE_MS||age< -250)reasons.push('stale_camera');
   if(h.length<required)reasons.push('insufficient_samples');
   if(brightness<48)reasons.push('underexposed');
   if(brightness>215)reasons.push('overexposed');
   if(contrast<16)reasons.push('low_contrast');
   if(detail<6)reasons.push('low_detail');
   if(drift!==null&&drift>.16)reasons.push('unstable_view');
   if(gap!==null&&gap>1000)reasons.push('frame_gaps');
   return Object.freeze({slot,sourceId:state.sourceId,scope:state.scope,generation:state.generation,
      samples:h.length,ageMs:age,brightness,contrast,detail,p95Drift:drift,p95GapMs:gap,
      ready:reasons.length===0,reasonCodes:Object.freeze(reasons),advisoryOnly:true,
      canAdvanceIngredient:false,fieldAccuracyCertified:false});
  }
  return Object.freeze({sample,status,clear,snapshot:()=>Object.freeze([1,2,3,4,5].map(status))});
 }
 return Object.freeze({create,analyzeRgba,validBox});
});
