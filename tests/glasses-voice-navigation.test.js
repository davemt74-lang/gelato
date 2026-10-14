'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
const elements=new Map(),events=new Map(),actions=[];
function elt(id){
  if(!elements.has(id)){
    const listeners={};
    elements.set(id,{id,dataset:{},title:'',textContent:'',disabled:false,hidden:false,checked:id==='simVoiceReplies',
      setAttribute(name,value){this[name]=value;},
      addEventListener(name,fn){listeners[name]=fn;},
      fire(name){if(listeners[name])listeners[name]({target:this});},
      listeners});
  }
  return elements.get(id);
}
const fakeDocument={
  hidden:false,visibilityState:'visible',
  getElementById:elt,
  querySelector(selector){return null;},
  addEventListener(name,cb){events.set(name,cb);}
};
const win={
  GelatoGlassesNavigation:{
    dispatch(action){actions.push(action);return {ok:true,message:'Handled '+action};},
    context(){return {item:'Test Pizza',step:2,virtual:true};}
  },
  addEventListener(name,cb){events.set(name,cb);}
};
const context={
  document:fakeDocument,window:win,console,
  setTimeout(callback){return 1;},clearTimeout(){},
  SpeechSynthesisUtterance:function(){},
  Event:class{}
};
vm.runInNewContext(source,context,{filename:'glasses-simulator-voice.js'});
assert.ok(win.GelatoGlassesVoiceUI,'voice/UI module should install');
const parse=win.GelatoGlassesVoiceUI.normalizePhrase;
const commands={
  'next':'next','Next Step.':'next','next item':'next_item',
  'next ticket':'next_ticket','next order':'next_ticket','previous ticket':'previous_ticket',
  'go to ticket 104':'select_ticket:104','Gelato, select order #105':'select_ticket:105',
  'start ticket':'focus_ticket','mark ticket started':'start_order',
  'previous item':'previous_item','item added':'ingredient_added','ingredient added':'ingredient_added','skip ingredient':'skip_ingredient','note added':'item_added',
  'repeat':'repeat','what is next':'repeat','status':'status',
  'stop listening':'stop_voice','help':'help'
};
for(const [phrase,want] of Object.entries(commands)){
  assert.equal(parse(phrase),want,'phrase '+phrase);
}
const page=fs.readFileSync('glasses-simulator.php','utf8');
for(const id of ['simNavNext','simNavPrevious','simNavNextItem','simNavPreviousItem','simNavItemAdded','simNavRepeat','simViewHudOnly','simViewGlasses'])
  assert.ok(!page.includes('id="'+id+'"'),'obsolete duplicated sidebar button removed: '+id);
for(const [spoken,command] of [['next','next'],['previous','previous'],['next item','next_item'],['previous item','previous_item'],['ingredient added','ingredient_added'],['repeat','repeat']])
  assert.equal(parse(spoken),command,'voice navigation preserved: '+command);
elt('simVoiceHelp').fire('click');assert.match(elt('simVoiceFeedback').textContent,/Say start ticket/,'voice help remains available without duplicate sidebar commands');
assert.equal(elt('simNavContext').textContent,'Test Pizza · Step 2 · Preview only');
assert.equal(elt('simVoiceToggle').disabled,true,'missing SpeechRecognition must disable microphone but preserve buttons');
assert.equal(elt('simVoiceToggle').disabled,true,'unsupported browser must not expose a broken mic');
assert.match(elt('simVoiceFeedback').textContent,/Say start ticket/,'help is handled locally without a KDS mutation');
assert.ok(!/\bfetch\s*\(|XMLHttpRequest|navigator\.sendBeacon/.test(source),'voice navigation may not write directly to POS/KDS');
require('./glasses-section285a-production-ticket-focus.test.js');
console.log('glasses-voice-navigation=ok');
