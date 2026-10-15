'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright'),root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let checks=0;const ok=(value,label)=>{assert.ok(value,label);checks++};
 try{
  const page=await browser.newPage({viewport:{width:1280,height:800},reducedMotion:'reduce'}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://gelato.test/',route=>route.fulfill({status:200,contentType:'text/html',
    body:'<!doctype html><html><head></head><body class="food-monster-stage-page"><main id="food-monster-stage"><a class="fm-stage-brand" href="#">Food Monster AI</a></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea></div></body></html>'}));
  await page.goto('https://gelato.test/');
  await page.addStyleTag({content:fs.readFileSync(path.join(root,'css/food-monster.css'),'utf8')});
  await page.evaluate(()=>{
   window.GelatoGlobalAgent={user:{id:77,name:'Taylor Account'},permissions:['*']};
   window.__greetings=[];window.GelatoFoodMonster={greet:text=>window.__greetings.push(text)};
   window.fetch=async()=>({ok:true,json:async()=>({ok:true,session:{userId:77,displayName:'Taylor Account',signedInHere:true},
    staff:[{userId:77,displayName:'Taylor Account',signedInHere:true,status:'Scheduled · clocked in'},
           {userId:80,displayName:'Jordan',signedInHere:false,status:'Clocked in · not scheduled today'}],
    access:{scheduleReady:true,clockReady:true},scopeNote:'Only this session verified.'})});
   window.GelatoFoodMonsterCamera={active:false,enrolled:false,stream:{getTracks:()=>[]},preferences:{greet:true,speak:false},
    start:async function(){this.active=true;window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:true}}));return true;},
    stop:function(){this.active=false;window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:false}}));}};
   Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{configurable:true,get(){return this.__stream||null},set(v){this.__stream=v}});
   HTMLMediaElement.prototype.play=async function(){Object.defineProperty(this,'videoWidth',{configurable:true,value:640});Object.defineProperty(this,'videoHeight',{configurable:true,value:480})};
   HTMLMediaElement.prototype.pause=function(){};
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'js/food-monster-participants.js'),'utf8')});
  await page.waitForFunction(()=>document.querySelectorAll('.fm-staff-item').length===2);
  ok(await page.locator('.fm-participants-panel').count()===1,'stage participant panel mounted');
  ok(await page.locator('.fm-participant-card').count()===0,'no fabricated participants without camera');
  ok(await page.locator('.fm-staff-item').count()===2,'signed-in and unscheduled clocked-in records shown');
  ok(!await page.locator('.fm-room-camera-frame').isVisible(),'camera view hidden by default');
  ok(await page.evaluate(()=>window.__greetings.length)===0,'no camera or unsolicited greeting at load');
  await page.locator('[data-fm-camera-toggle]').click();
  ok(await page.evaluate(()=>window.GelatoFoodMonsterParticipants.cameraActive),'user toggle enables camera');
  await page.locator('[data-fm-view="camera"]').click();
  ok(await page.locator('.fm-room-camera-frame').isVisible(),'camera mode visible');
  const faces=[{x:.10,y:.15,width:.22,height:.34,confidence:.91},{x:.55,y:.22,width:.19,height:.30,confidence:.87}];
  for(let i=0;i<5;i++)await page.evaluate(faces=>window.GelatoFoodMonsterParticipants.updateFaces(faces,false),faces);
  ok(await page.locator('.fm-participant-card').count()===2,'two independently tracked faces');
  ok((await page.locator('.fm-participant-card').first.innerText()).includes('Identity unknown'),'unverified face not named as employee');
  ok(await page.evaluate(()=>window.__greetings.length)===1,'one welcoming announcement for newly detected group');
  ok(await page.locator('.fm-participant-card img').count()===0,'photos off by default');
  await page.locator('[data-fm-photos]').check();
  for(let i=0;i<3;i++)await page.evaluate(faces=>window.GelatoFoodMonsterParticipants.updateFaces(faces,false),faces);
  ok(await page.locator('.fm-participant-card img').count()===2,'opt-in thumbnails captured locally');
  ok(await page.evaluate(()=>localStorage.length===0),'photos never persisted in localStorage');
  await page.waitForTimeout(2100);
  await page.evaluate(()=>window.GelatoFoodMonsterParticipants.updateFaces([{x:.1,y:.15,width:.22,height:.34,confidence:.92}],true));
  ok((await page.locator('.fm-participant-card').first.innerText()).includes('Taylor Account'),'verified sole face can use only signed-in account');
  await page.locator('[data-fm-photos]').uncheck();
  ok(await page.locator('.fm-participant-card img').count()===0,'turning off photos clears thumbnail');
  await page.locator('[data-fm-camera-toggle]').click();
  ok(await page.locator('.fm-participant-card').count()===0,'camera off removes all participant tracks');
  ok(!await page.evaluate(()=>window.GelatoFoodMonsterParticipants.cameraActive),'camera off exits');
  ok(errors.length===0,'no browser JavaScript errors: '+errors.join('; '));
  console.log('FM-G8A browser stage, tracking and privacy: PASS ('+checks+' checks)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
