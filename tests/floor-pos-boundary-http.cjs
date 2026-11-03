'use strict';

const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const {mkdtempSync,rmSync}=require('node:fs');
const {createServer}=require('node:net');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');

const root=resolve(__dirname,'..');
const php=process.env.PHP_BINARY||'php';
const floor='/floor-plan-api.php';
const pos='/api/pos.php';
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));

function fixture(...args){
  const out=spawnSync(php,[join(__dirname,'floor-pos-boundary-fixture.php'),...args],{
    cwd:root,env:{...process.env,GELATO_FLOOR_BOUNDARY_FIXTURE:'1'},encoding:'utf8',timeout:30000
  });
  if(out.error)throw out.error;
  assert.equal(out.status,0,out.stderr);
  return JSON.parse(out.stdout);
}

async function availablePort(){
  const server=createServer();
  await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});
  const port=server.address().port;
  await new Promise((resolve,reject)=>server.close(error=>error?reject(error):resolve()));
  return port;
}

function client(base){
  const cookies=new Map();
  let csrf='';
  async function request(path,{body,form}={}){
    const data=form??(body===undefined?undefined:JSON.stringify(body));
    const response=await fetch(base+path,{method:data===undefined?'GET':'POST',body:data,redirect:'manual',
      signal:AbortSignal.timeout(10000),headers:{
        Cookie:[...cookies].map(([key,value])=>key+'='+value).join('; '),
        ...(body===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf})
      }
    });
    for(const header of response.headers.getSetCookie()){
      const cookie=header.split(';',1)[0],pos=cookie.indexOf('=');
      cookies.set(cookie.slice(0,pos),cookie.slice(pos+1));
    }
    const text=await response.text();
    const json=response.headers.get('content-type')?.includes('application/json')?JSON.parse(text):null;
    return {status:response.status,text,json};
  }
  return {request,cookies,setCsrf:token=>{csrf=token;}};
}

function expect(response,status,label){
  assert.equal(response.status,status,label+' — '+(response.json?.message||response.text.slice(0,300)));
  return response.json;
}

