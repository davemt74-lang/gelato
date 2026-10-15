const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const source=fs.readFileSync(path.resolve(__dirname,'../js/food-monster-suggestions.js'),'utf8');
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
let checks=0;const ok=(yes,why)=>{assert.ok(yes,why);checks++};
async function setup(stage=false){
 const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion:'reduce'});
 const errors=[];page.on('pageerror',err=>errors.push(err.message));
 await page.route('https://gelato.test/',r=>r.fulfill({status:200,contentType:'text/html',body:'<!doctype html><html><body><div id="food-monster-root"><div class="fm-actor"><div class="fm-actions"></div></div></div><div id="gelato-agent-bar"><textarea></textarea></div></body></html>'}));
 await page.goto('https://gelato.test/');
 await page.evaluate((stage)=>{
  window.__reviews=[];window.__dismissed=[];
  window.GelatoGlobalAgent={user:{id:23},send:async(text,voice)=>window.__reviews.push({text,voice})};
  window.GelatoFoodMonster={root:document.getElementById('food-monster-root'),isStage:stage};
  window.GelatoAgentNextMoves={moves:[],dismiss:key=>{window.__dismissed.push(key);return true}};
  window.fetch=async url=>({ok:false,status:403,json:async()=>({ok:false})});
 },stage);
 await page.addScriptTag({content:source});
 await page.locator('.fm-suggestion-trigger').waitFor({state:'attached'});
 return {page,errors};
}
try{
 const {page,errors}=await setup(false);
 ok(await page.locator('.fm-suggestion-trigger').isVisible()===false,'no badge when no authorized signal');
 await page.evaluate(()=>window.GelatoFoodMonsterSuggestions.accept([{key:'norm1',severity:'normal',title:'Improve prep handoff',summary:'Reduce wait',prompt:'Explain prep'}]));
 ok(await page.locator('.fm-suggestion-trigger').isVisible(),'normal suggestion gets quiet badge');
 ok(await page.locator('.fm-suggestion-panel').isVisible()===false,'normal suggestion never auto-interrupts');
 ok(await page.evaluate(()=>window.__reviews.length)===0,'no unapproved tool execution');
 await page.locator('.fm-suggestion-trigger').click();
 ok(await page.locator('.fm-suggestion-panel').isVisible(),'badge opens review popover');
 ok((await page.locator('.fm-suggestion-title').innerText()).includes('Improve prep'),'visible recommendation title');
 await page.locator('[data-suggestion-action="review"]').click();
 await page.waitForFunction(()=>window.__reviews.length===1);
 const review=await page.evaluate(()=>window.__reviews[0]);
 ok(review.text.includes('Do not execute consequential changes unless I explicitly confirm.'),'review sends safety qualification');
 ok(review.voice===false,'no automatic voice');
 ok(await page.locator('.fm-suggestion-panel').isVisible()===false,'review closes panel');
 await page.evaluate(()=>window.GelatoFoodMonsterSuggestions.accept([
  {key:'norm2',severity:'low',title:'General tip'},
  {key:'crit1',severity:'critical',title:'Urgent stock',summary:'Check depleted item',nodeLabel:'Purchasing',prompt:'Review safe purchasing action'},
  {key:'crit1',severity:'critical',title:'Duplicate should be removed'},
  {key:'hi1',severity:'high',title:'Late delivery'}]));
 ok((await page.locator('.fm-suggestion-title').innerText()).includes('Urgent stock'),'critical signal prompts visually');
 ok(await page.locator('#food-monster-root.fm-suggestion-alert').count()===1,'urgent visual expression');
 ok(await page.evaluate(()=>window.__reviews.length)===1,'no review until user clicks');
 ok(await page.evaluate(()=>window.GelatoFoodMonsterSuggestions.moves.length)===3,'duplicate recommendations removed');
 await page.locator('[data-suggestion-action="dismiss"]').click();
 ok((await page.evaluate(()=>window.__dismissed)).includes('crit1'),'dismiss routes to shared Next Moves state');
 ok((await page.evaluate(()=>sessionStorage.getItem('gelato.fm.suggestions.dismissed.v1.23'))).includes('crit1'),'dismissal stored per user');
 await page.evaluate(()=>window.GelatoFoodMonsterSuggestions.accept([{key:'crit1',severity:'critical',title:'Dismissed item should stay hidden'}]));
 ok(await page.locator('.fm-suggestion-trigger').isVisible()===false,'dismissed suggestion stays hidden');
 await page.evaluate(()=>window.GelatoFoodMonsterSuggestions.accept([{key:'xss',severity:'high',title:'<img src=x onerror=alert(1)>',summary:'<script>unsafe()</script>'}]));
 ok((await page.locator('.fm-suggestion-title').innerText()).includes('<img'),'external title inserted as inert text');
 ok(await page.locator('.fm-suggestion-panel img').count()===0,'external content cannot inject DOM');
 ok(errors.length===0,'no page errors '+errors.join(';'));
 await page.close();
 const {page:limited,errors:e2}=await setup(true);
 await limited.waitForFunction(()=>Boolean(window.GelatoFoodMonsterSuggestions));
 await limited.waitForTimeout(160);
 ok(await limited.locator('.fm-suggestion-trigger').isVisible()===false,'unauthorized Brain data never displayed');
 ok(await limited.evaluate(()=>window.__reviews.length)===0,'no server action on denied manager snapshot');
 ok(e2.length===0,'stage denied access handled silently');
 await limited.close();
 console.log('FM-G5 governed Next Moves UI PASS ('+checks+' checks)');
}finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
