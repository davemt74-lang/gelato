'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const ui=fs.readFileSync('glasses-simulator.php','utf8');
const hand=fs.readFileSync('assets/js/glasses-held-ingredient-evidence.js','utf8');
const start=code.indexOf('async function unloadReadonlyIngredientModel(');
const end=code.indexOf('function setShadowStatus(',start);
assert.ok(start>0&&end>start,'independent read-only inference functions exist');
const events=[],timers=[],model='a'.repeat(64);
const document={hidden:false};
const elements={
 glassesReadonlyDetector:{value:'food-detector'},
 glassesReadonlyModelStatus:{textContent:'',dataset:{}},
 glassesReadonlyModelLoad:{disabled:false},
 glassesReadonlyModelUnload:{disabled:true},
 cameraVideo:{videoWidth:1280,videoHeight:720}
};
const $=id=>elements[id];
const item={kdsItemPublicId:'item-one',status:'in_progress',
 menu:{ingredients:[{name:'Sauce'},{name:'Mozzarella'}]}};
let focus=item,sessions=0,inference=0;
const state={cameraStream:{},device:{publicId:'preview:1:all'},mode:'live'};
const readonlyIngredient={status:'idle',session:null,config:null,package:null,verifiedSha256:null,
 epoch:0,timer:null,busy:false,frameSeq:0,devicePublicId:''};
const window={
 GelatoMediaPipeHandsRuntime:{active:true,isActive:()=>true,setObjects:(objects,meta)=>events.push({objects,meta})},
 GelatoHeldIngredientEvidence:{choices:()=>[{canonical:'mozzarella'}],normalize:v=>String(v).toLowerCase()}
};
const validPkg={runtimeType:'onnx',platform:'browser_preview',status:'ready',
 modelName:'Ingredient Model',modelVersion:'1.0',
 metadata:{browserInference:{schema:'gelato.browser_onnx_detector.v1'}}};
const context={readonlyIngredient,state,window,document,$,
 selectedItem:()=>focus,itemActions:()=>({}),log:()=>{},
 setTimeout:fn=>{timers.push(fn);return timers.length},clearTimeout:()=>{},
 api:async(name,data)=>{
   assert.equal(name,'vision.ingredient_readonly_assignment');
   assert.equal(data.detectorName,'food-detector');
   return {assignment:{mode:'read_only',action:'apply',
     compatibility:{compatible:true},package:validPkg}};
 },
 createVerifiedOnnxSession:async()=>({
   session:{release:async()=>{sessions++}},config:{input:{},output:{}},digest:model
 }),
 inferOnnxModel:async(_model,_frame,opts)=>{
   inference++;assert.equal(opts.readonly,true);
   return [{label:'Mozzarella',confidence:.92,bbox:{x:.2,y:.2,width:.1,height:.1}},
     {label:'Not an ingredient',confidence:.98,bbox:{x:.5,y:.6,width:.1,height:.1}}];
 },
 Date,Number,String,Object,Set,Promise,Error
};
vm.runInNewContext(code.slice(start,end)+
 '\nthis.runLoad=loadReadonlyIngredientModel;this.runPoll=pollReadonlyIngredientFrame;this.runUnload=unloadReadonlyIngredientModel;',context);
(async()=>{
 await context.runLoad();
 assert.equal(readonlyIngredient.status,'ready');
 assert.equal(readonlyIngredient.devicePublicId,'preview:1:all');
 assert.equal(elements.glassesReadonlyModelUnload.disabled,false);
 assert.equal(readonlyIngredient.verifiedSha256,model);
 assert.ok(timers.length>0,'read-only inference scheduled after model verification');
 await context.runPoll(readonlyIngredient.epoch);
 assert.equal(inference,1);
 assert.equal(events.at(-1).objects.length,1,'only KDS-scoped configured ingredients emitted');
 assert.equal(events.at(-1).objects[0].label,'Mozzarella');
 assert.equal(events.at(-1).meta.modelSha256,model);
 focus={...item,status:'completed'};
 await context.runPoll(readonlyIngredient.epoch);
 assert.equal(inference,1,'completed KDS item cannot drive inference');
 focus=item;
 state.device.publicId='preview:2:all';
 await context.runPoll(readonlyIngredient.epoch);
 assert.equal(readonlyIngredient.status,'idle','device changes revoke session');
 assert.equal(sessions,1);
 state.device.publicId='preview:1:all';
 await context.runLoad();
 document.hidden=true;
 await context.runPoll(readonlyIngredient.epoch);
 assert.equal(inference,1,'hidden camera cannot perform inference');
 document.hidden=false;
 let finish;
 context.inferOnnxModel=()=>new Promise(resolve=>{finish=resolve});
 const framePromise=context.runPoll(readonlyIngredient.epoch);
 focus={...item,kdsItemPublicId:'item-two'};
 if(typeof finish==='function')finish([{label:'Mozzarella',confidence:.91}]);
 await framePromise;
 assert.equal(events.at(-1).objects.length,0,'changed item cannot create held-object evidence');
 await context.runUnload();
 assert.equal(readonlyIngredient.status,'idle');
 assert.equal(sessions,2);
 assert.match(code,/if\(readonly\)return raw/);
 assert.match(code,/if\(readonlyIngredient\.status!=='ready'\)/);
 assert.match(code,/window\.GelatoMediaPipeHandsRuntime\.setObjects\(focused/);
 assert.match(code,/modelSha256:readonlyIngredient\.verifiedSha256/);
 assert.match(ui,/glassesReadonlyModelLoad/);
 assert.match(ui,/glasses-vision-models\.php/);
 assert.match(hand,/minFrames=3/);
 console.log('glasses-readonly-ingredient-inference=ok');
})().catch(e=>{console.error(e);process.exitCode=1});