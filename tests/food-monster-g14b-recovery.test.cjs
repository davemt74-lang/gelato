'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
let n=0;function check(test,name){assert.ok(test,name);n++;}
const agent=fs.readFileSync('js/global-agent.js','utf8');
const kds=fs.readFileSync('js/kds-voice-listener.js','utf8');
const camera=fs.readFileSync('assets/js/glasses-camera-runtime.js','utf8');
check(agent.includes('S.recognitionRetryTimer=setTimeout('),'chat has cancellable retry timer');
check(agent.includes('startContinuous();') && !agent.includes('activeRec.start();}catch'),
    'recognition recovery makes fresh recognizer, not restart same ended instance');
check(agent.includes('cancelRecognitionRetry();\n    if(S.rec)'),'stop cancels scheduled retry');
check(agent.includes('S.rec!==activeRec||(!S.listening&&!S.wakeStandby)||document.hidden'),
    'retry rejects stopped, replaced or hidden recognizers');
const begin=agent.indexOf('  function cancelRecognitionRetry(){');
const end=agent.indexOf('  function haltVoiceAfterFailure(){',begin);
check(begin>=0&&end>begin,'retry and wake command helpers present');
const helpers=agent.slice(begin,end);
const S={recognitionRetryTimer:17,wakeFinal:{text:'',at:0}};
const cancelled=[],clock={time:1000};
const sandbox={S,Date:{now(){return clock.time;}},clearTimeout(id){cancelled.push(id);}};
vm.runInNewContext(helpers+';this.helpers={cancelRecognitionRetry,isDuplicateWakeTurn};',sandbox);
sandbox.helpers.cancelRecognitionRetry();
check(cancelled.length===1&&cancelled[0]===17&&S.recognitionRetryTimer===null,
    'retry timer cancelled idempotently before disable');
sandbox.helpers.cancelRecognitionRetry();
check(cancelled.length===1,'repeat stop does not cancel nonexistent timer');
check(!sandbox.helpers.isDuplicateWakeTurn(' Hey  Gelato, READ ORDER 1 '),
    'first wake command accepted');
clock.time+=800;
check(sandbox.helpers.isDuplicateWakeTurn('hey gelato, read order 1'),
    'same command duplicated within 2.2 seconds suppressed');
clock.time+=2300;
check(!sandbox.helpers.isDuplicateWakeTurn('hey gelato, read order 1'),
    'later deliberate voice request remains allowed');

let time=150000, nextTimer=0;
const timers=new Map(),status=[],voices=[],recognizers=[];
class FakeRecognition {
  constructor(){this.started=0;this.stopped=false;recognizers.push(this);}
  start(){this.started++;this.onstart?.();}
  abort(){this.stopped=true;}
}
const screen={
  SpeechRecognition:FakeRecognition,
  addEventListener(){},
  dispatchEvent(e){if(e.type==='gelato-kds-voice-state')status.push(e.detail);
    if(e.type==='gelato-kds-voice-answer')voices.push(e.detail);}
};
const kdsDom={hidden:false,addEventListener(){}};
const kdsSandbox={
  window:screen,document:kdsDom,localStorage:{getItem(){return 'off';},setItem(){}},
  CustomEvent:class{constructor(type,options){this.type=type;this.detail=options?.detail||{};}},
  Date:{now(){return time;}},setTimeout(fn,delay){const id=++nextTimer;timers.set(id,{fn,delay});return id;},
  clearTimeout(id){timers.delete(id);},Promise,console
};
vm.runInNewContext(kds,kdsSandbox,{filename:'kds-voice-listener.js'});
const k=screen.GelatoKdsVoice;
check(k.setMode('always')===true&&k.mode==='always','operator explicitly opts in to kitchen listening');
check(recognizers.length===1,'one KDS recognizer created');
const finalText=(text)=>({resultIndex:0,results:[{0:{transcript:text,confidence:.98},isFinal:true}]});
let rec=recognizers.at(-1);
rec.onresult(finalText('help'));
rec.onresult(finalText('help'));
check(voices.length===1,'duplicate final KDS command gives one reply');
time+=2300;
rec.onresult(finalText('help'));
check(voices.length===2,'distinct later request is not suppressed');
for(let i=0;i<5;i++){
  rec=recognizers.at(-1);
  rec.onend?.();time+=50;
  const queued=[...timers.entries()].filter(([,x])=>x.delay>0);
  if(i<4){
    check(queued.length===1,'rapid exit '+i+' schedules just one retry');
    const [id,t]=queued[0];timers.delete(id);t.fn();
    check(recognizers.length===i+2,'each recovery gets a fresh KDS recognizer');
  }
}
check(k.mode==='off','five rapid recognizer exits stop KDS microphone');
check(status.some(x=>x.code==='retry-exhausted'),
    'visible retry exhaustion requires explicit re-enable');
const oldCount=recognizers.length;
for(const [id,t] of [...timers]){timers.delete(id);t.fn();}
check(recognizers.length===oldCount,'exhaustion leaves no pending ghost restart');
check(k.setMode('always')===true&&recognizers.length===oldCount+1,
    'manual re-enable can recover after troubleshooting');
const stable=recognizers.at(-1);
time+=12000;stable.onend();
check(k.mode==='always','long healthy recognition session not counted as rapid failure');
k.setMode('off');
const countBefore=recognizers.length;
for(const [id,t] of [...timers]){timers.delete(id);t.fn();}
check(k.mode==='off'&&recognizers.length===countBefore,
    'manual disable cancels restart timer');
const stale=stable.onresult;stale?.(finalText('help'));
check(voices.length===2,'callback from replaced listener cannot execute KDS command');

let release;
const deferred=new Promise(resolve=>{release=resolve;});
const stream={on:false,allow:true,locationId:8,
  isEnabled(slot){return slot===2&&this.allow;},
  isStreaming(slot){return slot===2&&this.on;}};
let starts=0,stops=0;
const cameraAPI={
  async start(){starts++;await deferred;stream.on=true;},
  stop(){stops++;stream.on=false;},
  video(){return null;}
};
const cameraWindow={GelatoCameraConfiguration:stream,GelatoGlassesMultiCamera:cameraAPI,
  addEventListener(){},dispatchEvent(){}};
const camSandbox={window:cameraWindow,document:{hidden:false,readyState:'loading',
  addEventListener(){},getElementById(){return null;}},navigator:{mediaDevices:{addEventListener(){}}},
  Date,Map,Set,Math,Number,Uint8Array,Uint8ClampedArray,Promise,console,
  setInterval(){},CustomEvent:class{constructor(type){this.type=type;}}};
vm.runInNewContext(camera,camSandbox,{filename:'glasses-camera-runtime.js'});
const runtime=cameraWindow.GelatoCameraRuntime;
(async()=>{
  const first=runtime.start(2);
  check(runtime.status(2).startPending===true,'camera start exposes pending state');
  check(await runtime.start(2)===false&&starts===1,
      'overlapping camera reconnect cannot open two streams');
  runtime.stop(2,'manual_stop');
  release();
  check(await first===false,'cancelled in-flight start rejects late camera result');
  check(stops>=1&&!stream.on,'cancelled reconnect closes a late opened camera stream');
  check(runtime.status(2).desired===false&&!runtime.status(2).startPending,
      'manual stop leaves no ghost camera recovery pending');
  await runtime.poll();
  check(starts===1,'stopped stream does not restart automatically on next poll');
  console.log('FM-G14B voice, KDS and camera recovery PASS ('+n+' assertions)');
})().catch(error=>{console.error(error);process.exitCode=1;});
