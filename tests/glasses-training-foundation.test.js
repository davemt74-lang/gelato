'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const web=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const php=fs.readFileSync('glasses-simulator.php','utf8');
const camera=fs.readFileSync('assets/js/glasses-multicamera.js','utf8');
const presence=fs.readFileSync('js/voice-session-presence.js','utf8');
const tabs=fs.readFileSync('assets/js/glasses-simulator-tabs.js','utf8');
const css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8');
assert.ok(!php.includes('Live KDS preview · add a scene image'),'placeholder must be removed');
assert.match(php,/id="scenePlaceholder"\s+aria-hidden="true"/,'blank scene placeholder retains camera fallback');
assert.match(php,/id="rightWidth"/,'independent right-side HUD width slider');
assert.match(web,/calibration\.regions\.hudRight\.w/,'width acts on actual calibrated region');
assert.match(css,/\.glasses-stage \.hud-right-build/,'right content renders in actual calibrated region');
assert.match(css,/var\(--region-w/,'right width affects actual rendered element');
assert.match(php,/id="simTicketTimeSync"/,'POS timer freshness visible');
assert.match(web,/posOpenAgeSeconds/,'POS timestamp is authoritative');
assert.match(camera,/const MAX=5,AUX=\[2,3,4,5\]/,'five camera total limit');
assert.match(camera,/getUserMedia\(\{video:.*audio:false\}\)/,'auxiliary camera starts only video');
assert.match(camera,/if\(document\.hidden\)stopAll/,'hidden tab must stop auxiliary capture');
assert.match(camera,/acceptReviewedAuxiliaryFrame/,'auxiliary frames go through reviewed sample pathway');
assert.ok(!camera.includes('build.observe'),'aux camera must not create live ingredient verification');
assert.ok(!camera.includes('kds.item.transition'),'aux camera must not mutate KDS');
assert.ok(!presence.includes('getUserMedia'),'status must not activate capture');
assert.ok(!presence.includes('SpeechRecognition('),'presence is metadata-only');
assert.ok(!tabs.includes("const target=$('simVoiceCertification')"),'voice summary cannot early-return on missing target');
assert.match(tabs,/gelato-voice-presence-change/,'Overview is synchronized across tabs');
assert.match(tabs,/gelato-glasses-voice-device-change/,'Overview receives local mic lifecycle');
assert.match(php,/js\/voice-session-presence\.js/,'session presence starts in glasses simulator');

const start=web.indexOf('function ticketElapsedSeconds(');
const end=web.indexOf('function renderTopStatus()',start);
assert.ok(start>0&&end>start,'timer helper must exist');
let clock=1000;
const span={textContent:''};
const nodes={hudOrders:{querySelectorAll(){return [span];}},simTicketTimeSync:{textContent:''}};
const item={timing:{posOpenAgeSeconds:126}};
const state={mode:'live',ticketSyncAt:1000,work:{items:[item]}};
const ctx={state,Math,Number,String,Date,performance:{now:()=>clock},document:{hidden:false},
  orderItems:()=>state.work.items,$:id=>nodes[id]};
vm.runInNewContext(web.slice(start,end)+'\nthis.getElapsed=elapsedLabel;this.tick=tickLiveTicketTimers;',ctx);
assert.equal(ctx.getElapsed(item,0),'02:06','POS ticket uses DB elapsed seconds');
clock=3000;ctx.tick();
assert.equal(span.textContent,'02:08','clock ticks from monotonic elapsed time');
assert.equal(nodes.simTicketTimeSync.textContent,'POS TIME · SYNCED');
clock=35000;ctx.tick();
assert.equal(nodes.simTicketTimeSync.textContent,'POS TIME · STALE','loss of sync must be visible');
assert.equal(ctx.getElapsed({},0),'--:--','no fabricated ticket age');
state.mode='mock';assert.equal(ctx.getElapsed({},0),'DEMO','mock never claims POS time');

let channel,receivedEvents=[];
class Channel {
  constructor(name){this.name=name;channel=this;}
  postMessage(data){this.lastMessage=data;}
}
const page={addEventListener(){},dispatchEvent(ev){receivedEvents.push(ev);}};
vm.runInNewContext(presence,{
  window:page,BroadcastChannel:Channel,CustomEvent:class{constructor(name,data){this.type=name;this.detail=data.detail;}},
  setInterval(){},Set,Map,Math,Date,String
});
const api=page.GelatoVoicePresence;
api.report('glasses',true,'active');
assert.equal(api.snapshot().local.active,true);
assert.equal(channel.lastMessage.active,true);
channel.onmessage({data:{v:1,id:'remote-browser-tab',source:'global-agent',active:true,mode:'active'}});
assert.equal(api.snapshot().remote.length,1);
assert.equal(api.snapshot().remote[0].source,'global-agent');
api.report('glasses',false,'off');
assert.equal(api.snapshot().local.active,false);
assert.equal(receivedEvents.at(-1).type,'gelato-voice-presence-change');
console.log('glasses-training-foundation-and-voice-presence=ok');