(async()=>{
  const folder=mkdtempSync(join(tmpdir(),'gelato-floor-boundary-'));
  let server,log='',startupError;
  try{
    const seed=fixture('seed');
    const port=await availablePort();
    const base='http://127.0.0.1:'+port;
    server=spawn(php,['-d','session.save_path='+folder,'-S','127.0.0.1:'+port,'-t',root],{
      cwd:root,stdio:['ignore','ignore','pipe']
    });
    server.once('error',error=>{startupError=error;});
    server.stderr.on('data',chunk=>{log=(log+chunk).slice(-7000);});
    const deadline=Date.now()+6000;
    while(true){
      if(startupError)throw startupError;
      try{if((await client(base).request('/login.php')).status===200)break;}catch{}
      assert.ok(Date.now()<deadline,'PHP fixture login is reachable. '+log);
      await pause(50);
    }
    async function signIn(kind){
      const account=client(base),login=await account.request('/login.php');
      expect(login,200,'Login page');
      const token=login.text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
      assert.ok(token);
      expect(await account.request('/login.php',{form:new URLSearchParams({email:seed.users[kind].email,
        password:seed.password,csrf_token:token,return:'workspace.php'})}),302,'Authenticate '+kind);
      const workspace=await account.request('/workspace.php');
      expect(workspace,200,'Authenticated workspace');
      const csrf=workspace.text.match(/RESTAURANT_CSRF_TOKEN="([a-f0-9]+)"/)?.[1];
      assert.ok(csrf);
      account.setCsrf(csrf);
      return account;
    }

    const owner=await signIn('owner'),otherTab=await signIn('owner');
    const employee=await signIn('employee'),foreign=await signIn('otherOwner');
    const {east,west}=seed;
    const item=(id,label,x=200)=>({id,type:'table',label,xPx:x,yPx:100,wPx:96,hPx:72,seats:4});
    const save=(account,items,{id,version,name='East dining room'}={})=>account.request(floor,{body:{action:'save',plan:{
      id,version,name,data:{version:7,planWft:50,planHft:34,scale:24,items}
    }}});
    const read=(account,id)=>account.request(floor+'?id='+encodeURIComponent(id));
    const run=(account,action,locationId,extra={})=>account.request(pos,{body:{action,locationId,...extra}});
    const archive=(account,id,version)=>account.request(floor,{body:{action:'archive',id,version}});
    const state=async (location,check)=>expect(await owner.request(pos+'?'+new URLSearchParams({
      locationId:String(location),...(check?{check}:{})
    })),200,'Read authorized POS state');
    const table=(floorPlan,id)=>floorPlan.tables.find(row=>row.floorPlanComponentId===id);
    const durable=id=>fixture('state',String(seed.organizations.home),id);

    const initial=expect(await save(owner,[item('stable','Keep table'),item('removed','Occupied table',450)]),200,'Create East layout').plan;
    const opened=expect(await read(otherTab,initial.id),200,'Second editor opens the same version').plan;
    const newer=expect(await save(owner,[item('stable','Newer table'),item('removed','Occupied table',450)],initial),
      200,'First editor saves').plan;
    const stale=expect(await save(otherTab,opened.data.items,opened),409,'Stale save cannot overwrite');
    assert.equal(stale.code,'floor_plan_version_conflict');
    assert.equal(stale.currentVersion,newer.version);
    expect(await save(owner,newer.data.items,{id:newer.id,name:newer.name}),422,'Existing edit needs a version');
    expect(await archive(otherTab,opened.id,opened.version),409,'Stale archive cannot remove a newer version');
    assert.equal(expect(await read(owner,newer.id),200,'Reload saved state').plan.data.items[0].label,'Newer table');
    expect(await read(foreign,newer.id),404,'Foreign restaurant cannot read the layout');
    expect(await save(foreign,newer.data.items,newer),404,'Foreign restaurant cannot save the layout');

    const westPlan=expect(await save(owner,[item('west','West only')],{name:'West dining room'}),200,'Create West layout').plan;
    expect(await run(owner,'floor.select',east,{floorPlanPublicId:newer.id}),200,'Assign East layout');
    expect(await save(employee,newer.data.items,newer),403,'A shared default used at West requires West edit authority');
    expect(await run(owner,'floor.select',west,{floorPlanPublicId:westPlan.id}),200,'Assign West layout');
    const eastPos=expect(await employee.request(pos+'?locationId='+east),200,'Scoped employee can read East POS');
    assert.ok(eastPos.floorPlan.plans.some(plan=>plan.publicId===newer.id));
    assert.ok(!eastPos.floorPlan.plans.some(plan=>plan.publicId===westPlan.id),'East POS cannot enumerate West-only plans');
    expect(await employee.request(pos+'?locationId='+west),403,'Scoped employee cannot read West POS');
    expect(await read(employee,westPlan.id),404,'Scoped employee cannot read West layout');
    expect(await save(employee,westPlan.data.items,westPlan),403,'Scoped employee cannot save West layout');
    expect(await run(employee,'floor.select',east,{floorPlanPublicId:westPlan.id}),403,
      'Selecting at East cannot grant access to a West-only plan');
    expect(await save(employee,[item('local','Local template')]),403,'Scoped employee cannot create a restaurant-wide template');
    const listed=expect(await employee.request(floor),200,'Read scoped floor list').plans.map(plan=>plan.id);
    assert.ok(listed.includes(newer.id));
    assert.ok(!listed.includes(westPlan.id));
    const eastPlan=expect(await save(employee,newer.data.items,newer),200,'Scoped employee can save East-only layout').plan;

    const host=(account,action,locationId,extra={})=>account.request('/api/host-stand.php',{body:{action,locationId,...extra}});
    const hostTable=expect(await host(employee,'table.create',east,{name:'Fixture Host Stand table',capacity:2}),
      201,'East Host Stand manager can create an unplaced table').table;
    assert.equal(hostTable.asset.floorPlanId,null);
    expect(await host(employee,'table.create',west,{name:'Unauthorized Host Stand table',capacity:2}),
      403,'East Host Stand manager cannot create a West table');
    const westPlacement={floorPlanId:westPlan.id,xFt:5,yFt:5};
    expect(await host(employee,'table.create',east,{name:'Unauthorized Host Stand placement',capacity:2,unplace:true,...westPlacement}),
      403,'Creating a Host Stand table cannot change a West-only layout');
    const tablePublicId=hostTable.publicId;
    expect(await host(employee,'table.place',east,{tablePublicId,...westPlacement}),
      403,'Placing a Host Stand table cannot change a West-only layout');
    const placed=expect(await host(employee,'table.place',east,{tablePublicId,...westPlacement,floorPlanId:eastPlan.id}),
      200,'East Host Stand manager can place a table on the East layout').table;
    assert.equal(placed.asset.floorPlanId,eastPlan.id);
    expect(await host(owner,'table.place',east,{tablePublicId,...westPlacement}),
      200,'Organization-wide Host Stand manager can place the table on the West layout');
    expect(await host(employee,'table.place',east,{tablePublicId,unplace:true}),
      403,'Unplacing a Host Stand table also requires authority over its current layout');
    expect(await host(employee,'asset.update',east,{tablePublicId,widthInches:48}),
      403,'Changing the footprint also requires authority over the current layout');
    expect(await host(owner,'table.place',east,{tablePublicId,unplace:true}),
      200,'Organization-wide Host Stand manager can unplace the table');
    expect(await host(employee,'asset.sync_all',west),403,'East Host Stand manager cannot sync West assets');

    const original=expect(await run(owner,'floor.sync',east),200,'Map East tables').floorPlan;
    const occupied=table(original,'removed');
    const stable=table(original,'stable');
    const check=expect(await run(owner,'table.seat',east,{tablePublicId:occupied.publicId,guestCount:2}),
      201,'Seat from the selected layout').check;
    const edited=expect(await save(owner,[item('stable','Newer table')],eastPlan),200,'Remove occupied table from the layout').plan;
    const removedState=(await state(east,check.publicId));
    assert.equal(removedState.check.status,'open');
    assert.equal(removedState.check.tableName,occupied.name);
    const retired=removedState.floorPlan.tables.find(row=>row.publicId===occupied.publicId);
    assert.equal(retired?.retired,true);
    assert.equal(retired?.seatAllowed,false);
    assert.equal(retired?.checkPublicId,check.publicId);
    assert.equal(durable(occupied.publicId).active_check,check.publicId);
    expect(await run(owner,'table.seat',east,{tablePublicId:occupied.publicId,guestCount:1}),422,
      'Removed occupied table cannot be used for new seating');

    const replacement=expect(await save(owner,[item('stable','Replacement table')],{name:'East replacement layout'}),
      200,'Create replacement layout').plan;
    const replaced=expect(await run(owner,'floor.select',east,{floorPlanPublicId:replacement.id}),200,'Publish replacement').floorPlan;
    assert.equal(replaced.selectedPlan.publicId,replacement.id);
    assert.ok(!replaced.tables.some(row=>row.publicId===stable.publicId),'An empty old-plan table is absent from the active floor');
    expect(await run(owner,'table.seat',east,{tablePublicId:stable.publicId,guestCount:1}),422,
      'Previous-plan table cannot accept new seating before an explicit sync');
    const synced=expect(await run(owner,'floor.sync',east),200,'Sync replacement layout').floorPlan;
    const active=table(synced,'stable');
    assert.notEqual(active.publicId,stable.publicId);
    const replacementCheck=expect(await run(owner,'table.seat',east,{tablePublicId:active.publicId,guestCount:1}),
      201,'Seat at the active replacement point').check;

    expect(await archive(owner,replacement.id,replacement.version),200,'Archive the assigned plan');
    const archived=await state(east,replacementCheck.publicId);
    assert.equal(archived.floorPlan.selectedPlan,null);
    assert.equal(archived.check.status,'open');
    assert.equal(archived.floorPlan.tables.find(row=>row.publicId===active.publicId)?.checkPublicId,replacementCheck.publicId);
    expect(await run(owner,'table.seat',east,{tablePublicId:active.publicId,guestCount:1}),422,'Archived point cannot seat again');
    expect(await run(owner,'check.cancel',east,{checkPublicId:replacementCheck.publicId,reason:'Floor fixture cleanup'}),
      200,'Existing check can still close from retired point');
    assert.equal(durable(active.publicId).status,'retired');
    assert.equal(durable(active.publicId).active_check,null);
    assert.ok(!(await state(east)).floorPlan.tables.some(row=>row.publicId===active.publicId));
    assert.equal((await state(west)).floorPlan.selectedPlan.publicId,westPlan.id,'East changes leave West assignment intact');
    assert.equal(expect(await read(owner,edited.id),200,'Previous nonarchived layout remains readable').plan.version,edited.version);

    const eastOnly=expect(await save(owner,[item('east-only','East-only table')],{name:'East only'}),200,
      'Create another East-only layout').plan;
    expect(await run(owner,'floor.select',east,{floorPlanPublicId:eastOnly.id}),200,'Explicitly assign the East-only plan');
    expect(await archive(owner,edited.id,edited.version),200,'Archive the old organization default');
    const unconfigured=fixture('add-location',String(seed.organizations.home));
    assert.equal((await state(unconfigured)).floorPlan.selectedPlan.publicId,westPlan.id,'Fallback picks the oldest remaining plan');
    expect(await save(employee,[item('east-only','Updated East-only table')],eastOnly),200,'Save authorized East-only edits');
    assert.equal((await state(unconfigured)).floorPlan.selectedPlan.publicId,westPlan.id,
      'Editing a different plan cannot implicitly publish it at another location');
    console.log('Floor and POS authenticated HTTP passed: scoped edits, stale writes, retirement, open checks, and archive.');
    if(process.env.GELATO_TEST_FLOOR_EDITOR_BROWSER==='1'){
      const editable=expect(await save(owner,[item('editor','Editable table')],{name:'Browser editor layout'}),200,
        'Create the browser editor fixture').plan;
      const {verifyFloorEditorBrowser}=require('./floor-planner-editor-browser.cjs');
      await verifyFloorEditorBrowser({origin:base,cookies:owner.cookies,planId:editable.id});
    }
  }finally{
    if(server&&server.exitCode===null&&server.pid){
      const exited=new Promise(resolve=>server.once('exit',resolve));
      server.kill();
      await exited;
    }
    rmSync(folder,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
