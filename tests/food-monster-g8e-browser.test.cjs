'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright'),root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let checks=0;
 const ok=(v,label)=>{assert.ok(v,label);checks++};
 try{
  const page=await browser.newPage({viewport:{width:1250,height:850}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<!doctype html><html><head><meta name="gelato-csrf" content="test"></head><body class="food-monster-stage-page"><main id="food-monster-stage"></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea></div></body></html>');
  await page.evaluate(()=>{
   window.__ops=[];window.__goals=[];window.__micRequests=0;
   window.GelatoGlobalAgent={user:{id:1,name:'Owner'},permissions:['voice.agent']};
   window.GelatoFoodMonster={greet:()=>{}};
   window.GelatoFoodMonsterCamera={active:true,preferences:{greet:true,speak:false}};
   window.confirm=()=>true;
   const accounts=[{id:1,name:'Owner',self:true},{id:2,name:'Jamie Employee',self:false}];
   window.fetch=async(url,options={})=>{
    const u=String(url);
    if(u.includes('food-monster-participants.php')){
     return {ok:true,json:async()=>({ok:true,session:{userId:1,displayName:'Owner'},
      staff:Array.from({length:10},(_,i)=>({userId:i+1,displayName:'Employee '+i,scheduledToday:true})),
      access:{schedule:true,scheduleReady:true}})};
    }
    if(u.includes('action=list'))return {ok:true,json:async()=>({ok:true,accounts,roles:[],goals:window.__goals,matches:[]})};
    const body=JSON.parse(options.body||'{}');window.__ops.push(body);
    const g=window.__goals[0];
    if(body.action==='start'||body.action==='start_self'){
     const next={id:'fmg-test',name:body.action==='start_self'?'Owner':'',
      type:body.action==='start_self'?'employee':'unknown',phase:'enrollment',status:'in_progress',
      employeeUserId:body.action==='start_self'?1:null,trackLabel:body.trackLabel||'P1',
      photoConsent:false,voiceConsent:false,photos:[],voiceVerified:false};
     window.__goals=[next];return {ok:true,json:async()=>({ok:true,goal:next})};
    }
    if(body.action==='name'){
     g.name=body.name;g.phase='type';
     const matches=body.name==='Jamie'?[accounts[1]]:[];
     return {ok:true,json:async()=>({ok:true,goal:{...g},matches})};
    }
    if(body.action==='type'){g.type=body.type;g.phase='photos';}
    if(body.action==='link_existing'){g.employeeUserId=body.userId;g.phase='enrollment';}
    return {ok:true,json:async()=>({ok:true,goal:{...g}})};
   };
  });
  await page.addStyleTag({content:fs.readFileSync(path.join(root,'css/food-monster.css'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'js/food-monster-participants.js'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'js/food-monster-onboarding.js'),'utf8')});
  await page.waitForFunction(()=>window.GelatoFoodMonsterParticipants?.roster?.staff?.length===10);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:true}})));
  const boxes=[{x:.07,y:.13,width:.15,height:.33},{x:.39,y:.17,width:.16,height:.31},{x:.72,y:.19,width:.17,height:.33}];
  for(let i=0;i<6;i++)await page.evaluate(b=>window.GelatoFoodMonsterParticipants.updateFaces(b),boxes);
  ok(await page.locator('.fm-participant-card').count()===3,'Three camera participants yield three cards');
  ok(await page.locator('.fm-participant-input').count()===3,'Each of three participants owns a meter');
  ok(await page.locator('.fm-participant-roster,.fm-participants-top,.fm-participant-options,.fm-staff-item').count()===0,
   'No roster, decorative heading, or extra sidebar controls');
  ok(await page.locator('.fm-participant-card').first().innerText().then(x=>x.includes('Person 1')),'Unknowns have stable temporary names');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-food-monster-participant-input',{
    detail:{trackId:'P2',level:.63,attributed:true}
  })));
  ok(await page.locator('[data-track="P2"] .fm-participant-input').getAttribute('aria-valuenow')==='63','Attributable meter changes only Person 2');
  ok(await page.locator('[data-track="P1"] .fm-participant-input').getAttribute('aria-valuenow')==='0','Person 1 remains quiet');
  ok(await page.locator('[data-track="P3"] .fm-participant-input').getAttribute('aria-valuenow')==='0','Person 3 remains quiet');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-food-monster-participant-input',{
    detail:{trackId:'P3',level:1,attributed:false}
  })));
  ok(await page.locator('[data-track="P3"] .fm-participant-input').getAttribute('aria-valuenow')==='0','Unattributed room audio cannot impersonate Person 3');
  ok(await page.evaluate(()=>window.__micRequests===0),'No unsolicited microphone permission requests');
  await page.evaluate(()=>window.GelatoFoodMonsterParticipants.beginUnavailable=true);
  // Camera reset clears all live tracks and their meter state.
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:false}})));
  ok(await page.locator('.fm-participant-card').count()===0,'No participants when camera stops');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:true}})));
  for(let i=0;i<4;i++)await page.evaluate(()=>window.GelatoFoodMonsterParticipants.updateFaces([{x:.15,y:.2,width:.2,height:.35}]));
  await page.locator('#gelato-agent-bar .fm-goal-launch').click();
  await page.waitForFunction(()=>document.querySelector('[data-goal-action="start-self"]'));
  ok(await page.locator('.fm-goal-prompt').innerText().then(x=>x.includes('signed in as Owner')),'Signed-in user prefilled from Gelato');
  ok(await page.locator('[name="name"]').count()===0,'No redundant owner name field');
  await page.locator('[data-goal-action="start-self"]').click();
  await page.waitForFunction(()=>window.GelatoFoodMonsterGoals.goal?.employeeUserId===1);
  ok(await page.evaluate(()=>window.__ops.some(x=>x.action==='start_self'&&x.selfConfirmed===true)),
   'Self linking explicitly confirmed');
  ok(await page.evaluate(()=>!window.__ops.some(x=>x.action==='name'||x.action==='type')),
   'Self enrollment does not ask existing name or employee role');
  // Another participant is NOT automatically treated as the logged-in owner.
  await page.evaluate(()=>window.GelatoFoodMonsterGoals.begin('P1'));
  await page.locator('[data-goal-action="someone-else"]').click();
  await page.locator('[name="name"]').fill('Jamie');
  await page.locator('[data-goal-action="start"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-goal-action="match-2"]'));
  ok(await page.locator('[data-goal-action="match-2"]').count()===1,'Existing employee offered from user DB');
  ok(await page.locator('[data-goal-action="type-employee"]').count()===0,'Employee/guest question skipped when matching existing record');
  ok(await page.evaluate(()=>window.GelatoFoodMonsterGoals.goal?.employeeUserId===null),
   'Name match does not automatically link identity');
  await page.locator('[data-goal-action="match-2"]').click();
  await page.waitForFunction(()=>window.GelatoFoodMonsterGoals.goal?.employeeUserId===2);
  ok(await page.evaluate(()=>window.__ops.some(x=>x.action==='link_existing'&&x.userId===2)),
   'Existing account explicitly linked, without creating duplicate user');
  await page.evaluate(()=>window.GelatoFoodMonsterGoals.begin('P1'));
  await page.locator('[data-goal-action="someone-else"]').click();
  await page.locator('[name="name"]').fill('Riley');
  await page.locator('[data-goal-action="start"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-goal-action="type-employee"]'));
  ok(await page.locator('[data-goal-action="type-guest"]').count()===1,'Unknown name leads to employee/guest question');
  ok(await page.evaluate(()=>window.__ops.filter(x=>x.action==='start_self').length===1),
   'New unknown participants never auto-link signed-in owner');
  ok(errors.length===0,'No JavaScript exceptions '+errors.join('; '));
  console.log('FM-G8E data-driven onboarding and participant meters PASS ('+checks+' checks)');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
