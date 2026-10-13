'use strict';
const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const text=fs.readFileSync('js/kds-voice-listener.js','utf8');
const events=[],listeners={},recognized=[],sent=[];
class Recognition {
  constructor(){recognized.push(this);}
  start(){this.onstart?.();}
  abort(){this.onend?.();}
  emit(phrase){this.onresult?.({resultIndex:0,results:[{isFinal:true,0:{transcript:phrase}}]});}
}
const win={
  SpeechRecognition:Recognition,KDS_CONFIG:{csrf:'read-only-test-csrf'},
  GelatoKdsSlots:{
    snapshot:()=>({locationId:1,stationPublicId:'',page:0,slots:[{slot:1,checkPublicId:'T-A',checkNumber:'5001'},{slot:4,checkPublicId:'T-D',checkNumber:'9009'}]}),
    read:(n)=>n===4?{ok:true,text:'Order 4, ticket 9009. 2 Breakfast Pizzas, add bacon.'}:n===1?{ok:true,text:'Order 1, ticket 5001. 1 Cheese Pizza.'}:{ok:false,text:'Order '+n+' is empty on this page.'}
  },
  dispatchEvent:(event)=>{events.push(event);},
  addEventListener:(event,callback)=>{listeners['window:'+event]=callback;}
};
const doc={hidden:false,addEventListener:(type,cb)=>listeners[type]=cb};
const store=new Map();
const context={
  window:win,document:doc,CustomEvent:class{constructor(type,args){this.type=type;this.detail=args.detail;}},
  localStorage:{getItem:(k)=>store.get(k),setItem:(k,v)=>store.set(k,v)},
  setTimeout:(fn)=>{if(fn.toString().includes("status('off'"))fn();return 5;},
  clearTimeout:()=>{},Date,console,
  fetch:async(url,options)=>{sent.push({url,options});return {ok:true,json:async()=>({ok:true,answer:'Kitchen report'})}}
};
vm.runInNewContext(text,context,{filename:'kds-voice-listener.js'});
const voice=win.GelatoKdsVoice;
assert.ok(voice,'KDS voice engine must install');
assert.equal(voice.supported,true);
assert.equal(voice.readSlot(4),'Order 4, ticket 9009. 2 Breakfast Pizzas, add bacon.');
assert.match(voice.readSlot(2),/empty/);
assert.ok(voice.setMode('always'));
assert.equal(voice.mode,'always');
assert.equal(voice.active,true);
recognized.at(-1).emit('read order 4');
assert.ok(events.some(x=>x.type==='gelato-kds-voice-answer'&&x.detail.text.includes('ticket 9009')),'slot must use displayed slot, not ticket #4');
assert.equal(sent.length,0,'read order slot cannot mutate or query general agent');
voice.setMode('off');
voice.setMode('wake');
let answers=events.filter(x=>x.type==='gelato-kds-voice-answer').length;
recognized.at(-1).emit('read order 1');
assert.equal(events.filter(x=>x.type==='gelato-kds-voice-answer').length,answers,'wake mode ignores commands without wake word');
recognized.at(-1).emit('Hey Gelato read order 1');
assert.ok(events.filter(x=>x.type==='gelato-kds-voice-answer').length>answers,'wake prefix allows command');
assert.equal(voice.mode,'wake');
voice.pauseForSpeech();
assert.equal(voice.active,false,'speech must pause recognition to avoid acoustic feedback');
voice.resumeAfterSpeech();
assert.equal(voice.active,true);
voice.setMode('off');
assert.equal(voice.active,false);
assert.equal(voice.mode,'off');
assert.ok(events.some(x=>x.type==='gelato-kds-voice-state'&&x.detail.code==='listening'));
assert.ok(events.some(x=>x.type==='gelato-kds-voice-state'&&x.detail.code==='speaking'));
assert.ok(!/api\/kds\.php/.test(text),'voice must not mutate KDS');
console.log('kds-slot-voice-interaction=ok');
