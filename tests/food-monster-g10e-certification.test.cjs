'use strict';
const assert=require('node:assert/strict');
const tracker=require('../js/food-monster-room-tracker.js');
let current=0,sequence=0;
const make=()=>tracker.create({now:()=>current,id:()=>('r-'+String(++sequence).padStart(16,'0'))});
const vector=seed=>Array.from({length:128},(_,i)=>seed+i*.000001);
const profiles=Array.from({length:8},(_,i)=>({email:'person'+i+'@example.test',vector:vector(.1+i*.09)}));
const observations=()=>profiles.map((p,i)=>({box:[.02+i*.12,.2,.08,.14],descriptor:p.vector}));
const room=make(),elapsed=process.hrtime.bigint();
for(let frame=0;frame<250;frame++){
 current+=1300;
 const frameData=frame%2===0?observations():observations().reverse();
 const results=room.update(frameData,profiles);
 assert.equal(results.length,8,'eight participant frames remain bounded');
 assert.equal(room.trackCount,8,'track collection remains bounded');
 assert.ok(results.every(x=>!('descriptor' in x)&&!('voiceEventId' in x)&&!('currentSpeaker' in x)),
   'tracker output never leaks descriptors or infers speakers');
 if(frame>=2){
  for(let i=0;i<8;i++){
   const observed=frameData[i].descriptor;
   const profile=profiles.find(p=>p.vector===observed);
   assert.equal(results[i].localProfileEmail,profile.email,'stable per-person association across reordered observations');
  }
 }
}
const durationMs=Number(process.hrtime.bigint()-elapsed)/1e6;
assert.ok(durationMs<5000,'bounded eight-person assignment stays within generous CI budget: '+durationMs+'ms');
current+=6000;
assert.equal(room.update([],profiles).length,0,'expired presence no longer appears');
assert.equal(room.trackCount,0,'expired descriptor references are dropped');
room.clear();
assert.equal(room.trackCount,0,'stop erases tracking state');
console.log('FM-G10E 8-person reliability and privacy PASS (250 frames; '+durationMs.toFixed(1)+'ms)');
