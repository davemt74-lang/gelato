'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const src=fs.readFileSync('assets/js/glasses-voice-device.js','utf8');
const handlers={},events=[],tracks=[];let captureCount=0;
const mic={kind:'audioinput',deviceId:'test-id'};
const context={
  window:{isSecureContext:true,navigator:{permissions:{query:async()=>({state:'granted'})},
    mediaDevices:{enumerateDevices:async()=>[mic],getUserMedia:async()=>{
      captureCount++;const t={readyState:'live',stop(){tracks.push(true);},getSettings(){return {echoCancellation:true};}};
      return {getAudioTracks:()=>[t],getTracks:()=>[t]};
    },addEventListener(name,cb){handlers[name]=cb;}}},
    SpeechRecognition:function(){},speechSynthesis:{speak(){},cancel(){}},
    dispatchEvent(event){events.push(event);}},
  document:{addEventListener(){}},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  Date,Set,Math,JSON
};
vm.runInNewContext(src,context);
const d=context.window.GelatoGlassesVoiceDevice;
async function run(){
  assert.equal(captureCount,0,'module load cannot open microphone');
  assert.equal(d.snapshot().state,'off');
  assert.equal(d.transition('active',{microphoneActive:true}),false,'audio cannot activate without explicit consent');
  assert.equal(d.recoveryDelay(1),750);
  assert.equal(d.recoveryDelay(2),1500);
  assert.equal(d.recoveryDelay(3),3000);
  assert.equal(d.recoveryDelay(4),null);
  let result=await d.certify({probeMicrophone:false});
  assert.equal(captureCount,0,'passive diagnostics never prompt for microphone');
  assert.equal(result.status,'warning','no mic probe must not claim certified');
  result=await d.certify({probeMicrophone:true});
  assert.equal(captureCount,1);
  assert.equal(tracks.length,1,'temporary test stream must be released');
  assert.equal(result.checks.find(x=>x.id==='capture').status,'ready');
  assert.equal(result.checks.find(x=>x.id==='speaker').status,'warning','missing verified speaker not falsely certified');
  assert.equal(d.snapshot().state,'off','certification never activates ongoing capture');
  assert.equal(d.transition('starting',{consented:true}),true);
  assert.equal(d.transition('active',{microphoneActive:true}),true);
  assert.equal(d.snapshot().microphoneActive,true);
  d.transition('recovering',{retry:1,error:'network'});
  assert.equal(d.snapshot().retries,1);
  assert.equal(d.snapshot().microphoneActive,false);
  d.transition('blocked',{error:'evil-payload'});
  assert.equal(d.snapshot().lastError,'unknown','untrusted error not stored');
  d.transition('off');
  assert.equal(d.snapshot().consented,false);
  assert.equal(d.transition('active',{microphoneActive:true}),false);
  assert.ok(!JSON.stringify(d.snapshot()).includes('test-id'),'no microphone device IDs in report');
  console.log('glasses-voice-device-certification=ok');
}
run().catch(e=>{console.error(e);process.exitCode=1;});