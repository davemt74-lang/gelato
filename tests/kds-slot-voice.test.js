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
  GelatoGlobalAgent:{verifiedSpeaker:{eventId:'verified-test-event',userId:1}},
  GelatoKdsSlots:{
    snapshot:()=>({locationId:1,stationPublicId:'',page:0,total:18,slots:Array.from({length:8},(_,i)=>({slot:i+1,checkPublicId:'T-'+(i+1),checkNumber:String(5001+i)})),allSlots:Array.from({length:18},(_,i)=>({slot:i+1,checkPublicId:'T-'+(i+1),checkNumber:String(5001+i)}))}),
    read:(n)=>n===12?{ok:true,text:'Order 12, ticket 9012. 2 Breakfast Pizzas, add bacon.'}:n===4?{ok:true,text:'Order 4, ticket 9009. 2 Breakfast Pizzas, add bacon.'}:n===1?{ok:true,text:'Order 1, ticket 5001. 1 Cheese Pizza.'}:n>=1&&n<=18?{ok:true,text:'Order '+n+', ticket '+(5000+n)+'. 1 pizza.'}:{ok:false,text:'Order '+n+' is not active at this KDS station.'}
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
  fetch:async(url,options)=>{sent.push({url,options});const phrase=JSON.parse(options.body).message;const slot=Number((phrase.match(/order (\d+)/)||[])[1]||0);const result=win.GelatoKdsSlots.read(slot);return {ok:true,json:async()=>({ok:true,answer:result.text})}}
};
vm.runInNewContext(text,context,{filename:'kds-voice-listener.js'});
const voice=win.GelatoKdsVoice;
assert.ok(voice,'KDS voice engine must install');
assert.equal(voice.supported,true);
assert.equal(voice.readSlot(4),'Order 4, ticket 9009. 2 Breakfast Pizzas, add bacon.');
assert.match(voice.readSlot(19),/not active/);
assert.match(voice.readSlot(12),/Order 12, ticket 9012/);
assert.match(voice.readAll(),/Order 18/,'read all orders must not stop at first eight');
assert.match(voice.readAll(),/Order 12, ticket 9012/);
assert.ok(voice.setMode('always'));
assert.equal(voice.mode,'always');
assert.equal(voice.active,true);
(async()=>{
recognized.at(-1).emit('read order 4');
await new Promise(resolve=>setImmediate(resolve));
assert.ok(events.some(x=>x.type==='gelato-kds-voice-answer'&&x.detail.text.includes('ticket 9009')),'slot must use displayed slot, not ticket #4');
recognized.at(-1).emit('read order 12');
await new Promise(resolve=>setImmediate(resolve));
assert.ok(events.some(x=>x.type==='gelato-kds-voice-answer'&&x.detail.text.includes('ticket 9012')),'order 12 must work while page 1 is visible');
assert.equal(sent.length,2,'verified voice reads use canonical read-only kitchen endpoint');
assert.ok(sent.every(x=>x.url==='api/kds-agent.php'),'voice must not mutate or use general write API');
assert.ok(sent.every(x=>JSON.parse(x.options.body).voiceEventId==='verified-test-event'),'matched voice event attached for per-user Agent history');
voice.setMode('off');
voice.setMode('wake');
let answers=events.filter(x=>x.type==='gelato-kds-voice-answer').length;
recognized.at(-1).emit('read order 1');
assert.equal(events.filter(x=>x.type==='gelato-kds-voice-answer').length,answers,'wake mode ignores commands without wake word');
recognized.at(-1).emit('Hey Gelato read order 1');
await new Promise(resolve=>setImmediate(resolve));
assert.ok(events.filter(x=>x.type==='gelato-kds-voice-answer').length>answers,'wake prefix allows command');
assert.equal(voice.mode,'wake');
voice.pauseForSpeech();
assert.equal(voice.active,true,'wake-word barge-in requires recognition to remain active while the Agent speaks');
voice.resumeAfterSpeech();
assert.equal(voice.active,true);
voice.setMode('off');
assert.equal(voice.active,false);
assert.equal(voice.mode,'off');
assert.ok(events.some(x=>x.type==='gelato-kds-voice-state'&&x.detail.code==='listening'));
assert.ok(events.some(x=>x.type==='gelato-kds-voice-state'&&x.detail.code==='speaking'));
assert.ok(!/api\/kds\.php/.test(text),'voice must not mutate KDS');
console.log('kds-slot-voice-interaction=ok');
})().catch(e=>{console.error(e);process.exitCode=1});
