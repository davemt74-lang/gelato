'use strict';
const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const {mkdtempSync,rmSync}=require('node:fs');
const {createServer}=require('node:net');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');

const root=resolve(__dirname,'..');
const php=process.env.PHP_BINARY||'php';
const simulator='/api/glasses-simulator.php';
const lab='/api/glasses-vision-lab.php';
const models='/api/glasses-vision-models.php';
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function fixture(action,data){
  const out=spawnSync(php,[join(__dirname,'glasses-vision-boundaries-fixture.php'),action],{
    cwd:root,env:{...process.env,GELATO_VISION_BOUNDARY_FIXTURE:'1'},input:JSON.stringify(data),
    encoding:'utf8',timeout:30000
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
  return async (path,{body,csrf,form,bearer}={})=>{
    const data=form|| (body===undefined?undefined:JSON.stringify(body));
    const response=await fetch(base+path,{method:data===undefined?'GET':'POST',body:data,redirect:'manual',
      signal:AbortSignal.timeout(10000),headers:{
        Cookie:[...cookies].map(([key,value])=>key+'='+value).join('; '),
        ...(body===undefined?{}:{'Content-Type':'application/json'}),
        ...(csrf?{'X-CSRF-Token':csrf}:{}),
        ...(bearer?{Authorization:'Bearer '+bearer}:{})
      }
    });
    for(const header of response.headers.getSetCookie()){
      const cookie=header.split(';',1)[0];
      const pos=cookie.indexOf('=');
      cookies.set(cookie.slice(0,pos),cookie.slice(pos+1));
    }
    const text=await response.text();
    const json=response.headers.get('content-type')?.includes('application/json')?JSON.parse(text):null;
    return {status:response.status,text,json};
  };
}
function expect(response,status,label){
  assert.equal(response.status,status,label+' — '+(response.json?.message||response.text.slice(0,300)));
  return response.json;
}

(async()=>{
  const folder=mkdtempSync(join(tmpdir(),'gelato-vision-boundaries-'));
  let server,log='',startupError;
  try{
    const data=fixture('seed');
    const state=()=>fixture('state',data);
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
      try{const response=await client(base)('/login.php');if(response.status===200)break;}
      catch{}
      assert.ok(Date.now()<deadline,'PHP fixture login is reachable. '+log);
      await pause(50);
    }
    async function signIn(role){
      const request=client(base);
      const login=await request('/login.php');
      expect(login,200,'Login page');
      const token=login.text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
      assert.ok(token,'Login page provides CSRF');
      const signed=await request('/login.php',{form:new URLSearchParams({
        email:data.users[role],password:data.password,csrf_token:token,return:'workspace.php'
      })});
      expect(signed,302,'Authenticate '+role);
      const options=expect(await request(simulator),200,'Authenticated simulator catalogue');
      const post=(path,body)=>request(path,{body,csrf:options.csrfToken});
      return {request,post};
    }

    const preview='preview:'+data.east+':all';
    const getWork=(request,devicePublicId)=>request(simulator+'?'+new URLSearchParams({action:'work',devicePublicId}));
    const sim=(client,action,extra={})=>client.post(simulator,{action,devicePublicId:data.device,
      buildSessionPublicId:data.session,...extra});
    const before=state();
    for(const role of ['glasses-only','split-view']){
      const account=await signIn(role);
      expect(await account.request('/api/kds.php?locationId='+data.east),403,'KDS requires access at East');
      expect(await getWork(account.request,preview),403,'Preview GET requires the same East access');
      expect(await sim(account,'work',{devicePublicId:preview}),403,'Preview POST requires the same East access');
      expect(await getWork(account.request,data.device),403,'Paired browser polling requires East KDS access');
      expect(await sim(account,'build.get'),403,'The exact build cannot bypass kitchen scope');
    }
    const viewer=await signIn('viewer');
    expect(await viewer.request('/api/kds.php?locationId='+data.east),200,'Scoped viewer can read KDS');
    for(const response of [await getWork(viewer.request,preview),await sim(viewer,'work',{devicePublicId:preview}),
      await getWork(viewer.request,data.device)]){
      const work=expect(response,200,'Scoped viewer can read the same kitchen from the simulator').work;
      const row=work.items.find(item=>item.kdsItemPublicId===data.kds);
      assert.equal(row.ticket.checkPublicId,data.check);
      assert.equal(row.posLine.specialInstructions,'Private fixture preparation note');
      assert.equal(row.latestOperatorNote,'Private fixture cook note');
    }
    expect(await getWork(viewer.request,'preview:'+data.west+':all'),403,'Another location remains denied');
    assert.equal(expect(await sim(viewer,'validation.get'),200,'Read unevaluated build validation').validation,null);
    expect(await sim(viewer,'validation.evaluate'),403,'Viewer cannot evaluate');
    expect(await sim(viewer,'vision.model_assignment',{detectorName:'boundary-fixture'}),200,'Assignment lookup is readable');
    expect(await sim(viewer,'vision.shadow_assignment',{detectorName:'boundary-fixture'}),403,'Start Shadow is a write');
    assert.deepEqual(state(),before,'Browser reads and denied requests must leave kitchen and device state untouched');

    const localManager=await signIn('local-manager');
    const validation=expect(await sim(localManager,'validation.evaluate'),200,'Authorized explicit validation').validation;
    assert.ok(validation.publicId);
    assert.equal(expect(await sim(viewer,'validation.get'),200,'Viewer can read persisted validation').validation.publicId,
      validation.publicId);
    const afterValidation=state();
    assert.equal(afterValidation.glasses_product_validations,before.glasses_product_validations+1);
    assert.equal(afterValidation.glasses_validation_events,before.glasses_validation_events+1);
    assert.equal(afterValidation.lastSeen,before.lastSeen);
    expect(await client(base)('/api/glasses-device.php',{body:{action:'heartbeat'},bearer:data.token}),200,
      'A real paired device can still send heartbeat');
    assert.notEqual(state().lastSeen,before.lastSeen,'Only authenticated device heartbeat advanced last seen in this journey');

    const modelCatalog=expect(await localManager.request(models),200,'Scoped model catalogue').catalog;
    assert.deepEqual(modelCatalog.rollouts.map(rollout=>rollout.publicId),[data.modelRollouts.east]);
    assert.deepEqual(Object.keys(modelCatalog.metricsByRollout),[data.modelRollouts.east]);
    for(const publicId of [data.modelRollouts.west,data.modelRollouts.global]){
      for(const action of ['shadow_evidence','activate','advance','advance_override','pause','rollback']){
        expect(await localManager.post(models,{action:'rollout.'+action,publicId}),403,
          'Local manager cannot '+action+' a different rollout scope');
      }
    }
    const modelDraft={action:'rollout.create',targetPackagePublicId:data.modelPackages.challenger,
      baselinePackagePublicId:data.modelPackages.champion,canaryPercent:5};
    expect(await localManager.post(models,{...modelDraft,locationId:data.west}),403,'Local manager cannot create a West rollout');
    expect(await localManager.post(models,modelDraft),403,'Local manager cannot create a tenant-wide rollout');
    expect(await localManager.post(models,{...modelDraft,locationId:data.east}),201,'Local manager can create an East rollout');
    const modelActivation={action:'rollout.activate',publicId:data.modelRollouts.east};
    expect(await localManager.post(models,modelActivation),422,'Passing model reports cannot replace manager review');
    const modelEvidence=expect(await localManager.post(models,{...modelActivation,action:'rollout.shadow_evidence'}),
      200,'Authorized manager can review completed local evidence').shadowEvidence;
    assert.equal(modelEvidence.detectionFrameCount,30);
    const activatedModel=expect(await localManager.post(models,{...modelActivation,shadowReview:{
      runPublicId:modelEvidence.publicId,reviewHash:modelEvidence.reviewHash,reason:'Reviewed representative fixture ingredients.'
    }}),200,'Authorized reviewer can activate the exact local evidence').rollout;
    assert.equal(activatedModel.status,'active');

    expect(await localManager.request(lab),403,'A location grant cannot read the pooled Lab catalog');
    expect(await localManager.post(lab,{action:'mission.create',title:'Local manager',locationId:data.east}),403,
      'Location-scoped management cannot mutate the pooled Lab');
    const labViewer=await signIn('lab-viewer');
    expect(await labViewer.request(lab),200,'Restaurant-wide viewers can read the pooled Lab');
    expect(await labViewer.post(lab,{action:'mission.create',title:'Read only',locationId:data.east}),403,
      'Lab read scope does not grant writes');

    const mediaApi='/api/glasses-vision-media.php';
    const capture={action:'upload',consentBasis:'training_media_opt_in',perceptualHash:'0000000000000000',
      imageBase64:'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAe0lEQVR4nO3PQQ0AIBDAsJPIBwn4t4AIHg3Jkgno5uz1dcMFDWhBA1rQgBY0oAUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY0oAUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY0oAUNaEEDWtCAFjx2AQHjoPEt7JAfAAAAAElFTkSuQmCC'};
    expect(await localManager.request(mediaApi),403,'Local training authority cannot browse pooled media');
    expect(await localManager.post(mediaApi,{...capture,devicePublicId:data.westDevice}),403,
      'Local training capture cannot attach to a device in another location');
    expect(await localManager.post(mediaApi,capture),403,'Unbound capture requires pooled management');
    const firstMedia=expect(await localManager.post(mediaApi,{...capture,devicePublicId:data.device,
      buildSessionPublicId:data.session}),201,'Location manager can upload a governed local capture').media;
    const secondMedia=expect(await localManager.post(mediaApi,{...capture,devicePublicId:data.device}),201,
      'The same local device can submit another capture').media;
    assert.notEqual(firstMedia.publicId,secondMedia.publicId);
    assert.deepEqual([secondMedia.exactDuplicates,secondMedia.nearDuplicates],[[],[]],
      'Scoped captures cannot enumerate pooled duplicate media');
    expect(await localManager.request(mediaApi+'?publicId='+firstMedia.publicId),403,
      'Scoped upload is not pooled byte download permission');

    const manager=await signIn('manager');
    const globalModelCatalog=expect(await manager.request(models),200,'Tenant-wide model catalogue').catalog;
    assert.ok(Object.values(data.modelRollouts).every(id=>globalModelCatalog.rollouts.some(rollout=>rollout.publicId===id)));
    const labAction=(action,input={})=>manager.post(lab,{action,...input});
    const denied=await labAction('mission.create',{title:'Foreign restaurant',locationId:data.foreign});
    expect(denied,422,'Foreign location cannot be attached to a mission');
    assert.ok(!denied.text.includes(data.foreignName),'Response cannot disclose the foreign location name');
    expect(await labAction('mission.create',{title:'Wrong station',locationId:data.east,stationPublicId:data.westStation}),
      422,'Station and location must agree');
    assert.equal(state().glasses_vision_training_missions,before.glasses_vision_training_missions);
    const mission=expect(await labAction('mission.create',{title:'Authorized mission',locationId:data.east,
      stationPublicId:data.station,assignedDevicePublicId:data.device}),201,'Valid tenant and station can create a mission').mission;
    assert.equal(mission.locationName,'Fixture East');

    const noPlan=expect(await labAction('dataset.freeze',{datasetPublicId:data.dataset}),422,
      'Canonical Freeze resolves a real draft and enforces its split plan');
    assert.match(noPlan.message,/split plan/i);
    expect(await labAction('dataset.freeze',{datasetPublicId:data.dataset,publicId:'other'}),422,
      'Conflicting Freeze identifiers are rejected');
    const {plan}=expect(await labAction('curation.plan_split',{datasetPublicId:data.dataset}),201,'Create a real split plan');
    expect(await labAction('curation.apply_split',{publicId:plan.publicId}),200,'Apply the split plan');
    const frozen=expect(await labAction('dataset.freeze',{datasetPublicId:data.dataset}),200,'Freeze the draft').dataset;
    assert.equal(frozen.status,'frozen');
    assert.match(frozen.datasetHash,/^[a-f0-9]{64}$/);
    const replay=expect(await labAction('dataset.freeze',{publicId:data.dataset}),200,'Legacy Freeze retry').dataset;
    assert.equal(replay.datasetHash,frozen.datasetHash);
    assert.equal(replay.frozenAt,frozen.frozenAt);
    expect(await sim(manager,'kds.ticket.action',{checkPublicId:data.check,ticketAction:'hold'}),200,
      'A kitchen operator can hold the ticket');
    const staleShadow=expect(await sim(manager,'vision.shadow_assignment',{detectorName:'boundary-fixture'}),422,
      'A held ticket cannot start a shadow run through its old build');
    assert.match(staleShadow.message,/no longer active/i);
    console.log('Vision Lab HTTP boundaries, browser-read purity, device heartbeat and dataset Freeze passed.');
  }finally{
    if(server&&server.exitCode===null&&server.pid){
      const exited=new Promise(resolve=>server.once('exit',resolve));
      server.kill();
      await exited;
    }
    rmSync(folder,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
