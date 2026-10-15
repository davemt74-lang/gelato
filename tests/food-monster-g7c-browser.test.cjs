'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright'),base=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let count=0;const ok=(value,name)=>{assert.ok(value,name);count++};
 try{
  const page=await browser.newPage({viewport:{width:1000,height:800},reducedMotion:'reduce'});
  const errors=[];page.on('pageerror',err=>errors.push(err.message));
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(()=>{
    window.__recognizers=[];window.__requests=[];window.__answers=[];
    window.__tts={speaking:false,interruptions:0};
    window.SpeechRecognition=class {
      constructor(){window.__recognizers.push(this)}
      start(){this.onstart?.()}stop(){this.onend?.()}abort(){}
      say(text,final=true){this.onresult?.({resultIndex:0,results:[{isFinal:final,0:{transcript:text,confidence:.99}}]})}
    };
    window.AudioContext=class{
      createMediaStreamSource(){return {connect(){}}}
      createAnalyser(){return {frequencyBinCount:48,getByteFrequencyData(buffer){buffer.fill(90)}}}
      close(){return Promise.resolve()}
    };
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
    window.GelatoVoiceTurns={
      get speaking(){return window.__tts.speaking},
      evaluate(words){
        if(!window.__tts.speaking)return {kind:'command'};
        return words.includes('echo')?{kind:'ignored',reason:'tts-echo'}:{kind:'ignored',reason:'wake-required-while-speaking'};
      },
      speak(text){window.__tts.speaking=true;window.__tts.text=text;return true},
      interrupt(){const active=window.__tts.speaking;window.__tts.speaking=false;
        if(active)window.__tts.interruptions++;return active;}
    };
    window.fetch=async(url,options={})=>{
      const request=options.body?JSON.parse(options.body):{};
      window.__requests.push({url,request});let data={ok:true};
      if(url.includes('agent-workspace.php?action=bootstrap'))
        data={ok:true,csrf:'token',activeThread:'thread',user:{id:9,name:'Cook'},permissions:['voice.agent','agent.proactive','menu.view']};
      else if(url.includes('proactive-agent.php?action=preferences'))
        data={ok:true,preferences:{listening_enabled:0,proactive_voice_enabled:0}};
      else if(url.includes('proactive-agent.php')&&request.action==='preferences')
        data={ok:true,preferences:{listening_enabled:request.listeningEnabled?1:0,proactive_voice_enabled:0}};
      else if(url.includes('voice-identity.php'))
        data={ok:true,match:{matched:true,sessionUserMatch:true,userId:9,eventId:'verified'}};
      else if(url.includes('agent-workspace.php')&&request.action==='route')
        data={ok:true,route:null,domain:'general'};
      else if(url.includes('agent-workspace.php')&&request.action==='append')
        data={ok:true,message:{databaseId:12}};
      else if(url.includes('proactive-agent.php'))data={ok:true,events:[]};
      return {ok:true,json:async()=>data};
    };
  });
  await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/global-agent.js'),'utf8')});
  await page.waitForFunction(()=>window.GelatoGlobalAgent?.user?.id===9);
  await page.evaluate(()=>{window.GelatoFoodMonsterLLM={
    ask:async({text})=>{window.__answers.push(text);return {ok:true,skill:'agent.llm.conversation',answer:'Claude: '+text};}
  }});
  ok(await page.locator('#gaConversation').count()===1,'Conversation control present');
  ok(!await page.evaluate(()=>window.GelatoGlobalAgent.conversationMode),'Conversation defaults off');
  ok(await page.evaluate(()=>window.GelatoGlobalAgent.setConversationMode(true)),'Opt-in starts listening');
  await page.evaluate(()=>window.__recognizers.at(-1).say('Tell me about space',false));
  ok(await page.locator('#gaInput').inputValue()==='Tell me about space','Live interim transcription');
  await page.evaluate(()=>window.__recognizers.at(-1).say('Tell me about space',true));
  await page.waitForFunction(()=>window.__answers.length===1,null,{timeout:7000});
  ok(await page.evaluate(()=>window.__answers[0]==='Tell me about space'),'Silence submits to Claude');
  await page.waitForFunction(()=>window.__tts.speaking);
  await page.evaluate(()=>window.__recognizers.at(-1).say('speech echo',true));
  ok(await page.locator('#gaInput').inputValue()==='','Echo is suppressed');
  await page.evaluate(()=>window.__recognizers.at(-1).say('Tell me more',true));
  ok(await page.evaluate(()=>window.__tts.interruptions)===1,'Speech interrupts Agent reply');
  await page.waitForFunction(()=>window.__answers.length===2,null,{timeout:7000});
  ok(await page.evaluate(()=>window.__answers[1]==='Tell me more'),'Interrupted voice creates next turn');
  await page.evaluate(()=>window.GelatoGlobalAgent.setConversationMode(false));
  ok(!await page.evaluate(()=>window.GelatoGlobalAgent.conversationMode),'Mode can stop');
  // Listening remains active when Conversation Mode is off. It must still auto-send.
  await page.evaluate(()=>window.__recognizers.at(-1).say('Listening sends automatically',true));
  await page.waitForFunction(()=>window.__answers.length===3,null,{timeout:7000});
  ok(await page.evaluate(()=>window.__answers[2]==='Listening sends automatically'),'Listening Mode also auto-sends after speech stops');
  ok(await page.locator('#gaInput').inputValue()==='','Voice transcript cleared after automatic submission');
  // Typed messages are never auto-sent or overwritten by recognized speech.
  await page.locator('#gaInput').fill('My typed message');
  await page.evaluate(()=>window.__recognizers.at(-1).say('Do not replace typed message',true));
  await page.waitForTimeout(1400);
  ok(await page.locator('#gaInput').inputValue()==='My typed message','Manual typing wins over recognized words');
  ok(await page.evaluate(()=>window.__answers.length===3),'Typed text does not auto-submit');
  await page.locator('#gaInput').fill('');
  // Microphone Off cancels the pending recognized phrase.
  await page.evaluate(()=>window.__recognizers.at(-1).say('Cancel pending turn',true));
  await page.evaluate(()=>window.GelatoGlobalAgent.setListening(false,{keepWake:false}));
  await page.waitForTimeout(1400);
  ok(await page.evaluate(()=>window.__answers.length===3),'Stopping Listening cancels pending auto-send');
  ok(errors.length===0,'No page errors: '+errors.join(';'));
  console.log('FM-G7C hands-free conversation Chromium: PASS ('+count+' assertions)');
 }finally{await browser.close()}
})().catch(error=>{console.error(error.stack||error);process.exit(1)});
