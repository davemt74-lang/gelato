'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream']});
 try{
  const page=await browser.newPage();
  await page.route('http://localhost/**',route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>FM-G9C Test</title>'}));
  await page.goto('http://localhost/');
  await page.setContent('<!doctype html><html><head><meta name="gelato-csrf" content="ci-token"></head><body><button data-fm-camera>Camera</button></body></html>');
  await page.evaluate(async()=>{
   window.calls=[];window.refreshed=0;window.mediaStream=null;window.cameraErrors=[];
   window.addEventListener('gelato-room-camera-error',e=>window.cameraErrors.push(e.detail?.message||'Unknown camera error'));
   // The canvas stream has no real video frames; camera inference is mocked below.
   // Resolve HTMLVideoElement.play() without awaiting physical frame delivery.
   window.HTMLMediaElement.prototype.play=async function(){return;};
   window.GelatoGlobalAgent={refreshContext:async()=>{window.refreshed++;return {};}};
   window.loadedModels=0;
   const canvas=document.createElement('canvas');canvas.width=320;canvas.height=240;document.body.append(canvas);
   Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{window.mediaStream=canvas.captureStream(10);return window.mediaStream;}}});
   Object.defineProperty(window,'isSecureContext',{configurable:true,get:()=>true});
   const d=Array.from({length:128},(_,i)=>.1+(i%5)*.001);
   // Same encrypted IndexedDB contract as preexisting Face/PIN enrollment.
   const open=()=>new Promise((resolve,reject)=>{const r=indexedDB.open('gelato-fm-face-pin-v1',1);r.onupgradeneeded=()=>{r.result.createObjectStore('keys');r.result.createObjectStore('profiles')};r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error)});
   const db=await open();
   const store=(name,key,value)=>new Promise((resolve,reject)=>{const t=db.transaction(name,'readwrite');t.objectStore(name).put(value,key);t.oncomplete=resolve;t.onerror=()=>reject(t.error)});
   const key=await crypto.subtle.generateKey({name:'AES-GCM',length:256},false,['encrypt','decrypt']);
   await store('keys','master',key);
   const iv=crypto.getRandomValues(new Uint8Array(12));
   const plain=new TextEncoder().encode(JSON.stringify({id:'local-profile',email:'cook@example.test',label:'Test Cook',vector:d,createdAt:Date.now()}));
   const crypt=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,plain);
   const toB64=x=>btoa(String.fromCharCode(...new Uint8Array(x)));
   await store('profiles','local-profile',{iv:toB64(iv),cipher:toB64(crypt)});db.close();
   window.faceapi={
     nets:{tinyFaceDetector:{loadFromUri:async()=>{window.loadedModels++;}},faceLandmark68Net:{loadFromUri:async()=>{window.loadedModels++;}},faceRecognitionNet:{loadFromUri:async()=>{window.loadedModels++;}}},
     TinyFaceDetectorOptions:class{},
     detectAllFaces:()=>({withFaceLandmarks:()=>({withFaceDescriptors:async()=>[
       {detection:{box:{x:32,y:24,width:80,height:80}},descriptor:new Float32Array(d)},
       {detection:{box:{x:205,y:30,width:75,height:75}},descriptor:null},
     ]})})
   };
   window.fetch=async(url,opt={})=>{
     const action=opt.method==='POST'?JSON.parse(opt.body).action:'status';
     if(action!=='status')window.calls.push(JSON.parse(opt.body));
     const observations=action==='observe'?JSON.parse(opt.body).tracks:[];
     const people=observations.map(x=>({trackId:x.trackId,personId:x.localProfileEmail==='cook@example.test'?22:null,displayName:x.localProfileEmail==='cook@example.test'?'Test Cook':null,identitySource:x.localProfileEmail?'local_face_profile':'unknown',identityAssurance:x.localProfileEmail?'provisional_visual_match':'none',agentAdministration:false,websiteAuthenticated:false}));
     return {ok:true,json:async()=>({ok:true,room:{status:action==='stop'?'not_connected':'active',people,currentSpeaker:null}})};
   };
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster-room-tracker.js'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster-room.js'),'utf8')});
  let n=0;const ok=(v,label)=>{assert.ok(v,label);n++;};
  ok(await page.evaluate(()=>window.GelatoRoomPresence.active===false),'camera initially off');
  ok(await page.evaluate(()=>window.calls.length===0),'no autonomous camera request');
  await page.locator('[data-fm-camera]').click();
  await page.waitForFunction(()=>window.calls.some(x=>x.action==='observe')||window.cameraErrors.length>0,null,{timeout:12000});
  const cameraErrors=await page.evaluate(()=>window.cameraErrors);
  assert.deepEqual(cameraErrors,[],'Camera startup should not fail: '+cameraErrors.join('; '));
  ok(await page.locator('#fm-room-presence').isVisible(),'small presence strip visible only while enabled');
  ok(await page.evaluate(()=>window.calls.some(x=>x.tracks?.length===2)),'two faces observed independently');
  ok(await page.evaluate(()=>window.calls.every(x=>!(JSON.stringify(x).includes('vector')||JSON.stringify(x).includes('descriptor')||JSON.stringify(x).includes('password')))),'no raw biometric vector in network payload');
  ok(await page.evaluate(()=>window.calls.every(x=>x.action==='stop'||x.csrf_token==='ci-token')),'CSRF preserved for room observations');
  await page.waitForFunction(()=>window.calls.some(x=>x.tracks?.some(t=>t.localProfileEmail==='cook@example.test')),{timeout:18000});
  ok(await page.evaluate(()=>window.calls.some(x=>x.tracks?.some(t=>t.localProfileEmail==='cook@example.test'))),'existing encrypted profile reused after consistent visual observations');
  ok(await page.evaluate(()=>window.GelatoRoomPresence.room.people.some(p=>p.personId===22)),'server-scoped identity displayed only after lookup');
  ok(await page.evaluate(()=>window.GelatoRoomPresence.room.people.some(p=>p.personId===null)),'unrecognized person remains anonymous');
  ok(await page.evaluate(()=>window.GelatoRoomPresence.room.currentSpeaker===null),'video never fabricates current speaker');
  ok(await page.locator('#fm-eyes-open').isVisible(),'Agent Eyes launcher visible');
  ok(await page.locator('#fm-eyes-panel').isHidden(),'live preview closed by default');
  await page.locator('#fm-eyes-open').click();
  ok(await page.locator('#fm-eyes-panel').isVisible(),'live Agent Eyes canvas opens');
  ok(await page.locator('#fm-eyes-panel video').count()===1,'existing camera stream reused in preview');
  ok(await page.locator('#fm-eyes-panel .fm-eyes-box').count()===2,'both participant bounding boxes displayed');
  ok(await page.locator('#fm-eyes-people li').count()===2,'participant list mirrors active tracks');
  ok(await page.locator('#fm-eyes-panel').textContent().then(x=>x.includes('Test Cook · provisional')&&x.includes('Unknown participant')),'recognized and unknown participants labeled honestly');
  await page.locator('#fm-eyes-panel button[aria-label="Close Agent Eyes"]').click();
  ok(await page.locator('#fm-eyes-panel').isHidden(),'closing preview leaves detection running');
  ok(await page.evaluate(()=>window.GelatoRoomPresence.active),'preview close does not stop room camera');

  ok(await page.evaluate(()=>window.GelatoRoomPresence.diagnostics.modelCached===true
    &&window.GelatoRoomPresence.diagnostics.intervalMs===1450),'active room uses responsive adaptive rate');
  ok(await page.evaluate(()=>window.loadedModels===3),'model weights loaded only once on first camera start');
  await page.locator('[data-fm-camera]').click();
  await page.waitForFunction(()=>!window.GelatoRoomPresence.active);
  ok(await page.locator('#fm-room-presence').isHidden(),'camera stop removes presence strip');
  ok(await page.evaluate(()=>window.mediaStream.getTracks().every(t=>t.readyState==='ended')),'camera tracks closed on stop');
  ok(await page.evaluate(()=>window.calls.some(x=>x.action==='stop')),'server room presence cleared on stop');
  ok(await page.evaluate(()=>window.refreshed>=1),'Agent Brain context refreshed');
  await page.evaluate(()=>window.GelatoRoomPresence.start());
  ok(await page.evaluate(()=>window.loadedModels===3),
    'subsequent opt-in camera session reuses already loaded face recognition models');
  ok(await page.evaluate(()=>window.GelatoRoomPresence.diagnostics.modelCached
    &&window.GelatoRoomPresence.diagnostics.active),'restart preserves initialized camera session');
  await page.evaluate(()=>window.GelatoRoomPresence.stop());
  console.log('FM-G9C opt-in camera and presence Chromium PASS ('+n+' checks)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
