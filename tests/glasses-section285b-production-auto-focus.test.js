'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const {create,candidateItems,normalize}=require('../assets/js/glasses-production-auto-focus.js');
const repo=path=>fs.readFileSync(path,'utf8');
const sim=repo('assets/js/glasses-web-simulator.js');
const page=repo('glasses-simulator.php');
const voice=repo('assets/js/glasses-simulator-voice.js');
const hash='a'.repeat(64),stamp=10000,devicePublicId='device-1',stationPublicId='station-1';
const obj=(label,x=.12,y=.24,confidence=.96)=>({label,bbox:{x,y,width:.10,height:.08},confidence});
const hand=(handedness='left',x=.11,y=.22)=>({handedness,confidence:.94,bbox:{x,y,width:.14,height:.16}});
const ticket=(id,no,ingredients,status='queued')=>({
 kdsItemPublicId:id,status,menu:{ingredients:ingredients.map(name=>({name}))},
 ticket:{checkPublicId:'check-'+no,checkNumber:no},posLine:{name:'Item '+id}
});
const items=[ticket('pizza-a','104',['Sauce','Mozzarella','Pepperoni']),
 ticket('pizza-b','105',['Sauce','Mozzarella','Basil']),
 ticket('pizza-c','106',['Sauce','Mozzarella','Olives'])];
const frame=(n,overrides={})=>({
 items,objects:[obj('Pepperoni')],hands:[hand()],seq:n,
 objectTime:stamp+n*400,handTime:stamp+n*400,now:stamp+n*400+30,
 modelSha256:hash,devicePublicId,stationPublicId,sourceSlot:1,
 ...overrides
});
assert.equal(normalize('  PEPPERONI ! '),'pepperoni');
assert.equal(candidateItems([...items,ticket('ready','107',['Basil'],'ready')]).length,3);
let e=create(),r;
for(let n=1;n<=3;n++){r=e.update(frame(n));assert.equal(r.status,'candidate');}
r=e.update(frame(4));
assert.equal(r.status,'matched','four independent stable handheld detections select unique KDS item');
assert.equal(r.itemPublicId,'pizza-a');assert.equal(r.ticketNumber,'104');
assert.equal(e.update(frame(4)).reason,'duplicate_frame','same model frame cannot satisfy temporal gate');
assert.equal(e.update(frame(5)).status,'stable','same unchanged item does not reselect repeatedly');
assert.equal(create().update(frame(1,{hands:[]})).status,'waiting','object on counter without hand cannot focus');
assert.equal(create().update(frame(1,{hands:[hand('left',.75,.8)]})).status,'waiting','object far from hand cannot focus');
assert.equal(create().update(frame(1,{hands:[{...hand(),handedness:null}]})).status,'waiting','unknown hand identity cannot be assumed');
assert.equal(create().update(frame(1,{objects:[obj('Sauce')]})).status,'ambiguous','ingredient shared by KDS items cannot identify a ticket');
assert.equal(create().update(frame(1,{objects:[obj('Mozzarella')]})).status,'ambiguous','generic cheese insufficient');
assert.equal(create().update(frame(1,{objects:[obj('Pepperoni'),obj('Basil')]})).status,'ambiguous','two competing ticket items block automatic focus');
assert.equal(create().update(frame(1,{hands:[hand('left'),hand('right',.12,.23)]})).status,'ambiguous','crossing hands must fail closed');
assert.equal(create().update(frame(1,{objects:[obj('Pepperoni',.12,.24,.65)]})).status,'waiting','low confidence cannot count');
assert.equal(create().update(frame(1,{objects:[obj('Chicken')]})).status,'waiting','labels outside the KDS catalog cannot select tickets');
assert.equal(create().update(frame(1,{modelSha256:'unverified'})).status,'waiting','unverified models cannot focus');
assert.equal(create().update(frame(1,{sourceSlot:2})).status,'waiting','stationary cameras not represented as Camera 1');
assert.equal(create().update(frame(1,{stationPublicId:''})).status,'waiting','station assignment required');
assert.equal(create().update(frame(1,{devicePublicId:''})).status,'waiting','paired device context required');
assert.equal(create().update(frame(1,{manualLocked:true})).status,'waiting','manual voice focus wins');
assert.equal(create().update(frame(1,{buildActive:true})).status,'waiting','governed build item pinned');
assert.equal(create().update(frame(1,{objectTime:stamp-1000})).status,'waiting','old object frame must be rejected');
assert.equal(create().update(frame(1,{handTime:stamp-1000})).status,'waiting','stale hand frame must be rejected');
assert.equal(create().update(frame(1,{objectTime:stamp+900})).status,'waiting','object timestamp from future rejected');
assert.equal(create().update(frame(1,{objects:[obj('Pepperoni',-.2,.25)]})).status,'waiting','malformed boxes rejected');
const gap=create();gap.update(frame(1));assert.equal(gap.update(frame(2,{now:stamp+3000,objectTime:stamp+2990,handTime:stamp+2990})).status,'candidate','long gap resets dwell');
const switched=create();switched.update(frame(1));
assert.equal(switched.update(frame(2,{devicePublicId:'device-2'})).frames,1,'new device cannot inherit old temporal evidence');
const stale=create();stale.update(frame(1));assert.equal(stale.update(frame(2,{seq:1})).reason,'duplicate_frame');
const canceled=[ticket('a','104',['Pepperoni'],'completed'),ticket('b','105',['Basil'],'ready')];
assert.equal(create().update(frame(1,{items:canceled,objects:[obj('Pepperoni')]})).reason,'no_ticket_candidates','completed work excluded');
const repeated=[ticket('a','104',['Pepperoni']),ticket('b','105',['Pepperoni'])];
assert.equal(create().update(frame(1,{items:repeated})).status,'ambiguous','identical ingredient across tickets refuses focus');
assert.match(sim,/gelato-glasses-production-object-frame/);
assert.match(sim,/GelatoMediaPipeHandsRuntime\?\.isActive\?\.\(\)/);
assert.match(sim,/resetProductionFocusEvidence\(\)/);
assert.match(sim,/source!=='vision'/);
assert.match(sim,/manualFocusLocked=true/);
assert.match(sim,/if\(result\.status!=='matched'/,'only stable result changes item focus');
assert.match(sim,/focusKitchenItem\(result\.itemPublicId,\{source:'vision'\}\)/);
assert.match(sim,/!state\.build&&!state\.manualFocusLocked/,'production focus must block active build or manual lock');
assert.match(sim,/sourceKind:state\.cameraInput\?\.kind/,'existing camera source provenance preserved');
assert.match(page,/glasses-production-auto-focus\.js\?v=20261014-285b/);
assert.match(page,/glassesAutoFocusStatus/);
assert.doesNotMatch(page,/<button type="button" data-glasses-command="start_order">Start Ticket<\/button>/,'never present legacy mandatory Start Ticket label');
assert.match(voice,/return 'follow_activity'/);
assert.ok(!/fetch\(|XMLHttpRequest|kds\.item\.transition|kds\.ticket\.action/.test(repo('assets/js/glasses-production-auto-focus.js')),
 'focus scoring never performs POS/KDS requests');
console.log('section285b-unique-held-ingredient-focus-safety=ok');
