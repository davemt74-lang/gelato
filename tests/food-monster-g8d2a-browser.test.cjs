const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let n=0;const ok=(v,m)=>{assert.ok(v,m);n++};
try{const page=await browser.newPage({viewport:{width:1000,height:800}}),errs=[];page.on('pageerror',e=>errs.push(e.message));
await page.setContent('<!doctype html><html><head><meta name="gelato-csrf" content="demo"></head><body class="food-monster-stage-page"><main id="food-monster-stage"></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea></div></body></html>');
await page.evaluate(()=>{
 window.GelatoGlobalAgent={user:{id:7},threadId:'agt-thread-1'};
 window.GelatoFoodMonsterGoals={hide:()=>{}};
 window.__log=[];window.__folders=[];
 window.fetch=async(url,opt={})=>{
  const body=opt.body?JSON.parse(opt.body):{},u=String(url);window.__log.push({url:u,body});
  if(u.includes('agent-workspace.php'))return {ok:true,json:async()=>({ok:true,messages:[{role:'user',content:'Private question'},{role:'assistant',content:'Private response'}]})};
  if(body.action==='link_thread')window.__folders.push({id:'fmf-1',threadId:body.threadId,title:body.title,createdAt:'2026-10-15 12:00'});
  if(body.action==='remove_thread')window.__folders=[];
  const target=new URL(u,'https://example.org').searchParams.get('userId');
  const own=target===null||target==='7';
  return {ok:true,json:async()=>body.action?({ok:true,folders:[...window.__folders]}):({ok:true,userId:target?Number(target):7,ownWorkspace:own,
   folders:own?[...window.__folders]:[],events:[{id:'e1',type:'goal_started',summary:'Participant setup started',source:'onboarding',at:'2026-10-15 12:00'}],
   goals:[{id:'g1',status:'in_progress',phase:'photos',updatedAt:'2026-10-15 12:01'}],summary:{pendingGoals:1}})};
 };
});
await page.addStyleTag({content:fs.readFileSync(path.join(__dirname,'../css/food-monster.css'),'utf8')});
await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../js/food-monster-participant-history.js'),'utf8')});
await page.waitForFunction(()=>!!window.GelatoFoodMonsterParticipantHistory);
ok(await page.locator('.fm-history-panel').isHidden(),'History not opened without user action');
await page.evaluate(()=>window.GelatoFoodMonsterParticipantHistory.open(7));
await page.waitForFunction(()=>document.querySelector('.fm-history-event')?.textContent.includes('Participant setup started'));
ok(await page.locator('.fm-history-event').count()===1,'Only timestamped events displayed');
ok((await page.locator('.fm-history-event').innerText()).includes('2026-10-15'),'Event timestamp displayed');
await page.locator('[data-fmh-tab="chat"]').click();
ok(await page.locator('[data-fmh-save]').isVisible(),'Private chat folder controls visible to owner');
await page.locator('[data-fmh-save]').click();
await page.waitForFunction(()=>document.querySelector('[data-fmh-open-thread]'));
ok(await page.evaluate(()=>window.__log.some(x=>x.body.action==='link_thread'&&x.body.threadId==='agt-thread-1')),'Existing owned thread is explicitly linked');
await page.locator('[data-fmh-open-thread]').click();
await page.waitForFunction(()=>document.querySelector('.fm-history-messages')?.textContent.includes('Private response'));
ok(await page.locator('.fm-history-messages').isVisible(),'Private thread loaded via existing Agent Chat');
await page.locator('[data-fmh-close]').click();
await page.evaluate(()=>window.GelatoFoodMonsterParticipantHistory.open(8));
await page.waitForFunction(()=>document.querySelector('.fm-history-panel')?.textContent.includes('Participant setup started'));
await page.locator('[data-fmh-tab="chat"]').click();
ok(await page.locator('[data-fmh-save]').count()===0,'Cross-user private folder never shown');
ok((await page.locator('.fm-history-content').innerText()).includes('only to the signed-in'),'Operational-only cross-account view');
ok(!errs.length,'No browser script errors '+errs.join(';'));
console.log('FM-G8D2A participant timeline and owned chat folder browser PASS ('+n+' checks)');
}finally{await browser.close()}})().catch(e=>{console.error(e.stack||e);process.exit(1)});
