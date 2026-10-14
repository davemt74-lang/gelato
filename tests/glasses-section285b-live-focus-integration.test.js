'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const factory=require('../assets/js/glasses-production-auto-focus.js');
const app=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const first=app.indexOf('const productionAutoFocus=window.GelatoProductionAutoFocus');
const last=app.indexOf('const rejectedAssemblyTracks',first);
const eventsStart=app.indexOf('// Observe existing MediaPipe hand frames');
const eventsEnd=app.indexOf('window.GelatoGlassesNavigation=',eventsStart);
assert.ok(first>0&&last>first&&eventsStart>0&&eventsEnd>eventsStart);
const listeners={},notes=[],focuses=[],modelSha256='a'.repeat(64);
let clock=10000;
class FakeDate extends Date{static now(){return clock;}}
const items=[
 {kdsItemPublicId:'a',status:'queued',ticket:{checkPublicId:'104',checkNumber:'104'},
  menu:{ingredients:[{name:'Sauce'},{name:'Pepperoni'}]}},
 {kdsItemPublicId:'b',status:'in_progress',ticket:{checkPublicId:'105',checkNumber:'105'},
  menu:{ingredients:[{name:'Sauce'},{name:'Basil'}]}}
];
let selected='b';
const state={mode:'live',device:{publicId:'device1',stationPublicId:'station1'},
 work:{items},cameraStream:{},build:null,manualFocusLocked:false,cookUx:{stopped:false},
 recovery:{state:'ready',suppressAutomatic:false}};
const model={status:'ready',verifiedSha256:modelSha256};
const win={
 GelatoProductionAutoFocus:factory,
 GelatoGlassesRuntimes:{binding:()=>({publicId:'runtime1'})},
 GelatoMediaPipeHandsRuntime:{isActive:()=>true},
 addEventListener(name,fn){listeners[name]=fn;}
};
const env={window:win,state,cfg:{runtimePublicId:'runtime1'},readonlyIngredient:model,
 document:{hidden:false,addEventListener(name,fn){listeners['doc:'+name]=fn;}},
 $:id=>id==='glassesAutoFocusStatus'?{set textContent(v){notes.push(v)},dataset:{}}:null,
 selectedItem:()=>items.find(x=>x.kdsItemPublicId===selected),Date:FakeDate,
 focusKitchenItem:async(id,opts)=>{focuses.push({id,source:opts.source});selected=id;return {ok:true}},
 log:()=>{},CustomEvent:class{constructor(name,args){this.type=name;this.detail=args?.detail}},
 Number,String,Math};
vm.runInNewContext(app.slice(first,last)+app.slice(eventsStart,eventsEnd)+
 '\nthis.clear=resetProductionFocusEvidence;this.eligible=productionFocusEligible;',env);
assert.equal(env.eligible(),true);
function emit(seq,{label='Pepperoni',confidence=.97,devicePublicId='device1',stationPublicId='station1',hash=modelSha256}={}){
 clock=10000+seq*400;
 listeners['gelato-glasses-hand-frame']({detail:{cameraReady:true,modelReady:true,timestamp:clock,
   hands:[{handedness:'left',confidence:.9,bbox:{x:.11,y:.20,width:.14,height:.16}}]}});
 listeners['gelato-glasses-production-object-frame']({detail:{cameraSlot:1,
   devicePublicId,stationPublicId,modelSha256:hash,seq,timestamp:clock,
   objects:[{label,confidence,bbox:{x:.12,y:.23,width:.10,height:.09}}]}});
}
(async()=>{
 for(let i=1;i<=3;i++){emit(i);assert.equal(focuses.length,0,'no early visual focus');}
 emit(4);await Promise.resolve();assert.equal(focuses.length,1);
 assert.deepEqual(focuses[0],{id:'a',source:'vision'},'real stable event selects the unique matching KDS item view only');
 assert.equal(items[0].status,'queued','no KDS status changes');
 assert.equal(items[1].status,'in_progress','other ticket unchanged');
 selected='b';state.manualFocusLocked=true;env.clear();
 for(let i=10;i<=14;i++)emit(i);
 assert.equal(focuses.length,1,'manual lock wins');
 state.manualFocusLocked=false;state.build={kdsItemPublicId:'b'};env.clear();
 for(let i=20;i<=24;i++)emit(i);
 assert.equal(focuses.length,1,'governed build pins its original item');
 state.build=null;state.recovery.state='blocked';env.clear();
 for(let i=30;i<=34;i++)emit(i);
 assert.equal(focuses.length,1,'recovery blocked suppresses auto-focus');
 state.recovery.state='ready';env.clear();
 for(let i=40;i<=44;i++)emit(i,{label:'Sauce'});
 assert.equal(focuses.length,1,'shared ingredient never selects ticket');
 for(let i=50;i<=54;i++)emit(i,{hash:'invalid'});
 assert.equal(focuses.length,1,'unverified model never selects ticket');
 assert.ok(notes.some(x=>/Recognized hand-held pepperoni/.test(x)));
 console.log('section285b-live-camera-event-focus-integration=ok');
})().catch(e=>{console.error(e);process.exitCode=1;});
