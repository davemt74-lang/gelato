'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const {create}=require('../assets/js/glasses-ingredient-progression.js');
const item=()=>({kdsItemPublicId:'item',status:'in_progress',ticket:{checkPublicId:'ticket1'},menu:{ingredients:[{name:'Cheese'}]}});
const context=()=>({mode:'live',runtimeBound:true,cameraLive:true,modelReady:true,hidden:false,
 permissions:{kdsUpdate:true,glassesView:true},devicePublicId:'device',stationPublicId:'station',locationId:1,
 modelSha256:'a'.repeat(64),cameraSourceId:'trackA',cameraStreamId:'streamA',item:item(),actions:{}});
const receipt=()=>({id:'receipt',decision:'confirm',operatorConfirmed:true,cameraPlacementVerified:false,
 ingredientAdded:false,kdsChanged:false,reviewedAt:1000,itemPublicId:'item',label:'Cheese'});
for(const dimension of ['cameraSourceId','cameraStreamId','item']){
 test('invalidates pending approval before audit when '+dimension+' changes',async()=>{
  let audit=0,applied=0,g=create({now:()=>1000,tokenFactory:()=> 'a'.repeat(32),
   persist:async()=>{audit++;return {kdsResult:{noted:true,kdsItemPublicId:'item'}};},
   applyChecklist:()=>{applied++;return {ok:true};}});
  assert.equal(g.queue(receipt(),context()).ok,true);
  const changed={...context(),[dimension]:dimension==='item'?{...item(),ticket:{checkPublicId:'ticket2'}}:'changed'};
  assert.equal((await g.approve(changed)).ok,false);assert.equal(audit,0);assert.equal(applied,0);
 });
 test('rejects stale local mutation after persisted audit when '+dimension+' changes',async()=>{
  let live=context(),applied=0,g=create({now:()=>1000,tokenFactory:()=> 'b'.repeat(32),
   currentContext:()=>live,
   persist:async()=>{live={...live,[dimension]:dimension==='item'?{...item(),ticket:{checkPublicId:'ticket2'}}:'changed'};
    return {kdsResult:{noted:true,kdsItemPublicId:'item'}};},
   applyChecklist:()=>{applied++;return {ok:true};}});
  assert.equal(g.queue(receipt(),context()).ok,true);
  const result=await g.approve(context());assert.equal(result.reason,'audited_but_context_revoked');assert.equal(applied,0);
 });
}
test('runtime supplies source identity to audited progression',()=>{
 const s=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
 assert.match(s,/cameraSourceId:String\(state\.cameraStream/);
 assert.match(s,/cameraStreamId:String\(state\.cameraStream/);
});
