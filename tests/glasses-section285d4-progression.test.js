'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const {create}=require('../assets/js/glasses-ingredient-progression.js');
let now=10000,index=0,networkCalls=[],checklist=[],fail=false;
const tokenFactory=()=>String(++index).padStart(32,'0');
const item={kdsItemPublicId:'kds-item-9',status:'in_progress',ticket:{checkPublicId:'check-7'},
 menu:{ingredients:[{name:'Sauce'},{name:'Mozzarella',canonicalName:'mozzarella'},{name:'Basil'}]}};
const good=()=>({mode:'live',devicePublicId:'d1',locationId:20,stationPublicId:'s1',
 item,modelSha256:'a'.repeat(64),runtimeBound:true,cameraLive:true,modelReady:true,hidden:false,
 permissions:{glassesView:true,kdsUpdate:true},actions:{}});
const receipt=()=>({id:'local-1',itemPublicId:'kds-item-9',label:'Mozzarella',
 decision:'confirm',operatorConfirmed:true,cameraPlacementVerified:false,ingredientAdded:false,
 kdsChanged:false,reviewedAt:now});
const engine=create({now:()=>now,tokenFactory,
 persist:async p=>{networkCalls.push(p);if(fail)throw Error('network error');
 return {kdsResult:{noted:true,kdsItemPublicId:p.kdsItemPublicId,duplicate:false}};},
 applyChecklist:p=>{checklist.push(p);return {ok:true};}});
(async()=>{
 // Gate 1: no automatic operation after constructing engine
 assert.equal(engine.snapshot().automaticEnabled,false);
 assert.equal(networkCalls.length,0);
 // Gate 2: only a reviewed, explicit D3 operator receipt enters pending
 assert.equal(engine.queue({...receipt(),operatorConfirmed:false},good()).ok,false);
 assert.equal(engine.queue({...receipt(),decision:'reject'},good()).ok,false);
 assert.equal(engine.queue({...receipt(),cameraPlacementVerified:true},good()).ok,false);
 assert.equal(networkCalls.length,0);
 // Gate 3: auth/location and real runtime are required
 for(const changes of [{runtimeBound:false},{cameraLive:false},{hidden:true},
   {permissions:{glassesView:true,kdsUpdate:false}},{stationPublicId:''},{modelReady:false}])
  assert.equal(engine.queue(receipt(),{...good(),...changes}).ok,false);
 // Gate 4: active KDS item only; different ticket or revoked item fails closed
 assert.equal(engine.queue(receipt(),{...good(),item:{...item,status:'queued'}}).ok,false);
 assert.equal(engine.queue({...receipt(),itemPublicId:'another'},good()).ok,false);
 // Gate 5: duplicate ingredient class and accounted ingredient reject
 const same={...item,menu:{ingredients:[{name:'Mozzarella'},{name:'Mozzarella'}]}};
 assert.equal(engine.queue(receipt(),{...good(),item:same}).ok,false);
 assert.equal(engine.queue(receipt(),{...good(),actions:{'1:mozzarella':'added'}}).ok,false);
 // Gate 6: accept unique valid receipt as pending, do not update automatically
 let v=engine.queue(receipt(),good());assert.equal(v.ok,true);
 assert.equal(v.pending.ingredientId,'1:mozzarella');
 assert.equal(networkCalls.length,0);
 assert.equal(checklist.length,0);
 // Gate 7: second simultaneous/duplicate receipt cannot queue
 assert.equal(engine.queue(receipt(),good()).ok,false);
 // Gate 8: new item/model/station invalidates pending approval
 assert.equal((await engine.approve({...good(),stationPublicId:'other'})).ok,false);
 assert.equal(engine.snapshot().pending,null);
 // Gate 9: reject is local and does not call backend or touch checklist
 engine.queue(receipt(),good());assert.equal(engine.reject().ok,true);
 assert.equal(networkCalls.length,0);assert.equal(checklist.length,0);
 // Gate 10: audit errors fail closed and retain idempotency token for retry
 engine.queue(receipt(),good());fail=true;
 let failed=await engine.approve(good());assert.equal(failed.reason,'audit_failed');
 assert.equal(checklist.length,0);assert.ok(engine.snapshot().pending);
 // Gate 11: successful retry uses SAME token and calls server BEFORE checklist
 fail=false;const retryToken=engine.snapshot().pending.token;
 let applied=await engine.approve(good());assert.equal(applied.ok,true);
 assert.equal(networkCalls.at(-1).reviewToken,retryToken);
 assert.equal(checklist.length,1);assert.equal(networkCalls.length,2);
 assert.equal(networkCalls.at(-1).decision,'operator_confirmed');
 // Gate 12: no second identical application, even after double click
 assert.equal((await engine.approve(good())).ok,false);
 assert.equal(checklist.length,1);
 // Gate 13: no KDS status mutation or inferred camera verification
 assert.equal(engine.snapshot().kdsStatusChanged,false);
 assert.equal(engine.snapshot().cameraVerified,false);
 assert.equal(engine.snapshot().audit[0].decision,'operator_confirmed');
 assert.equal(engine.snapshot().audit[0].persisted,true);
 // Gate 14: undo requires same station, selected item, and Added local status
 let undoContext={...good(),actions:{'1:mozzarella':'added'}};
 assert.equal((await engine.undo({...undoContext,stationPublicId:'foreign'})).ok,false);
 assert.equal((await engine.undo({...undoContext,actions:{}})).ok,false);
 assert.equal(networkCalls.length,2);
 // Gate 15: explicit undo audits a new token linked to original before reversal
 let undone=await engine.undo(undoContext);assert.equal(undone.ok,true);
 assert.equal(networkCalls.at(-1).decision,'operator_reversed');
 assert.equal(networkCalls.at(-1).referenceToken,retryToken);
 assert.equal(checklist.at(-1).undo,true);
 assert.equal(engine.snapshot().lastApplied,null);
 // Gate 16: undo cannot replay and is never automatically attempted
 assert.equal((await engine.undo(undoContext)).ok,false);
 assert.equal(networkCalls.length,3);
 // Gate 17: stale review fails
 now+=16000;
 assert.equal(engine.queue(receipt(),good()).ok,true); // fresh updated receipt
 now+=16000;
 assert.equal((await engine.approve(good())).ok,false);
 assert.equal(networkCalls.length,3);
 // Gate 18: guard source paths, UI click, server idempotency and KDS immutability
 const model=fs.readFileSync('assets/js/glasses-ingredient-progression.js','utf8');
 const ui=fs.readFileSync('assets/js/glasses-ingredient-progression-ui.js','utf8');
 const source=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
 const html=fs.readFileSync('glasses-simulator.php','utf8');
 const php=fs.readFileSync('includes/glasses-simulator.php','utf8');
 assert.doesNotMatch(model,/\bfetch\s*\(|getUserMedia|setInterval|setTimeout|localStorage|build\.observe|kds\.item\.transition/);
 assert.match(ui,/glassesProgressionApply/);assert.match(ui,/addEventListener\('click'/);
 assert.match(source,/applyReviewedIngredient:/);assert.match(source,/ingredientProgressionContext:/);
 assert.match(html,/glasses-ingredient-progression-ui\.js/);
 assert.match(php,/glasses_ingredient_review/);assert.match(php,/kds_transaction/);
 assert.match(php,/operational_location_allowed/);assert.match(php,/kds_order_events/);
 assert.match(php,/reviewToken/);assert.match(php,/cameraVerified'=>false/);
 assert.equal(item.status,'in_progress');
 console.log('285d4-governed-progression-acceptance=18/18');
})().catch(e=>{console.error(e);process.exitCode=1;});
