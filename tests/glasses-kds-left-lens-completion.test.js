'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const page=fs.readFileSync('glasses-simulator.php','utf8');
const source=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const voice=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
const css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8');
const server=fs.readFileSync('includes/glasses-simulator.php','utf8');
const kds=fs.readFileSync('includes/kds-production.php','utf8');
const from=source.indexOf('function ticketCompletionCandidate('),
      to=source.indexOf('function renderOrdersQueue(',from);
assert.ok(from>0&&to>from,'contextual KDS completion state machine must exist');
const items=[
 {kdsItemPublicId:'a',status:'in_progress',ticket:{checkPublicId:'abc',checkNumber:'123'}},
 {kdsItemPublicId:'b',status:'in_progress',ticket:{checkPublicId:'abc',checkNumber:'123'}}
];
const results={a:{complete:true},b:{complete:true}};
const nodes=Object.fromEntries(['hudTicketCompletion','hudTicketCompletionAction',
 'hudTicketCompletionState','hudTicketCompletionHelp'].map(id=>[id,{hidden:true,disabled:true,dataset:{},setAttribute(){}}]));
const state={device:{kitchenWritable:true,stationPublicId:null},build:null,kitchenBusy:false,
 finishConfirmation:null,mode:'live'};
const $=id=>nodes[id];
const ctx={state,$,Date,Number,String,Array,Object,
 ingredientSummary:i=>results[i.kdsItemPublicId]||{complete:false},
 activeTicket:()=>({key:'abc',items}),
 selectedItem:()=>items[0],
 kitchenWritable:()=>state.device.kitchenWritable};
vm.runInNewContext(source.slice(from,to)+'\nthis.completion=ticketCompletionCandidate;this.renderCompletion=renderTicketCompletion;',ctx);
const evaluate=(extra={})=>ctx.completion({key:'abc',items},{
 editable:true,expo:true,busy:false,buildActive:false,...extra});
let r=evaluate();
assert.equal(r.visible,true,'all ingredient lists assembled -> prompt KDS transition');
assert.equal(r.command,'ready_order','assembly accounted does not bypass KDS Ready');
assert.equal(r.label,'Ticket Ready');
assert.equal(r.enabled,true);
ctx.renderCompletion();
assert.equal(nodes.hudTicketCompletionAction.dataset.glassesCommand,'ready_order');
assert.equal(nodes.hudTicketCompletionAction.hidden,false);
results.b.complete=false;
assert.equal(evaluate().visible,false,'not all ingredients accounted -> no premature ticket action');
results.b.complete=true;
items[0].status='queued';
assert.equal(evaluate().command,'start_order','queued ticket requires KDS Start');
items[0].status='ready';items[1].status='ready';
r=evaluate();
assert.equal(r.command,'finish_order','all KDS-ready items may be finished at Expo');
assert.equal(r.label,'Finish Ticket');
assert.equal(r.enabled,true);
assert.equal(evaluate({expo:false}).enabled,false,'station-only view cannot finish whole ticket');
assert.match(evaluate({expo:false}).help,/Expo/);
assert.equal(evaluate({editable:false}).enabled,false,'read-only user cannot finish');
assert.equal(evaluate({buildActive:true}).enabled,false,'active governed build blocks completion');
assert.equal(evaluate({busy:true}).enabled,false,'busy kitchen transition blocks duplicate submit');
state.finishConfirmation={ticket:'abc',expires:Date.now()+11000};
ctx.renderCompletion();
assert.equal(nodes.hudTicketCompletionAction.textContent,'Confirm Finish Ticket');
state.finishConfirmation={ticket:'other',expires:Date.now()+11000};
ctx.renderCompletion();
assert.equal(nodes.hudTicketCompletionAction.textContent,'Finish Ticket','another ticket cannot authorize confirmation');
state.finishConfirmation={ticket:'abc',expires:Date.now()-1};
ctx.renderCompletion();
assert.equal(nodes.hudTicketCompletionAction.textContent,'Finish Ticket','expired confirmation not reused');
items[1].status='held';
assert.equal(evaluate().command,null,'held items never bump');
items[1].status='ready';
results.a.complete=false;results.b.complete=false;
assert.equal(evaluate().command,'finish_order','authoritative KDS Ready remains eligible');
items.length=0;
assert.equal(evaluate().visible,false,'empty ticket no completion');
assert.match(page,/id="hudOrders"[\s\S]*id="hudTicketCompletion"/,'ticket action directly beneath left lens ticket list');
assert.match(page,/data-glasses-command="finish_order"/,'same delegated button handler as voice');
assert.match(css,/#glassesStage \.hud-ticket-completion/,'projection-safe left-lens styling');
assert.match(voice,/return 'finish_order'/,'existing finish voice command reused');
assert.match(voice,/confirm \(?:finish\|complete\)/,'explicit spoken confirmation alias');
assert.match(server,/kds_production_ticket_action\(/,'same KDS dispatcher used for physical and browser preview');
assert.match(kds,/Only Expo \/ All can bump a completed kitchen ticket/,'server checks Expo');
assert.match(kds,/All fired items in this ticket must be Ready/,'server verifies each item Ready');
assert.match(source,/state\.finishConfirmation=\{ticket,expires:now\+12000\}/,'two-phase confirmation');
assert.match(source,/await api\(type,data\)/,'actual permission-checked kitchen dispatcher');
console.log('glasses-kds-left-lens-completion=ok');
