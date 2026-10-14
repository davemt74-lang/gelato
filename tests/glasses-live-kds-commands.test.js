'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const js=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const start=js.indexOf('const KITCHEN_COMMANDS='),end=js.indexOf('window.GelatoGlassesNavigation=',start);
assert.ok(start>=0&&end>start,'kitchen action model missing');
const code=js.slice(start,end);
const item=(id,status)=>({kdsItemPublicId:id,status,ticket:{checkPublicId:'check-100',checkNumber:'100'},posLine:{name:'Pizza '+id}});
const a=item('item-a','queued'),b=item('item-b','queued');
const state={mode:'live',device:{publicId:'preview:2:all',virtualPreview:true,kitchenWritable:true,stationPublicId:null},
  work:{items:[a,b]},selectedKdsItemPublicId:'item-a',previewStepIndex:0,kitchenBusy:false,build:null,finishConfirmation:null};
const calls=[],logs=[],controls=[],note={value:'Extra basil added'};
const $=id=>id==='simItemNoteInput'?note:id==='simKitchenCommandStatus'?{set textContent(v){}}:null;
const ctx={state,$,Object,Date,String,Math,Set,JSON,
  window:{prompt:()=>null,addEventListener(){}},
  document:{querySelectorAll:()=>controls,addEventListener(){}},
  selectedItem:()=>state.work.items.find(x=>x.kdsItemPublicId===state.selectedKdsItemPublicId)||state.work.items[0]||null,
  orderItems:()=>state.work.items,
  deriveSteps:()=>['Add basil','Bake','Serve'],
  render:()=>{},
  renderTicketCompletion:()=>{},
  syncLiveStationWork:async()=>true,
  log:(type,message)=>logs.push({type,message}),
  api:async(action,payload)=>{
    calls.push({action,payload});
    if(action==='kds.item.transition'){
      const current=state.work.items.find(x=>x.kdsItemPublicId===payload.kdsItemPublicId);
      if(current.status!==payload.expectedStatus)throw Error('stale');
      current.status=payload.status;
    }
    if(action==='kds.item.note'){
      const current=state.work.items.find(x=>x.kdsItemPublicId===payload.kdsItemPublicId);
      current.latestOperatorNote=payload.note;
    }
    if(action==='kds.ticket.action'){
      const transitions={start:['queued','in_progress'],ready:['in_progress','ready'],bump:['ready','completed']};
      const [from,to]=transitions[payload.ticketAction]||['none','none'];
      state.work.items.forEach(x=>{if(x.status===from)x.status=to;});
      state.work.items=state.work.items.filter(x=>x.status!=='completed');
    }
    return {ok:true};
  }
};
vm.runInNewContext(code+'\nthis.runCommand=simulatorNavigation;this.updateControls=updateKitchenControls;',ctx);
async function run(){
  ctx.updateControls();
  assert.equal((await ctx.runCommand('start_item')).ok,true);
  assert.equal(calls.at(-1).action,'kds.item.transition');
  assert.equal(calls.at(-1).payload.expectedStatus,'queued');
  assert.equal(a.status,'in_progress');
  assert.equal((await ctx.runCommand('ready_item')).ok,true);
  assert.equal(a.status,'ready');
  assert.equal((await ctx.runCommand('start_order')).ok,true);
  assert.equal(b.status,'in_progress');
  assert.equal((await ctx.runCommand('ready_order')).ok,true);
  assert.equal(b.status,'ready');
  let before=calls.length;
  let r=await ctx.runCommand('finish_order');
  assert.ok(r.message.includes('Confirm finish ticket'));
  assert.equal(calls.length,before,'first finish never writes');
  r=await ctx.runCommand('finish_order');
  assert.equal(r.ok,true);
  assert.equal(calls.at(-1).payload.ticketAction,'bump');
  assert.equal(state.work.items.length,0);
  state.work.items=[item('item-c','in_progress')];state.selectedKdsItemPublicId='item-c';
  before=calls.length;
  r=await ctx.runCommand('finish_order');
  assert.equal(r.ok,false,'cannot finish before every item Ready');
  assert.equal(calls.length,before);
  const n=await ctx.runCommand('item_added');
  assert.equal(n.ok,true);
  assert.equal(calls.at(-1).action,'kds.item.note');
  assert.equal(calls.at(-1).payload.note,'Extra basil added');
  assert.equal(state.work.items[0].latestOperatorNote,'Extra basil added');
  assert.ok(logs.at(-1).message.includes('POS and ingredient verification were not changed'));
  state.device.kitchenWritable=false;before=calls.length;
  const blocked=await ctx.runCommand('ready_item');
  assert.equal(blocked.ok,false);assert.equal(calls.length,before);
  state.device.kitchenWritable=true;state.device.stationPublicId='station-1';
  state.work.items[0].status='ready';state.finishConfirmation=null;
  const stationFinish=await ctx.runCommand('finish_order');
  assert.equal(stationFinish.ok,false,'station cannot finish full Expo ticket');
  state.mode='mock';state.device.stationPublicId=null;state.work.items=[item('mock-1','queued')];state.selectedKdsItemPublicId='mock-1';
  before=calls.length;
  const mockStart=await ctx.runCommand('start_item');
  assert.equal(mockStart.ok,true);assert.equal(calls.length,before,'mock cannot write server');
  console.log('glasses-live-kds-commands=ok');
}
run().catch(e=>{console.error(e);process.exitCode=1;});