'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const src=fs.readFileSync(path.join(__dirname,'../js/global-agent.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let checks=0;
 const ok=(yes,label)=>{assert.ok(yes,label);checks++};
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('about:blank');await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(()=>{
   window.__calls=[];window.__reply=[];
   window.fetch=async(url,options={})=>{
    const data=options.body?JSON.parse(options.body):{};window.__calls.push({url,data});let result={ok:true};
    if(url.includes('action=bootstrap'))result={ok:true,csrf:'token',activeThread:'t-1',permissions:[],user:{id:11,name:'Chef'}};
    else if(url.includes('action=preferences'))result={ok:true,preferences:{listening_enabled:0,proactive_voice_enabled:0}};
    else if(url.includes('agent-workspace.php')&&data.action==='route')result={ok:true,route:'api/food-monster-read.php',domain:'governed_verified_read',nodeMode:'read_only'};
    else if(url.includes('agent-workspace.php')&&data.action==='append')result={ok:true,message:{databaseId:101}};
    else if(url.includes('food-monster-read.php'))result={ok:true,skill:'session.current_user',answer:'You are signed into Gelato as Chef.',data:{sessionUserId:11},sources:['Gelato authenticated session']};
    else if(url.includes('proactive-agent.php'))result={ok:true,events:[]};
    return {ok:true,json:async()=>result};
   };
   window.addEventListener('gelato-agent-response',e=>window.__reply.push(e.detail));
  });
  await page.addScriptTag({content:src});await page.waitForFunction(()=>window.GelatoGlobalAgent?.user?.id===11);
  await page.evaluate(()=>window.GelatoGlobalAgent.send('Who is signed in?',false));
  const calls=await page.evaluate(()=>window.__calls),answer=await page.evaluate(()=>window.__reply[0]);
  ok(calls.some(x=>x.data.action==='route'),'Server router used');
  ok(calls.some(x=>x.url.includes('food-monster-read.php')&&x.data.threadId==='t-1'),'Read has authenticated thread');
  ok(calls.some(x=>x.data.action==='append'&&x.data.role==='user'),'User message persisted');
  ok(calls.some(x=>x.data.action==='append'&&x.data.role==='agent'&&x.data.skill==='session.current_user'),'Tool receipt persisted');
  ok(calls.some(x=>x.data.action==='record_action'&&x.data.route==='api/food-monster-read.php'),'Execution recorded');
  ok(answer.result.sources[0]==='Gelato authenticated session','Source preserved');
  ok(answer.answer.includes('signed into Gelato as Chef'),'Verified answer used');
  ok(errors.length===0,'No JS errors: '+errors.join(';'));
  console.log('FM-G7B2 authenticated read browser PASS ('+checks+' assertions)');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
