'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
const nodes=new Map(),listeners={},actions=[];let currentRecognition;
function el(id){
  if(!nodes.has(id)){
    const ev={};nodes.set(id,{id,dataset:{},disabled:false,hidden:false,checked:false,value:'',textContent:'',
      addEventListener(k,cb){ev[k]=cb;},setAttribute(k,v){this[k]=v;},
      fire(k){ev[k]?.({target:this});}
    });
  }
  return nodes.get(id);
}
class Recognition {
  constructor(){currentRecognition=this;}
  start(){this.onstart?.();}
  abort(){this.onend?.();}
  speak(phrase){this.onresult?.({resultIndex:0,results:[{isFinal:true,0:{transcript:phrase}}]});}
}
const document={hidden:false,getElementById:el,querySelector:()=>null,addEventListener:(type,cb)=>{listeners[type]=cb;}};
const win={
  SpeechRecognition:Recognition,
  GelatoGlassesNavigation:{
    dispatch(action,options){actions.push({action,spoken:!!options?.spoken});return Promise.resolve({ok:true,message:'Handled '+action});},
    context(){return {item:'Pizza',step:1,virtual:true};}
  },
  addEventListener(type,cb){listeners['window:'+type]=cb;}
};
const context={document,window:win,console,Event:class{},Date,Math,
  setTimeout:()=>1,clearTimeout(){},SpeechSynthesisUtterance:class{}};
vm.runInNewContext(src,context,{filename:'glasses-simulator-voice.js'});
const ui=win.GelatoGlassesVoiceUI;
assert.ok(listeners.click,'delegated manual HUD controls must be registered');
const manual=action=>{
  const button={disabled:false,dataset:{glassesCommand:action}};
  button.closest=selector=>selector==='[data-glasses-command]'?button:null;
  listeners.click({target:button});
};
manual('start_order');
assert.deepEqual(actions.at(-1),{action:'start_order',spoken:false});
el('simVoiceToggle').fire('click');
assert.ok(currentRecognition);
currentRecognition.speak('start ticket');
assert.deepEqual(actions.at(-1),{action:'focus_ticket',spoken:true},'Start Ticket voice focuses without KDS writes');
currentRecognition.speak('mark ticket started');
assert.deepEqual(actions.at(-1),{action:'start_order',spoken:true},'explicit KDS start remains available');
currentRecognition.speak('next ticket');
assert.deepEqual(actions.at(-1),{action:'next_ticket',spoken:true});
currentRecognition.speak('previous ticket');
assert.deepEqual(actions.at(-1),{action:'previous_ticket',spoken:true});
currentRecognition.speak('go to ticket 104');
assert.deepEqual(actions.at(-1),{action:'select_ticket:104',spoken:true});
manual('ready_item');
assert.deepEqual(actions.at(-1),{action:'ready_item',spoken:false});
currentRecognition.speak('mark item ready');
assert.deepEqual(actions.at(-1),{action:'ready_item',spoken:true});
manual('finish_order');
currentRecognition.speak('finish ticket');
assert.equal(actions.at(-2).action,'finish_order');
assert.equal(actions.at(-1).action,'finish_order');
manual('ingredient_added');
currentRecognition.speak('ingredient added');
assert.equal(actions.at(-2).action,'ingredient_added');
assert.equal(actions.at(-1).action,'ingredient_added');
manual('skip_ingredient');
currentRecognition.speak('skip ingredient');
assert.equal(actions.at(-2).action,'skip_ingredient');
assert.equal(actions.at(-1).action,'skip_ingredient');
manual('item_added');
currentRecognition.speak('note added');
assert.equal(actions.at(-2).action,'item_added');
assert.equal(actions.at(-1).action,'item_added');
assert.equal(ui.normalizePhrase('hold ticket'),'hold_order');
assert.equal(ui.normalizePhrase('fire ticket'),'fire_order');
assert.equal(ui.normalizePhrase('next item'),'next_item');
assert.equal(ui.normalizePhrase('previous step'),'previous');
console.log('glasses-hud-manual-voice-parity=ok');