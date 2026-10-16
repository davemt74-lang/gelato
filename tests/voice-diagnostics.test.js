'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('js/voice-diagnostics.js','utf8');
const handlers={},elements=[],body={appendChild(n){elements.push(n);}};
const document={readyState:'loading',body,head:{appendChild(){}},
  addEventListener(name,cb){handlers['document:'+name]=cb;},
  createElement(tag){
    if(tag==='style')return {dataset:{},textContent:''};
    return {hidden:true,querySelector(){return {focus(){}};},addEventListener(){},setAttribute(){},className:'',innerHTML:''};
  },
  get activeElement(){return {focus(){}};}
};
const win={addEventListener(name,cb){handlers[name]=cb;},GelatoVoiceTurns:{environment:'noisy'}};
const context={window:win,document,Date,JSON,Array,Set,String,navigator:{clipboard:{writeText:async()=>{}}}};
vm.runInNewContext(source,context,{filename:'voice-diagnostics.js'});
const d=win.GelatoVoiceDiagnostics;
assert.ok(d,'diagnostics API');
win.GelatoRoomPresence={diagnostics:{
  active:true,modelCached:true,intervalMs:1450,lastInferenceMs:317,failures:2,
  personId:8945,transcript:'SECRET_TRANSCRIPT',descriptor:[42,99],faceName:'EMPLOYEE_SECRET'
}};
const camera=d.snapshot().camera;
assert.equal(camera.active,true,'opt-in camera is reported as enabled');
assert.equal(camera.modelCached,true,'model-cache state is included');
assert.equal(camera.intervalMs,1450,'adaptive interval is projected');
assert.equal(camera.lastObservationMs,317,'camera latency is projected');
assert.equal(camera.consecutiveFailures,2,'camera failure counter is projected');
assert.ok(!JSON.stringify(d.snapshot()).includes('SECRET_TRANSCRIPT'),'camera diagnostics never copy transcripts');
assert.ok(!JSON.stringify(d.snapshot()).includes('EMPLOYEE_SECRET'),'camera diagnostics never expose enrolled names');
assert.ok(!JSON.stringify(d.snapshot()).includes('8945'),'camera diagnostics never expose person identifiers');
win.GelatoRoomPresence.diagnostics.intervalMs=Infinity;
assert.equal(d.snapshot().camera.intervalMs,0,'untrusted nonfinite timing is rejected');
d.record('global-agent','retry-scheduled',{transcript:'SECRET_TRANSCRIPT',speaker:'EMPLOYEE_SECRET'});
assert.ok(!JSON.stringify(d.snapshot()).includes('SECRET_TRANSCRIPT'),'retry diagnostics retain no private text');

const speakerEvent=handlers['gelato-agent-speaker-turn'];
assert.equal(typeof speakerEvent,'function','speaker attribution diagnostics are connected');
speakerEvent({detail:{verified:true,correlated:true,personId:192,faceVector:[5,6],
  transcript:'SECRET_SPEECH',name:'SECRET_EMPLOYEE'}});
speakerEvent({detail:{verified:true,correlated:false,eventId:'SECRET_VOICE_ID'}});
speakerEvent({detail:{verified:false,correlated:true,personId:'SECRET_FAKE'}});
let stats=d.snapshot().speakerTurns;
assert.equal(stats.correlated,1,'verified+visual correlation counted');
assert.equal(stats.verifiedWithoutVisual,1,'verified voice without face separately counted');
assert.equal(stats.unknown,1,'unverified visual match remains unattributed');
assert.ok(!JSON.stringify(d.snapshot()).includes('SECRET_'),'diagnostics never retain participant metadata');
for(let i=0;i<1100;i++)speakerEvent({detail:{verified:false}});
assert.equal(d.snapshot().speakerTurns.unknown,999,'speaker diagnostic counters bounded');
assert.equal(d.snapshot().microphoneActive,false,'cannot open microphone at load');
assert.equal(elements.length,0,'no microphone or UI is mounted before DOM ready');
d.record('global-agent','starting');
d.record('global-agent','active',{transcript:'SECRET',speaker:'employee-123',error:'SECRET'});
let s=d.snapshot();
assert.equal(s.owner,'global-agent');
assert.equal(s.microphoneActive,true);
assert.ok(!JSON.stringify(s).includes('SECRET'));
assert.ok(!JSON.stringify(s).includes('employee-123'));
d.record('global-agent','echo-ignored',{text:'sensitive'});
d.record('global-agent','barge-in',{confidence:.4});
d.record('global-agent','ended');
d.record('global-agent','retry');
d.record('global-agent','blocked',{error:'not-allowed'});
s=d.snapshot();
assert.equal(s.lastError,'not-allowed');
assert.equal(s.owner,'none');
assert.equal(s.microphoneActive,false);
assert.equal(s.echoBlocks,1);
assert.equal(s.bargeIns,1);
assert.equal(s.restarts,1);
d.record('global-agent','failed',{error:'script-injection'});
assert.equal(d.snapshot().lastError,'start-failed','untrusted errors are never reported verbatim');
d.record('untrusted','active');
assert.equal(d.snapshot().microphoneActive,false,'unknown owner cannot claim microphone');
d.hydrate({savedListening:true,listenActive:false,microphoneActive:false,wakeStandby:false});
assert.equal(d.snapshot().savedListening,true);
assert.equal(d.snapshot().microphoneActive,false);
handlers['gelato-voice-turn']({detail:{kind:'speaking',speaking:true,owner:'global-agent',text:'NOT_LOGGED'}});
assert.equal(d.snapshot().speechOwner,'global-agent');
assert.ok(!JSON.stringify(d.snapshot()).includes('NOT_LOGGED'));
for(let i=0;i<100;i++)d.record('global-agent','no-speech',{transcript:'never'});
assert.equal(d.snapshot().events.length,24,'events bounded');
assert.equal(typeof win.GelatoVoiceDiagnostics.open,'function');
assert.equal(source.includes('getUserMedia'),false,'diagnostic never starts microphone');
assert.equal(source.includes('SpeechRecognition('),false,'diagnostic never starts recognition');
console.log('voice-diagnostics-session-and-privacy=ok');