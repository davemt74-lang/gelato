'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const {create}=require('../assets/js/glasses-placement-review.js');
const sha='f'.repeat(64);
let now=1000,seq=0;
const item={kdsItemPublicId:'item-7',status:'in_progress',menu:{ingredients:[{name:'Sauce'},{name:'Mozzarella'},{name:'Basil'}]}};
const zone={x:.55,y:.45,width:.35,height:.4};
const ctx={devicePublicId:'device-1',stationPublicId:'prep',locationId:12,item,
 modelSha256:sha,zone,actions:{}};
const hand=(x=.08,y=.18,id='hand-1',at=now)=>({trackId:id,status:'tracking',confidence:.97,seen:at,
 bbox:{x,y,width:.13,height:.14}});
const object=(x=.10,y=.2,label='Mozzarella',confidence=.96)=>({label,confidence,bbox:{x,y,width:.11,height:.09}});
const eng=create({now:()=>now});
const tick=(hands=[hand()],objects=[object()],scope=ctx,age=0)=>{
 now+=150;const timestamp=now-age;
 return eng.observe({context:scope,frame:{seq:++seq,timestamp,handTimestamp:timestamp,
   hands:hands.map(h=>({...h,seen:timestamp})),objects}});
};
const entry=()=>{
 eng.reset();
 tick();tick();tick();
 tick([hand(.63,.58)],[object(.65,.61)]);
 tick([hand(.63,.58)],[]);
 return tick([hand(.63,.58)],[]);
};
// Gate 1: without calibration no engine state
assert.equal(eng.observe({context:{...ctx,zone:null},frame:{seq:1,timestamp:now}}).accepted,false);
// Gate 2: held label needs 3 frames and independent movement
let r=tick();assert.equal(r.candidate,null);
r=tick();assert.equal(r.candidate,null);
// Gate 3: moving toward target with visible object is not release
r=tick();r=tick([hand(.63,.58)],[object(.65,.61)]);
assert.equal(r.candidate,null);
// Gate 4: absent object once is not enough
r=tick([hand(.63,.58)],[]);
assert.equal(r.candidate,null);
// Gate 5: two distinct absent-object frames and visible hand generate human-only candidate
r=tick([hand(.63,.58)],[]);
assert.equal(r.status,'review_required');
assert.equal(r.candidate.label,'Mozzarella');
assert.equal(r.candidate.placementVerified,false);
assert.equal(r.candidate.kdsChanged,false);
assert.equal(r.candidate.evidence.movementObserved,true);
// Gate 6: only matching current context and one decision can confirm review
const first=r.candidate;
let denied=eng.review({id:first.id,decision:'confirm',context:{...ctx,stationPublicId:'different'}});
assert.equal(denied.ok,false);
const accepted=eng.review({id:first.id,decision:'confirm',context:ctx});
assert.equal(accepted.ok,true);assert.equal(accepted.receipt.operatorConfirmed,true);
assert.equal(accepted.receipt.cameraPlacementVerified,false);
assert.equal(accepted.receipt.ingredientAdded,false);
assert.equal(eng.review({id:first.id,decision:'confirm',context:ctx}).ok,false);
// Gate 7: reset and negative review, independent of the KDS.
r=entry();
assert.equal(eng.review({id:r.candidate.id,decision:'reject',context:ctx}).receipt.operatorConfirmed,false);
assert.equal(item.status,'in_progress');
// Gate 8: hand disappearance fails closed even after a stable hold
eng.reset();tick();tick();tick();tick([hand(.63,.58)],[object(.65,.61)]);
r=tick([],[]);
r=tick([hand(.63,.58)],[]);
assert.equal(r.candidate,null);
// Gate 9: object initially in zone cannot be assumed picked up then placed
eng.reset();for(let i=0;i<7;i++)r=tick([hand(.63,.58)],[object(.65,.61)]);
r=tick([hand(.63,.58)],[]);r=tick([hand(.63,.58)],[]);
assert.equal(r.candidate,null);
// Gate 10: wrong ingredient and low confidence not sufficient
eng.reset();for(let i=0;i<4;i++)r=tick([hand()],[object(.1,.2,'Chicken')]);
assert.equal(r.status,'waiting');
for(let i=0;i<4;i++)r=tick([hand()],[object(.1,.2,'Mozzarella',.40)]);
assert.equal(r.status,'waiting');
// Gate 11: two indistinguishable hands must not claim holding ingredient
eng.reset();for(let i=0;i<4;i++){
 r=tick([hand(.08,.18,'one'),hand(.1,.18,'two')],[object(.11,.21)]);
}
assert.equal(r.status,'waiting');
// Gate 12: stale object frame or stale hand evidence rejected and state invalidated
eng.reset();r=tick([hand()],[object()],ctx,1000);
assert.equal(r.reason,'stale_object_frame');
now+=150;seq++;r=eng.observe({context:ctx,frame:{seq,timestamp:now,handTimestamp:now-600,
 hands:[hand(.08,.18,'hand-1',now-600)],objects:[object()]}});
assert.equal(r.reason,'stale_hand_frame');
// Gate 13: repeated frame sequence cannot count toward pickup or candidate
eng.reset();r=tick();const oldSeq=seq;
now+=150;const replay=eng.observe({context:ctx,frame:{seq:oldSeq,timestamp:now,handTimestamp:now,hands:[hand()],objects:[object()]}});
assert.equal(replay.reason,'duplicate_or_replay');
// Gate 14: model/device/item/zone changes invalidate previous candidate
r=entry();const pending=r.candidate;
const different={...ctx,item:{...item,kdsItemPublicId:'new-ticket'}};
assert.equal(eng.review({id:pending.id,decision:'confirm',context:different}).ok,false);
eng.observe({context:different,frame:{seq:++seq,timestamp:now,handTimestamp:now,hands:[],objects:[]}});
assert.equal(eng.summary().candidate,null);
r=entry();eng.reset();assert.equal(eng.summary().reviews.length,0);
// Gate 15: check read-only source and UI integration, no KDS side effects
const source=fs.readFileSync('assets/js/glasses-placement-review.js','utf8');
const ui=fs.readFileSync('assets/js/glasses-placement-review-ui.js','utf8');
const html=fs.readFileSync('glasses-simulator.php','utf8');
assert.doesNotMatch(source,/\bfetch\s*\(|getUserMedia|localStorage|build\.observe|kds\.|\.submit\(|XMLHttpRequest|postMessage/);
assert.doesNotMatch(ui,/\bfetch\s*\(|\bapi\s*\(|build\.observe|\.submit\(/);
assert.match(html,/glasses-placement-review-ui\.js/);
assert.match(html,/glassesPlacementConfirm/);assert.match(html,/glassesPlacementReject/);
assert.match(ui,/GelatoHandTrackingUI\?\.state/);
assert.match(ui,/glasses-production-object-frame/);
assert.match(ui,/glasses-hand-frame/);
// Gate 16: no duplicate ingredients can make an authoritative mapping
eng.reset();const ambiguous={...ctx,item:{...item,menu:{ingredients:[{name:'Mozzarella'},{name:'Mozzarella'}]}}};
for(let i=0;i<7;i++)r=tick([hand()],[object()],ambiguous);
assert.equal(r.status,'waiting');
console.log('section285d3-placement-review=16/16');
