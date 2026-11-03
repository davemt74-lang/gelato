'use strict';
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const path = require('node:path');
const net = require('node:net');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
function fixture(...args) {
  const run = spawnSync(php, [path.join(__dirname, 'hardening-auth-fixture.php'), ...args], {cwd:root,encoding:'utf8'});
  assert.equal(run.status, 0, run.stderr || 'Authentication fixture failed.');
  return run.stdout;
}
function csrf(html) {
  const match = html.match(/name="csrf_token" value="([a-f0-9]+)"/);
  assert.ok(match, 'The actual PHP form must provide a CSRF token.');
  return match[1];
}
async function freePort() {
  const socket = net.createServer();
  await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
  const port=socket.address().port;
  await new Promise(resolve=>socket.close(resolve));
  return port;
}
(async()=>{
  const data=JSON.parse(fixture('seed'));
  const port=await freePort();
  const origin=`http://127.0.0.1:${port}`;
  const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,stdio:['ignore','ignore','pipe']});
  let errors=''; server.stderr.on('data',chunk=>{errors+=chunk;});
  class BrowserSession {
    constructor(){this.cookies=new Map();}
    async request(route,fields){
      const headers={};
      if(this.cookies.size)headers.Cookie=[...this.cookies].map(([key,value])=>`${key}=${value}`).join('; ');
      if(fields)headers['Content-Type']='application/x-www-form-urlencoded';
      const response=await fetch(origin+'/'+route,{method:fields?'POST':'GET',headers,body:fields?new URLSearchParams(fields):undefined,redirect:'manual'});
      for(const line of response.headers.getSetCookie()){
        const pair=line.split(';',1)[0]; const i=pair.indexOf('=');
        if(i>0)this.cookies.set(pair.slice(0,i),pair.slice(i+1));
      }
      return {status:response.status,location:response.headers.get('location'),body:await response.text()};
    }
    async login(kind,password=data.password){
      const form=await this.request('login.php');
      return this.request('login.php',{csrf_token:csrf(form.body),email:data.users[kind].email,password,return:'workspace.php'});
    }
  }
  try{
    let ready=false;
    for(let attempt=0;attempt<60;attempt++){
      try{await fetch(origin+'/login.php',{redirect:'manual'});ready=true;break;}catch{}
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    assert.ok(ready,'PHP HTTP server did not start: '+errors);
    const ownerOne=new BrowserSession();const ownerTwo=new BrowserSession();const employee=new BrowserSession();
    for(const owner of [ownerOne,ownerTwo])assert.equal((await owner.login('owner')).location,'workspace.php','Actual password login must redirect to workspace.');
    const ownerPage=await ownerOne.request('workspace.php');
    assert.equal(ownerPage.status,200);
    assert.ok(ownerPage.body.includes(data.users.coworker.email),'Owner retains authorized roster.');
    assert.ok(ownerPage.body.includes(data.draft),'Owner retains authorized hiring drafts.');
    assert.equal((await employee.login('employee')).location,'workspace.php');
    const employeePage=await employee.request('workspace.php');
    assert.equal(employeePage.status,200);
    assert.ok(employeePage.body.includes(data.users.employee.email),'Employee retains their own identity.');
    assert.ok(!employeePage.body.includes(data.users.coworker.email),'The actual PHP workspace must not deliver another staff email.');
    assert.ok(!employeePage.body.includes(data.draft),'The actual PHP workspace must not deliver unpublished jobs.');
    assert.ok(!employeePage.body.includes('localStorage.setItem("restaurant-admin-users-v1"'),'Server workspace must not persist staff payload into shared local storage.');

    const recovery=new BrowserSession();
    const resetPage=await recovery.request('reset-password.php?token='+data.resetToken);
    const recoveredPassword='Http-Recovered-Password!43';
    const completed=await recovery.request('reset-password.php',{token:data.resetToken,csrf_token:csrf(resetPage.body),password:recoveredPassword,password_confirmation:recoveredPassword,employee_pin:'928453',confirm_employee_pin:'928453'});
    assert.equal(completed.location,'login.php?reset=success','Actual reset form must complete successfully.');
    for(const owner of [ownerOne,ownerTwo]){
      const revoked=await owner.request('workspace.php');
      assert.equal(revoked.status,302,'Independent old sessions must be revoked at the protected endpoint.');
      assert.match(revoked.location,/^login\.php\?return=/);
    }
    const replacement=new BrowserSession();
    const oldPassword=await replacement.login('owner');
    assert.equal(oldPassword.status,200);
    assert.ok(oldPassword.body.includes('The email or password is incorrect.'));
    assert.equal((await replacement.login('owner',recoveredPassword)).location,'workspace.php');
    assert.equal((await replacement.request('workspace.php')).status,200,'New password must authenticate after global revocation.');
    const replayPage=await recovery.request('reset-password.php?token='+data.resetToken);
    assert.ok(replayPage.body.includes('Reset link unavailable'),'Consumed reset URL must no longer offer a reset.');

    for(const mode of ['idle','absolute','legacy']){
      const expiring=new BrowserSession();
      assert.equal((await expiring.login('employee')).location,'workspace.php');
      fixture('expire',expiring.cookies.get(data.sessionName),mode);
      const expired=await expiring.request('workspace.php');
      assert.equal(expired.status,302,`${mode} session must fail on the actual protected page.`);
      assert.match(expired.location,/^login\.php\?return=/);
    }
    const logout=await employee.request('logout.php');
    assert.equal(logout.status,200);
    assert.ok(logout.body.includes('data-logout-target="login.php"'),'Server logout must execute targeted browser cleanup.');
    assert.equal((await employee.request('workspace.php')).status,302,'Logout must destroy server authentication.');
    for(const file of ['hardening-auth-fixture.php','hardening-auth-contract.php','hardening-workspace-contract.php']){
      assert.equal((await new BrowserSession().request('tests/'+file)).status,404,'Test fixture must not execute over HTTP.');
    }
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(errors),'HTTP integration must not hide PHP errors: '+errors);
    console.log('Authentication HTTP integration: PASS (two-session recovery, role-filtered workspace, expiry, replay, logout and guarded fixtures)');
  }finally{
    server.kill('SIGTERM');
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
