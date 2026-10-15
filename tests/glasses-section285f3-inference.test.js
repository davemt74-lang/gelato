'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const {create}=require('../assets/js/glasses-shared-camera-recognition.js');
const sha='a'.repeat(64);let now=3000;
const g=create({now:()=>now}),source={slot:2,locationId:1,stationPublicId:'prep',revision:1,stream:{id:'stream1'}};
const base=at=>({source,modelSha256:sha,devicePublicId:'dev',locationId:1,currentStationPublicId:'prep',capturedAt:at,raw:[]});
test('accepts only fresh in-order observations for same source',()=>{
 assert.ok(g.accept(base(2500)));assert.equal(g.accept(base(2490)),null);assert.equal(g.accept(base(2500)),null);
 assert.ok(g.accept(base(2600)));
});
test('camera revision permits a new timestamp, but source identity remains scoped',()=>{
 assert.ok(g.accept({...base(2510),source:{...source,revision:2}}));
 assert.equal(g.accept(base(2400)),null);
});
test('future and expired results never reach evidence consumers',()=>{
 assert.equal(g.accept(base(3100)),null);assert.equal(g.accept(base(1000)),null);
});
test('camera clearing resets per-source sequence',()=>{
 g.clearSlot(2);assert.ok(g.accept(base(2500)));g.reset();assert.ok(g.accept(base(2500)));
});
test('invalid detector box and confidence are stripped',()=>{
 const raw=[{label:'cheese',confidence:1.2,bbox:{x:0,y:0,width:.2,height:.2}},
 {label:'sauce',confidence:.9,bbox:{x:.1,y:.1,width:.2,height:.2}}];
 assert.equal(g.accept({...base(2700),raw}).objects.length,1);
});
