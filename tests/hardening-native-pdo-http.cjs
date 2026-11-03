'use strict';
const assert = require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const path=require('node:path');
const net=require('node:net');
const root=path.resolve(__dirname,'..');
const php=process.env.PHP_BINARY||'php';
function fixture(...args){
  const r=spawnSync(php,[path.join(__dirname,'hardening-native-pdo-fixture.php'),...args],{cwd:root,encoding:'utf8'});
  assert.equal(r.status,0,r.stderr||'Native PDO fixture failed');return JSON.parse(r.stdout);
}
async function freePort(){const socket=net.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));return port;}
(async()=>{
  const data=fixture('seed');assert.equal(data.nativePrepares,true,'Actual bootstrap must use native PDO prepares.');
  const port=await freePort();const origin=`http://127.0.0.1:${port}`;
  const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,stdio:['ignore','ignore','pipe']});
  let errors='';server.stderr.on('data',b=>errors+=b);
  class Browser {
    constructor(){this.cookies=new Map();this.csrf='';}
    async request(route,json,form,token=this.csrf){
      const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
      let body;if(json){headers['Content-Type']='application/json';headers['X-CSRF-Token']=token;body=JSON.stringify(json);}
      if(form){headers['Content-Type']='application/x-www-form-urlencoded';body=new URLSearchParams(form);}
      const response=await fetch(origin+'/'+route,{method:body?'POST':'GET',headers,body,redirect:'manual'});
      for(const line of response.headers.getSetCookie()){const pair=line.split(';',1)[0];const i=pair.indexOf('=');if(i>0)this.cookies.set(pair.slice(0,i),pair.slice(i+1));}
      const text=await response.text();let result;try{result=JSON.parse(text);}catch{}
      return {status:response.status,location:response.headers.get('location'),text,data:result};
    }
    async login(kind){
      const page=await this.request('login.php');const csrf=page.text.match(/name="csrf_token" value="([a-f0-9]+)"/);assert.ok(csrf,'Real login CSRF missing.');
      const login=await this.request('login.php',null,{csrf_token:csrf[1],email:data.users[kind].email,password:data.password,return:'workspace.php'});
      assert.equal(login.location,'workspace.php','Real login must establish a session.');
      const workspace=await this.request('workspace.php');assert.equal(workspace.status,200);
      const token=workspace.text.match(/RESTAURANT_CSRF_TOKEN="([a-f0-9]+)"/);assert.ok(token,'Actual workspace CSRF missing.');this.csrf=token[1];
    }
  }
  try{
    let ready=false;for(let i=0;i<60;i++){try{await fetch(origin+'/login.php');ready=true;break;}catch{}await new Promise(r=>setTimeout(r,100));}
    assert.ok(ready,'PHP server did not start: '+errors);
    const owner=new Browser(),employee=new Browser(),outsider=new Browser();
    await owner.login('owner');await employee.login('employee');await outsider.login('otherOwner');
    let response=await owner.request('api/equipment.php',{action:'save_asset',asset:{name:'Hardening Oven',assetType:'oven',purchasePrice:4200}});
    assert.equal(response.status,200,response.text);assert.equal(response.data.ok,true);
    const asset=response.data.assets.find(x=>x.name==='Hardening Oven');assert.ok(asset?.id,'Persisted asset identifier missing.');
    response=await owner.request('api/equipment.php',{action:'save_asset',asset:{id:asset.id,name:'Hardening Oven Updated',assetType:'oven',purchasePrice:4300}});
    assert.equal(response.status,200,response.text);
    response=await owner.request('api/equipment.php',{action:'save_contact',contact:{companyName:'Hardening Service',contactName:'Test Technician'}});
    assert.equal(response.status,200,response.text);const contact=response.data.contacts.find(x=>x.companyName==='Hardening Service');assert.ok(contact?.id,'Persisted contact identifier missing.');
    response=await owner.request('api/equipment.php',{action:'save_contact',contact:{id:contact.id,companyName:'Hardening Service Updated'}});assert.equal(response.status,200,response.text);
    const content='Food safety checklist. Store ingredients at their verified required temperatures.';
    response=await owner.request('api/knowledge.php',{title:'Hardening Guide',content,agentEnabled:true});
    assert.equal(response.status,200,response.text);const document=response.data.document;assert.ok(document?.id,'Persisted knowledge identifier missing.');
    response=await owner.request('api/knowledge.php',{id:document.id,title:'Hardening Guide Updated',content:content+' Check the dated receipt.',agentEnabled:true});assert.equal(response.status,200,response.text);
    const fresh=new Browser();await fresh.login('owner');
    response=await fresh.request('api/equipment.php');assert.equal(response.status,200);assert.equal(response.data.assets.find(x=>x.id===asset.id).name,'Hardening Oven Updated');assert.equal(response.data.contacts.find(x=>x.id===contact.id).companyName,'Hardening Service Updated');
    response=await fresh.request('api/knowledge.php?id='+document.id);assert.equal(response.status,200,response.text);assert.equal(response.data.document.title,'Hardening Guide Updated');assert.equal(response.data.document.version,2);assert.equal(response.data.document.content,content+' Check the dated receipt.');
    response=await owner.request('api/timeclock.php',{action:'clock_in'});assert.equal(response.status,200,response.text);
    response=await fresh.request('api/timeclock.php',{action:'clock_in'});assert.equal(response.status,422,'Independent existing session must observe the active entry.');
    for(const action of ['break_start','break_end','clock_out']){response=await owner.request('api/timeclock.php',{action});assert.equal(response.status,200,response.text);}
    const before=fixture('inspect',String(data.organizations.home));
    for(const [route,input] of [
      ['api/equipment.php',{action:'save_asset',asset:{name:'Denied asset'}}],
      ['api/equipment.php',{action:'save_contact',contact:{companyName:'Denied contact'}}],
      ['api/knowledge.php',{title:'Denied guide',content:'Must not persist.'}]
    ]){
      response=await employee.request(route,input);assert.equal(response.status,403,response.text);
      response=await owner.request(route,input,null,'invalid-token');assert.equal(response.status,419,response.text);
    }
    for(const [route,input] of [
      ['api/equipment.php',{action:'save_asset',asset:{name:''}}],
      ['api/equipment.php',{action:'save_contact',contact:{companyName:''}}],
      ['api/knowledge.php',{title:'',content:'Valid content with an invalid title.'}]
    ]){response=await owner.request(route,input);assert.equal(response.status,422,response.text);}
    for(const [route,input,status] of [
      ['api/equipment.php',{action:'save_asset',asset:{id:asset.id,name:'Cross-tenant edit'}},422],
      ['api/equipment.php',{action:'save_contact',contact:{id:contact.id,companyName:'Cross-tenant edit'}},422],
      ['api/knowledge.php',{id:document.id,title:'Cross-tenant edit',content:'Must not persist.'},404]
    ]){response=await outsider.request(route,input);assert.equal(response.status,status,response.text);}
    assert.deepEqual(fixture('inspect',String(data.organizations.home)),before,'Rejected writes must preserve saved data and audit history.');
    const other=fixture('inspect',String(data.organizations.other));assert.equal(other.equipment_assets.length+other.equipment_service_contacts.length+other.knowledge_documents.length+other.audits.length,0,'Cross-tenant edits must not create rows.');
    for(const table of ['equipment_assets','equipment_service_contacts','knowledge_documents']){
      assert.equal(before[table].length,1,`${table} must contain one durable record.`);
      assert.equal(Number(before[table][0].created_by),data.users.owner.id);assert.equal(Number(before[table][0].updated_by),data.users.owner.id);
    }
    assert.deepEqual(before.audits,['equipment.created','equipment.updated','equipment.contact_created','equipment.contact_updated','knowledge.created','knowledge.updated']);
    // Account-type controls share the lifecycle authorization and revoke affected sessions.
    const roleManager=new Browser();await roleManager.login('roleManager');
    const roleInput=(id,permissions)=>({action:'update',roleId:'db-role-'+id,name:'Role hardening fixture',permissions});
    const managerKeys=['roles.view','roles.create','roles.edit','roles.assign_permissions'];
    for(const [input,status] of [
      [{action:'create',name:'Forbidden grant',slug:'forbidden-grant',permissions:['users.suspend']},403],
      [roleInput(data.users.roleManager.roleId,[...managerKeys,'users.suspend']),403],
      [roleInput(data.users.roleManager.roleId,[...managerKeys,'dashboard.view']),403],
      [roleInput(data.roles.broader,[]),403],
      [roleInput(data.roles.legacyProtected,[]),422],
      [roleInput(data.users.owner.roleId,[]),422]
    ]){response=await roleManager.request('api/roles.php',input);assert.equal(response.status,status,response.text);}
    response=await roleManager.request('api/roles.php',{action:'create',name:'Managed read role',slug:'managed_read_role',permissions:['roles.view']});
    assert.equal(response.status,200,response.text);assert.ok(response.data.roles.find(r=>r.slug==='managed_read_role')?.permissions.includes('roles.view'));
    response=await owner.request('api/roles.php',roleInput(data.roles.legacyProtected,[]));assert.equal(response.status,422,'Legacy system Super Admin must remain protected even with a cleared owner flag.');
    response=await outsider.request('api/roles.php',roleInput(data.users.employee.roleId,[]));assert.equal(response.status,422,'Role editing must be scoped to the signed-in restaurant.');
    response=await owner.request('api/roles.php',roleInput(data.users.employee.roleId,['equipment.view']));assert.equal(response.status,200,response.text);
    response=await employee.request('api/equipment.php');assert.equal(response.status,302,'Changing role permissions must revoke affected old sessions.');
    await employee.login('employee');response=await employee.request('api/equipment.php');assert.equal(response.status,200,'A new session must use the saved role permissions.');
    for(const name of ['hardening-native-pdo-fixture.php','hardening-timeclock-concurrency.php'])assert.equal((await new Browser().request('tests/'+name)).status,404,'Database fixtures must not execute over HTTP.');
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(errors),errors);
    console.log('Native PDO HTTP writes: PASS (equipment, contact, knowledge create/edit, independent login persistence, permission/CSRF/validation/tenant denial, bounded role grants, role-change session revocation and audit)');
  }finally{server.kill('SIGTERM');}
})().catch(e=>{console.error(e);process.exitCode=1;});
