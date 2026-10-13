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
  'previous item':'previous_item','item added':'item_added','ingredient added':'item_added',
  'repeat':'repeat','what is next':'repeat','status':'status',
  'stop listening':'stop_voice','help':'help'
};
for(const [phrase,want] of Object.entries(commands)){
  assert.equal(parse(phrase),want,'phrase '+phrase);
}
for(const [id,command] of [['simNavNext','next'],['simNavNextItem','next_item'],['simNavItemAdded','item_added'],['simNavPrevious','previous']]){
  elt(id).fire('click');assert.equal(actions.at(-1),command);
}
assert.equal(elt('simNavContext').textContent,'Test Pizza · Step 2 · Preview only');
assert.equal(elt('simVoiceToggle').disabled,true,'unsupported browser must not expose a broken mic');
assert.ok(elt('simVoiceFeedback').textContent.includes('unavailable'));
assert.ok(!/\bfetch\s*\(|XMLHttpRequest|navigator\.sendBeacon/.test(source),'voice navigation may not write directly to POS/KDS');
console.log('glasses-voice-navigation=ok');
