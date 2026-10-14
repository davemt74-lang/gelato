'use strict';
const assert=require('node:assert/strict');const {test}=require('node:test');
const {create,analyzeRgba,validBox}=require('../assets/js/glasses-camera-calibration-gate.js');
const baseline=(at=1000,override={})=>({scope:'station:1:ticket:42',sourceId:'usb-1',generation:3,at,
 brightness:120,contrast:35,detail:12,drift:.01,...override});
test('calibration requires sufficient samples and never authorizes ingredient actions',()=>{
 let t=1000,gate=create({now:()=>t});for(let i=0;i<8;i++){t=1000+i*100;assert.equal(gate.sample(1,baseline(t)).ok,true);}
 const state=gate.status(1);assert.equal(state.ready,true);assert.equal(state.canAdvanceIngredient,false);
 assert.equal(state.fieldAccuracyCertified,false);assert.equal(state.advisoryOnly,true);
});
test('reject invalid slots, malformed metrics and camera timestamps',()=>{
 let t=2000,g=create({now:()=>t});
 assert.equal(g.sample(6,baseline(t)).reason,'invalid_camera');
 assert.equal(g.sample(1,baseline(t,{brightness:NaN})).reason,'invalid_metrics');
 assert.equal(g.sample(1,baseline(t,{roi:{x:-.1,y:0,width:.5,height:.5}})).reason,'invalid_roi');
 assert.equal(g.sample(1,baseline(-900)).reason,'stale_frame');
 assert.equal(g.sample(1,baseline(t+1000)).reason,'stale_frame');
});
test('camera slots are isolated',()=>{let t=1000,g=create({now:()=>t});
 for(let i=0;i<8;i++){t+=100;g.sample(1,baseline(t));}
 assert.equal(g.status(1).ready,true);assert.equal(g.status(2).ready,false);});
test('source, station, generation, and ROI changes invalidate prior calibration',()=>{
 let t=1000,g=create({now:()=>t});
 for(let i=0;i<8;i++){t+=100;g.sample(1,baseline(t));}
 assert.equal(g.status(1).ready,true);
 t+=100;g.sample(1,baseline(t,{sourceId:'replacement'}));
 assert.equal(g.status(1).samples,1);
 t+=100;g.sample(1,baseline(t,{generation:4}));
 assert.equal(g.status(1).samples,1);
 t+=100;g.sample(1,baseline(t,{scope:'station:2'}));
 assert.equal(g.status(1).samples,1);
 t+=100;g.sample(1,baseline(t,{scope:'station:2',roi:{x:0,y:0,width:.5,height:.5}}));
 t+=100;g.sample(1,baseline(t,{scope:'station:2',roi:{x:.1,y:0,width:.5,height:.5}}));
 assert.equal(g.status(1).samples,1);
});
test('stale frames and reorders cannot refresh camera status',()=>{let t=1000,g=create({now:()=>t});
 g.sample(1,baseline(t));assert.equal(g.sample(1,baseline(t)).reason,'duplicate_or_reordered');
 t=4000;assert.equal(g.status(1).reasonCodes.includes('stale_camera'),true);
 assert.equal(g.sample(1,baseline(1000)).reason,'stale_frame');
});
test('exposure, contrast, detail and motion create independent advisory reasons',()=>{
 let t=1000,g=create({now:()=>t});
 for(let i=0;i<8;i++){t+=100;g.sample(2,baseline(t,{brightness:20,contrast:4,detail:2,drift:.5}));}
 assert.deepEqual(g.status(2).reasonCodes.filter(x=>['underexposed','low_contrast','low_detail','unstable_view'].includes(x)),
 ['underexposed','low_contrast','low_detail','unstable_view']);
});
test('large frame gaps invalidate prior sample window',()=>{let t=1000,g=create({now:()=>t});
 for(let i=0;i<8;i++){t+=100;g.sample(1,baseline(t));}t+=2000;
 g.sample(1,baseline(t));assert.equal(g.status(1).samples,1);
});
test('RGBA extraction rejects malformed frames and returns finite statistics',()=>{
 assert.throws(()=>analyzeRgba([],4,4),TypeError);
 let frame=new Uint8ClampedArray(8*8*4);for(let i=0;i<64;i++){frame[4*i]=i*4;frame[4*i+1]=i*4;frame[4*i+2]=i*4;frame[4*i+3]=255;}
 const s=analyzeRgba(frame,8,8);assert.ok(s.brightness>0&&s.contrast>0&&s.detail>0);
 assert.equal(validBox({x:0,y:0,width:1,height:1}),true);
});
test('clear revokes calibration without affecting other cameras',()=>{let t=1000,g=create({now:()=>t});
 g.sample(1,baseline(t));g.sample(2,baseline(t));g.clear(1);
 assert.equal(g.status(1).samples,0);assert.equal(g.status(2).samples,1);g.clear();assert.equal(g.status(2).samples,0);
});
