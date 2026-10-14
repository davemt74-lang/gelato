'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const broker=require('../assets/js/glasses-shared-camera-recognition.js');
const modelSha256='b'.repeat(64),locationId=24;
const streams=new Map(),records=new Map();
for(let slot=2;slot<=5;slot++){
 const track={readyState:'live'},stream={getVideoTracks:()=>[track]};
 streams.set(slot,{track,stream,video:{videoWidth:640,videoHeight:480,srcObject:stream}});
 records.set(slot,{slot,enabled:true,stationPublicId:slot===5?null:'station-1',role:'prep',revision:3});
}
const configuration={locationId,isEnabled:slot=>records.get(slot)?.enabled===true,
 getSaved:slot=>records.get(slot)?{...records.get(slot)}:null};
const multiCamera={video:slot=>streams.get(slot)?.video||null};
const env={configuration,multiCamera,locationId};
let now=10000;const instance=broker.create({now:()=>now});
const selected=[];
for(let i=0;i<8;i++)selected.push(instance.pick(env)?.slot);
assert.deepEqual(selected,[2,3,4,5,2,3,4,5],'four cameras are fairly round-robin sampled');
let source=instance.pick(env);assert.equal(source.slot,2);
const valid={label:'Mozzarella',confidence:.96,bbox:{x:.2,y:.2,width:.2,height:.2}};
let result=instance.accept({source,modelSha256,raw:[valid],devicePublicId:'glasses-1',
 locationId,currentStationPublicId:'station-1',capturedAt:now});
assert.equal(result.slot,2);assert.equal(result.scope,'assigned_station');
assert.equal(result.count,1);assert.equal(result.objects[0].label,'Mozzarella');
assert.equal(result.modelSha256,modelSha256);
assert.equal(result.sourceKind,'stationary');
assert.ok(!Object.hasOwn(result,'kdsItemPublicId'),'no unstated ticket association');
assert.ok(!Object.hasOwn(result,'added'),'observations are never ingredient completion');
for(let i=0;i<3;i++)instance.pick(env);
source=instance.pick(env);assert.equal(source.slot,2);
assert.equal(instance.accept({source,modelSha256:'invalid',raw:[valid],devicePublicId:'glasses-1',locationId,
 currentStationPublicId:'station-1',capturedAt:now}),null,'unverified model rejected');
assert.equal(instance.status().find(x=>x.slot===2).state,'waiting','revocation clears prior evidence');
const bad=[{...valid,confidence:NaN},{...valid,bbox:{x:-1,y:.2,width:.3,height:.4}},
 {...valid,confidence:1.3},null,{...valid,label:'x'.repeat(200)}];
assert.equal(broker.sanitize(bad).length,0,'invalid boxes, labels and confidence rejected');
assert.equal(broker.sanitize([valid]).length,1);
assert.equal(instance.accept({source,modelSha256,raw:[valid],devicePublicId:'glasses-1',locationId,
 currentStationPublicId:'station-1',capturedAt:now-1900}),null,'stale inference rejected');
assert.equal(instance.accept({source,modelSha256,raw:[valid],devicePublicId:'glasses-1',locationId:99,
 currentStationPublicId:'station-1',capturedAt:now}),null,'cross-location inference rejected');
assert.equal(instance.accept({source,modelSha256,raw:[valid],devicePublicId:'glasses-1',locationId,
 currentStationPublicId:'station-2',capturedAt:now}).scope,'unscoped','station mismatch cannot claim KDS scope');
records.get(5).enabled=true;
const unassigned={...records.get(5),locationId,video:streams.get(5).video,stream:streams.get(5).stream};
assert.equal(instance.accept({source:unassigned,modelSha256,raw:[valid],devicePublicId:'glasses-1',
 locationId,currentStationPublicId:'station-1',capturedAt:now}).scope,'unscoped');
instance.clearSlot(5);
assert.equal(instance.status().find(x=>x.slot===5).state,'waiting','disabled/disconnected observations cleared');
records.get(2).enabled=false;streams.get(3).track.readyState='ended';streams.get(4).video.srcObject=null;
const only=Array.from({length:8},()=>instance.pick(env)?.slot);
assert.deepEqual(only,Array(8).fill(5),'only live enabled camera is sampled');
assert.equal(instance.pick({...env,locationId:99}),null,'misconfigured restaurant location blocked');
instance.reset();assert.ok(instance.status().every(x=>x.state==='waiting'));
const sourceCode=fs.readFileSync('assets/js/glasses-shared-camera-recognition.js','utf8');
assert.doesNotMatch(sourceCode,/fetch\(|\.getUserMedia\(|kds\.ticket\.action|kds\.item\.transition|build\.observe/,
 'shared broker cannot open cameras or mutate kitchen');
console.log('section285c-shared-camera-model-contract=ok');

