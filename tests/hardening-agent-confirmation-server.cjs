'use strict';
const assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process');
const path=require('node:path'),net=require('node:net');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const fixturePath=path.join(__dirname,'hardening-agent-confirmation-fixture.php');
function fixture(...args){
  const run=spawnSync(php,[fixturePath,...args.map(String)],{cwd:root,encoding:'utf8'});
  assert.equal(run.status,0,run.stderr||run.stdout||'Confirmation fixture failed.');
  return JSON.parse(run.stdout);
}
async function start(){
  const data=fixture('seed');const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));
  const port=reserve.address().port;await new Promise(r=>reserve.close(r));const origin='http://127.0.0.1:'+port;
  const server=spawn(php,['-S','127.0.0.1:'+port,'-t',root],{cwd:root,detached:true,
    env:{...process.env,PHP_CLI_SERVER_WORKERS:'4'},stdio:['ignore','ignore','pipe']});
  let log='';server.stderr.on('data',chunk=>{log=(log+chunk).slice(-30000);});
  let ready=false;
  for(let i=0;i<100;i++){
    if(server.exitCode!==null)throw new Error('PHP server stopped: '+log);
    try{await fetch(origin+'/tests/hardening-agent-confirmation-fixture.php',{signal:AbortSignal.timeout(300)});ready=true;break;}catch{}
    await new Promise(r=>setTimeout(r,40));
  }
  assert.ok(ready,'PHP confirmation server did not start: '+log);
  class Client{
    constructor(cookie=''){this.cookie=cookie;this.csrf='';}
    get sessionId(){return this.cookie.split(';').map(s=>s.trim()).find(s=>s.startsWith(data.sessionName+'='))?.slice(data.sessionName.length+1)||'';}
    async request(route,body=null,form=false,token=null){
      const response=await fetch(origin+(route.startsWith('/')?route:'/'+route),{method:body?'POST':'GET',redirect:'manual',signal:AbortSignal.timeout(20000),
        headers:{...(this.cookie?{Cookie:this.cookie}:{}),...(body?{'Content-Type':form?'application/x-www-form-urlencoded':'application/json',
          ...(form?{}:{'X-CSRF-Token':token??this.csrf})}:{})},
        ...(body?{body:form?new URLSearchParams(body):JSON.stringify(body)}:{})});
      const saved=new Map(this.cookie.split(';').filter(Boolean).map(c=>{const i=c.indexOf('=');return [c.slice(0,i).trim(),c.slice(i+1)];}));
      for(const line of response.headers.getSetCookie()){const pair=line.split(';',1)[0];const i=pair.indexOf('=');saved.set(pair.slice(0,i),pair.slice(i+1));}
      this.cookie=[...saved].map(([k,v])=>k+'='+v).join('; ');
      const text=await response.text();let json=null;try{json=JSON.parse(text);}catch{}
      return {status:response.status,location:response.headers.get('location'),text,json,headers:response.headers};
    }
    async login(kindOrEmail,password=data.password){
      const email=data.users[kindOrEmail]?.email||kindOrEmail;
      let page=await this.request('/login.php?return=workspace.php');const csrf=page.text.match(/name="csrf_token" value="([^"]+)"/);assert.ok(csrf);
      page=await this.request('/login.php',{csrf_token:csrf[1],email,password,return:'workspace.php'},true);
      assert.equal(page.status,302,page.text);assert.equal(page.location,'workspace.php',page.text);
      page=await this.request('/workspace.php');assert.equal(page.status,200,page.text);
      const token=page.text.match(/RESTAURANT_CSRF_TOKEN="([^"]+)"/);assert.ok(token,'Workspace CSRF missing.');this.csrf=token[1];return page;
    }
    async thread(){const r=await this.request('/api/agent-workspace.php',{action:'new_thread',channel:'text'});assert.equal(r.status,200,r.text);return r.json.thread.public_id;}
    async userMessage(threadId,content){const r=await this.request('/api/agent-workspace.php',{action:'append',threadId,role:'user',channel:'text',content});assert.equal(r.status,200,r.text);return r.json.message;}
    async bind(threadId,result){
      const r=await this.request('/api/agent-workspace.php',{action:'append',threadId,role:'agent',channel:'text',
        content:result.answer||result.data?.answer,skill:result.skill||'operations.action_proposal',structured:result.data,sources:result.sources||[]});
      assert.equal(r.status,200,r.text);assert.ok(r.json.message?.structured?.proposal?.hash,'Canonical result includes binding hash.');
      return r.json.message.structured.proposal;
    }
  }
  async function stop(){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(r=>{if(server.exitCode!==null)r();else server.once('exit',r);});}
  return {data,origin,base:origin,Client,fixture,logs:()=>log,stop,close:stop};
}
module.exports={start,fixture};
