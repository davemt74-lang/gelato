'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const reliability=require('../assets/js/glasses-placement-reliability.js');
const progression=require('../assets/js/glasses-ingredient-progression.js');
let now=10000;
const e=reliability.create({now:()=>now,maxFrames:50,maxProposals:10});
let s=e.snapshot();
assert.equal(s.fieldCertification,'required');
assert.equal(s.cameraPlacementCertified,false);
assert.equal(s.automaticProgressionEnabled,false); // Gate 1
assert.equal(s.labeledPrecisionProxy,null);assert.equal(s.labeledRecallProxy,null); // Gate 2
assert.equal(e.frame({seq:1,frameAt:now-100,handAt:now-130,accepted:true}).ok,true);
assert.equal(e.snapshot().accepted,1); // Gate 3
assert.equal(e.frame({seq:1,frameAt:now,handAt:now,accepted:true}).reason,'duplicate_or_replay');
assert.equal(e.snapshot().accepted,1); // Gate 4
assert.equal(e.frame({seq:2,frameAt:now,handAt:now,accepted:true,sourceSlot:3}).reason,'invalid_source');
assert.equal(e.snapshot().frames,1); // Gate 5
now+=300;e.frame({seq:3,frameAt:now-700,handAt:now-730,accepted:false,reason:'stale_object_frame'});
assert.equal(e.snapshot().reasons.stale_object_frame,1); // Gate 6
now+=150;e.frame({seq:4,frameAt:now-250,handAt:now-260,accepted:true});
assert.equal(e.snapshot().p95FrameDelayMs,250); // Gate 7
assert.equal(e.proposed({id:'hyp-1'}),true);
assert.equal(e.proposed({id:'hyp-1'}),false);
assert.equal(e.snapshot().proposals,1); // Gate 8
assert.equal(e.label({id:'hyp-1',outcome:'observed_placed',explicit:true}).ok,false);
assert.equal(e.reviewedProposal({id:'hyp-1',decision:'confirm'}),true);
assert.equal(e.reviewedProposal({id:'hyp-1',decision:'confirm'}),false); // Gate 9
assert.equal(e.label({id:'hyp-1',outcome:'observed_placed'}).ok,false);
assert.equal(e.label({id:'hyp-1',outcome:'observed_placed',explicit:true}).ok,true);
assert.equal(e.label({id:'hyp-1',outcome:'observed_not_placed',explicit:true}).ok,false); // Gate 10
assert.equal(e.proposed({id:'hyp-2'}),true);e.reviewedProposal({id:'hyp-2',decision:'reject'});
assert.equal(e.label({id:'hyp-2',outcome:'observed_not_placed',explicit:true}).ok,true);
assert.equal(e.snapshot().labeledPrecisionProxy,.5); // Gate 11
assert.equal(e.reportMiss().ok,false);assert.equal(e.reportMiss({explicit:true}).ok,true);
assert.equal(e.snapshot().labeledRecallProxy,.5); // Gate 12
assert.equal(e.proposed({id:'hyp-3'}),true);e.reviewedProposal({id:'hyp-3',decision:'reject'});
assert.equal(e.label({id:'hyp-3',outcome:'uncertain',explicit:true}).ok,true);
assert.equal(e.snapshot().operatorLabeled,2); // Gate 13
s=e.snapshot();assert.equal(s.cameraPlacementCertified,false);
assert.equal(s.metricsAreOperatorReported,true); // Gate 14
let snapshot=JSON.stringify(s);
for(const banned of ['devicePublicId','trackId','objectBBox','videoFrame','faceIdentity','employeeId'])
 assert.equal(snapshot.includes(banned),false,'no raw subject or camera data'); // Gate 15
const oldEpoch=s.epoch;
e.reset();s=e.snapshot();assert.equal(s.epoch,oldEpoch+1);
assert.equal(s.frames,0);assert.equal(s.operatorLabeled,0);assert.equal(s.proposals,0); // Gate 16
assert.equal(e.reviewedProposal({id:'hyp-1',decision:'confirm'}),false); // Gate 17
assert.equal(e.proposed({id:'hyp-1'}),true); // Gate 18
const item={kdsItemPublicId:'item-a',status:'in_progress',ticket:{checkPublicId:'ticket-a'},menu:{ingredients:[{name:'Mozzarella'}]}};
const good={mode:'live',runtimeBound:true,cameraLive:true,modelReady:true,hidden:false,
 permissions:{kdsUpdate:true,glassesView:true},devicePublicId:'dev',stationPublicId:'prep',locationId:3,
 item,modelSha256:'a'.repeat(64),actions:{}};
