/* Gelato 285E5 — independent field-evidence readiness evaluation.
 * Aggregated, manually adjudicated ground-truth trials only. No raw frames,
 * identities, network writes, persistent certification or automatic KDS actions.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoFieldReadiness=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const CLASSES=new Set(['tp','fp','fn','tn']),MAX=10000;
 function wilson(success,total){
  if(!total)return null;
  const z=1.96,p=success/total,z2=z*z,d=1+z2/total;
  return Math.max(0,(p+z2/(2*total)-z*Math.sqrt((p*(1-p)+z2/(4*total))/total))/d);
 }
 function create({minimumPositive=100,minimumNegative=100,minimumSessions=3,
  minPrecisionLower=.95,minRecallLower=.90,maxP95LatencyMs=500,now=()=>Date.now()}={}){
  const trials=new Map();let revision=0;
  function record(x){
   if(!x||typeof x.id!=='string'||!x.id||x.id.length>100||trials.has(x.id))
    return {ok:false,reason:'duplicate_or_invalid_id'};
   if(!CLASSES.has(x.outcome)||x.independentlyAdjudicated!==true||
      typeof x.session!=='string'||!x.session||x.session.length>100||
      !Number.isSafeInteger(x.cameraSlot)||x.cameraSlot<1||x.cameraSlot>5||
      !Number.isFinite(x.at)||x.at>now()||x.at<0)
    return {ok:false,reason:'independent_adjudication_required'};
   if(x.latencyMs!==undefined&&(!Number.isFinite(x.latencyMs)||x.latencyMs<0||x.latencyMs>30000))
    return {ok:false,reason:'invalid_latency'};
   if(trials.size>=MAX)return {ok:false,reason:'capacity_exceeded'};
   // Only fixed aggregate-safe trial properties are retained.
   trials.set(x.id,{outcome:x.outcome,session:x.session,cameraSlot:x.cameraSlot,
      latencyMs:x.latencyMs??null});
   revision++;return {ok:true};
  }
  function evaluate(){
   const arr=[...trials.values()],counts={tp:0,fp:0,fn:0,tn:0};
   for(const t of arr)counts[t.outcome]++;
   const positive=counts.tp+counts.fn,predicted=counts.tp+counts.fp;
   const negatives=counts.tn+counts.fp;
   const sessions=new Set(arr.map(x=>x.session)).size;
   const latencies=arr.map(x=>x.latencyMs).filter(Number.isFinite).sort((a,b)=>a-b);
   const p95=latencies.length?latencies[Math.ceil(latencies.length*.95)-1]:null;
   const precision=predicted?counts.tp/predicted:null,recall=positive?counts.tp/positive:null;
   const precisionLower=wilson(counts.tp,predicted),recallLower=wilson(counts.tp,positive);
   const reasons=[];
   if(positive<minimumPositive)reasons.push('insufficient_positive_ground_truth');
   if(negatives<minimumNegative)reasons.push('insufficient_negative_ground_truth');
   if(sessions<minimumSessions)reasons.push('insufficient_independent_sessions');
   if(precisionLower===null||precisionLower<minPrecisionLower)reasons.push('precision_lower_bound_below_threshold');
   if(recallLower===null||recallLower<minRecallLower)reasons.push('recall_lower_bound_below_threshold');
   if(p95===null||p95>maxP95LatencyMs)reasons.push('latency_not_demonstrated');
   return Object.freeze({version:'285E5',revision,samples:arr.length,
     counts:Object.freeze(counts),sessions,positive,negative:negatives,
     precision,recall,precisionLower95:precisionLower,recallLower95:recallLower,
     p95LatencyMs:p95,criteriaMet:reasons.length===0,blockingReasons:Object.freeze(reasons),
     evidenceType:'independently_adjudicated_manual_trials',
     // Passing an evaluation is not an authorization or official field certification.
     fieldCertified:false,automaticIngredientProgressionEnabled:false,
     requiresHumanCertification:true});
  }
  function reset(){trials.clear();revision++;}
  return Object.freeze({record,evaluate,reset});
 }
 return Object.freeze({create,wilson});
});
