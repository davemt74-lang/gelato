'use strict';
const assert=require('node:assert/strict');
const {resolve,key}=require('../js/agent-eyes-speaker.js');
const {chromium}=require('playwright');
const trackA='r-'+ 'a'.repeat(16),trackB='r-'+ 'b'.repeat(16);
const person=(id,trackId,name)=>({trackId,personId:id,displayName:name,identitySource:'local_face_profile',identityAssurance:'provisional_visual_match'});
const speaker=(id,trackId,handoff=false)=>({personId:id,trackId,identityAssurance:'correlated_voice_and_visual_observation',websiteAuthenticated:false,agentAdministration:false,handoffFromPreviousVerifiedTurn:handoff});
const room={status:'active',people:[person(12,trackA,'Cook A'),person(13,trackB,'Cook B')],currentSpeaker:speaker(12,trackA)};
let n=0;
function check(value,label){assert.ok(value,label);n++;}
check(resolve(room)?.message.includes('Speaking · Cook A'),'correlated verified event highlighted');
check(resolve({...room,currentSpeaker:null})===null,'camera-only observation is silent');
check(resolve({...room,currentSpeaker:{...room.currentSpeaker,identityAssurance:'provisional_visual_match'}})===null,'visual-only speaker spoof refused');
check(resolve({...room,currentSpeaker:{...room.currentSpeaker,websiteAuthenticated:true}})===null,'authority confusion refused');
check(resolve({...room,currentSpeaker:speaker(12,trackB)})===null,'mismatched track refuses attribution');
check(resolve({...room,people:[room.people[0],room.people[0]]})===null,'duplicate face claim ambiguous');
check(resolve({...room,people:[room.people[1]]})===null,'exited person not attributed');
check(resolve({...room,status:'not_connected'})===null,'camera stop clears speaker');
check(resolve({...room,currentSpeaker:speaker(13,trackB,true)})?.message.startsWith('Speaker changed'),'independent verified handoff indicated');
check(JSON.stringify(key(room))!==JSON.stringify(key({...room,currentSpeaker:speaker(13,trackB,true)})),'handoff changes Brain context event key');
check(key({...room,currentSpeaker:null})===null,'no stale Brain key');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  await page.route('http://localhost/**',route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><html><head></head><body></body></html>'}));
  await page.goto('http://localhost/');
  const fs=require('node:fs'),path=require('node:path');
  await page.addScriptTag({content:fs.readFileSync(path.join(__dirname,'../js/agent-eyes-speaker.js'),'utf8')});
  const browserResult=await page.evaluate(room=>window.GelatoEyesSpeaker.resolve(room),room);
  check(browserResult?.name==='Cook A','real browser resolves speaker name');
  check(browserResult?.message.includes('provisional visual match'),'uncertainty visible in browser');
  console.log('Agent Eyes AE3 voice + visual display PASS ('+n+' checks)');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
