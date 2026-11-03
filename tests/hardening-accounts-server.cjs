'use strict';
const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net');
const root=path.resolve(__dirname,'..'),fixturePath=path.join(__dirname,'hardening-accounts-fixture.php');
function fixture(...args){
  const result=spawnSync('php',[fixturePath,...args.map(String)],{cwd:root,encoding:'utf8'});
  assert.equal(result.status,0,result.stderr||result.stdout);
  return JSON.parse(result.stdout);
}
function csrf(html){const match=html.match(/name="csrf_token" value="([^"]+)"/);assert.ok(match,'CSRF form token is required');return match[1];}
async function start(){
  const data=fixture('seed'),dir=fs.mkdtempSync(path.join(os.tmpdir(),'gelato-accounts-')),capture=path.join(dir,'mail.txt');
  const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));
  const port=reserve.address().port;await new Promise(r=>reserve.close(r));
  const quote=s=>"'"+s.replaceAll("'","'\\''")+"'";
  const server=spawn('php',['-d','sendmail_path=php '+quote(fixturePath)+' mail','-S','127.0.0.1:'+port,'-t',root],
    {cwd:root,env:{...process.env,GELATO_ACCOUNTS_MAIL_CAPTURE:capture},stdio:['ignore','ignore','pipe']});
  let logs='';server.stderr.on('data',s=>{logs=(logs+s).slice(-12000);});
  const base='http://127.0.0.1:'+port;
  let ready=false;
  for(let i=0;i<100;i++){
    if(server.exitCode!==null)throw new Error('PHP server stopped: '+logs);
    try{await fetch(base+'/tests/hardening-accounts-fixture.php',{signal:AbortSignal.timeout(300)});ready=true;break;}catch{}
    await new Promise(r=>setTimeout(r,40));
  }
  assert.ok(ready,'PHP server failed to start: '+logs);
  class Client{
    constructor(cookie=''){this.cookie=cookie;this.csrf='';}
    async request(route,input=null,form=false,token=null){
      const response=await fetch(base+route,{method:input?'POST':'GET',redirect:'manual',signal:AbortSignal.timeout(15000),
        headers:{...(this.cookie?{Cookie:this.cookie}:{}),...(input?{'Content-Type':form?'application/x-www-form-urlencoded':'application/json',
          ...(form?{}:{'X-CSRF-Token':token??this.csrf})}:{})},
        ...(input?{body:form?new URLSearchParams(input):JSON.stringify(input)}:{})});
      const cookies=response.headers.getSetCookie?.()||[response.headers.get('set-cookie')].filter(Boolean);
      if(cookies.length)this.cookie=cookies.at(-1).split(';')[0];
      const text=await response.text();let json=null;try{json=JSON.parse(text);}catch{}
      return {status:response.status,location:response.headers.get('location'),headers:response.headers,text,json};
    }
    async login(email,password=data.password){
      let page=await this.request('/login.php?return=workspace.php');
      page=await this.request('/login.php',{csrf_token:csrf(page.text),email,password,return:'workspace.php'},true);
      assert.equal(page.status,302,page.text);assert.equal(page.location,'workspace.php',page.text);
      page=await this.request('/workspace.php');
      assert.equal(page.status,200,page.text);
      const token=page.text.match(/RESTAURANT_CSRF_TOKEN="([^"]+)"/);assert.ok(token,'Workspace CSRF missing');
      this.csrf=token[1];return page;
    }
  }
  return {data,base,capture,Client,fixture,csrf,logs:()=>logs,
    token(){const match=fs.readFileSync(capture,'utf8').match(/account-invitation\.php\?token=([a-f0-9]{64})/);assert.ok(match,'Captured invitation link required');return match[1];},
    async close(){server.kill('SIGTERM');await new Promise(r=>{if(server.exitCode!==null)r();else server.once('exit',r);});fs.rmSync(dir,{recursive:true,force:true});}};
}
module.exports={start,fixture,csrf};
