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