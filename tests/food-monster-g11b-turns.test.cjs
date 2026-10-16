'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('js/global-agent.js','utf8');
const recognizers=[],sent=[],listeners={},voiceTimers=[],turnEvents=[];let matches=0;
let n=0;const ok=(value,reason)=>{assert.ok(value,reason);n++};
const input={value:'',dataset:{},handlers:{},dispatchEvent(e){this.handlers[e.type]?.(e);},
 addEventListener(t,f){this.handlers[t]=f}};
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
 if(url.includes('bootstrap'))data={ok:true,csrf:'x',activeThread:'thread',
  user:{id:15,name:'Kitchen'},permissions:['agent.proactive','voice.agent']};
 else if(url.includes('proactive-agent.php?action=preferences'))data={ok:true,preferences:pref};
 else if(url.includes('proactive-agent.php')&&body.action==='preferences'){
  pref={listening_enabled:body.listeningEnabled?1:0,proactive_voice_enabled:0,wake_phrase:'Hey Gelato'};
  data={ok:true,preferences:pref};
 }
 else if(url.includes('voice-identity.php'))data={ok:true,match:{matched:true,sessionUserMatch:true,userId:15,eventId:'voice-'+String(++matches).padStart(20,'a')}};
 else if(body.action==='append')data={ok:true,message:{databaseId:12}};
 else if(url.includes('proactive-agent.php'))data={ok:true,events:[]};
 return {ok:true,json:async()=>data};
};
const document={readyState:'complete',hidden:false,body:{dataset:{agentCanvas:'1'}},
 getElementById:id=>id==='gaInput'?input:null,querySelector:()=>null,
 addEventListener:(name,fn)=>{listeners[name]=fn}};
const window={SpeechRecognition:Recognition,AudioContext:Audio,
 addEventListener:(name,fn)=>{listeners['window:'+name]=fn},dispatchEvent:e=>{if(e.type==='gelato-agent-speaker-turn')turnEvents.push(e.detail)}};
const context={window,document,fetch,navigator:{mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}},
 CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail}},
 Event:class{constructor(type){this.type=type;this.isTrusted=false}},
 Float64Array,Uint8Array,Date,Promise,console,
 setInterval:fn=>{fn();return 1},clearInterval:()=>{},
 setTimeout:(fn,ms)=>{if(ms===2800)queueMicrotask(fn);if(ms===1200)voiceTimers.push(fn);return 1},
 clearTimeout:()=>{}};
vm.runInNewContext(source,context);
const flush=async()=>{for(let i=0;i<50;i++)await Promise.resolve()};
(async()=>{
 await flush();const agent=window.GelatoGlobalAgent;
 ok(await agent.setListening(true),'listener enabled');
 const rec=recognizers.at(-1);
 window.GelatoVoiceTurns={evaluate:()=>({kind:'ignored',reason:'low-confidence'})};
 rec.say('TV says buy more cheese',true);
 ok(input.value==='TV says buy more cheese','low-confidence speech visible as editable draft');
 ok(voiceTimers.length===0,'low-confidence result cannot schedule auto-send');
 ok(!sent.some(x=>x.body.action==='append'),'low-confidence voice cannot execute model action');
 delete window.GelatoVoiceTurns;
 input.value='';input.dispatchEvent({type:'input',isTrusted:true});
 rec.say('Review orders now',true);
 ok(input.value==='Review orders now','confident speech visible');
 rec.say('Review orders now',true);
 ok(input.value==='Review orders now','repeated browser final ignored');
 ok(voiceTimers.length===1,'repeated final cannot schedule second response');
 voiceTimers.shift()();await flush();
 ok(sent.filter(x=>x.body.action==='append'&&x.body.content==='Review orders now').length===1,
   'confident final is submitted exactly once');
 const messages=()=>sent.filter(x=>x.body.action==='append'&&x.body.role==='user');
 const before=messages().length;
 const first=await agent.identifySpeaker({force:true});
 ok(/^voice-[a-zA-Z0-9-]{8,90}$/.test(first.eventId),'first verified event is recognized');
 await agent.send('How do we prepare dough?',true);
 ok(messages().at(-1).body.voiceEventId===first.eventId,'first voice turn uses fresh verified identity evidence');
 await agent.send('What about the next batch?',true);
 ok(messages().at(-1).body.voiceEventId==='','a second voice turn cannot replay consumed identity');
 const second=await agent.identifySpeaker({force:true});
 ok(second.eventId!==first.eventId,'new verification produces new event');
 await agent.send('Is the topping fresh?',true);
 ok(messages().at(-1).body.voiceEventId===second.eventId,'freshly verified next speaker event is attached');
 await agent.send('Typed operator note',false);
 ok(messages().at(-1).body.voiceEventId==='','typed chat cannot inherit voice attribution');
 ok(messages().length===before+4,'all chat turns persist even with unknown speaker');
 ok(turnEvents.length===4&&turnEvents.every(e=>Object.keys(e).every(k=>['verified','correlated','unverified'].includes(k))),
   'speaker-turn notifications reveal no voiceprint, name or person identity');
 console.log('FM-G11B single-use voice and speaker-switch safeguards PASS ('+n+' assertions)');
})().catch(e=>{console.error(e);process.exitCode=1});
