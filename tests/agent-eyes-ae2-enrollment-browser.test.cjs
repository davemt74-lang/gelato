'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  await page.route('http://localhost/**',route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>AE2</title>'}));
  await page.goto('http://localhost/');
  await page.setContent('<!doctype html><html><head><meta name="gelato-csrf" content="ae2-ci"></head><body><section id="fm-eyes-panel"><div class="fm-eyes-head"></div><video></video></section></body></html>');
  await page.evaluate(()=>{
   window.apiCalls=[];window.GelatoRoomPresence={active:true,refreshProfiles:async()=>{window.refreshed=true;}};
   const video=document.querySelector('video');Object.defineProperty(video,'videoWidth',{configurable:true,get:()=>640});
   const descriptor=Array.from({length:128},(_,i)=>.1+(i%7)*.001);
   window.faceapi={TinyFaceDetectorOptions:class{},
     detectAllFaces:()=>({withFaceLandmarks:()=>({withFaceDescriptors:async()=>[{descriptor}]})})};
   window.fetch=async(url,options={})=>{
    if(url.includes('enroll_candidates'))return {ok:true,json:async()=>({ok:true,people:[{id:22,name:'Test Participant',email:'participant@example.test',enrolled:false}]})};
    const body=JSON.parse(options.body||'{}');window.apiCalls.push(body);
    if(body.action==='enroll_commit')return {ok:true,json:async()=>({ok:true,person:{id:22,name:'Test Participant',email:'participant@example.test'}})};
    if(body.action==='enroll_revoke')return {ok:true,json:async()=>({ok:true,email:'participant@example.test'})};
    throw Error('Unexpected fetch: '+url);
   };
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../js/agent-eyes-enrollment.js'),'utf8')});
  const ok=async(condition,label)=>assert.ok(await condition,label);
  await page.locator('#fm-eyes-enroll-open').click();
  await ok(page.locator('#fm-eyes-enrollment').isVisible(),'enrollment appears in Agent Eyes');
  await page.locator('#fm-eyes-person').selectOption('22');
  await page.locator('#fm-eyes-capture').click();
  await ok(page.locator('#fm-eyes-enroll-status').textContent().then(s=>s.includes('consent')),'no capture without consent');
  await page.locator('#fm-eyes-consent').check();
  for(let i=0;i<3;i++){
   await page.locator('#fm-eyes-capture').click();
   await page.waitForFunction(expected=>document.querySelector('#fm-eyes-capture')?.textContent.includes(expected),i===2?'Save enrollment':'Capture sample '+(i+2),{timeout:10000});
  }
  await page.locator('#fm-eyes-capture').click();
  await page.waitForFunction(()=>document.querySelector('#fm-eyes-enroll-status')?.textContent.includes('saved locally'),{timeout:10000});
  const stored=await page.evaluate(async()=>{
   const db=await new Promise((resolve,reject)=>{const r=indexedDB.open('gelato-fm-face-pin-v1',1);r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});
   const rows=await new Promise((resolve,reject)=>{const tx=db.transaction('profiles','readonly'),req=tx.objectStore('profiles').getAll();req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error);});db.close();return rows;
  });
  assert.equal(stored.length,1,'one encrypted profile');
  assert.ok(!JSON.stringify(stored).includes('participant@example.test'),'no plaintext email in IndexedDB');
  assert.ok(!JSON.stringify(stored).includes('vector'),'no plaintext descriptor');
  await ok(page.evaluate(()=>window.apiCalls.every(c=>!JSON.stringify(c).includes('descriptor')&&!JSON.stringify(c).includes('vector'))),'no vectors on network');
  await page.locator('#fm-eyes-consent').check();
  await page.locator('#fm-eyes-person').selectOption('22');
  // Backend fixture represents freshly enrolled status on next roster fetch in the real API.
  await page.evaluate(()=>window.fetch=async(url,options={})=>{
   if(url.includes('enroll_candidates'))return {ok:true,json:async()=>({ok:true,people:[{id:22,name:'Test Participant',email:'participant@example.test',enrolled:true}]})};
   const body=JSON.parse(options.body||'{}');window.apiCalls.push(body);
   if(body.action==='enroll_revoke')return {ok:true,json:async()=>({ok:true,email:'participant@example.test'})};
   throw Error('Unexpected action');
  });
  await page.locator('#fm-eyes-enroll-open').click();
  await page.locator('#fm-eyes-enroll-open').click();
  await page.locator('#fm-eyes-person').selectOption('22');
  await page.locator('#fm-eyes-consent').check();
  await page.locator('#fm-eyes-revoke').click();
  await page.waitForFunction(()=>document.querySelector('#fm-eyes-enroll-status')?.textContent.includes('revoked'),{timeout:10000});
  console.log('Agent Eyes AE2 local enrollment browser PASS');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
