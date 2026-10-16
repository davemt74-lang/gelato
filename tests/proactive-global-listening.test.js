'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const parse=fs.readFileSync('js/proactive-listening-commands.js','utf8');
const global=fs.readFileSync('js/global-agent.js','utf8');
let preferences={listening_enabled:1,proactive_voice_enabled:0,wake_phrase:'Hey Gelato'};
const records=[],micStarts=[],listeners={};let captureCalls=0;
class Recognition{
  start(){micStarts.push(this);this.onstart?.();}
  abort(){this.aborted=true;}
  stop(){this.aborted=true;}
  phrase(text){this.onresult?.({resultIndex:0,results:[{isFinal:true,0:{transcript:text,confidence:.99}}]});}
}
class Context{
  createMediaStreamSource(){return {connect(){}};}
  createAnalyser(){return {frequencyBinCount:48,getByteFrequencyData(bin){bin.fill(90);}};}
  close(){return Promise.resolve();}
}
function build(){
  const events=[];
  const document={readyState:'complete',hidden:false,body:{dataset:{agentCanvas:'1'}},
    addEventListener(name,fn){listeners[name]=fn;},
    getElementById(){return null;},
    querySelector(){return null;}};
  const win={SpeechRecognition:Recognition,AudioContext:Context,
    dispatchEvent(e){events.push({type:e.type,...e.detail});},
    addEventListener(name,fn){listeners['window:'+name]=fn;}};
  const fetch=async(url,opt={})=>{
    const req=opt.body?JSON.parse(opt.body):{};
    records.push({url,action:req.action||'',pref:req});
    let data={ok:true};
    if(url.includes('agent-workspace.php?action=bootstrap'))
      data={ok:true,csrf:'test-csrf',activeThread:'thread',user:{id:99,name:'Test User'},permissions:['agent.proactive','voice.agent']};
    else if(url.includes('proactive-agent.php?action=preferences'))
      data={ok:true,preferences:{...preferences},microphoneActive:false};
    else if(url.includes('api/proactive-agent.php')&&req.action==='preferences'){
      preferences={listening_enabled:req.listeningEnabled?1:0,proactive_voice_enabled:req.proactiveVoiceEnabled?1:0,wake_phrase:req.wakePhrase};
      data={ok:true,preferences:{...preferences}};
    }else if(url.includes('api/voice-identity.php')){
      data={ok:true,match:{matched:true,sessionUserMatch:true,userId:99,displayName:'Test User',eventId:'matched-test',confidence:.92}};
    }else if(url.includes('api/proactive-agent.php'))data={ok:true,events:[]};
    return {ok:true,json:async()=>data};
  };
  const context={window:win,document,console,fetch,Date,CustomEvent:class{constructor(type,opt){this.type=type;this.detail=opt.detail;}},
    navigator:{mediaDevices:{getUserMedia:async()=>{captureCalls++;return {getTracks(){return [{stop(){}}]}};}}},
    Float64Array,Uint8Array,Array,Math,
    setInterval:fn=>{fn();return 1;},clearInterval:()=>{},
    setTimeout:(fn,ms)=>{if(ms<4000)queueMicrotask(fn);return 1;},clearTimeout:()=>{}};
  vm.runInNewContext(parse,context,{filename:'proactive-listening-commands.js'});
  vm.runInNewContext(global,context,{filename:'global-agent.js'});
  return {win,events};
}
const settle=async()=>{for(let i=0;i<24;i++)await Promise.resolve();};
(async()=>{
 const first=build();await settle();
 const agent=first.win.GelatoGlobalAgent;
 assert.ok(agent);
 assert.equal(agent.listeningStatus.savedListening,true,'saved ON must hydrate from backend');
 assert.equal(agent.listeningStatus.listenActive,false,'hydration must not turn microphone on');
 assert.equal(agent.listeningStatus.microphoneActive,false,'microphone remains off on load');
 assert.equal(captureCalls,0,'no automatic voice sample on page load');
 assert.equal(micStarts.length,0);
 assert.equal(await agent.setListening(true),true,'explicit user Start Listening');
 await settle();
 assert.equal(preferences.listening_enabled,1);
 assert.equal(agent.listeningStatus.listenActive,true);
 assert.equal(agent.listeningStatus.microphoneActive,true);
 assert.equal(captureCalls,0,'listening starts without preliminary biometric microphone capture');
 assert.equal(records.filter(x=>x.url.includes('voice-identity.php')).length,0,'normal listening must not invoke speaker verification');
 const recognizer=micStarts.at(-1);
 recognizer.phrase('Hey Gelato stop listening');await settle();
 assert.equal(preferences.listening_enabled,0,'spoken Stop must save per-user preference OFF');
 assert.equal(agent.listeningStatus.listenActive,false);
 assert.equal(agent.listeningStatus.wakeStandby,true,'spoken stop retains explicitly armed wake mode');
 assert.equal(agent.listeningStatus.microphoneActive,true,'wake standby microphone status must be honest');
 const before=records.length;
 recognizer.phrase('TV says read my contacts');await settle();
 assert.equal(records.length,before,'wake standby must ignore unrelated speech');
 recognizer.phrase('Hey Gelato start listening');await settle();
 assert.equal(preferences.listening_enabled,1,'spoken Start must save per-user ON');
 assert.equal(agent.listeningStatus.listenActive,true);
 assert.equal(captureCalls,0,'wake-mode resume must not invoke a biometric challenge');
 recognizer.phrase('Hey Gelato microphone off');await settle();
 assert.equal(preferences.listening_enabled,0,'Microphone Off also saves listening OFF');
 assert.equal(agent.listeningStatus.microphoneActive,false);
 assert.equal(agent.listeningStatus.wakeStandby,false);
 assert.ok(recognizer.aborted,'Microphone Off must release recognizer');
 const second=build();await settle();
 assert.equal(second.win.GelatoGlobalAgent.listeningStatus.savedListening,false);
 assert.equal(second.win.GelatoGlobalAgent.listeningStatus.microphoneActive,false);
 console.log('proactive-global-listening-lifecycle=ok');
})().catch(error=>{console.error(error);process.exitCode=1});
