'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs');
const src=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const voice=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
test('next item stays within the active ticket and never wraps',()=>{
 const start=src.indexOf("if(action==='next_item'||action==='previous_item')");
 const end=src.indexOf("if(action==='skip_ingredient')",start);
 assert.ok(start>0&&end>start);
 const slice=src.slice(start,end);
 assert.match(slice,/if\(index<0\)/);
 assert.match(slice,/if\(target<0\|\|target>=group\.items\.length\)/);
 assert.match(slice,/Last item on this ticket/);
 assert.match(slice,/Next Ticket/);
 assert.doesNotMatch(slice,/%group\.items\.length/);
});
test('voice commands and finish-ticket safeguards remain in existing runtime',()=>{
 assert.match(voice,/next_ticket/);
 assert.match(voice,/next_item/);
 assert.match(voice,/skip_ingredient/);
 assert.match(src,/function ticketCompletionCandidate/);
 assert.match(src,/confirmation\.expires/);
 assert.match(src,/All ticket items must be marked Ready/);
 assert.match(src,/Choose All stations \/ Expo/);
});
test('no recognition event can directly complete a ticket',()=>{
 const start=src.indexOf("window.addEventListener('gelato-glasses-hand-frame'");
 const end=src.indexOf("window.GelatoGlassesNavigation=",start);
 assert.ok(start>=0&&end>start);
 const slice=src.slice(start,end);
 assert.doesNotMatch(slice,/kds\.ticket\.action|ticketAction:'finish'|kds\.item\.transition/);
});