let resolvePending;const audits=[],applies=[];let current={...good};let time=20000,tokenNumber=0;
const p=progression.create({now:()=>time,tokenFactory:()=>String(++tokenNumber).padStart(32,'a'),
 currentContext:()=>current,
 persist:payload=>{audits.push(payload);return new Promise(resolve=>resolvePending=resolve);},
 applyChecklist:payload=>{applies.push(payload);return {ok:true};}});
function receipt(){return {id:'local-a',itemPublicId:'item-a',label:'Mozzarella',decision:'confirm',
 operatorConfirmed:true,cameraPlacementVerified:false,ingredientAdded:false,kdsChanged:false,reviewedAt:time};}
async function testRaces(){
 assert.equal(p.queue(receipt(),good).ok,true);const waiting=p.approve(good);
 assert.equal(p.snapshot().busy,true);p.reset();
 assert.equal(p.snapshot().busy,true); // Gate 19
 assert.equal(p.queue(receipt(),good).ok,false);
 resolvePending({kdsResult:{noted:true,kdsItemPublicId:'item-a'}});
 let applied=await waiting;
 assert.equal(applied.reason,'audited_but_context_revoked');assert.equal(applies.length,0); // Gate 20
 assert.equal(p.snapshot().busy,false);
 assert.equal(p.queue(receipt(),good).ok,true);const waiting2=p.approve(good);
 current={...good,stationPublicId:'different'};resolvePending({kdsResult:{noted:true,kdsItemPublicId:'item-a'}});
 applied=await waiting2;
 assert.equal(applied.reason,'audited_but_context_revoked');assert.equal(applies.length,0); // Gate 21
 current={...good};
 assert.equal(p.queue(receipt(),good).ok,true);const waiting3=p.approve(good);
 current={...good,permissions:{glassesView:true,kdsUpdate:false}};
 resolvePending({kdsResult:{noted:true,kdsItemPublicId:'item-a'}});
 assert.equal((await waiting3).reason,'audited_but_context_revoked');assert.equal(applies.length,0); // Gate 22
 current={...good};assert.equal(p.queue(receipt(),good).ok,true);const waiting4=p.approve(good);
 resolvePending({kdsResult:{noted:true,kdsItemPublicId:'item-a'}});
 assert.equal((await waiting4).ok,true);assert.equal(applies.length,1); // Gate 23
 const reversal=p.undo({...good,actions:{'0:Mozzarella':'added'}});
 current={...good,actions:{'0:Mozzarella':'added'},cameraLive:false};
 resolvePending({kdsResult:{noted:true,kdsItemPublicId:'item-a'}});
 assert.equal((await reversal).reason,'undo_audited_but_context_revoked');
 assert.equal(applies.length,1); // Gate 24
 const source=fs.readFileSync('assets/js/glasses-placement-reliability.js','utf8');
 const ui=fs.readFileSync('assets/js/glasses-placement-reliability-ui.js','utf8');
 const html=fs.readFileSync('glasses-simulator.php','utf8');
 const engineSrc=fs.readFileSync('assets/js/glasses-ingredient-progression.js','utf8');
 const placed=fs.readFileSync('assets/js/glasses-placement-review-ui.js','utf8');
 for(const s of [source,ui])assert.doesNotMatch(s,/\bfetch\s*\(|XMLHttpRequest|localStorage|sessionStorage|build\.observe|kds\.item\.transition/);
 assert.match(placed,/gelato-glasses-placement-observation/);
 assert.match(placed,/gelato-glasses-placement-outcome/);
 assert.match(engineSrc,/currentContext/);assert.match(engineSrc,/approvalEpoch/);
 assert.match(html,/glassesReliabilityStatus/);assert.match(html,/glasses-placement-reliability\.js/);
 assert.match(html,/glasses-placement-reliability-ui\.js/); // Gate 25
 console.log('285d5-placement-reliability-acceptance=25/25');
}
testRaces().catch(e=>{console.error(e);process.exitCode=1});
