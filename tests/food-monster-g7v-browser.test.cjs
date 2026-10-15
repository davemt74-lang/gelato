'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base=path.resolve(__dirname,'..');
(async()=>{
 const binary=process.env.CHROMIUM_PATH||(fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined);
 const browser=await chromium.launch({headless:true,executablePath:binary,args:['--no-sandbox']});
 let checks=0;const ok=(yes,why)=>{assert.ok(yes,why);checks++};
 try{
  const page=await browser.newPage({viewport:{width:920,height:760},reducedMotion:'reduce'});
  const errors=[];page.on('pageerror',err=>errors.push(err.message));
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="food-monster-stage-page"><main id="food-monster-stage"></main></body></html>');
  await page.evaluate(()=>{
   window.__recognizers=[];window.__requests=[];
   window.SpeechRecognition=class{
    constructor(){window.__recognizers.push(this)}
    start(){this.onstart?.()}
    stop(){this.onend?.()}
    abort(){}
    say(text,final=false){this.onresult?.({resultIndex:0,results:[{isFinal:final,0:{transcript:text,confidence:.99}}]})}
   };
   window.AudioContext=class{
    createMediaStreamSource(){return {connect(){}}}
    createAnalyser(){return {frequencyBinCount:48,getByteFrequencyData(b){b.fill(95)}}}
    close(){return Promise.resolve()}
   };
   Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
   window.fetch=async(url,opt={})=>{
    const request=opt.body?JSON.parse(opt.body):{};window.__requests.push({url,request});
    let data={ok:true};
    if(url.includes('agent-workspace.php?action=bootstrap'))data={ok:true,csrf:'test',activeThread:'thread',permissions:['voice.agent','agent.proactive'],user:{id:4,name:'Tester'}};
    else if(url.includes('proactive-agent.php?action=preferences'))data={ok:true,preferences:{listening_enabled:0,proactive_voice_enabled:0,wake_phrase:'Hey Gelato'}};
    else if(url.includes('proactive-agent.php')&&request.action==='preferences')data={ok:true,preferences:{listening_enabled:request.listeningEnabled?1:0,proactive_voice_enabled:0}};
    else if(url.includes('voice-identity.php'))data={ok:true,match:{matched:true,sessionUserMatch:true,userId:4,displayName:'Tester',eventId:'verified'}};
    else if(url.includes('proactive-agent.php'))data={ok:true,events:[]};
    else if(request.action==='append')data={ok:true,message:{databaseId:4}};
    return {ok:true,json:async()=>data};
   };
  });
  await page.addStyleTag({content:fs.readFileSync(path.join(base,'css/food-monster.css'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/global-agent.js'),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster.js'),'utf8')});
  await page.locator('#gaInput').waitFor();
  await page.waitForFunction(()=>window.GelatoGlobalAgent?.user?.id===4);
  ok(await page.locator('#gelato-agent-bar').count()===1,'One visible composer');
  ok(await page.locator('#food-monster-root').count()===1,'Food Monster mounted');
  ok(!await page.evaluate(()=>window.GelatoGlobalAgent.listening),'No automatic microphone');
  ok(await page.evaluate(()=>window.GelatoGlobalAgent.setListening(true)),'Explicit opt-in');
  await page.evaluate(()=>window.__recognizers.at(-1).say('What is',false));
  ok(await page.locator('#gaInput').inputValue()==='What is','Interim transcript visible');
  await page.evaluate(()=>window.__recognizers.at(-1).say('What is the inventory',true));
  ok(await page.locator('#gaInput').inputValue()==='What is the inventory','Final transcript visible');
  ok(await page.evaluate(()=>!window.__requests.some(x=>x.request.action==='append')),'No silent tool calls');
  await page.locator('#gaInput').fill('My own message');
  await page.evaluate(()=>window.__recognizers.at(-1).say('Should not replace',true));
  ok(await page.locator('#gaInput').inputValue()==='My own message','Manual edit priority');
  await page.locator('#gaInput').fill('');
  await page.evaluate(()=>window.__recognizers.at(-1).say('Hey Gelato stop listening',true));
  await page.waitForFunction(()=>!window.GelatoGlobalAgent.listening);
  ok(await page.evaluate(()=>window.GelatoGlobalAgent.listeningStatus.wakeStandby),'Wake standby retained');
  await page.evaluate(()=>window.__recognizers.at(-1).say('TV speech',true));
  ok(await page.locator('#gaInput').inputValue()==='','TV speech not rendered in standby');
  ok(!errors.length,'No browser JavaScript errors: '+errors.join('; '));
  console.log('FM-G7V browser chatbar dictation: PASS ('+checks+' assertions)');
 }finally{await browser.close()}
})().catch(error=>{console.error(error.stack||error);process.exitCode=1});
