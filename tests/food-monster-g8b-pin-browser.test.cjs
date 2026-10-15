'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const source=fs.readFileSync(path.resolve(__dirname,'../js/fm-face-pin.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let n=0;
 const check=(ok,name)=>{assert.ok(ok,name);n++};
 try{
  const context=await browser.newContext({viewport:{width:920,height:760}});
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const enroll='<form id="fm-pin-set"><input name="currentPassword"><input name="pin"><input name="confirmPin"><button type="submit">Save</button></form><input id="fm-face-consent" type="checkbox"><video id="fm-face-preview" autoplay muted playsinline hidden></video><button id="fm-face-enroll">Enroll</button><button id="fm-face-remove">Remove</button>';
  const login='<button id="fm-face-pin-start">Camera sign in</button><video id="fm-face-pin-video" autoplay muted playsinline hidden></video><form id="fm-face-pin-form" hidden><input id="fm-face-pin-identity"><input id="fm-face-pin-email" name="email"><input id="fm-face-pin-value" name="pin"><button type="submit">Sign in</button></form>';
  await page.route('https://gelato.test/**',route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><html><body>'+(route.request().url().includes('/enroll')?enroll:login)+'<p id="fm-pin-status"></p></body></html>'}));
  await page.addInitScript(()=>{
   window.__requests=[];const vector=Float32Array.from({length:128},(_,i)=>(i%13)*.007);
   window.faceapi={nets:{tinyFaceDetector:{loadFromUri:async()=>{}},faceLandmark68Net:{loadFromUri:async()=>{}},faceRecognitionNet:{loadFromUri:async()=>{}}},TinyFaceDetectorOptions:class{},detectAllFaces:async()=>[{box:{x:20,y:20,width:100,height:100}}],detectSingleFace:()=>({withFaceLandmarks:()=>({withFaceDescriptor:async()=>({descriptor:vector})})})};
   Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){window.__stops=(window.__stops||0)+1}}]})}});
   Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{configurable:true,get(){return this.__media||null},set(v){this.__media=v}});
   HTMLMediaElement.prototype.play=async function(){};HTMLMediaElement.prototype.pause=function(){};
   window.fetch=async(url,opts={})=>{
    const arg=opts.body?JSON.parse(opts.body):{};window.__requests.push({url,arg});let code=200;
    let response={ok:true,csrf:'test-csrf',trustedDevice:true,deviceLabel:'Kitchen',signedIn:location.pathname.includes('/enroll'),email:'employee@example.com',pinSet:true};
    if(arg.action==='face_enroll')response={ok:true,email:'employee@example.com',label:'Example Employee'};
    if(arg.action==='pin_login'){if(arg.pin==='928453')response={ok:true,redirect:'workspace.php'};else{code=401;response={ok:false,message:'PIN rejected'}}}
    return {ok:code===200,status:code,json:async()=>response};
   };
   window.confirm=()=>true;
  });
  await page.goto('https://gelato.test/enroll');await page.addScriptTag({content:source});
  await page.locator('#fm-face-consent').check();await page.locator('#fm-face-enroll').click();
  await page.waitForFunction(()=>document.getElementById('fm-pin-status').textContent.includes('Face enrolled'),null,{timeout:15000});
  check(await page.evaluate(()=>window.__requests.some(r=>r.arg.action==='face_enroll')),'Authenticated employee enrollment');
  check(await page.evaluate(()=>window.__requests.find(r=>r.arg.action==='face_enroll').arg.csrf_token==='test-csrf'),'CSRF supplied');
  check(await page.evaluate(()=>window.__stops>0),'Camera stops after enrollment');
  await page.goto('https://gelato.test/login');await page.addScriptTag({content:source});
  check(await page.locator('#fm-face-pin-form').isHidden(),'PIN form hidden until recognition');
  await page.locator('#fm-face-pin-start').click();
  await page.waitForFunction(()=>!document.getElementById('fm-face-pin-form').hidden,null,{timeout:15000});
  check(await page.locator('#fm-face-pin-identity').inputValue()==='Example Employee','Local facial match selects account');
  check(await page.locator('#fm-face-pin-email').inputValue()==='employee@example.com','Enrolled employee selected');
  check(await page.evaluate(()=>!window.__requests.some(r=>r.arg.action==='pin_login')),'Face alone never submits login');
  await page.locator('#fm-face-pin-value').fill('000000');await page.locator('#fm-face-pin-form button').click();
  await page.waitForFunction(()=>document.getElementById('fm-pin-status').textContent==='PIN rejected');
  check(page.url().includes('/login'),'Invalid PIN does not sign in');
  await page.locator('#fm-face-pin-value').fill('928453');await page.locator('#fm-face-pin-form button').click();
  await page.waitForURL('**/workspace.php');
  check(page.url().includes('workspace.php'),'Valid PIN redirects after server acceptance');
  check(errors.length===0,'No browser errors '+errors.join('; '));
  console.log('FM-G8B PIN Chromium sign-in: PASS ('+n+' assertions)');
  await context.close();
 }finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exitCode=1});
