/* FM-G1 isolated interaction suite against committed assets. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const base=path.resolve(__dirname,'..');
(async()=>{
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  let checks=0;
  const ok=(truth,why)=>{assert.ok(truth,why);checks++;};
  async function scenario(stage,width,height){
    const page=await browser.newPage({viewport:{width,height},reducedMotion:'reduce'});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="'+(stage?'food-monster-stage-page':'')+'"><main id="food-monster-stage"></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea><button id="gaSend">Send</button></div></body></html>');
    await page.addStyleTag({content:fs.readFileSync(path.join(base,'css/food-monster.css'),'utf8')});
    const svg=fs.readFileSync(path.join(base,'assets/food-monster/chef-mascot.svg'),'utf8');
    await page.evaluate(source=>{window.fetch=async()=>({ok:true,text:async()=>source});},svg);
    await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster.js'),'utf8')});
    await page.locator('[data-fm-pupil]').waitFor({timeout:5000});
    ok(await page.locator('#food-monster-root').count()===1,'must mount once');
    ok(await page.locator('#gelato-agent-bar').count()===1,'must not duplicate chat footer');
    ok(await page.locator('aside').count()===0,'no sidebar in standalone stage');
    const pupil=page.locator('[data-fm-pupil]');
    await page.mouse.move(Math.floor(width*.88),Math.floor(height*.17));
    await page.waitForTimeout(180);
    ok((await pupil.getAttribute('transform'))!=='translate(0 0)','pupil should follow pointer');
    const actor=page.locator('.fm-actor');
    const starting=await actor.boundingBox();
    await page.locator('[data-size="1"]').click();
    const enlarged=await actor.boundingBox();
    ok(enlarged.width>starting.width,'increase size');
    await page.locator('[data-size="-1"]').click();
    ok((await actor.boundingBox()).width<enlarged.width,'decrease size');
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-agent-response',{detail:{answer:'Hello from Gelato Brain.'}})));
    ok((await page.locator('.fm-speech').innerText()).includes('Hello from Gelato Brain.'),'responses appear in bubble');
    ok(await page.locator('#food-monster-root.fm-happy').count()===1,'typed response reacts without faking speech');
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-agent-listening-change',{detail:{listening:true,microphoneActive:true}})));
    ok(await page.locator('#food-monster-root.fm-listening').count()===1,'listening state');
    const box=await actor.boundingBox();
    await page.mouse.move(box.x+box.width*.55,box.y+box.height*.65);
    await page.mouse.down();await page.mouse.move(box.x+box.width*.42,box.y+box.height*.58,{steps:5});await page.mouse.up();
    const dragged=await actor.boundingBox();
    ok(Math.abs(dragged.x-box.x)>1||Math.abs(dragged.y-box.y)>1,'mascot draggable');
    await page.locator('.fm-minimize').click();
    ok(await page.locator('#food-monster-root.fm-minimized').count()===1,'can minimize');
    await page.locator('.fm-minimize').click();
    ok(await page.locator('#food-monster-root.fm-minimized').count()===0,'can restore');
    await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster.js'),'utf8')});
    ok(await page.locator('#food-monster-root').count()===1,'script cannot mount duplicate');
    ok((await page.evaluate(()=>document.documentElement.scrollWidth))<=width+1,'no horizontal overflow');
    ok(errors.length===0,'no uncaught JS errors: '+errors.join(';'));
    await page.close();
  }
  try{
    await scenario(true,1280,800);
    await scenario(true,390,844);
    await scenario(false,1280,800);
    console.log('FM-G1 browser interactions: PASS ('+checks+' assertions)');
  }finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
