'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const {create,wilson}=require('../assets/js/glasses-field-readiness.js');
test('empty evidence stays unqualified and never certifies field accuracy',()=>{
 const g=create(),e=g.evaluate();assert.equal(e.criteriaMet,false);
 assert.equal(e.fieldCertified,false);assert.equal(e.automaticIngredientProgressionEnabled,false);
 assert.equal(e.precision,null);assert.equal(e.recall,null);
});
test('operator proxies cannot substitute for independent adjudication',()=>{
 let g=create(),p={id:'1',outcome:'tp',session:'shift-a',cameraSlot:1,at:100};
 assert.equal(g.record(p).ok,false);assert.equal(g.evaluate().samples,0);
 assert.equal(g.record({...p,independentlyAdjudicated:true,at:Infinity}).ok,false);
});
test('deduplicates trials and guards malformed camera/latency',()=>{
 let g=create({now:()=>200}),p={id:'1',outcome:'tp',session:'shift-a',cameraSlot:1,at:100,independentlyAdjudicated:true};
 assert.equal(g.record(p).ok,true);assert.equal(g.record(p).ok,false);
 assert.equal(g.record({...p,id:'2',cameraSlot:6}).ok,false);
 assert.equal(g.record({...p,id:'2',latencyMs:-2}).ok,false);
 assert.equal(g.evaluate().samples,1);
});
test('perfect independent trial set can meet criteria but does not self-certify',()=>{
 let g=create({now:()=>1000,minimumPositive:20,minimumNegative:20,minimumSessions:3,
 minPrecisionLower:.80,minRecallLower:.80});
 for(let i=0;i<60;i++)assert.equal(g.record({id:String(i),outcome:i<30?'tp':'tn',
 session:'shift-'+i%3,cameraSlot:1,at:900,latencyMs:100,independentlyAdjudicated:true}).ok,true);
 let e=g.evaluate();assert.equal(e.criteriaMet,true);assert.equal(e.fieldCertified,false);
 assert.equal(e.counts.tp,30);assert.equal(e.counts.tn,30);
});
test('false alarms and missed placements correctly reduce precision and recall',()=>{
 const g=create({now:()=>1000,minimumPositive:1,minimumNegative:1,minimumSessions:1,
 minPrecisionLower:0,minRecallLower:0});
 for(let i=0;i<4;i++)g.record({id:String(i),outcome:['tp','fp','fn','tn'][i],
 session:'one',cameraSlot:2,at:500,latencyMs:100,independentlyAdjudicated:true});
 let s=g.evaluate();assert.equal(s.precision,.5);assert.equal(s.recall,.5);
 assert.equal(s.negative,2);assert.equal(s.positive,2);
});
test('missing latency and sessions prevent release readiness',()=>{
 const g=create({now:()=>1000,minimumPositive:1,minimumNegative:1,minimumSessions:2,
 minPrecisionLower:0,minRecallLower:0});
 for(let i=0;i<2;i++)g.record({id:String(i),outcome:i?'tn':'tp',session:'same',cameraSlot:1,at:100,independentlyAdjudicated:true});
 let e=g.evaluate();assert.ok(e.blockingReasons.includes('insufficient_independent_sessions'));
 assert.ok(e.blockingReasons.includes('latency_not_demonstrated'));
});
test('Wilson lower bounds are conservative at small sample size',()=>{
 assert.equal(wilson(0,0),null);assert.ok(wilson(1,1)<.3);
 assert.ok(wilson(95,100)<.95);
});
test('reset clears trials and cannot preserve readiness',()=>{
 let g=create({now:()=>1000});g.record({id:'a',outcome:'tp',session:'x',cameraSlot:1,at:900,independentlyAdjudicated:true});g.reset();
 assert.equal(g.evaluate().samples,0);
});
