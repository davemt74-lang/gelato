const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let checks=0;const ok=(truth,name)=>{assert.ok(truth,name);checks++};
 const mime=p=>p.endsWith('.css')?'text/css':p.endsWith('.svg')?'image/svg+xml':'text/javascript';
 async function scenario(stage,width,height){
  const page=await browser.newPage({viewport:{width,height},reducedMotion:'reduce'});
  const errors=[],assetFetches=[];page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{window.GelatoGlobalAgent={user:{id:771,name:'Kitchen Team'},permissions:[],send:async()=>({ok:true})};window.GelatoVoiceTurns={speak:()=>false};});
  await page.route('https://gelato.test/**',async route=>{
   const u=new URL(route.request().url()),p=u.pathname.slice(1);
   if(p==='food-monster.php'||p==='workspace.php'){
     const html='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>#gelato-agent-bar{position:fixed;left:10px;right:10px;bottom:0}</style>'+
       (stage?'<link rel="stylesheet" href="css/food-monster.css?v=20261014-fmg6">':'')+
       '</head><body '+(stage?'class="food-monster-stage-page"':'')+'><main id="food-monster-stage"></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea><button>Send</button></div>'+
       '<script src="js/food-monster.js?v=20261014-fmg6"></script></body></html>';
     return route.fulfill({status:200,contentType:'text/html',body:html});
   }
   if(p==='api/food-monster-llm.php')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,providers:{openai:false,anthropic:false}})});
   if(p==='api/brain-orchestrator.php')return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({ok:false})});
   if(p.startsWith('js/')||p==='assets/food-monster/chef-mascot.svg'||p==='css/food-monster.css'){
     const filename=path.join(base,p);
     if(!fs.existsSync(filename))return route.fulfill({status:404,body:'missing asset'});
     assetFetches.push(p);
     return route.fulfill({status:200,contentType:mime(p),body:fs.readFileSync(filename)});
   }
   return route.fulfill({status:404,body:'unknown resource '+p});
  });
  await page.goto('https://gelato.test/'+(stage?'food-monster.php':'workspace.php'));
  await page.locator('[data-fm-pupil]').waitFor({timeout:7500});
  await page.locator('.fm-camera-trigger').waitFor({timeout:7500});
  await page.locator('.fm-llm-trigger').waitFor({timeout:7500});
  await page.locator('.fm-suggestion-trigger').waitFor({state:'attached',timeout:7500});
  ok(await page.locator('#food-monster-root').count()===1,'one mascot mount');
  ok(await page.locator('#gelato-agent-bar').count()===1,'one chat composer');
  ok(await page.locator('#fm-camera-dialog').count()===1,'one camera settings dialog');
  ok(await page.locator('#fm-llm-dialog').count()===1,'one model settings dialog');
  ok(await page.locator('.fm-suggestion-panel').count()===1,'one suggestion panel');
  ok(await page.locator('#fm-camera-dialog').isVisible()===false,'webcam opt-in');
  ok(await page.locator('#fm-llm-dialog').isVisible()===false,'model selection opt-in');
  ok(await page.locator('.fm-suggestion-panel').isVisible()===false,'suggestions quiet by default');
  ok((await page.evaluate(()=>document.documentElement.scrollWidth))<=width+1,'no horizontal overflow '+width);
  ok(await page.locator('link[href^="css/food-monster.css"]').count()===1,'CSS loaded once');
  ok(await page.locator('aside').count()===0,'no duplicate sidebar');
  ok(assetFetches.includes('js/food-monster-suggestions.js'),'G5 scripts loaded after upgraded boot');
  ok(assetFetches.includes('js/food-monster-llm.js'),'G4 scripts loaded after upgraded boot');
  if(stage){
   ok((await page.locator('body').getAttribute('class')).includes('food-monster-stage-page'),'dedicated stage present');
   ok((await page.locator('#gelato-agent-bar').evaluate(el=>getComputedStyle(el).position))==='fixed','composer pinned to viewport');
  } else {
   ok((await page.locator('#food-monster-root').evaluate(el=>getComputedStyle(el).position))==='fixed','global mascot overlays page');
  }
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-agent-response',{detail:{answer:'Welcome to Gelato'}})));
  ok(await page.locator('#food-monster-root.fm-happy').count()===1,'agent response animates expression');
  ok(await page.locator('#food-monster-root.fm-speaking').count()===0,'typed response does not invent spoken audio');
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster.js'),'utf8')});
  ok(await page.locator('#food-monster-root').count()===1,'repeat mount does not duplicate mascot');
  ok(errors.length===0,'no uncaught JavaScript errors: '+errors.join(' ; '));
  await page.close();
 }
 try{
  await scenario(true,1280,800);
  await scenario(true,390,844);
  await scenario(false,390,844);
  console.log('FM-G6 integrated stage, global shell and cache acceptance: PASS ('+checks+' assertions)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
