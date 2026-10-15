'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const model=require('../assets/js/glasses-production-ticket-focus.js');
const read=(path)=>fs.readFileSync(path,'utf8');
const voice=read('assets/js/glasses-simulator-voice.js'),sim=read('assets/js/glasses-web-simulator.js'),page=read('glasses-simulator.php');
const ticket=(id,no,status,check)=>({kdsItemPublicId:id,status,ticket:{checkPublicId:check,checkNumber:no},posLine:{name:'Pizza '+id}});
const a=ticket('a','104','queued','c104'),b=ticket('b','104','in_progress','c104'),c=ticket('c','105','queued','c105'),d=ticket('d','106','ready','c106');
const groups=[{key:'c104',items:[a,b]},{key:'c105',items:[c]},{key:'c106',items:[d]}];
assert.equal(model.navigate(groups,'a',1).item.kdsItemPublicId,'c','next ticket skips other items in current ticket');
assert.equal(model.navigate(groups,'c',-1).item.kdsItemPublicId,'b','previous ticket prioritizes in-progress item');
assert.equal(model.navigate(groups,'d',1).item.kdsItemPublicId,'b','ticket cycling wraps');
assert.equal(model.findByNumber(groups,'105').item.kdsItemPublicId,'c');
assert.equal(model.findByNumber(groups,'10').reason,'ticket_not_found','ticket matching is exact, not a prefix');
assert.equal(model.findByNumber(groups,'105 OR 1=1').reason,'invalid_number','never parse raw ticket number as a query');
assert.equal(model.navigate(groups.slice(0,1),'a',1).reason,'only_one_ticket');
assert.equal(model.navigate([],null,1).reason,'no_tickets');
assert.equal(model.findByNumber([{key:'another',items:[{...c,kdsItemPublicId:'other',ticket:{checkPublicId:'c106',checkNumber:'105'}}]},...groups],'105').reason,'ambiguous_ticket');
assert.equal(model.navigate([{key:'h',items:[{...a,status:'completed'}]},...groups],'c',-1).item.kdsItemPublicId,'b','completed tickets not navigable');
assert.throws(()=>model.navigate(groups,'a',0),/direction/);
assert.equal(model.readiness({mode:'live',device:{virtualPreview:false},work:{items:[a]},item:a,assigned:true,cameraLive:false}).state,'ready','no Start Ticket required for queued KDS item');
assert.equal(model.readiness({mode:'live',device:{virtualPreview:false},work:{items:[a]},item:a,assigned:true,cameraLive:true,modelReady:false}).state,'observing','camera alone not claimed to identify ingredients');
assert.equal(model.readiness({mode:'live',device:{virtualPreview:false},work:{items:[a]},item:a,assigned:true,cameraLive:true,modelReady:true}).state,'recognizing');
assert.equal(model.readiness({mode:'live',device:{virtualPreview:false},work:{items:[a]},item:a,assigned:false}).state,'unassigned','glasses must belong to employee');
assert.equal(model.readiness({mode:'live',device:{virtualPreview:true},work:{items:[a]},item:a}).state,'preview');
assert.equal(model.readiness({mode:'live',device:{virtualPreview:false},work:{items:[a]},item:a,assigned:true,hidden:true}).state,'paused');
assert.equal(model.readiness({mode:'live',device:{virtualPreview:false},work:{items:[]},item:null,assigned:true}).state,'waiting');
assert.equal(model.readiness({mode:'mock',device:{virtualPreview:false},work:{items:[a]},item:a,assigned:true}).state,'inactive');
assert.match(page,/id="glassesProductionStatus"/);
assert.match(page,/glasses-production-ticket-focus\.js[\s\S]*glasses-web-simulator\.js/,'production focus library loads before runtime');
assert.match(page,/data-glasses-command="start_order"[^>]*>Mark KDS Started</,'manual KDS status retains an explicit, distinguishable label');
assert.match(sim,/if\(action==='focus_ticket'\)/,'voice Start Ticket never writes KDS');
assert.match(sim,/focus\.findByNumber\(groups,action\.slice/);
assert.match(sim,/focus\.navigate\(groups,current\?\.kdsItemPublicId/);
const begin=sim.indexOf('function simulatorNavigation('),end=sim.indexOf('window.GelatoGlassesAssemblyContext=',begin);
assert.ok(begin>0&&end>begin);
let selected='a',build=null,remoteWrites=0;
const progress=new Map([['a',{'0:Sauce':'added'}],['c',{'0:Cheese':'added'}]]);
const env={
 state:{get build(){return build},mode:'live',work:{items:[a,b,c,d]},get selectedKdsItemPublicId(){return selected},previewStepIndex:0},
 window:{GelatoProductionTicketFocus:model},
 KITCHEN_COMMANDS:new Set(['start_order']),
 selectedItem:()=>[a,b,c,d].find(x=>x.kdsItemPublicId===selected),
 orderItems:()=>[a,b,c,d],visibleKdsTickets:()=>groups,
 focusKitchenItem:async id=>{selected=id;return {ok:true,message:'Focused '+id+' without KDS mutation.'};},
 activeTicket:()=>groups.find(g=>g.items.some(x=>x.kdsItemPublicId===selected)),
 kitchenCommand:async()=>{remoteWrites++;return {ok:true,message:'KDS status changed'};},
 currentAssemblyIngredient:()=>({id:'0:Sauce',name:'Sauce'}),
 acknowledgeIngredient:()=>({ok:true}),
 assembly:()=>({ingredientsFor:()=>[{id:'0:Sauce',name:'Sauce'}]}),
 itemActions:id=>progress.get(id)||{},
 kdsSlotForItem:()=>1,
 ingredientCursor:new Map(),render:()=>{},Math,Number,String,Set
};
vm.runInNewContext(sim.slice(begin,end)+'\nthis.navigate=simulatorNavigation;',env);
(async()=>{
 assert.equal((await env.navigate('focus_ticket')).ok,true);
 assert.equal(remoteWrites,0,'Start Ticket voice does not write');
 assert.equal(selected,'a');
 assert.equal((await env.navigate('next_ticket')).ok,true);assert.equal(selected,'c');
 assert.equal((await env.navigate('next_ticket')).ok,true);assert.equal(selected,'d');
 assert.equal((await env.navigate('previous_ticket')).ok,true);assert.equal(selected,'c');
 assert.equal((await env.navigate('select_ticket:104')).ok,true);assert.equal(selected,'b');
 assert.equal((await env.navigate('next_item')).ok,false);assert.equal(selected,'b','last item must not wrap');
 assert.equal((await env.navigate('previous_item')).ok,true);assert.equal(selected,'a');
 assert.equal((await env.navigate('previous_item')).ok,false);assert.equal(selected,'a','first item must not wrap');
 assert.equal((await env.navigate('next_item')).ok,true);assert.equal(selected,'b');
 assert.equal((await env.navigate('select_ticket:999')).ok,false);assert.equal(selected,'b','unknown ticket never changes focus');
 assert.equal((await env.navigate('select_ticket:abc')).ok,false);
 assert.equal(remoteWrites,0,'all navigation is view-local');
 assert.equal(progress.get('a')['0:Sauce'],'added','navigation preserves per-item checklist history');
 build={publicId:'live-build'};
 assert.equal((await env.navigate('next_ticket')).ok,false,'active governed build cannot be silently reassigned');
 assert.equal(selected,'b');assert.equal(remoteWrites,0);
 build=null;
 await env.navigate('start_order');assert.equal(remoteWrites,1,'explicit KDS start remains separate');
 console.log('glasses-section285a-production-ticket-navigation=ok');
})().catch(e=>{console.error(e);process.exitCode=1;});
