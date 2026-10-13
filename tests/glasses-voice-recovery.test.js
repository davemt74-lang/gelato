'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const core=fs.readFileSync('assets/js/glasses-voice-device.js','utf8');
const voice=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
const nodes=new Map(),listeners={},scheduled=[],instances=[];
function node(id){
  if(!nodes.has(id)){
    const handlers={};
    nodes.set(id,{id,dataset:{},hidden:false,disabled:false,checked:id==='simVoiceReplies',
      value:'',textContent:'',innerHTML:'',setAttribute(name,value){this[name]=value;},
      addEventListener(name,handler){handlers[name]=handler;},
      fire(name){handlers[name]?.({target:this});},
      closest(){return null;}});
  }
  return nodes.get(id);
}
class Recognition {
  constructor(){instances.push(this);this.starts=0;}
  start(){this.starts++;this.onstart?.();}
  stop(){this.onend?.();}
  abort(){this.aborted=true;}
  error(code){this.onerror?.({error:code});}
  end(){this.onend?.();}
  result(phrase){this.onresult?.({resultIndex:0,results:[{isFinal:true,0:{transcript:phrase,confidence:.99}}]});}
}
const document={hidden:false,readyState:'complete',getElementById:node,querySelector(){return null;},
  addEventListener(name,cb){listeners['document:'+name]=cb;}};
const window={SpeechRecognition:Recognition,navigator:{mediaDevices:{addEventListener(name,cb){listeners['media:'+name]=cb;}}},
  GelatoGlassesNavigation:{dispatch(a){return {ok:true,message:a}},context(){return {item:'Pizza',step:1,virtual:true}}},
  addEventListener(name,cb){listeners['window:'+name]=cb;},dispatchEvent(){},
  isSecureContext:true};
let clock=0;
const context={window,document,Set,Math,Date,JSON,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  SpeechSynthesisUtterance:class{},Event:class{},
  setTimeout(fn,ms){scheduled.push({fn,ms,cancelled:false});return scheduled.length;},
  clearTimeout(id){if(scheduled[id-1])scheduled[id-1].cancelled=true;},
  console};
function nextTimer(){const t=scheduled.find(x=>!x.cancelled&&!x.fired);assert.ok(t,'expected scheduled restart');t.fired=true;t.fn();return t.ms;}
vm.runInNewContext(core,context,{filename:'glasses-voice-device.js'});
vm.runInNewContext(voice,context,{filename:'glasses-simulator-voice.js'});
const ui=window.GelatoGlassesVoiceUI,d=window.GelatoGlassesVoiceDevice;
assert.equal(instances.length,0,'no microphone startup on page load');
node('simVoiceToggle').fire('click');
assert.equal(instances.length,1);
assert.equal(ui.status().microphoneActive,true);
instances.at(-1).error('network');
assert.equal(ui.status().microphoneActive,false);
assert.equal(d.snapshot().state,'recovering');
assert.equal(nextTimer(),750);
assert.equal(instances.length,2);
instances.at(-1).error('network');
assert.equal(nextTimer(),1500);
instances.at(-1).error('network');
assert.equal(nextTimer(),3000);
instances.at(-1).error('network');
assert.equal(ui.status().enabled,false,'bounded retries halt after three attempts');
assert.equal(d.snapshot().state,'failed');
assert.equal(scheduled.filter(t=>!t.fired&&!t.cancelled).length,0,'no background loop');
ui.recoverVoice();
assert.equal(ui.status().enabled,true,'manual recovery requires explicit click');
instances.at(-1).error('not-allowed');
assert.equal(d.snapshot().state,'blocked');
assert.equal(ui.status().enabled,false);
const n=instances.length;
listeners['media:devicechange']();
assert.equal(instances.length,n,'permission denied does not retry from device change');
ui.recoverVoice();instances.at(-1).error('network');
const stale=scheduled.find(t=>!t.fired&&!t.cancelled);
assert.ok(stale);
ui.stopVoice();
stale.fn(); // Simulate a queued callback racing cancellation.
assert.equal(instances.length,n+1,'explicit microphone off prevents late restart');
assert.equal(ui.status().enabled,false);
assert.equal(d.snapshot().state,'off');
ui.startVoice();
document.hidden=true;listeners['document:visibilitychange']();
assert.equal(d.snapshot().state,'paused','hidden tab releases microphone');
assert.equal(ui.status().microphoneActive,false);
document.hidden=false;listeners['document:visibilitychange']();
assert.equal(nextTimer(),750,'only a previously explicit live session may resume');
ui.stopVoice();
assert.equal(d.snapshot().state,'off');
console.log('glasses-voice-device-auto-recovery=ok');