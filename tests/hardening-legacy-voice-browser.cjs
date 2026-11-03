'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
  const browser=await chromium.launch({headless:true});let checks=0;
  try{
    for(const [script,button]of [['restaurant-agent-bridge.js','gelatoVoiceButton'],['operations-core.js','agentMic'],['timeclock-voice.js','talkButton']]){
      const page=await browser.newPage();
      await page.setContent('<body data-agent-canvas="1"><textarea id="gaInput"></textarea><div id="gelato-agent-status"></div><div id="ownerAgentMessages"></div><div><textarea id="ownerAgentInput"></textarea><button id="ownerAgentSend">Send</button></div><p id="ownerComposerGuidance"></p><textarea id="agentInput"></textarea><button id="agentSend">Send</button><button id="agentMic">Speak Operations</button><button id="talkButton">Speak Time Clock</button><div id="agentStatus"></div><div id="toast"></div></body>');
      await page.evaluate(()=>{
        window.__requests=[];window.__recognizers=[];window.__sequence=0;window.__events=0;
        const memory=new Map(),history=new Map();let thread=0;
        window.RESTAURANT_SERVER_SESSION=true;window.RESTAURANT_CSRF_TOKEN='fixture';
        window.OPERATIONS_CONFIG={csrf:'fixture'};window.GELATO_TIME_CLOCK={csrf:'fixture',canProactive:false};
        window.RestaurantAuth={has:()=>true,current:()=>({id:'db-user-7',displayName:'Fixture employee'}),read:(k,d)=>memory.get(k)||d,write:(k,v)=>memory.set(k,v)};
        window.SpeechRecognition=class{start(){window.__recognizers.push(this);}stop(){}abort(){this.onend?.();}};
        window.AudioContext=class{createMediaStreamSource(){return {connect(){}};}createAnalyser(){return {frequencyBinCount:48,getByteFrequencyData:a=>a.fill(96)};}async close(){}};
        Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})},configurable:true});
        window.fetch=async(url,options={})=>{
          const input=options.body?JSON.parse(options.body):{};window.__requests.push({url,input});let body={ok:true};
          if(url.includes('agent-workspace.php?action=bootstrap'))body={ok:true,csrf:'fixture',activeThread:'agent-'+'9'.repeat(20),user:{id:7},permissions:['*'],browserState:{organizationId:3,userId:'db-user-7',identity:'a'.repeat(32)}};
          else if(url.includes('voice-identity.php?action=profile'))body={ok:true,profile:{status:'active'}};
          else if(url.includes('voice-identity.php'))body={ok:true,match:{matched:true,sessionUserMatch:true,eventId:'voice-'+(++window.__events).toString(16).padStart(16,'0')}};
          else if(url.includes('proactive-agent.php'))body={ok:true,preferences:{listening_enabled:0,proactive_voice_enabled:0},events:[]};
          else if(input.action==='new_thread'){const id='agent-'+(++thread).toString(16).padStart(20,'0');history.set(id,[]);body={ok:true,thread:{public_id:id}};}
          else if(input.action==='append'){
            const id='msg-'+(++window.__sequence).toString(16).padStart(20,'0');
            const structured=input.structured?structuredClone(input.structured):null;
            if(structured?.proposal)Object.assign(structured.proposal,{hash:'b'.repeat(64),messageId:id});
            const message={id,public_id:id,role:input.role,content:input.content,channel:input.channel,structured,databaseId:window.__sequence};
            if(!history.has(input.threadId))history.set(input.threadId,[]);history.get(input.threadId).push(message);body={ok:true,message,participant:input.voiceEventId?{userId:7}:null};
          }else if(input.action==='authorize_private_voice')body={ok:true,privateTurnToken:'c'.repeat(48)};
          else if(url.includes('food-monster-llm.php')){
            const last=history.get(input.threadId).filter(m=>m.role==='user').at(-1);
            body=input.privateTurnToken?{ok:true,data:{delegation:{route:'api/timeclock-agent.php',threadId:input.threadId,sourceMessageId:last.id}}}:{ok:true,answer:'Private request kept public.',data:{}};
          }else if(url==='api/timeclock-agent.php')body={ok:true,answer:input.message==='Confirm'?'Confirmed.':'Proposed action: clock you in now.',data:input.message==='Confirm'?{}:{requiresConfirmation:true,proposal:{id:'gap-'+'d'.repeat(16),hash:'',messageId:'',node:'timeclock',threadId:input.threadId,sourceMessageId:input.sourceMessageId,expiresAt:new Date(Date.now()+300000).toISOString()}}};
          return {ok:true,status:200,json:async()=>body};
        };
      });
      for(const name of ['agent-confirmation-transport.js','global-agent.js',script])await page.addScriptTag({content:fs.readFileSync(path.join(root,'js',name),'utf8')});
      await page.waitForFunction(()=>window.GelatoGlobalAgent?.user?.id===7);
      assert.equal(await page.evaluate(()=>window.__recognizers.length),0);checks++;
      async function speak(text,index,approve=true){
        await page.locator('#'+button).click();await page.waitForFunction(n=>window.__recognizers.length===n,index);
        // Controlled audio frames exercise the shipped sampler; no device is opened.
        await page.waitForTimeout(440);
        await page.evaluate(text=>{const r=[{transcript:text,confidence:.99}];r.isFinal=true;window.__recognizers.at(-1).onresult({resultIndex:0,results:[r]});},text);
        const dialog=page.getByRole('dialog',{name:'Approve private Gelato voice request'});await dialog.waitFor();
        assert.equal(await dialog.locator('blockquote').innerText(),text);checks++;
        await dialog.getByRole('button',{name:approve?'Approve this request':'Keep public',exact:true}).click();
        await page.waitForFunction(()=>!window.GelatoGlobalAgent.busy);
      }
      await speak('clock me in',1);
      const first=await page.evaluate(()=>window.__requests.filter(r=>r.url==='api/timeclock-agent.php').at(-1).input);
      assert.equal(first.voice,true);assert.match(first.voiceEventId,/^voice-[a-f0-9]{16}$/);checks+=2;
      await speak('Confirm',2);
      const second=await page.evaluate(()=>window.__requests.filter(r=>r.url==='api/timeclock-agent.php').at(-1).input);
      assert.equal(second.voice,true);assert.notEqual(second.voiceEventId,first.voiceEventId);assert.equal(second.threadId,first.threadId);assert.equal(second.proposalId,'gap-'+'d'.repeat(16));checks+=4;
      assert.equal(await page.locator('#ownerAgentInput').inputValue(),'');assert.equal(await page.locator('#agentInput').inputValue(),'');checks+=2;
      await speak('clock me in',3,false);
      assert.equal(await page.evaluate(()=>window.__requests.filter(r=>r.url==='api/timeclock-agent.php').length),2,'Declining private access sends no protected Agent request.');checks++;
      if(script==='restaurant-agent-bridge.js'){
        const destination=process.env.PLAYWRIGHT_ARTIFACTS||process.env.QA_OUTPUT_DIR||'artifacts';fs.mkdirSync(destination,{recursive:true});
        await page.screenshot({path:path.join(destination,'hardening-legacy-voice-browser.png'),fullPage:true});
      }
      await page.close();
    }
    console.log(`Legacy microphone Chromium controls: PASS (${checks} assertions; three shipped adapters, actual onresult, visible per-turn consent, fresh events and preserved proposal conversation; controlled events only).`);
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
