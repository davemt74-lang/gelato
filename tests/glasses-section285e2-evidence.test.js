'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const {create}=require('../assets/js/glasses-hand-to-food-evidence.js');
const box=(x,y)=>({x,y,width:.1,height:.1});
const context=()=>({stationPublicId:'stationA',ticketPublicId:'ticket1',itemPublicId:'item1',
 cameraSlot:1,sourceId:'usb',generation:1,modelSha256:'a'.repeat(64),
 zone:{x:.55,y:.5,width:.4,height:.4},expectedIngredients:['Mozzarella']});
const hand=(x,y)=>({trackId:'handA',status:'tracking',confidence:.9,bbox:box(x,y)});
const obj=(x,y)=>({label:'Mozzarella',confidence:.95,bbox:box(x,y)});
test('held to zone to release produces review hypothesis but no automation',()=>{
 let t=1000,g=create({now:()=>t}),c=context(),f=(h,o)=>g.observe({context:c,frame:{seq:Math.round((t-1000)/100),at:t,hands:[h],objects:o?[o]:[]}});
 for(let i=0;i<3;i++){f(hand(.12,.15),obj(.12,.15));t+=100;}
 f(hand(.65,.65),obj(.65,.65));t+=100;
 assert.equal(f(hand(.65,.65),null).suggestions.length,0);t+=100;
 const out=f(hand(.65,.65),null);assert.equal(out.suggestions.length,1);
 assert.equal(out.suggestions[0].reviewRequired,true);
 assert.equal(out.suggestions[0].verifiedPlacement,false);
 assert.equal(out.kdsChanged,false);
});
test('no initial held ingredient means no suggestion',()=>{
 let t=1000,g=create({now:()=>t}),c=context();
 for(let i=0;i<6;i++){assert.equal(g.observe({context:c,frame:{seq:i,at:t,hands:[hand(.65,.65)],objects:[]}}).suggestions.length,0);t+=100;}
});
test('wrong ingredient and camera scope change invalidate history',()=>{
 let t=1000,g=create({now:()=>t}),c=context();
 for(let i=0;i<3;i++){g.observe({context:c,frame:{seq:i,at:t,hands:[hand(.12,.15)],objects:[{...obj(.12,.15),label:'Sauce'}]}});t+=100;}
 assert.equal(g.snapshot().length,0);
 c={...c,sourceId:'reconnected'};
 assert.equal(g.observe({context:c,frame:{seq:0,at:t,hands:[hand(.65,.65)],objects:[]}}).suggestions.length,0);
});
test('stale or duplicate frames are rejected',()=>{let t=1000,g=create({now:()=>t}),c=context();
 const f={seq:0,at:t,hands:[],objects:[]};assert.equal(g.observe({context:c,frame:f}).accepted,true);
 assert.equal(g.observe({context:c,frame:f}).reason,'replay');
 t=3000;assert.equal(g.observe({context:c,frame:{...f,seq:1}}).reason,'stale_frame');
});
test('invalid geometry or unqualified model fails closed',()=>{let g=create(),c=context();
 c.zone={x:-1,y:0,width:1,height:1};
 assert.equal(g.observe({context:c,frame:{}}).reason,'invalid_scope');
 c=context();c.modelSha256='';
 assert.equal(g.observe({context:c,frame:{}}).reason,'invalid_scope');
});
