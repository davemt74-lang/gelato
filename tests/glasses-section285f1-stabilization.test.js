'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const {create}=require('../assets/js/glasses-hand-box-stabilizer.js');
const make=(slot,id,x,seen,status='tracking')=>({slot,trackId:id,status,seen,bbox:{x,y:.2,width:.12,height:.18}});
test('duplicate track identity cannot draw two hand overlays',()=>{
 const g=create();
 assert.deepEqual(g.step([make(1,'same',.1,1000),make(2,'same',.7,1000)],1000),[]);
 assert.equal(g.step([make(1,'left',.1,1100),make(2,'right',.7,1100)],1100).length,2);
});
test('identity swap changes anchors rather than drawing interpolation ghosts',()=>{
 const g=create();
 g.step([make(1,'a',.1,1000),make(2,'b',.7,1000)],1000);
 const next=g.step([make(1,'b',.7,1100),make(2,'a',.1,1100)],1100);
 assert.equal(next[0].bbox.x,.7);assert.equal(next[1].bbox.x,.1);
});
test('teleport responds immediately and ordinary jitter remains smoothed',()=>{
 const g=create();g.step([make(1,'a',.1,1000)],1000);
 const jitter=g.step([make(1,'a',.12,1100)],1100);
 assert.ok(jitter[0].bbox.x>.1&&jitter[0].bbox.x<.12);
 assert.equal(g.step([make(1,'a',.75,1200)],1200)[0].bbox.x,.75);
});
test('held overlay expires and never serves as physical evidence',()=>{
 const g=create({holdMs:150});g.step([make(1,'a',.1,1000)],1000);
 const pending=g.step([{slot:1,trackId:'a',status:'occluded',seen:1000}],1100);
 assert.equal(pending[0].status,'reacquiring');assert.equal(pending[0].held,true);
 assert.deepEqual(g.step([{slot:1,trackId:'a',status:'occluded',seen:1000}],1151),[]);
 assert.doesNotMatch(fs.readFileSync('assets/js/glasses-hand-box-stabilizer.js','utf8'),/\bfetch\s*\(|build\.observe|kds\.item\.transition/);
});
