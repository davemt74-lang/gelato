const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const source=name=>fs.readFileSync(path.resolve(__dirname,'..',name),'utf8');
const thread='agent-0123456789abcdefabcd',second='agent-bbbbbbbbbbbbbbbbbbbb';
const turn='msg-aaaaaaaaaaaaaaaaaaaa';
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let assertions=0,stops=0,lastBody=null;
 const ok=(truth,message)=>{assert.ok(truth,message);assertions++;};
 const task=()=>({turnId:turn,state:'running',impact:'read',attempts:1,
  completedSteps:1,plannedSteps:3,interrupted:false,cancelRequested:stops>0,
  canRequestCancel:stops===0,tools:['read_fact'],createdAt:'2026-10-25 12:00:00',
  updatedAt:'2026-10-25 12:00:01'});
 async function setup(page,canvas){
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://gelato.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/'){
    const markup=canvas
     ?'<body data-agent-canvas="1"><main class="chat"><section id="canvasMessages"></section></main><aside class="results"><div class="result-body"></div></aside></body>'
     :'<body><main>Gelato Agent</main></body>';
    return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'+markup+'</html>'});
   }
   if(url.pathname.startsWith('/js/'))
    return route.fulfill({contentType:'text/javascript',body:source(url.pathname.slice(1).split('?')[0])});
   if(url.pathname==='/api/agent-workspace.php'){
    return route.fulfill({contentType:'application/json',body:JSON.stringify({
     ok:true,csrf:'approved-token',activeThread:thread,permissions:['menu.view'],
     user:{id:17,organization_id:1},threads:[]})});
   }
   if(url.pathname==='/api/food-monster-b8d10-approvals.php')
    return route.fulfill({contentType:'application/json',body:'{"ok":true,"approval":null}'});
   if(url.pathname==='/api/food-monster-llm.php'){
    const action=url.searchParams.get('action');
    const body=route.request().postDataJSON?.()||{};
    if(action==='task_list')
     return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,tasks:url.searchParams.get('threadId')===thread?[task()]:[]
     })});
    if(body.action==='task_cancel'){
     lastBody=body;stops++;
     return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,task:task()
     })});
    }
   }
   return route.fulfill({contentType:'application/json',body:'{"ok":true}'});
  });
  await page.goto('https://gelato.test/');
  await page.addScriptTag({content:source('js/global-agent.js')});
  await page.waitForFunction(()=>Boolean(window.GelatoAgentTasks));
  return errors;
 }
 try{
  const page=await browser.newPage({viewport:{width:1100,height:800}});
  let errors=await setup(page,false);
  await page.locator('#fmTaskToggle').click();
  await page.locator('#fmTaskPopup .item').waitFor();
  ok(await page.locator('#fmTaskPopup').innerText().then(t=>t.includes('1/3 verified steps')),
   'Floating bar exposes verified progress without expanding full chat');
  ok(!(await page.locator('#fmTaskPopup').innerText()).includes('24.5 private hours'),
   'Status drawer never reveals private observations');
  await page.locator('#fmTaskPopup button').click();
  await page.waitForFunction(()=>document.querySelector('#fmTaskPopup')?.textContent?.includes('Stop requested'));
  ok(stops===1&&lastBody.action==='task_cancel'&&lastBody.threadId===thread
    &&lastBody.turnId===turn&&lastBody.csrf_token==='approved-token',
    'Explicit click sends scoped CSRF-protected stop request');
  ok(await page.locator('#fmTaskPopup button').count()===0,
   'UI disables further stop requests when the server accepted one');
  await page.evaluate(id=>window.GelatoGlobalAgent.selectThread(id),second);
  await page.waitForFunction(()=>document.querySelector('#fmTaskPopup')?.textContent?.includes('No recorded Agent tasks'));
  ok(!(await page.locator('#fmTaskPopup').innerText()).includes('1/3'),
    'Switching conversations clears previous private task cards');
  await page.evaluate(id=>window.GelatoGlobalAgent.selectThread(id),thread);
  await page.locator('#fmTaskPopup .item').waitFor();
  ok(await page.locator('#fmTaskPopup').innerText().then(t=>t.includes('Stop requested')),
    'Returning to a conversation reloads persisted stop intent');
  ok(errors.length===0,'Floating Agent UI raised no errors: '+errors.join('; '));
  await page.close();

  const canvas=await browser.newPage({viewport:{width:1100,height:800}});
  errors=await setup(canvas,true);
  await canvas.locator('#fmTaskChat .item').waitFor();
  ok(await canvas.locator('#fmTaskChat').innerText().then(t=>t.includes('1/3 verified steps')),
    'Agent Chat shows task progress beside the conversation');
  ok(await canvas.locator('#fmTaskBrain').innerText().then(t=>t.includes('Agent tasks')),
    'Agent Brain shows the same server-scoped receipts');
  ok(await canvas.locator('#fmTaskBrain button').count()===0,
    'Brain copy has no duplicate stop action');
  await canvas.setViewportSize({width:390,height:790});
  ok(await canvas.locator('#fmTaskChat').isVisible(),
    'Mobile chat retains a task status card');
  ok(errors.length===0,'Chat/Brain UI raised no errors: '+errors.join('; '));
  await canvas.close();
  console.log('FM-B8D13D browser chat + brain PASS ('+assertions+' assertions)');
 }finally{await browser.close();}
})().catch(err=>{console.error(err.stack||err);process.exit(1);});
