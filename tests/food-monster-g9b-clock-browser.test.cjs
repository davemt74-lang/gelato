'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright'),base=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body><button id="fm-shift-clock-launch">Employee clock</button></body></html>');
  await page.evaluate(()=>{
    window.calls=[];window.contextRefreshes=0;window.shiftState=false;
    window.GelatoGlobalAgent={user:{id:9},refreshContext:async()=>{window.contextRefreshes++;return {clockedIn:window.shiftState}}};
    window.fetch=async(url,opt={})=>{
      const action=opt.method==='POST'?JSON.parse(opt.body).action:'roster';
      const data=action==='roster'?{ok:true,csrf:'test-csrf',employees:[{personId:21,displayName:'Test Cook',employeeNumber:'21',clockedIn:window.shiftState,pinSet:true}]}:
        action==='set_pin'?{ok:true,message:'PIN set'}:
        {ok:true,message:'Test Cook '+(action==='clock_in'?'clocked in':'clocked out'),result:{personId:21,status:action==='clock_in'?'clocked_in':'clocked_out'}};
      if(opt.method==='POST'){window.calls.push(JSON.parse(opt.body));if(action==='clock_in')window.shiftState=true;else if(action==='clock_out')window.shiftState=false;}
      return {ok:true,status:200,json:async()=>data};
    };
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster-clock-popup.js'),'utf8')});
  let count=0;const check=(v,msg)=>{assert.ok(v,msg);count++};
  const generic=await page.evaluate(()=>{
    const event=new CustomEvent('gelato-agent-before-send',{cancelable:true,detail:{text:'What ingredients are in the pizza?'}});
    window.dispatchEvent(event);return event.defaultPrevented;
  });
  check(!generic,'ingredient question remains normal conversation');
  check(await page.locator('#fm-shift-clock').count()===0,'no persistent popup clutter');
  let wasIntercepted=await page.evaluate(()=>{
    const event=new CustomEvent('gelato-agent-before-send',{cancelable:true,detail:{text:'Monster, I want to log in for my shift'}});
    window.dispatchEvent(event);return event.defaultPrevented;
  });
  check(wasIntercepted,'natural employee request opens popup');
  await page.locator('select[name="personId"] option').first().waitFor({state:'attached'});
  check((await page.locator('#fm-clock-heading').textContent()).includes('Clock in'),'correct shift operation shown');
  check((await page.locator('#fm-shift-clock').textContent()).includes('never signs anyone'),'popup is clearly clock-only');
  await page.locator('input[name="pin"]').fill('907286');
  await page.locator('[data-fm-clock-submit]').click();
  await page.waitForFunction(()=>window.contextRefreshes===1);
  check(await page.evaluate(()=>window.calls[0].action==='clock_in'&&window.calls[0].personId===21),'clock in server request uses selected employee');
  check(await page.evaluate(()=>window.calls[0].csrf_token==='test-csrf'),'shift request includes CSRF');
  check(await page.evaluate(()=>window.shiftState===true),'shift becomes active');
  await page.waitForSelector('#fm-shift-clock',{state:'detached'});
  await page.evaluate(()=>{const e=new CustomEvent('gelato-agent-before-send',{cancelable:true,detail:{text:'Hey Gelato, clock out'}});window.dispatchEvent(e);});
  await page.locator('select[name="personId"] option').first().waitFor({state:'attached'});
  check((await page.locator('#fm-clock-heading').textContent()).includes('Clock out'),'clock-out voice prompt recognized');
  await page.locator('input[name="pin"]').fill('907286');
  await page.locator('[data-fm-clock-submit]').click();
  await page.waitForFunction(()=>window.contextRefreshes===2);
  check(await page.evaluate(()=>window.calls[1].action==='clock_out'&&!window.shiftState),'clock out recorded and context refreshed');
  await page.waitForSelector('#fm-shift-clock',{state:'detached'});
  await page.locator('#fm-shift-clock-launch').click();
  await page.locator('select[name="personId"] option').first().waitFor({state:'attached'});
  await page.locator('[data-fm-clock-setup]').click();
  check(await page.locator('[data-fm-clock-owner]').isVisible(),'PIN setup prompts for Super Admin password');
  await page.locator('input[name="pin"]').fill('201937');
  await page.locator('input[name="ownerPassword"]').fill('OwnerPass987');
  await page.locator('[data-fm-clock-submit]').click();
  await page.waitForFunction(()=>window.calls.length===3);
  check(await page.evaluate(()=>window.calls[2].action==='set_pin'&&window.calls[2].ownerPassword==='OwnerPass987'),'PIN reset requests owner verification');
  check(await page.evaluate(()=>window.GelatoEmployeeClockPopup.matchIntent('What is our menu?')===null),'no unintended agent interception');
  await page.keyboard.press('Escape');
  check(await page.locator('#fm-shift-clock').count()===0,'Escape dismisses popup');
  console.log('FM-G9B Food Monster employee PIN popup Chromium PASS ('+count+' assertions)');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
