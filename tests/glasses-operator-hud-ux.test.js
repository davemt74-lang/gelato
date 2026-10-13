'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const src=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8');
const page=fs.readFileSync('glasses-simulator.php','utf8');
const tabs=fs.readFileSync('assets/js/glasses-simulator-tabs.js','utf8');
assert.match(page,/id="leftWidth" type="range"/);
assert.match(page,/id="leftWidthValue"/);
assert.match(page,/id="hudCurrentInstruction"/);
assert.match(page,/id="hudBuildMode"/);
assert.match(page,/ACTIVE KDS TICKETS/);
assert.match(src,/calibration\.regions\.hudOrdersRegion\.w/);
assert.match(src,/\$\('leftWidth'\)\.addEventListener\('input'/);
assert.match(src,/saveCalibration\(\)/);
assert.match(src,/async function focusKitchenItem/);
assert.match(src,/\$\('hudOrders'\)\.addEventListener\('click'/);
assert.match(src,/state\.device\?\.simulatorWritable&&next\.status==='in_progress'/);
assert.match(src,/function renderBuildRail\(item\)/);
assert.match(src,/No assembly ingredients configured/);
assert.doesNotMatch(src,/:\['Load work','Build item','Validate','Send to Expo'\]/);
assert.match(src,/item\?'ITEM INGREDIENTS'/);
assert.match(src,/validationCard'\)\.hidden=!state\.build/);
assert.match(css,/#glassesStage:not\(\.calibrating\) \.camera-capture-zone\{display:none!important/);
assert.match(css,/#glassesStage\.calibrating \.camera-capture-zone\{display:block/);
assert.match(css,/#glassesStage \.hud-clock\{display:none!important/);
assert.match(css,/\.sim-stage-panel \.sim-glasses-quick-actions\{[\s\S]*?transform:translateX\(-50%\)!important/);
assert.match(css,/border:0!important;border-radius:0!important;box-shadow:none!important/);
assert.match(css,/\.glasses-stage\.calibrating \.hud-orders-left,[\s\S]*?height:max-content!important/);
assert.match(css,/#glassesStage \.hud-next-card\{display:none!important/);
assert.match(css,/#glassesStage \.hud-validation-panel\[hidden\]/);
assert.match(tabs,/More KDS actions & notes/);
assert.match(tabs,/audit\.open=false/);
// Execute the actual ticket grouping and HUD renderer against a controlled work queue.
const begin=src.indexOf('function visibleKdsTickets(){');
const end=src.indexOf('\nfunction buildProgressIndex(',begin);
assert.ok(begin>0&&end>begin,'KDS grouping/short-slots implementation exists');
const blocks=src.slice(begin,end);
let queue=[],selection=null;
const nodes={
 hudOrdersCount:{textContent:''},hudOrders:{innerHTML:''},
 hudOrdersRegion:{classList:{toggle(){}}}
};
const ctx={Map,Array,Math,Number,String,JSON,
  assembly:()=>({
    ticketKey:item=>item?.ticket?.checkPublicId||item?.kdsItemPublicId||'',
    ticketGroups:items=>{
      const groups=new Map();
      for(const item of items){if(['cancelled','completed'].includes(item.status))continue;
        const key=item.ticket?.checkPublicId||item.kdsItemPublicId;
        if(!groups.has(key)){if(groups.size>=8)continue;groups.set(key,{key,items:[]});}
        groups.get(key)?.items.push(item);}
      return [...groups.values()];
    }
  }),
  ingredientSummary:()=>({total:0,accounted:0,complete:false}),
  orderItems:()=>queue,elapsedLabel:()=> '03:25',selectedItem:()=>selection,
  escapeHtml:val=>String(val).replace(/[&<>"]/g,''),
  $:id=>nodes[id]};
vm.runInNewContext(blocks+'\nthis.visibleKdsTickets=visibleKdsTickets;this.kdsSlotForItem=kdsSlotForItem;this.renderOrdersQueue=renderOrdersQueue;',ctx);
const ticket=(id,check,line)=>({kdsItemPublicId:id,ticket:{checkPublicId:check,checkNumber:'269014-ABCDEFGHIJ'+check},posLine:{name:line}});
queue=[ticket('a','orderA','Pepperoni'),ticket('b','orderA','Garlic'),ticket('c','orderB','Cheese')];
let groups=ctx.visibleKdsTickets();
assert.equal(groups.length,2,'two POS tickets become two KDS slots');
assert.equal(groups[0].items.length,2,'items on same ticket stay grouped');
assert.equal(ctx.kdsSlotForItem(queue[0]),1);
assert.equal(ctx.kdsSlotForItem(queue[1]),1);
assert.equal(ctx.kdsSlotForItem(queue[2]),2);
selection=queue[2];ctx.renderOrdersQueue(selection);
assert.equal(nodes.hudOrdersCount.textContent,'2');
assert.match(nodes.hudOrders.innerHTML,/data-kds-order-slot="1"/);
assert.match(nodes.hudOrders.innerHTML,/data-kds-order-slot="2"/);
assert.match(nodes.hudOrders.innerHTML,/#1/);
assert.match(nodes.hudOrders.innerHTML,/#2/);
assert.doesNotMatch(nodes.hudOrders.innerHTML,/269014-/,'full POS check never printed inside glasses');
assert.match(nodes.hudOrders.innerHTML,/Garlic|Pepperoni/);
queue=Array.from({length:12},(_,i)=>ticket('x'+i,'order'+i,'Pizza '+i));
groups=ctx.visibleKdsTickets();
assert.equal(groups.length,8,'lens bounded to slots 1–8');
assert.equal(ctx.kdsSlotForItem(queue[9]),null,'offscreen ticket does not steal short slot');
selection=queue[0];ctx.renderOrdersQueue(selection);
assert.equal((nodes.hudOrders.innerHTML.match(/data-kds-order-slot/g)||[]).length,8);
console.log('glasses-operator-hud-ux=ok');
