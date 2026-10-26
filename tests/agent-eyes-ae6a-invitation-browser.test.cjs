'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');

(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  await page.route('http://localhost/**',route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>AE6A</title>'}));
  await page.goto('http://localhost/');
  await page.setContent('<!doctype html><html><head><meta name="gelato-csrf" content="ae6a-ci"></head><body><section id="fm-eyes-panel"><div class="fm-eyes-head"></div><ul class="fm-eyes-list"></ul><video></video></section></body></html>');
  await page.evaluate(()=>{
   window.offers=[];window.requests=[];window.opened=0;window.sampleCaptures=0;
   window.GelatoRoomPresence={active:true,openEyes:()=>{window.opened++;},refreshProfiles:async()=>0};
   window.GelatoGlobalAgent={proactiveGreetingEnabled:true,proactiveEnrollmentInvite:()=>{window.offers.push('spoken');return true;}};
   window.fetch=async(url,options={})=>{
    window.requests.push({url:String(url),method:options.method||'GET'});
    if(String(url).includes('enroll_candidates'))return {ok:true,json:async()=>({ok:true,people:[{id:7,name:'Existing Member',email:'member@example.test',enrolled:false}]})};
    throw Error('Enrollment must not be submitted without a separate consented capture.');
   };
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../js/agent-eyes-enrollment.js'),'utf8')});
  const unknown=(track)=>({status:'active',people:[{trackId:track,identitySource:'unknown',identityAssurance:'none',personId:null,displayName:null}]});
  const a='r-aaaaaaaaaaaaaaaa',b='r-bbbbbbbbbbbbbbbb',c='r-cccccccccccccccc';
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(a));
  assert.equal(await page.locator('#fm-eyes-enrollment-invitation').isHidden(),true,'a single observation does not prompt');
  assert.deepEqual(await page.evaluate(()=>window.offers),[],'no immediate speech');
  await page.waitForTimeout(3150);
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(a));
  assert.equal(await page.locator('#fm-eyes-enrollment-invitation').isVisible(),true,'stable unknown participant offered enrollment');
  assert.equal(await page.evaluate(()=>window.offers.length),1,'exactly one proactive voice invitation');
  assert.equal(await page.evaluate(()=>window.requests.length),0,'neither audio nor photo is collected by an invitation');
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(a));
  assert.equal(await page.evaluate(()=>window.offers.length),1,'no repeated spoken invitation to same track');
  await page.locator('#fm-eyes-invitation-enroll').click();
  await page.waitForFunction(()=>window.requests.some(r=>r.url.includes('enroll_candidates')));
  assert.equal(await page.locator('#fm-eyes-enrollment').isVisible(),true,'existing enrollment flow reused');
  assert.equal(await page.locator('#fm-eyes-consent').isChecked(),false,'participant consent never preselected');
  assert.equal(await page.evaluate(()=>window.requests.every(r=>r.method==='GET')),true,'no enrollment write before consent');
  assert.equal(await page.evaluate(()=>window.opened),1,'Agent Eyes opened without starting a new camera');
  await page.evaluate(()=>window.GelatoEyesEnrollment.resetRoom());
  assert.equal(await page.locator('#fm-eyes-enrollment').isHidden(),true,'stopping the room closes unattended enrollment');
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),{
   status:'active',people:[...unknown(a).people,{trackId:'r-dddddddddddddddd',identitySource:'unknown',identityAssurance:'none'}]
  });
  assert.equal(await page.locator('#fm-eyes-enrollment-invitation').isHidden(),true,'mixed voices/faces cannot be attributed');
  await page.evaluate(()=>{window.GelatoGlobalAgent.proactiveGreetingEnabled=false;});
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(b));
  await page.waitForTimeout(3150);
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(b));
  assert.equal(await page.locator('#fm-eyes-enrollment-invitation').isVisible(),true,'visual invitation works with speech disabled');
  assert.equal(await page.evaluate(()=>window.offers.length),1,'no spoken invitation when proactive speech is off');
  await page.locator('#fm-eyes-invitation-decline').click();
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(b));
  assert.equal(await page.locator('#fm-eyes-enrollment-invitation').isHidden(),true,'decline honored for current participant track');
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(c));
  await page.waitForTimeout(3150);
  await page.evaluate(room=>window.GelatoEyesEnrollment.onRoom(room),unknown(c));
  assert.equal(await page.locator('#fm-eyes-enrollment-invitation').isVisible(),true,'new participant can be offered enrollment');
  console.log('Agent Eyes AE6A proactive consent invitation Chromium PASS (12 contracts)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
