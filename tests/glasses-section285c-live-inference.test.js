'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const broker=require('../assets/js/glasses-shared-camera-recognition.js');
const source=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const begin=source.indexOf('const sharedCameraRecognition='),end=source.indexOf('async function loadReadonlyIngredientModel(',begin);
assert.ok(begin>0&&end>begin);
const events=[],logs=[],records=new Map(),streams=new Map(),timers=[];
for(let i=2;i<=5;i++){
 const stream={getVideoTracks:()=>[{readyState:'live'}]};
 const video={videoWidth:1280,videoHeight:720,srcObject:stream};
 streams.set(i,{stream,video});
 records.set(i,{slot:i,enabled:true,role:'pre_oven',stationPublicId:i===5?'elsewhere':'station-1',revision:1});
}
const configuration={locationId:32,isEnabled:i=>!!records.get(i)?.enabled,
 getSaved:i=>records.get(i)?{...records.get(i)}:null};
const multiCamera={video:i=>streams.get(i)?.video||null};
const ui={textContent:'',dataset:{}};
let now=10000,call=0,release=0;class FakeDate extends Date{static now(){return now;}}
const readonlyIngredient={epoch:1,status:'ready',session:{},config:{},package:{},verifiedSha256:'d'.repeat(64),
 devicePublicId:'device1',timer:null,auxTimer:null,busy:false,frameSeq:0,auxErrors:0};
const state={mode:'live',cameraStream:null,device:{publicId:'device1',locationId:32,stationPublicId:'station-1'}};
const listeners={};const win={GelatoSharedCameraRecognition:broker,
 GelatoCameraConfiguration:configuration,GelatoGlassesMultiCamera:multiCamera,
 addEventListener(type,fn){listeners[type]=fn;},dispatchEvent(event){events.push(event.detail)}};
const context={window:win,readonlyIngredient,state,document:{hidden:false},$:id=>id==='glassesSharedCameraInferenceStatus'?ui:null,
 setTimeout(fn){timers.push(fn);return timers.length;},clearTimeout(){},Date:FakeDate,Number,String,Math,
 CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail}},
 inferOnnxModel:async(model,frame,options)=>{assert.equal(model,readonlyIngredient);assert.equal(options.readonly,true);
   assert.ok([...streams.values()].some(x=>x.video===frame.video));call++;
   return [{label:'Mozzarella',confidence:.92,bbox:{x:.1,y:.2,width:.2,height:.2}}];},
 unloadReadonlyIngredientModel:async()=>{release++;readonlyIngredient.epoch++},log:(type,msg)=>logs.push([type,msg])};
vm.runInNewContext(source.slice(begin,end)+'\nthis.runAux=pollAuxiliaryRecognition;',context);
(async()=>{
 for(let i=2;i<=5;i++){
   await context.runAux(readonlyIngredient.epoch);now+=120;
   assert.equal(call,i-1);
   const event=events.at(-1);assert.equal(event.slot,i);assert.equal(event.modelSha256,readonlyIngredient.verifiedSha256);
   assert.equal(event.sourceKind,'stationary');assert.equal(event.objects.length,1);
   assert.equal(event.scope,i===5?'unscoped':'assigned_station');
   assert.equal(state.mode,'live');
 }
 assert.match(ui.textContent,/Cam 2 1 object/);assert.match(ui.textContent,/Cam 5 1 object/);
 const stale=(await context.runAux(readonlyIngredient.epoch));assert.equal(release,0);
 // A camera disconnect in the middle of its async model task must discard result.
 let complete;context.inferOnnxModel=()=>new Promise(resolve=>complete=resolve);
 const active=context.runAux(readonlyIngredient.epoch);
 streams.get(3).video.srcObject=null;
 complete([{label:'Mozzarella',confidence:.9,bbox:{x:.1,y:.2,width:.2,height:.2}}]);
 const before=events.length;await active;assert.equal(events.length,before,'stopped stream never emits stale result');
 // Unload, device revocation, hidden page and invalid package prevent further events.
 readonlyIngredient.devicePublicId='different';await context.runAux(readonlyIngredient.epoch);
 assert.equal(events.length,before);
 readonlyIngredient.devicePublicId='device1';context.document.hidden=true;
 await context.runAux(readonlyIngredient.epoch);assert.equal(events.length,before);
 context.document.hidden=false;readonlyIngredient.status='idle';
 await context.runAux(readonlyIngredient.epoch);assert.equal(events.length,before);
 // A competing Camera 1 inference keeps exclusive ownership of the session.
 readonlyIngredient.status='ready';readonlyIngredient.busy=true;
 const seen=events.length;await context.runAux(readonlyIngredient.epoch);
 assert.equal(events.length,seen,'parallel session.run is prevented');
 readonlyIngredient.busy=false;
 // A camera-config revision change while model awaits must revoke the frame.
 context.inferOnnxModel=()=>new Promise(resolve=>complete=resolve);
 const pending=context.runAux(readonlyIngredient.epoch);
 for(const record of records.values())record.revision++;
 complete([{label:'Mozzarella',confidence:.92,bbox:{x:.1,y:.2,width:.2,height:.2}}]);
 await pending;assert.equal(events.length,seen,'configuration edits invalidate in-flight inference');
 assert.equal(release,0,'no implicit unqualified model reloads');
 assert.ok(!events.some(e=>Object.hasOwn(e,'kdsAction')||Object.hasOwn(e,'ingredientAdded')));
 console.log('section285c-stationary-live-inference-integration=ok');
})().catch(e=>{console.error(e);process.exitCode=1});

