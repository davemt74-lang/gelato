'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const baseline=process.argv.indexOf('--baseline');
const root=baseline>=0?path.resolve(process.argv[baseline+1]):path.resolve(__dirname,'..');
const settle=async()=>{for(let i=0;i<140;i++)await Promise.resolve();};
function fixture(adapter){
  const records=[],recognizers=[],events=[],nodes=new Map(),storage=new Map(),callbacks=new Map();
  let sequence=0,eventSequence=0,threadSequence=0,matched=true,streamsStopped=0;
  const histories=new Map(),proposals=new Map();
  class Element{
    constructor(tag='div'){this.tagName=tag;this.children=[];this.dataset={};this.style={};this.value='';this.textContent='';this.listeners={};this.disabled=false;this.classList={add(){},remove(){},contains(){return false;}};}
    append(...children){for(const c of children){c.parentNode=this;this.children.push(c);if(c.id)nodes.set(c.id,c);}}
    appendChild(c){this.append(c);return c;}
    insertBefore(c){this.append(c);}
    setAttribute(k,v){this[k]=v;}
    addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);}
    dispatchEvent(e){for(const fn of this.listeners[e.type]||[])fn(e);return true;}
    click(){return this.dispatchEvent({type:'click',target:this,preventDefault(){}});}
    remove(){}
    querySelector(){return null;}
    querySelectorAll(){return [];}
    showModal(){this.open=true;const find=n=>n.textContent==='Approve this request'?n:n.children.map(find).find(Boolean);queueMicrotask(()=>find(this).click());}
    close(){this.open=false;}
  }
  for(const id of ['ownerAgentInput','ownerAgentSend','ownerComposerGuidance','agentInput','agentSend','agentMic','agentStatus','talkButton','listeningToggle','wakeStandbyToggle','microphoneOff','clockIn','toast','gaInput'])nodes.set(id,new Element());
  for(const [id,node]of nodes){node.id=id;node.parentNode=new Element();}
  const body=new Element('body');body.dataset.agentCanvas='1';
  const document={readyState:'complete',hidden:false,body,head:new Element('head'),
    createElement:t=>new Element(t),getElementById:id=>nodes.get(id)||null,
    querySelector:s=>s.startsWith('#')?nodes.get(s.slice(1))||null:null,querySelectorAll:()=>[],
    addEventListener:(name,fn)=>{(callbacks.get(name)||callbacks.set(name,[]).get(name)).push(fn);}};
  class Recognition{start(){recognizers.push(this);this.onstart?.();}stop(){this.stopped=true;}abort(){this.stopped=true;this.onend?.();}}
  class Audio{createMediaStreamSource(){return {connect(){}};}createAnalyser(){return {frequencyBinCount:48,getByteFrequencyData:a=>a.fill(96)};}async close(){}}
  const win={RESTAURANT_SERVER_SESSION:true,RESTAURANT_CSRF_TOKEN:'csrf',OPERATIONS_CONFIG:{csrf:'csrf'},GELATO_TIME_CLOCK:{csrf:'csrf',canProactive:true},
    RestaurantAuth:{has:()=>true,current:()=>({id:'db-user-7',displayName:'Fixture employee'}),read:(k,d)=>storage.get(k)||d,write:(k,v)=>storage.set(k,v)},
    SpeechRecognition:Recognition,AudioContext:Audio,addEventListener:(name,fn)=>{(callbacks.get(name)||callbacks.set(name,[]).get(name)).push(fn);},
    dispatchEvent:e=>{events.push(e);for(const fn of callbacks.get(e.type)||[])fn(e);return !e.defaultPrevented;}};
  const messageId=()=> 'msg-'+(++sequence).toString(16).padStart(20,'0');
  const fetch=async(url,opt={})=>{
    const input=opt.body?JSON.parse(opt.body):{};records.push({url,input});let data={ok:true};
    if(url.includes('agent-workspace.php?action=bootstrap'))data={ok:true,csrf:'csrf',activeThread:'agent-'+'9'.repeat(20),user:{id:7},permissions:['*'],browserState:{organizationId:3,userId:'db-user-7',identity:'a'.repeat(32)}};
    else if(url.includes('proactive-agent.php'))data={ok:true,preferences:{listening_enabled:0,proactive_voice_enabled:0},events:[]};
    else if(url.includes('voice-identity.php?action=profile'))data={ok:true,profile:{status:'active'}};
    else if(url.includes('voice-identity.php'))data={ok:true,match:{matched,sessionUserMatch:matched,eventId:'voice-'+(++eventSequence).toString(16).padStart(16,'0')}};
    else if(input.action==='new_thread'){const id='agent-'+(++threadSequence).toString(16).padStart(20,'0');histories.set(id,[]);data={ok:true,thread:{public_id:id}};}
    else if(input.action==='append'){
      const id=messageId(),structured=input.structured?JSON.parse(JSON.stringify(input.structured)):null;
      if(structured?.proposal){structured.proposal={...structured.proposal,messageId:id,hash:'b'.repeat(64)};proposals.set(input.threadId,structured.proposal);}
      const message={id,public_id:id,role:input.role,content:input.content,channel:input.channel,structured,databaseId:sequence};
      if(!histories.has(input.threadId))histories.set(input.threadId,[]);histories.get(input.threadId).push(message);
      data={ok:true,message,participant:input.voiceEventId?{userId:7}:null};
    }else if(input.action==='authorize_private_voice')data={ok:true,privateTurnToken:'c'.repeat(48)};
    else if(url.includes('food-monster-llm.php')){
      const latest=histories.get(input.threadId).filter(m=>m.role==='user').at(-1);
      data={ok:true,data:{delegation:{route:'api/timeclock-agent.php',threadId:input.threadId,sourceMessageId:latest.id}}};
    }else if(/api\/(?:timeclock|operations)-agent.php/.test(url)){
      const decision=/^(Confirm|Cancel)$/i.test(input.message);
      data={ok:true,answer:decision?'Confirmed.':'Proposed action: clock you in now.',skill:'timeclock.action_proposal',
        data:decision?{}:{requiresConfirmation:true,proposal:{id:'gap-'+'d'.repeat(16),hash:'',node:'timeclock',threadId:input.threadId,sourceMessageId:input.sourceMessageId,messageId:'',expiresAt:new Date(Date.now()+300000).toISOString()}}};
    }
    return {ok:true,status:200,json:async()=>data};
  };
  const sessionStorage={get length(){return storage.size;},key:i=>[...storage.keys()][i],getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
  const context={window:win,document,console,fetch,sessionStorage,localStorage:sessionStorage,Date,Math,Intl,Array,Set,Map,Uint8Array,Float64Array,
    navigator:{mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop(){streamsStopped++;}}]})}},HTMLDialogElement:Element,
    Event:class{constructor(type,opts={}){this.type=type;Object.assign(this,opts);}},CustomEvent:class{constructor(type,opts={}){this.type=type;Object.assign(this,opts);}preventDefault(){this.defaultPrevented=true;}},
    setInterval:fn=>{for(let i=0;i<5;i++)fn();return 1;},clearInterval(){},setTimeout:()=>1,clearTimeout(){}};
  for(const name of ['agent-confirmation-transport.js','global-agent.js',adapter])vm.runInNewContext(fs.readFileSync(path.join(root,'js',name),'utf8'),context,{filename:name});
  return {win,nodes,records,recognizers,events,document,setMatched:value=>{matched=value;},get streamsStopped(){return streamsStopped;}};
}
async function say(f,button,text){
  const previous=f.recognizers.length;f.nodes.get(button).click();await settle();
  assert.equal(f.recognizers.length,previous+1,'The actual legacy control starts one canonical recognizer.');
  const result=[{transcript:text,confidence:.99}];result.isFinal=true;
  f.recognizers.at(-1).onresult({resultIndex:0,results:[result]});await settle();
}
async function loadedScripts(filename,flags={}){
  const requested=[];
  const document={readyState:'complete',scripts:[],getElementById:()=>null,
    querySelector:()=>null,querySelectorAll:()=>[],addEventListener(){},
    createElement:()=>({dataset:{}}),head:{appendChild(script){
      requested.push(script.src);document.scripts.push(script);script.onload?.();
    }}};
  const context={window:{RestaurantAuth:{has:()=>false},...flags},document,console,
    MutationObserver:class{observe(){}disconnect(){}},setTimeout:()=>1,clearTimeout(){}};
  vm.runInNewContext(fs.readFileSync(path.join(root,'js',filename),'utf8'),context,{filename});
  await settle();return requested;
}
(async()=>{
  let checks=0;
  for(const [adapter,button]of [['restaurant-agent-bridge.js','gelatoVoiceButton'],['operations-core.js','agentMic'],['timeclock-voice.js','talkButton']]){
    const f=fixture(adapter);await settle();
    assert.equal(f.recognizers.length,0,'Page load never starts a microphone.');checks++;
    await say(f,button,'clock me in');
    const request=f.records.filter(r=>/api\/(?:timeclock|operations)-agent.php/.test(r.url)).at(-1);
    assert.ok(request,'Actual onresult reaches the destination Agent.');checks++;
    assert.equal(request.input.voice,true,'FE-08: microphone onresult must preserve voice:true, never downgrade speech to a typed command.');checks++;
    assert.match(request.input.voiceEventId,/^voice-[a-f0-9]{16}$/,'Microphone uses a fresh event.');checks++;
    const first=request.input;
    await say(f,button,'Confirm');
    const confirm=f.records.filter(r=>r.url==='api/timeclock-agent.php').at(-1).input;
    assert.equal(confirm.voice,true);assert.notEqual(confirm.voiceEventId,first.voiceEventId);checks+=2;
    assert.equal(confirm.threadId,first.threadId,'Confirmation stays in the same adapter conversation.');checks++;
    assert.equal(confirm.proposalId,'gap-'+'d'.repeat(16),'Spoken confirmation forwards the displayed proposal.');checks++;
    assert.equal(f.records.filter(r=>r.input.action==='authorize_private_voice').length,2,'Both protected spoken turns require their own operator approval.');checks++;
    assert.equal(f.nodes.get(adapter==='operations-core.js'?'agentInput':'ownerAgentInput').value,'','Microphone does not enter a typed-submit field.');checks++;
    assert.equal(f.streamsStopped,2,'Both one-shot sampling streams are released.');checks++;
    f.setMatched(false);await say(f,button,'clock me in');
    const unknown=f.records.filter(r=>r.url==='api/timeclock-agent.php').at(-1).input;
    assert.equal(unknown.voice,true);assert.equal(unknown.voiceEventId,'','Unknown speech cannot inherit the prior verified speaker.');checks+=2;
  }
  const f=fixture('restaurant-agent-bridge.js');await settle();
  await f.win.GelatoRestaurantAgent.submit('clock me in');await settle();
  const typed=f.records.filter(r=>r.url==='api/timeclock-agent.php').at(-1).input;
  assert.equal(typed.voice,false,'Typed requests retain their original authorization path.');checks++;
  await say(f,'gelatoVoiceButton','Confirm');
  const decision=f.records.filter(r=>r.url==='api/timeclock-agent.php').at(-1).input;
  assert.equal(decision.threadId,typed.threadId,'A voice decision retains a typed proposal’s original conversation.');checks++;
  assert.equal(decision.proposalId,'gap-'+'d'.repeat(16));checks++;
  const current='agent-'+'8'.repeat(20);f.win.GelatoGlobalAgent.selectThread(current);
  f.win.GelatoAgentReview.remember(current,{id:'msg-'+'8'.repeat(20),structured:{proposal:{id:'gap-'+'8'.repeat(16),hash:'8'.repeat(64),threadId:current,messageId:'msg-'+'8'.repeat(20),node:'operations',expiresAt:new Date(Date.now()+60000).toISOString()}}});
  const before=f.recognizers.length;f.nodes.get('gelatoVoiceButton').click();await settle();
  assert.equal(f.recognizers.length,before,'A different pending conversation is never silently retargeted.');checks++;
  assert.match(f.nodes.get('ownerComposerGuidance').textContent,/pending action/);checks++;
  const changed=fixture('restaurant-agent-bridge.js');await settle();
  changed.nodes.get('gelatoVoiceButton').click();await settle();
  changed.win.GelatoGlobalAgent.selectThread('agent-'+'7'.repeat(20));
  const phrase=[{transcript:'clock me in',confidence:.99}];phrase.isFinal=true;
  changed.recognizers.at(-1).onresult({resultIndex:0,results:[phrase]});await settle();
  assert.equal(changed.records.filter(r=>r.url==='api/timeclock-agent.php').length,0,'A conversation change during capture cannot send into a different thread.');checks++;
  assert.equal(changed.streamsStopped,1);checks++;
  const hidden=fixture('restaurant-agent-bridge.js');await settle();
  hidden.nodes.get('gelatoVoiceButton').click();await settle();hidden.document.hidden=true;
  hidden.recognizers.at(-1).onresult({resultIndex:0,results:[phrase]});await settle();
  assert.equal(hidden.records.filter(r=>r.url==='api/timeclock-agent.php').length,0,'Hidden-tab late callbacks cannot execute a command.');checks++;
  assert.equal(hidden.streamsStopped,1);checks++;
  // Execute each transitive loader: a cached D bridge can still label microphone
  // speech typed, and a cached global runtime lacks the new turn protections.
  for(const [loader,flags]of [['floor-planner-module.js',{}],['pos.js',{}],
    ['table-cleaning-controls.js',{GELATO_TABLE_SERVICE:{}}],['table-cleaning-controls.js',{HOST_CONFIG:{}}]]){
    const scripts=await loadedScripts(loader,flags);
    assert.ok(scripts.includes('js/global-agent.js?v=20261103-e1'),`${loader} must request the secured global runtime with a new cache URL.`);checks++;
    if(loader==='floor-planner-module.js'){
      assert.ok(scripts.includes('js/restaurant-agent-bridge.js?v=20261103-e1'),
        'Floor Planner must not reuse the cached voice:false restaurant bridge.');checks++;
    }
  }
  for(const [page,asset]of [['pos.php','pos.js'],['table-service.php','table-cleaning-controls.js'],
    ['host-stand.php','table-cleaning-controls.js'],['workspace.php','floor-planner-module.js']]){
    assert.ok(fs.readFileSync(path.join(root,page),'utf8').includes(`js/${asset}?v=20261103-e1`),
      `${page} must refresh its changed Agent loader before that loader can request the secured scripts.`);checks++;
  }
  console.log(`Legacy voice shipped-script callbacks: PASS (${checks} assertions; canonical channel, fresh command/Confirm receipts, operator consent, unknown speaker, typed compatibility, conversation binding).`);
})().catch(error=>{console.error(error);process.exitCode=1;});
