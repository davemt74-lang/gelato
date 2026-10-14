'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const mediapipe=require('../assets/js/glasses-mediapipe-hands.js');
const html=fs.readFileSync('glasses-simulator.php','utf8');
const main=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const ui=fs.readFileSync('assets/js/glasses-mediapipe-hands-ui.js','utf8');
const tracks=fs.readFileSync('assets/js/glasses-hand-tracker-ui.js','utf8');
const workflow=fs.readFileSync('.github/workflows/full-deploy-package.yml','utf8');
const landmarks=(x,y)=>Array.from({length:21},(_,i)=>({x:x+(i%5)*.015,y:y+Math.floor(i/5)*.017,z:0}));
let out=mediapipe.convert({landmarks:[landmarks(.1,.2),landmarks(.7,.2)],handednesses:[[{categoryName:'Left',score:.98}],[{categoryName:'Right',score:.96}]]});
assert.equal(out.length,2);assert.deepEqual(out.map(d=>d.label),['left hand','right hand']);
assert.ok(out[0].bbox.x<.1&&out[1].bbox.x>.6);
assert.equal(mediapipe.convert({landmarks:[[],null]}).length,0);
assert.equal(mediapipe.VERSION,'0.10.32');
assert.match(html,/glassesHandsModelStatus/);assert.match(html,/glassesHandsToggle/);
assert.match(main,/void window.GelatoMediaPipeHandsRuntime\?\.start\(video/,'auto starts after user explicitly starts Camera 1');
assert.match(main,/window.GelatoMediaPipeHandsRuntime\?\.stop/,'Camera stop cancels tracking');
assert.match(main,/window.GelatoMediaPipeHandsRuntime\.setObjects\(/,'ONNX only supplies optional object observations');
assert.match(main,/filtered\.filter\(d=>!core\?\.isHand\(d\)\)/,'hands never become KDS observations');
assert.match(ui,/Stop Hand Tracking/);assert.match(ui,/Retry Hand Tracking/);
assert.match(tracks,/GREEN · /,'two green states preserved');
assert.match(workflow,/vision_bundle\.mjs/,'release must include runtime');
assert.match(workflow,/hand_landmarker\.task/,'release must include pretrained model');
async function verify(){
 let created=0,closed=0,detections=0,events=[],frameTime=0;
 const delayed=[];
 const fakeModule={FilesetResolver:{forVisionTasks:async dir=>{assert.match(dir,/mediapipe/);return {dir}}},
   HandLandmarker:{createFromOptions:async (_vision,opts)=>{
      created++;assert.equal(opts.runningMode,'VIDEO');assert.equal(opts.numHands,2);
      assert.equal(opts.baseOptions.delegate,'CPU');
      return {detectForVideo:(_video,stamp)=>{detections++;assert.ok(stamp>0);return {
       landmarks:[landmarks(.2,.2),landmarks(.64,.25)],
       handednesses:[[{categoryName:'Left',score:.95}],[{categoryName:'Right',score:.93}]]
      }},close(){closed++;}};
   }}};
 const ctrl=mediapipe.createRuntime({
   importModule:async()=>fakeModule,
   frameClock:()=>{frameTime+=16;return frameTime},
   hidden:()=>false,now:()=>1000+frameTime,
   emit:(type,detail)=>events.push({type,detail}),
   setDelay:fn=>{delayed.push(fn);return delayed.length},cancelDelay:()=>{},intervalMs:125
 });
 const video={srcObject:{id:'stream'},readyState:4,videoWidth:1280,videoHeight:720};
 assert.equal(await ctrl.start(video,()=>({activeItem:{kdsItemPublicId:'kds-1'},actions:{}})),true);
 assert.equal(ctrl.isActive(),true);assert.equal(created,1);assert.equal(detections,1);
 let frame=events.find(e=>e.type==='gelato-glasses-hand-frame')?.detail;
 assert.equal(frame.hands.length,2);assert.equal(frame.modelReady,true);
 assert.equal(frame.activeItem.kdsItemPublicId,'kds-1');
 ctrl.setObjects([{label:'Cheese',confidence:.9,bbox:{x:.2,y:.2,width:.1,height:.1}}],{kdsItemPublicId:'kds-1',modelSha256:'a'.repeat(64)});
 delayed.shift()();
 frame=events.filter(e=>e.type==='gelato-glasses-hand-frame').at(-1).detail;
 assert.equal(frame.objects.length,1);
 ctrl.setObjects([{label:'Cheese',confidence:.9,bbox:{x:.2,y:.2,width:.1,height:.1}}],{kdsItemPublicId:'different-ticket',modelSha256:'a'.repeat(64)});
 delayed.shift()();
 frame=events.filter(e=>e.type==='gelato-glasses-hand-frame').at(-1).detail;
 assert.equal(frame.objects.length,0,'other ticket objects must never be reused');
 ctrl.stop();assert.equal(ctrl.isActive(),false);
 assert.equal(events.at(-1).type,'gelato-glasses-hand-reset');
 assert.equal(await ctrl.start(video,()=>({})),true);
 assert.equal(created,1,'model reused without download');
 ctrl.close();assert.equal(closed,1);
 let finish;
 const pending=mediapipe.createRuntime({
   importModule:async()=>new Promise(resolve=>{finish=resolve}),
   emit:(type,detail)=>events.push({type,detail}),onStatus:()=>{},setDelay:fn=>delayed.push(fn)
 });
 const start=pending.start(video,()=>({}));
 await Promise.resolve();pending.stop();finish(fakeModule);
 assert.equal(await start,false);
 assert.equal(pending.isActive(),false);
 console.log('glasses-mediapipe-hands=ok');
}
verify().catch(e=>{console.error(e);process.exitCode=1});