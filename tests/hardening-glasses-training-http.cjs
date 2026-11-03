'use strict';
const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const net=require('node:net');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const php=process.env.PHP_BINARY||'php';
function fixture(operation='seed',input={}){
  const run=spawnSync(php,[path.join(__dirname,'hardening-glasses-training-fixture.php'),operation,JSON.stringify(input)],{cwd:root,encoding:'utf8',timeout:30000});
  assert.equal(run.status,0,run.stderr||String(run.error||'Training fixture failed.'));return JSON.parse(run.stdout);
}
async function freePort(){
  const server=net.createServer();await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});
  const port=server.address().port;await new Promise(resolve=>server.close(resolve));return port;
}
(async()=>{
  const data=fixture();const port=await freePort();const origin=`http://127.0.0.1:${port}`;
  const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,stdio:['ignore','ignore','pipe']});
  let errors='',startError;server.on('error',error=>{startError=error;});server.stderr.on('data',chunk=>{errors+=chunk;});
  const snapshot=()=>fixture('snapshot',{org:data.organizationId});
  async function device(which,body,token){
    const bearer=token===undefined?data.devices[which].token:token;const headers={'Content-Type':'application/json'};
    if(bearer)headers.Authorization='Bearer '+bearer;
    const response=await fetch(origin+'/api/glasses-device.php',{method:'POST',headers,body:JSON.stringify(body),signal:AbortSignal.timeout(15000)});
    const text=await response.text();let json;try{json=JSON.parse(text);}catch{}
    return {status:response.status,text,json};
  }
  class Browser{
    constructor(){this.cookies=new Map();this.token='';}
    async request(route,body,form=false,validCsrf=true){
      const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([key,value])=>`${key}=${value}`).join('; ');
      if(body){headers['Content-Type']=form?'application/x-www-form-urlencoded':'application/json';if(!form)headers['X-CSRF-Token']=validCsrf?this.token:'invalid';}
      const response=await fetch(origin+'/'+route,{method:body?'POST':'GET',headers,body:body?(form?new URLSearchParams(body):JSON.stringify(body)):undefined,redirect:'manual',signal:AbortSignal.timeout(15000)});
      for(const line of response.headers.getSetCookie()){const pair=line.split(';',1)[0];const at=pair.indexOf('=');if(at>0)this.cookies.set(pair.slice(0,at),pair.slice(at+1));}
      const text=await response.text();let json;try{json=JSON.parse(text);}catch{}
      return {status:response.status,location:response.headers.get('location'),text,json};
    }
    async login(kind){
      const page=await this.request('login.php');const csrf=page.text.match(/name="csrf_token" value="([a-f0-9]+)"/);assert.ok(csrf,'Login form needs CSRF.');
      const logged=await this.request('login.php',{csrf_token:csrf[1],email:data.users[kind].email,password:data.password,return:'workspace.php'},true);
      assert.equal(logged.location,'workspace.php',logged.text);
      const workspace=await this.request('workspace.php');assert.equal(workspace.status,200);
      const token=workspace.text.match(/window\.RESTAURANT_CSRF_TOKEN=("[a-f0-9]+")/);assert.ok(token,'Signed-in manager/viewer needs a fresh CSRF token.');this.token=JSON.parse(token[1]);
    }
  }
  try{
    let ready=false;for(let i=0;i<80;i++){
      if(startError)throw startError;if(server.exitCode!==null)throw new Error('PHP server exited: '+errors);
      try{await fetch(origin+'/tests/hardening-glasses-training-fixture.php',{signal:AbortSignal.timeout(500)});ready=true;break;}catch{}
      await new Promise(resolve=>setTimeout(resolve,50));
    }
    assert.ok(ready,'PHP server did not start: '+errors);
    const cliOnly=['fixture','contract','concurrency'].map(name=>`hardening-glasses-training-${name}.php`);
    cliOnly.push('hardening-high-impact-role-contract.php');
    for(const name of cliOnly)for(const method of ['GET','POST','HEAD']){
      const response=await fetch(`${origin}/tests/${name}`,{method,signal:AbortSignal.timeout(3000)});
      assert.equal(response.status,404,'Database tests must reject '+method+' before bootstrap.');
      assert.match(response.headers.get('content-type')||'',/^text\/plain/i);
      if(method!=='HEAD')assert.equal(await response.text(),'Not found.');
    }
    const start=key=>({action:'training.session_start',assignmentPublicId:data.assignments[key].public_id});
    let response=await device('A',start('A'));assert.equal(response.status,201,response.text);const sessionA=response.json.session;
    response=await device('B',start('B'));assert.equal(response.status,201,response.text);const sessionB=response.json.session;
    assert.equal(Number(sessionA.device_id),Number(data.devices.A.row.id));assert.equal(Number(sessionB.device_id),Number(data.devices.B.row.id));
    const end={action:'training.session_end',sessionPublicId:sessionA.public_id,status:'completed',reason:'HTTP completion'};
    let before=snapshot();
    for(const status of ['completed','cancelled','interrupted']){
      response=await device('B',{...end,status,organizationId:data.organizationId,actorUserId:data.users.manager.id,devicePublicId:data.devices.A.row.public_id});
      assert.equal(response.status,422,response.text);assert.equal(response.json.message,'Training session not found.');assert.equal(response.json.session,undefined);
    }
    response=await device('foreign',end);assert.equal(response.status,422,response.text);assert.equal(response.json.message,'Training session not found.');
    response=await device('B',{...end,sessionPublicId:'unknown-session'});assert.equal(response.status,422);assert.equal(response.json.message,'Training session not found.');
    assert.equal((await device('A',end,'')).status,401,'A session public ID is not a bearer credential.');
    assert.equal((await device('A',end,'0'.repeat(64))).status,422,'An unknown bearer token cannot end training.');
    assert.deepEqual(snapshot(),before,'Device, tenant, payload-spoofing and authentication denials leave every training ledger unchanged.');

    fixture('membership',{org:data.organizationId,user:data.users.pairA.id,status:'suspended'});
    response=await device('A',end);assert.equal(response.status,422);assert.match(response.json.message,/re-pair/);
    response=await device('A',start('A'));assert.equal(response.status,422);assert.match(response.json.message,/re-pair/);
    fixture('membership',{org:data.organizationId,user:data.users.pairA.id,status:'active'});
    fixture('account',{user:data.users.pairB.id,status:'suspended'});
    response=await device('B',{...end,sessionPublicId:sessionB.public_id});assert.equal(response.status,422);assert.match(response.json.message,/re-pair/);
    fixture('account',{user:data.users.pairB.id,status:'active'});
    assert.deepEqual(snapshot(),before,'Suspended local or global accountable users cannot write training state.');

    response=await device('A',end);assert.equal(response.status,200,response.text);assert.equal(response.json.session.status,'completed');const completed=response.json.session;
    before=snapshot();response=await device('A',{...end,status:'cancelled',reason:'Conflicting retry'});
    assert.equal(response.status,200,response.text);assert.equal(response.json.session.status,'completed');assert.equal(response.json.session.ended_at,completed.ended_at);assert.equal(response.json.session.end_reason,completed.end_reason);
    assert.deepEqual(snapshot(),before,'Own-session replay over HTTP is idempotent even with a conflicting requested status.');
    response=await device('B',end);assert.equal(response.status,422);assert.equal(response.json.message,'Training session not found.');
    assert.equal((await device('A',start('A'))).status,422,'A completed assignment cannot be restarted.');
    const assignmentA=before.glasses_training_assignments.find(row=>Number(row.id)===Number(data.assignments.A.id));
    const genericA=before.training_assignments.find(row=>Number(row.id)===Number(assignmentA.training_assignment_id));
    assert.equal(assignmentA.status,'completed');assert.equal(genericA.status,'completed');assert.ok(assignmentA.completed_at&&genericA.completed_at);
    const auditA=before.audit_log.filter(row=>row.action==='glasses.training_session_ended'&&row.entity_id===sessionA.public_id);
    assert.equal(auditA.length,1);assert.equal(Number(auditA[0].actor_user_id),data.users.pairA.id);assert.equal(JSON.parse(auditA[0].new_values_json).authority,'device');

    const manager=new Browser(),viewer=new Browser(),foreignManager=new Browser();
    await manager.login('manager');await viewer.login('viewer');await foreignManager.login('foreign');
    const managerEnd={action:'training.session_end',sessionPublicId:sessionB.public_id,status:'completed',reason:'Manager HTTP completion'};
    before=snapshot();
    assert.equal((await viewer.request('api/glasses-vision-lab.php',managerEnd)).status,403,'Viewing training does not grant the management override.');
    assert.equal((await manager.request('api/glasses-vision-lab.php',managerEnd,false,false)).status,419,'The manager route keeps its CSRF requirement.');
    response=await foreignManager.request('api/glasses-vision-lab.php',managerEnd);assert.equal(response.status,422,response.text);assert.equal(response.json.message,'Training session not found.');
    fixture('permission',{org:data.organizationId,role:data.users.manager.role_id,enabled:false});
    assert.equal((await manager.request('api/glasses-vision-lab.php',managerEnd)).status,403,'A signed-in manager cannot use a revoked permission.');
    fixture('permission',{org:data.organizationId,role:data.users.manager.role_id,enabled:true});
    assert.deepEqual(snapshot(),before,'Manager permission, tenant and CSRF failures cannot change training state.');
    response=await manager.request('api/glasses-vision-lab.php',managerEnd);assert.equal(response.status,200,response.text);assert.equal(response.json.session.status,'completed');
    before=snapshot();response=await manager.request('api/glasses-vision-lab.php',{...managerEnd,status:'interrupted'});assert.equal(response.status,200);assert.equal(response.json.session.status,'completed');
    assert.deepEqual(snapshot(),before,'Manager replays are idempotent too.');
    const auditB=before.audit_log.filter(row=>row.action==='glasses.training_session_ended'&&row.entity_id===sessionB.public_id);
    assert.equal(auditB.length,1);assert.equal(Number(auditB[0].actor_user_id),data.users.manager.id);assert.equal(JSON.parse(auditB[0].new_values_json).authority,'manager');
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(errors),'HTTP tests must not conceal PHP failures: '+errors);
    console.log('Glasses training HTTP: PASS (real independent device bearers, tenant/account denial, payload spoofing, manager permission/CSRF, coherent completion, replay, audit identity and CLI-only tests)');
  }finally{
    server.kill('SIGTERM');await new Promise(resolve=>{if(server.exitCode!==null||startError)resolve();else server.once('exit',resolve);});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
