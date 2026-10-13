'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const src=fs.readFileSync('js/kds-spoken-orders.js','utf8');
const ctx={window:{},console};vm.runInNewContext(src,ctx,{filename:'kds-spoken-orders.js'});
const voice=ctx.window.GelatoKdsSpeech;
assert.ok(voice,'natural KDS speech formatter installed');
const ticket={checkNumber:'92177',serviceMode:'takeout',items:[
  {quantity:2,item_name_snapshot:'Breakfast Pizza',option_name_snapshot:'12 inch',special_instructions:'no onions, add bacon'},
  {quantity:1,item_name_snapshot:'Calzone',special_instructions:'ricotta on the side'}
]};
const read=voice.ticket(ticket,12);
assert.match(read,/Order 12, ticket 92177/);
assert.match(read,/two Breakfast Pizza/);
assert.match(read,/12 inch/);
assert.match(read,/no onions, add bacon/);
assert.match(read,/one Calzone/);
assert.match(read,/ricotta on the side/);
assert.match(voice.ticket(ticket,12,{update:true,items:ticket.items.slice(0,1)}),/Update for Order 12/);
assert.doesNotMatch(voice.ticket(ticket,12,{update:true,items:ticket.items.slice(0,1)}),/Calzone/);
assert.match(voice.ticket({...ticket,late:true},12),/running late/);
assert.match(voice.ticket({...ticket,readyToBump:true},12),/ready for pickup/);
assert.match(voice.item({quantity:27,item_name_snapshot:'Coffee'}),/27 Coffee/);
assert.match(voice.item({quantity:1.5,item_name_snapshot:'Pizza'}),/1.5 Pizza/);
assert.match(voice.all([ticket,ticket]),/Order 2, ticket 92177/);
assert.match(voice.all([]),/no active kitchen orders/);
assert.equal(typeof voice.ticket,'function');
console.log('kds-natural-order-voice-js=ok');
