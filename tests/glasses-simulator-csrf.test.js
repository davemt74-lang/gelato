'use strict';
const assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const js=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const start=js.indexOf('const SIM_READ_ONLY_ACTIONS='),end=js.indexOf('const LIVE_SYNC_BASE_MS=',start);
assert.ok(start>=0&&end>start,'session refresh integration missing');
const snippet=js.slice(start,end);
const stale='a'.repeat(64),fresh='b'.repeat(64);
let requests=[],badToken=stale;
const ctx={
  cfg:{api:'api/glasses-simulator.php',csrf:stale},
  state:{recovery:{networkFault:false},device:{publicId:'preview:3:all'}},
  Set,JSON,String,Error,Promise,
  fetch:async(url,options)=>{
    requests.push({url,options});
    if(options.method==='GET')return {ok:true,status:200,json:async()=>({ok:true,csrfToken:fresh})};
    const token=options.headers['X-CSRF-Token'];
    return token===badToken?{ok:false,status:419,json:async()=>({ok:false,message:'Security token expired'})}:
      {ok:true,status:200,json:async()=>({ok:true,confirmed:true})};
  }
};
vm.runInNewContext(snippet+'\nthis.requestApi=api;',ctx);
async function test(){
  badToken=stale;
  const diagnostic=await ctx.requestApi('hardware.health.normalize',{snapshot:{camera:{state:'ready'}}});
  assert.equal(diagnostic.confirmed,true);
  assert.equal(ctx.cfg.csrf,fresh);
  assert.deepEqual(requests.map(x=>x.options.method),['POST','GET','POST'],'safe HEALTH retries once after fetching session token');
  assert.equal(requests[2].options.headers['X-CSRF-Token'],fresh);
  assert.equal(JSON.parse(requests[2].options.body).csrf_token,fresh);
  requests=[];ctx.cfg.csrf=stale;
  await assert.rejects(ctx.requestApi('kds.ticket.action',{ticketAction:'bump'}),/Repeat your action/);
  assert.deepEqual(requests.map(x=>x.options.method),['POST','GET'],'kitchen mutation never auto-replayed after 419');
  assert.equal(ctx.cfg.csrf,fresh);
  requests=[];await ctx.requestApi('kds.ticket.action',{ticketAction:'bump'});
  assert.deepEqual(requests.map(x=>x.options.method),['POST'],'manual retry works with new token');
  requests=[];ctx.cfg.csrf=stale;
  await assert.rejects(ctx.requestApi('build.observe',{componentKey:'test'}),/Repeat your action/);
  assert.deepEqual(requests.map(x=>x.options.method),['POST','GET'],'build observations cannot auto-replay');
  requests=[];ctx.state.recovery.networkFault=true;
  await assert.rejects(ctx.requestApi('hardware.health.normalize'),/SIMULATED_NETWORK_OFFLINE/);
  assert.equal(requests.length,0,'fault simulation still blocks real requests');
  console.log('glasses-simulator-csrf-session-recovery=ok');
}
test().catch(e=>{console.error(e);process.exitCode=1;});