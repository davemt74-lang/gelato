'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const runtime=fs.readFileSync('assets/js/glasses-camera-runtime.js','utf8');
const multicam=fs.readFileSync('assets/js/glasses-multicamera.js','utf8');
const flow=fs.readFileSync('assets/js/glasses-production-photo-flow.js','utf8');
const workspace=fs.readFileSync('assets/js/glasses-camera-workspace.js','utf8');
const html=fs.readFileSync('glasses-simulator.php','utf8');
assert.match(html,/glasses-camera-runtime\.js/);
assert.match(multicam,/window\.GelatoCameraRuntime\?\.start/);
assert.match(multicam,/window\.GelatoCameraRuntime\?\.trackStopped/);
assert.match(flow,/cameraPolicy\?\.sceneCandidate/);
assert.match(flow,/cameraPolicy\.quality<45/);
assert.match(flow,/consumeCapture\?\.\(step\.slot\)/);
assert.match(flow,/manual confirmation|Confirm pizza identity manually/);
assert.match(workspace,/referenceMatches/);
assert.ok(!/inbox_send|pos_check.*UPDATE|build\.observe/.test(runtime),'runtime must not perform downstream writes');
let clock=1000,shade=-60;
const fakeDate=class extends Date {static now(){return clock;}};
const ui={hidden:false,readyState:'loading',addEventListener(){},getElementById(){return null;},
  createElement(){return{width:0,height:0,getContext(){return {drawImage(){},getImageData(){
    const data=new Uint8ClampedArray(20*20*4);
    for(let i=0;i<400;i++){const v=(i%2===0?120:185)+shade;data[i*4]=v;data[i*4+1]=v;data[i*4+2]=v;data[i*4+3]=255;}
    return {data};
  }}}}}};
const events=[],device={
  active:false,configured:true,
  isEnabled(slot){return this.configured&&slot===2;},
  isStreaming(slot){return slot===2&&this.active;},
  get locationId(){return 3;}
};
const camera={
  video(slot){return slot===2&&device.active?{videoWidth:640,videoHeight:480}:null;},
  stop(){device.active=false;},async start(){device.active=true;}
};
const w={GelatoCameraConfiguration:device,GelatoGlassesMultiCamera:camera,
  addEventListener(){},dispatchEvent(e){events.push(e.type);}};
const context={window:w,document:ui,navigator:{mediaDevices:{addEventListener(){}}},
  Date:fakeDate,Map,Set,Math,Number,Uint8Array,Uint8ClampedArray,CustomEvent:class {constructor(type){this.type=type;}},
  setInterval(){},Promise,console};
vm.runInNewContext(runtime,context,{filename:'glasses-camera-runtime.js'});
const engine=w.GelatoCameraRuntime;
assert.equal(engine.snapshot().sessionBound,true);
assert.equal(engine.snapshot().backgroundSupported,false);
assert.equal(engine.policy(2).trainedPizzaDetection,false);
(async()=>{
  assert.equal(await engine.start(2),true);
  assert.equal(engine.status(2).desired,true);
  assert.equal(engine.status(2).active,true);
  await engine.poll();clock+=1500;shade=0;await engine.poll();
  assert.equal(engine.status(2).activity,'moving','large scene change captured');
  shade=0;clock+=2000;await engine.poll();
  clock+=1500;await engine.poll();
  assert.equal(engine.policy(2).sceneCandidate,true,'stable scene and quality become candidate');
  assert.equal(engine.consumeCapture(2),true);
  assert.equal(engine.consumeCapture(2),false,'duplicate capture rejected');
  assert.ok(engine.status(2).lastCapture>0);
  camera.stop(2);
  engine.trackStopped(2,'device_disconnected');
  assert.equal(engine.status(2).state,'recovering');
  clock+=800;await engine.poll();
  assert.equal(engine.status(2).active,true,'bounded re-open of previously started stream');
  engine.stop(2,'manual_stop');
  assert.equal(engine.status(2).desired,false);
  assert.equal(engine.status(2).active,false);
  engine.trackStopped(2,'device_disconnected');
  assert.equal(engine.status(2).nextRetry,0,'manual stop is terminal');
  await engine.start(2);
  engine.trackStopped(2,'permission_denied');
  assert.equal(engine.status(2).desired,false,'permission denied disables restart');
  assert.equal(engine.status(2).state,'permission_blocked');
  device.configured=false;
  assert.equal(await engine.start(2),false,'OFF cannot start camera');
  assert.equal(engine.policy(2).cameraReady,false);
  assert.ok(events.includes('gelato-camera-runtime-ready'),'ready event fires after scene stabilization');
  console.log('section261-camera-runtime-recovery-and-capture-policy=ok');
})().catch(e=>{console.error(e);process.exitCode=1;});
