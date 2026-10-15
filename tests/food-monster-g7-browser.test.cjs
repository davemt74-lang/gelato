const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
let checks=0;const ok=(truth,label)=>{assert.ok(truth,label);checks++};
try{
 const page=await browser.newPage({viewport:{width:390,height:800},reducedMotion:'reduce'});
 const errors=[],calls=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://gelato.test/**',async route=>{
   const url=new URL(route.request().url()),name=url.pathname;
   if(name==='/')return route.fulfill({status:200,contentType:'text/html',
     body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>'});
   const body=route.request().postDataJSON?.()||{};
   calls.push({path:name,body,method:route.request().method()});
   let response={ok:true};
   if(name==='/api/agent-workspace.php'){
     if(url.searchParams.get('action')==='bootstrap')response={ok:true,csrf:'g7-csrf',activeThread:'own-thread',permissions:[],user:{id:7,name:'Test User',firstName:'Test'}};
     else if(body.action==='append')response={ok:true,message:{databaseId:96}};
     else if(body.action==='route')response={ok:true,route:'api/food-monster-orchestrate.php',domain:'multi_node_read',node:'brain',nodeMode:'read_orchestrator'};
     else if(body.action==='record_action')response={ok:true,id:'audit-1'};
   }else if(name==='/api/food-monster-orchestrate.php'){
     response={ok:true,skill:'agent.multi_node.read_only',answer:'Reviewed orders and inventory. No changes made.',data:{mode:'read_only',steps:[{domain:'kitchen'},{domain:'inventory'}]},sources:['Gelato Agent Brain']};
   }else return route.fulfill({status:404,contentType:'application/json',body:JSON.stringify({ok:false})});
   return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(response)});
 });
 await page.goto('https://gelato.test/');
 await page.addScriptTag({content:fs.readFileSync(path.resolve(__dirname,'../js/global-agent.js'),'utf8')});
 await page.waitForFunction(()=>window.GelatoGlobalAgent?.user?.id===7);
 ok(await page.locator('#gelato-agent-bar').count()===1,'Existing chat bar mounted only once');
 const result=await page.evaluate(()=>window.GelatoGlobalAgent.send('Check orders, inventory and employee schedule and suggest priorities'));
 ok(result?.skill==='agent.multi_node.read_only','Read-only tool result returned to chat');
 const call=calls.find(c=>c.path==='/api/food-monster-orchestrate.php');
 ok(!!call,'Multi-node route executes only existing server endpoint');
 ok(call.body.threadId==='own-thread','Canonical session thread accompanies request');
 ok(call.body.csrf_token==='g7-csrf','CSRF included in original tool call');
 ok(call.body.action==='ask','No write action from client');
 const rec=calls.find(c=>c.path==='/api/agent-workspace.php'&&c.body.action==='record_action');
 ok(rec?.body.skill==='agent.multi_node.read_only','Action recorded in existing Agent Brain history');
 ok(rec?.body.result?.data?.mode==='read_only','Evidence preserved in conversation record');
 ok(calls.every(c=>!c.path.includes('delete')&&!c.path.includes('approve')),'No implicit change endpoints');
 ok(await page.locator('#gelato-agent-bar').count()===1,'No duplicate composer');
 ok(errors.length===0,'No JavaScript exceptions: '+errors.join(';'));
 await page.close();
 console.log('FM-G7 browser conversation handoff PASS ('+checks+' assertions)');
}finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
