'use strict';
const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const path=require('node:path');
const net=require('node:net');
const root=path.resolve(__dirname,'..');
const php=process.env.PHP_BINARY||'php';
function fixture(...args){
  const run=spawnSync(php,[path.join(__dirname,'hardening-inventory-http-fixture.php'),...args],{cwd:root,encoding:'utf8'});
  assert.equal(run.status,0,run.stderr||'Stock fixture failed.');return JSON.parse(run.stdout);
}
async function freePort(){const server=net.createServer();await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const port=server.address().port;await new Promise(resolve=>server.close(resolve));return port;}
(async()=>{
  const data=fixture('seed');const port=await freePort();const origin=`http://127.0.0.1:${port}`;
  const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,stdio:['ignore','ignore','pipe']});
  let errors='';server.stderr.on('data',chunk=>{errors+=chunk;});
  class Browser{
    constructor(){this.cookies=new Map();this.token='';}
    async request(route,body,form=false,validCsrf=true){
      const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
      if(body){headers['Content-Type']=form?'application/x-www-form-urlencoded':'application/json';if(!form)headers['X-CSRF-Token']=validCsrf?this.token:'invalid';}
      const response=await fetch(origin+'/'+route,{method:body?'POST':'GET',headers,body:body?(form?new URLSearchParams(body):JSON.stringify(body)):undefined,redirect:'manual'});
      for(const line of response.headers.getSetCookie()){const pair=line.split(';',1)[0];const at=pair.indexOf('=');if(at>0)this.cookies.set(pair.slice(0,at),pair.slice(at+1));}
      const text=await response.text();let json;try{json=JSON.parse(text);}catch{}
      return {status:response.status,location:response.headers.get('location'),text,json};
    }
    async login(kind){
      const page=await this.request('login.php');const csrf=page.text.match(/name="csrf_token" value="([a-f0-9]+)"/);assert.ok(csrf);
      const logged=await this.request('login.php',{csrf_token:csrf[1],email:data.users[kind].email,password:data.password,return:'workspace.php'},true);
      assert.equal(logged.location,'workspace.php','Real login must establish the test account.');
      const workspace=await this.request('workspace.php');assert.equal(workspace.status,200);
      const token=workspace.text.match(/window\.RESTAURANT_CSRF_TOKEN=("[a-f0-9]+")/);assert.ok(token,'Workspace provides a fresh CSRF token.');this.token=JSON.parse(token[1]);
    }
  }
  const snapshot=()=>fixture('snapshot',String(data.organizationId));
  try{
    let ready=false;for(let i=0;i<60;i++){try{await fetch(origin+'/login.php',{redirect:'manual'});ready=true;break;}catch{}await new Promise(resolve=>setTimeout(resolve,100));}
    assert.ok(ready,'PHP server did not start: '+errors);
    const owner=new Browser(),viewer=new Browser(),foreign=new Browser();await owner.login('owner');await viewer.login('viewer');await foreign.login('foreign');
    const adjustment={action:'inventory_adjust',id:data.stock,type:'use',quantity:5};
    let before=snapshot();let response=await owner.request('api/operations.php',adjustment);
    assert.equal(response.status,422);assert.equal(response.json.ok,false);assert.match(response.json.message,/insufficient/);
    assert.deepEqual(snapshot(),before,'Rejected excessive manual use preserves the database.');
    response=await viewer.request('api/operations.php',{...adjustment,quantity:1});assert.equal(response.status,403);
    response=await foreign.request('api/operations.php',{...adjustment,quantity:1});assert.equal(response.status,422);
    response=await owner.request('api/operations.php',{...adjustment,quantity:1},false,false);assert.equal(response.status,419);
    assert.deepEqual(snapshot(),before,'Permission, tenant and CSRF denials cannot write stock.');
    response=await owner.request('api/operations.php',{...adjustment,quantity:1.25});assert.equal(response.status,200);assert.equal(response.json.ok,true);assert.equal(Number(response.json.item.on_hand_quantity),1.75);
    before=snapshot();response=await owner.request('api/operations.php',{action:'inventory_save',id:data.stock,baseUnit:'g'});
    assert.equal(response.status,422);assert.match(response.json.message,/unit cannot be changed/);assert.deepEqual(snapshot(),before);
    response=await owner.request('api/operations.php',{action:'inventory_save',id:data.stock,name:'Durable HTTP stock',baseUnit:'kg',parLevel:6});assert.equal(response.status,200);
    const inventory=await owner.request('api/operations.php?action=inventory');assert.equal(inventory.json.items.find(x=>x.public_id===data.stock).name,'Durable HTTP stock');

    const receive=data.orders.receive,cancel=data.orders.cancel,draft=data.orders.draft;
    const receiveInput={action:'receive',id:receive.id,lines:[{itemId:receive.line,quantity:2}]};
    before=snapshot();assert.equal((await viewer.request('api/purchasing.php',receiveInput)).status,403);
    assert.equal((await foreign.request('api/purchasing.php',receiveInput)).status,422);
    assert.equal((await owner.request('api/purchasing.php',receiveInput,false,false)).status,419);assert.deepEqual(snapshot(),before);
    response=await owner.request('api/purchasing.php',receiveInput);assert.equal(response.status,200);assert.equal(response.json.status,'partially_received');
    response=await owner.request('api/purchasing.php',{action:'po_cancel',id:receive.id});assert.equal(response.status,200);assert.equal(response.json.order.status,'cancelled');
    assert.equal(Number(response.json.order.items[0].received_base_quantity),2,'Partial cancellation retains delivered quantity.');
    before=snapshot();response=await owner.request('api/purchasing.php',{...receiveInput,lines:[{itemId:receive.line,quantity:3}]});assert.equal(response.status,422);assert.deepEqual(snapshot(),before,'A cancelled order cannot receive more stock.');
    response=await owner.request('api/purchasing.php',{action:'po_cancel',id:cancel.id});assert.equal(response.status,200);
    response=await owner.request('api/purchasing.php',{action:'po_submit',id:draft.id});assert.equal(response.status,200);assert.equal(response.json.order.status,'submitted');
    response=await owner.request('api/purchasing.php',{action:'receive',id:draft.id,lines:[{itemId:draft.line,quantity:5}]});assert.equal(response.status,200);assert.equal(response.json.status,'received');
    before=snapshot();response=await owner.request('api/purchasing.php',{action:'po_cancel',id:draft.id});assert.equal(response.status,422);assert.deepEqual(snapshot(),before,'A completed order cannot be cancelled.');
    assert.equal((await new Browser().request('api/purchasing.php')).status,302);
    for(const name of ['hardening-inventory-http-fixture.php','hardening-inventory-purchasing-contract.php'])assert.equal((await new Browser().request('tests/'+name)).status,404,'Database test entrypoints stay private.');
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(errors),'HTTP tests must not hide PHP errors: '+errors);
    console.log('Inventory and purchasing HTTP: PASS (native writes, exact ledger, unit guard, state transitions, permission/tenant/CSRF and test-entrypoint protection)');
  }finally{server.kill('SIGTERM');}
})().catch(error=>{console.error(error);process.exitCode=1;});
