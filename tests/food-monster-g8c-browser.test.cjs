const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright'),root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let n=0;const ok=(v,l)=>{assert.ok(v,l);n++};
 try{
  const page=await browser.newPage({viewport:{width:1200,height:800}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<!doctype html><html><head><meta name="gelato-csrf" content="test-csrf"></head><body class="food-monster-stage-page"><main id="food-monster-stage"></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea></div></body></html>');
  await page.evaluate(()=>{
   window.GelatoGlobalAgent={user:{id:42,name:'Staff'},permissions:['voice.agent']};
   window.GelatoFoodMonster={greet(text){window.__lastGreeting=text}};
   window.GelatoFoodMonsterCamera={get preferences(){return {greet:true}},active:true};
   window.__goals=[];window.__ops=[];
   window.fetch=async(url,options={})=>{
    if(String(url).startsWith('api/food-monster-participants.php'))return {ok:true,json:async()=>({ok:true,staff:[],scopeNote:'test'})};
    if(String(url).includes('action=list'))return {ok:true,json:async()=>({ok:true,goals:window.__goals,roles:[]})};
    if(!String(url).startsWith('api/food-monster-onboarding.php'))throw Error('unexpected '+url);
    const req=JSON.parse(options.body||'{}');window.__ops.push(req);
    if(req.action==='start'){
     const g={id:'fmg-123',trackLabel:req.trackLabel,name:'',type:'unknown',phase:'name',status:'in_progress',
      photoConsent:false,voiceConsent:false,photos:[],voiceVerified:false,employeeUserId:null};window.__goals=[g];
     return {ok:true,json:async()=>({ok:true,goal:g})};
    }
    const g=window.__goals[0];
    if(req.action==='name'){g.name=req.name;g.phase='type';}
    if(req.action==='type'){g.type=req.type;g.phase='photos';}
    return {ok:true,json:async()=>({ok:true,goal:{...g}})};
   };
  });
  await page.addStyleTag({content:fs.readFileSync(path.join(root,'css/food-monster.css'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'js/food-monster-participants.js'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'js/food-monster-onboarding.js'),'utf8')});
  await page.waitForFunction(()=>!!window.GelatoFoodMonsterGoals?.load);
  ok(await page.locator('#gelato-agent-bar .fm-goal-launch').count()===1,'Goal launcher present inside sticky chat bar');
  ok(await page.locator('.fm-goal-panel').isHidden(),'No unattended camera/goal overlay');
  await page.evaluate(()=>window.GelatoFoodMonsterParticipants.updateFaces([{x:.20,y:.12,width:.19,height:.30}],false));
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-food-monster-camera',{detail:{active:true}})));
  for(let i=0;i<6;i++)await page.evaluate(()=>window.GelatoFoodMonsterParticipants.updateFaces([{x:.20,y:.12,width:.19,height:.30}],false));
  ok(await page.locator('[data-track="P1"]').count()===1,'Participant Person 1');
  await page.waitForTimeout(1950);
  await page.evaluate(()=>window.GelatoFoodMonsterParticipants.updateFaces([{x:.51,y:.12,width:.19,height:.30}],false));
  ok(await page.locator('[data-track="P1"]').count()===1,'Person 1 preserved after missed frames and head movement');
  ok(await page.locator('[data-track="P2"]').count()===0,'No Person 2 id churn');
  await page.evaluate(()=>window.GelatoFoodMonsterParticipants.setMode('camera'));
  ok(await page.evaluate(()=>getComputedStyle(document.querySelector('.fm-room-camera-view')).top)==='0px','Camera view starts at top of viewport');
  ok(await page.evaluate(()=>getComputedStyle(document.querySelector('.fm-room-camera-frame video')).objectFit)==='cover','Camera is full-bleed');
  await page.evaluate(()=>window.GelatoFoodMonsterParticipants.setMode('agent'));
  await page.locator('[data-fm-setup="P1"]').click();
  ok(await page.locator('.fm-goal-panel').isVisible(),'Start setup opens goal');
  const intercepted=await page.evaluate(()=>{
   const ev=new CustomEvent('gelato-agent-before-send',{cancelable:true,detail:{text:'My name is Jamie',voice:true}});
   window.dispatchEvent(ev);return ev.defaultPrevented;
  });
  ok(intercepted,'Name is consumed by goal, not Claude');
  await page.waitForFunction(()=>window.GelatoFoodMonsterGoals.goal?.name==='Jamie');
  ok(await page.evaluate(()=>window.__ops.map(o=>o.action).includes('start')),'Server goal created after introduction');
  ok(await page.evaluate(()=>window.__ops.map(o=>o.action).includes('name')),'Name persisted');
  const ordinary=await page.evaluate(()=>{
   const ev=new CustomEvent('gelato-agent-before-send',{cancelable:true,detail:{text:'How much did we sell today?',voice:true}});
   window.dispatchEvent(ev);return ev.defaultPrevented;
  });
  ok(!ordinary,'Unrelated chat never stolen by the onboarding flow');
  await page.locator('[data-goal-action="type-employee"]').click();
  await page.waitForFunction(()=>window.GelatoFoodMonsterGoals.goal?.type==='employee');
  ok(await page.locator('.fm-goal-safety').isVisible(),'Unverified identity clearly labeled');
  ok(errors.length===0,'No JavaScript crashes: '+errors.join(';'));
  await page.close();console.log('FM-G8C goal and stable tracking browser: PASS ('+n+' checks)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
