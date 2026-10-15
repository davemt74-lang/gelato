'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('js/global-agent.js','utf8');
const recognizers=[],sent=[],listeners={};
let n=0;const ok=(value,reason)=>{assert.ok(value,reason);n++};
const input={value:'',dataset:{},handlers:{},dispatchEvent(e){this.handlers[e.type]?.(e);},addEventListener(t,f){this.handlers[t]=f}};
class Recognition{
 constructor(){recognizers.push(this)}
 start(){this.running=true;this.onstart?.()}
 stop(){this.running=false;this.onend?.()}
 abort(){this.running=false}
 say(text,final=false){this.onresult?.({resultIndex:0,results:[{isFinal:final,0:{transcript:text,confidence:.99}}]})}
}
class Audio{
 createMediaStreamSource(){return {connect(){}}}
 createAnalyser(){return {frequencyBinCount:48,getByteFrequencyData(b){b.fill(95)}}}
 close(){return Promise.resolve()}
}
let pref={listening_enabled:0,proactive_voice_enabled:0,wake_phrase:'Hey Gelato'};
const fetch=async(url,opt={})=>{
 const body=opt.body?JSON.parse(opt.body):{};sent.push({url,body});
 let data={ok:true};
 if(url.includes('bootstrap'))data={ok:true,csrf:'x',activeThread:'thread',user:{id:15,name:'Kitchen'},permissions:['agent.proactive','voice.agent']};
 else if(url.includes('proactive-agent.php?action=preferences'))data={ok:true,preferences:pref};
 else if(url.includes('proactive-agent.php')&&body.action==='preferences'){pref={listening_enabled:body.listeningEnabled?1:0,proactive_voice_enabled:0,wake_phrase:'Hey Gelato'};data={ok:true,preferences:pref}}
 else if(url.includes('voice-identity.php'))data={ok:true,match:{matched:true,sessionUserMatch:true,userId:15,eventId:'verified'}};
 else if(body.action==='append')data={ok:true,message:{databaseId:12}};
 else if(url.includes('proactive-agent.php'))data={ok:true,events:[]};
 return {ok:true,json:async()=>data};
};
const document={readyState:'complete',hidden:false,body:{dataset:{agentCanvas:'1'}},
 getElementById:id=>id==='gaInput'?input:null,querySelector:()=>null,
 addEventListener:(name,fn)=>{listeners[name]=fn}};
const window={SpeechRecognition:Recognition,AudioContext:Audio,
 addEventListener:(name,fn)=>{listeners['window:'+name]=fn},dispatchEvent:()=>{}};
const context={window,document,fetch,navigator:{mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}},
 CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail}},
 Event:class{constructor(type){this.type=type;this.isTrusted=false}},
 Float64Array,Uint8Array,Date,Promise,console,
 setInterval:fn=>{fn();return 1},clearInterval:()=>{},setTimeout:(fn,ms)=>{if(ms===2800)queueMicrotask(fn);return 1},clearTimeout:()=>{}};
vm.runInNewContext(source,context);
const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve()};
(async()=>{
 await flush();const agent=window.GelatoGlobalAgent;
 ok(!!agent,'Agent starts');ok(recognizers.length===0,'No automatic microphone');
 ok(await agent.setListening(true),'Explicit listening starts');
 const rec=recognizers.at(-1);ok(rec.running,'Speech recognition active');
 rec.say('How many',false);
 ok(input.value==='How many'&&input.dataset.voiceInterim==='true','Interim transcription appears');
 rec.say('How many orders',true);
 ok(input.value==='How many orders'&&input.dataset.voiceInterim==='false','Final dictation appears');
 ok(!sent.some(x=>x.body.action==='append'),'No command without deliberate send');
 rec.say('Are ready',true);ok(input.value==='How many orders Are ready','Final phrases append');
 input.value='My own typing';input.dispatchEvent({type:'input',isTrusted:true});
 rec.say('Unwanted overwrite',true);ok(input.value==='My own typing','Manual text preserved');
 input.value='';
 window.GelatoVoiceTurns={evaluate:()=>({kind:'ignored',reason:'wake-required'})};
 rec.say('Noisy room dictation',true);
 ok(input.value==='Noisy room dictation','Wake-only acoustic profile still shows editable dictation');
 delete window.GelatoVoiceTurns;
 input.value='';rec.say('Hey Gelato stop listening',true);await flush();
 ok(!agent.listening&&agent.listeningStatus.wakeStandby,'Wake command retains standby');
 rec.say('Background television',true);ok(input.value==='','Standby does not transcribe background');
 rec.say('Hey Gelato microphone off',true);await flush();
 ok(!agent.listeningStatus.microphoneActive,'Microphone off releases recognizer');
 ok(await agent.setListening(true),'Recognizer restarts');
 const result=agent.talkOnce();await flush();
 const once=recognizers.at(-1);ok(once!==rec&&!once.continuous,'One-shot recognizer started');
 once.say('Review orders',false);ok(input.value==='Review orders','One-shot interim visible');
 once.say('Review orders today',true);await result;await flush();
 ok(sent.some(x=>x.body.action==='append'&&x.body.content==='Review orders today'),'One-shot final reaches Agent');
 ok(recognizers.at(-1)!==once&&agent.listeningStatus.microphoneActive,'Always-on session restored');
 ok(!sent.some(x=>x.url.includes('food-monster-llm.php')),'No LLM key needed for speech recognition');
 console.log('FM-G7V dictation and permission tests: PASS ('+n+' assertions)');
})().catch(e=>{console.error(e);process.exitCode=1});
