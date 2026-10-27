const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const source=fs.readFileSync(path.resolve(__dirname,'../js/food-monster-llm.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let checks=0;const ok=(value,name)=>{assert.ok(value,name);checks++};
 try{
  const page=await browser.newPage({viewport:{width:390,height:820}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://gelato.test/',route=>route.fulfill({status:200,contentType:'text/html',
    body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="food-monster-root"><div class="fm-actions"></div></div><div id="gelato-agent-bar"><textarea></textarea></div></body></html>'}));
  await page.goto('https://gelato.test/');
  await page.evaluate(()=>{
   window.GelatoGlobalAgent={user:{id:41,firstName:'Taylor'},permissions:['voice.agent']};
   window.GelatoFoodMonster={root:document.getElementById('food-monster-root')};
   window.__calls=[];
   window.fetch=async (url,opts={})=>{
     window.__calls.push({url,opts});
     if(String(url).includes('action=status'))return {ok:true,json:async()=>({ok:true,providers:{openai:true,anthropic:true}})};
     if(String(url).includes('food-monster-llm.php'))return {ok:true,json:async()=>({ok:true,skill:'agent.llm.conversation',provider:'anthropic',answer:'Welcome!',sources:[]})};
     throw new Error('unexpected URL '+url);
   };
  });
  await page.addScriptTag({content:source});
  await page.waitForFunction(()=>window.GelatoFoodMonsterLLM?.configured!==null);
  ok(await page.locator('.fm-llm-trigger').count()===1,'single AI model trigger on floating character');
  ok(await page.locator('#fm-llm-dialog').isVisible()===false,'dialog closed by default');
  await page.locator('.fm-llm-trigger').click();
  ok(await page.locator('#fm-llm-dialog').isVisible(),'model selector dialog opens');
  ok(await page.locator('#gelato-agent-bar').count()===1,'no extra chat footer');
  await page.locator('[data-fm-provider]').selectOption('anthropic');
  const response=await page.evaluate(()=>window.GelatoFoodMonsterLLM.ask({text:'Hello',threadId:'thr-7',csrf:'csrf-token'}));
  ok(response.answer==='Welcome!','provider result propagated without local hallucination');
  ok(await page.evaluate(()=>window.GelatoFoodMonsterLLM.provider)==='anthropic','selected provider retained');
  const post=await page.evaluate(()=>window.__calls.filter(x=>x.opts?.method==='POST')[0]);
  const body=JSON.parse(post.opts.body);
  ok(body.provider==='anthropic','request respects explicit provider');
  ok(body.threadId==='thr-7'&&body.csrf_token==='csrf-token','canonical user thread and CSRF');
  ok(post.opts.headers['X-CSRF-Token']==='csrf-token','CSRF header present');
  ok(!Object.keys(body).some(k=>/apiKey|tokenSecret|key/i.test(k)),'no provider secrets sent from browser');
  ok(await page.evaluate(()=>localStorage.getItem('gelato.fm.provider.v1.41'))==='anthropic','account-scoped model preference');
  await page.addScriptTag({content:source});
  ok(await page.locator('#fm-llm-dialog').count()===1,'no duplicate model dialogs');
  await page.locator('[data-fm-llm-close]').click();
  ok(await page.locator('#fm-llm-dialog').isVisible()===false,'dialog close works');
  ok((await page.evaluate(()=>document.documentElement.scrollWidth))<=390,'mobile no horizontal overflow');
  ok(errors.length===0,'browser without uncaught errors: '+errors.join(';'));
  await page.close();
  const noProvider=await browser.newPage();
  await noProvider.route('https://gelato.test/',r=>r.fulfill({status:200,contentType:'text/html',body:'<!doctype html><html><body><div id="food-monster-root"><div class="fm-actions"></div></div></body></html>'}));
  await noProvider.goto('https://gelato.test/');
  await noProvider.evaluate(()=>{
    window.GelatoGlobalAgent={user:{id:44},permissions:[]};
    window.GelatoFoodMonster={root:document.getElementById('food-monster-root')};
    window.fetch=async()=>({ok:true,json:async()=>({ok:true,providers:{anthropic:false,openai:false}})});
  });
  await noProvider.addScriptTag({content:source});
  await noProvider.waitForFunction(()=>window.GelatoFoodMonsterLLM?.configured!==null);
  const noKey=await noProvider.evaluate(async()=>{
    try{await window.GelatoFoodMonsterLLM.ask({text:'Hi',threadId:'one',csrf:'x'});return '';}
    catch(error){return String(error.message||error);}
  });
  ok(noKey.includes('No AI provider is configured'),'missing model fails closed without keyword-based private routing');
  ok(noKey.includes('offline keyword fallback'),'clear administrator guidance on missing credentials');
  await noProvider.close();
  console.log('FM-G4 browser provider selection and chat adapter PASS ('+checks+' checks)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
