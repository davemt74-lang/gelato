'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let n=0;const check=(v,k)=>{assert.ok(v,k);n++};
 try{
  const page=await browser.newPage({viewport:{width:390,height:780}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body><div id="food-monster-root"><div class="fm-actions"></div></div><div id="gelato-agent-bar"><textarea id="gaInput"></textarea><button id="gaSend">Send</button></div></body></html>');
  await page.addStyleTag({content:fs.readFileSync(path.join(__dirname,'../css/food-monster.css'),'utf8')});
  await page.evaluate(()=>{
   window.GelatoGlobalAgent={user:{id:23}};window.GelatoFoodMonster={root:document.getElementById('food-monster-root')};
   window.__requests=[];window.__selections=[];
   window.addEventListener('gelato-food-monster-skill-selected',e=>window.__selections.push(e.detail));
   window.fetch=async(url,options)=>{
    window.__requests.push({url,options});
    return {ok:true,json:async()=>({ok:true,mode:'chat_review',skills:[
      {id:'kitchen',node:'kds',label:'Kitchen tickets',description:'Review tickets',prompt:'Review KDS tickets and tell me what needs attention.'},
      {id:'inventory',node:'operations',label:'Inventory & tasks',description:'Review inventory',prompt:'Review inventory shortages and overdue restaurant tasks.'}
    ]})};
   };
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../js/food-monster-skills.js'),'utf8')});
  check(await page.locator('.fm-skills-trigger').count()===1,'One launcher');
  await page.locator('.fm-skills-trigger').click();
  await page.waitForFunction(()=>document.querySelectorAll('.fm-skills-choice').length===2);
  check(await page.locator('#fm-skills-dialog').isVisible(),'Dialog visible');
  check(await page.locator('.fm-skills-choice').count()===2,'Server-scoped skills');
  check(await page.locator('.fm-skills-choice').first().getAttribute('data-route')===null,'No endpoint DOM injection');
  await page.locator('.fm-skills-choice').first().click();
  check((await page.locator('#gaInput').inputValue()).startsWith('Review KDS'),'Selection fills chat input');
  check(!await page.locator('#fm-skills-dialog').isVisible(),'Selection closes modal');
  check(await page.evaluate(()=>window.__requests.length===1),'No action on selection');
  check(await page.evaluate(()=>window.__selections.length===1),'UI selection event emitted');
  await page.evaluate(()=>window.confirm=()=>false);
  await page.locator('.fm-skills-trigger').click();
  await page.waitForFunction(()=>document.querySelectorAll('.fm-skills-choice').length===2);
  await page.locator('.fm-skills-choice').nth(1).click();
  check((await page.locator('#gaInput').inputValue()).startsWith('Review KDS'),'Unsent draft preserved on rejection');
  await page.evaluate(()=>window.confirm=()=>true);
  await page.locator('.fm-skills-choice').nth(1).click();
  check((await page.locator('#gaInput').inputValue()).startsWith('Review inventory'),'Draft replacement requires confirmation');
  await page.locator('.fm-skills-trigger').click();
  await page.waitForFunction(()=>document.querySelectorAll('.fm-skills-choice').length===2);
  await page.keyboard.press('Escape');
  check(!await page.locator('#fm-skills-dialog').isVisible(),'Escape dismisses picker');
  check(await page.evaluate(()=>window.__requests.every(x=>x.options.credentials==='same-origin')),'Same-origin request');
  check(await page.evaluate(()=>window.__requests.every(x=>!x.options.method)),'GET only');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=390),'Fits mobile viewport');
  check(errors.length===0,'No browser errors '+errors.join(';'));
  console.log('FM-G7B1 browser skill picker: PASS ('+n+' assertions)');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exitCode=1});
