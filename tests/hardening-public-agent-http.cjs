'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const http=require('node:http');
const net=require('node:net');
const crypto=require('node:crypto');
const {spawn,spawnSync}=require('node:child_process');

const root=path.resolve(__dirname,'..');
const stage=fs.mkdtempSync(path.join(os.tmpdir(),'gelato-public-agent-'));
fs.chmodSync(stage,0o700);
const php=process.env.PHP_BINARY||'php';
const fixturePath=path.join(__dirname,'hardening-public-agent-fixture.php');
let checks=0,phpServer,phpErrors='',seeded=false,fixtureState,provider,mode='success';
const calls=[],held=[],sockets=new Set();
function check(value,label){assert.ok(value,label);checks++;}
function equal(actual,expected,label){assert.deepEqual(actual,expected,label);checks++;}
function fixture(action,input={}){
  const result=spawnSync(php,[fixturePath,action,stage],{
    cwd:root,env:{...process.env,GELATO_PUBLIC_AGENT_FIXTURE:'1'},input:JSON.stringify(input),encoding:'utf8',timeout:30000,maxBuffer:2*1024*1024,
  });
  if(result.error)throw result.error;
  if(result.status!==0)throw new Error(`Public assistant fixture ${action} failed: ${result.stderr || result.stdout}`);
  return JSON.parse(result.stdout);
}
function waitFor(predicate,label,milliseconds=5000){
  const end=Date.now()+milliseconds;
  return new Promise((resolve,reject)=>{
    const poll=()=>{if(predicate())return resolve();if(Date.now()>=end)return reject(new Error(label));setTimeout(poll,20);};poll();
  });
}
function listen(server){return new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',()=>resolve(server.address().port));});}
function close(server){return new Promise(resolve=>server.close(resolve));}
function success(response,payload){
  response.writeHead(200,{'Content-Type':'application/json'});
  response.end(JSON.stringify(payload.messages?{content:[{type:'text',text:'Fixture pizza has tomatoes and cheese.'}]}:{output_text:'Fixture pizza has tomatoes and cheese.'}));
}
function client(port,{body,raw,method='POST',headers={},localAddress='127.0.0.1',pathname='/api/public-agent.php'}={}){
  const data=raw!==undefined?raw:body===undefined?'':JSON.stringify(body);
  return new Promise((resolve,reject)=>{
    const request=http.request({hostname:'127.0.0.1',port,path:pathname,method,localAddress,agent:false,
      headers:{...(method==='POST'?{'Content-Type':'application/json','Content-Length':Buffer.byteLength(data)}:{}),...headers},timeout:8000},response=>{
      let text='';response.setEncoding('utf8');response.on('data',part=>{text+=part;});
      response.on('end',()=>{let json;try{json=JSON.parse(text);}catch{}resolve({status:response.statusCode,headers:response.headers,json,text});});
    });
    request.on('error',reject);request.on('timeout',()=>request.destroy(new Error('Public API request exceeded its test deadline.')));
    request.end(data);
  });
}
function noProviderAllocation(snapshot,label){
  equal(snapshot.usage.organization_requests||0,0,label+': provider requests');
  equal(snapshot.usage.organization_token_units||0,0,label+': provider allocation');
  equal(snapshot.leases.length,0,label+': provider slots');
}
function rateLimited(response,reason){
  equal(response.status,429,'Quota exhaustion uses HTTP 429.');
  equal(response.json.reason,reason,'The response identifies the exhausted boundary.');
  check(Number(response.headers['retry-after'])>0,'Quota exhaustion supplies Retry-After.');
  equal(Number(response.headers['retry-after']),response.json.retryAfter,'Header and JSON retry values agree.');
}

(async()=>{
  try{
    provider=http.createServer((request,response)=>{
      let raw='';request.setEncoding('utf8');request.on('data',part=>{raw+=part;});
      request.on('end',()=>{
        const payload=JSON.parse(raw);calls.push({payload,bytes:Buffer.byteLength(raw),path:request.url});
        if(mode==='hold'){held.push({response,payload});return;}
        if(mode==='timeout')return;
        if(mode==='error'){response.writeHead(503,{'Content-Type':'application/json'});response.end('{"error":"local fixture failure"}');return;}
        if(mode==='invalid'){response.writeHead(200);response.end('not-json');return;}
        if(mode==='empty'){response.writeHead(200,{'Content-Type':'application/json'});response.end('{"content":[]}');return;}
        if(mode==='oversized'){response.writeHead(200,{'Content-Type':'application/json'});response.end(JSON.stringify({content:[{type:'text',text:'x'.repeat(150000)}]}));return;}
        success(response,payload);
      });
    });
    provider.on('connection',socket=>{sockets.add(socket);socket.on('close',()=>sockets.delete(socket));});
    const providerPort=await listen(provider);
    const portProbe=net.createServer();const port=await listen(portProbe);await close(portProbe);
    fixtureState=fixture('seed',{providerPort});seeded=true;
    for(const file of fixtureState.sourceFiles){
      const digest=filePath=>crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
      equal(digest(path.join(stage,'site',file)),digest(path.join(root,file)),'The HTTP stage contains byte-identical production '+file+'.');
    }
    phpServer=spawn(php,['-d','opcache.enable_cli=0','-d',`session.save_path=${path.join(stage,'sessions')}`,'-S',`127.0.0.1:${port}`,'-t',path.join(stage,'site'),path.join(stage,'router.php')],{
      cwd:root,env:{...process.env,PHP_CLI_SERVER_WORKERS:'8'},detached:true,stdio:['ignore','ignore','pipe'],
    });
    phpServer.stderr.on('data',chunk=>{phpErrors=(phpErrors+chunk.toString()).slice(-16000);});
    let startupError;phpServer.on('error',error=>{startupError=error;});
    let ready=false;
    const startupDeadline=Date.now()+5000;
    do{
      if(startupError)throw startupError;
      try{ready=(await client(port,{method:'GET'})).json?.ready===true;}catch{}
      if(!ready)await new Promise(resolve=>setTimeout(resolve,50));
    }while(!ready&&Date.now()<startupDeadline);
    check(ready,'The actual staged production controller starts with migrated MariaDB: '+phpErrors);
    equal(calls.length,0,'Readiness does not call a provider.');
    for(const pathname of ['/tests/hardening-public-agent-fixture.php','/config.php','/storage/keys/application.key','/includes/public-agent-core.php']){
      equal((await client(port,{method:'GET',pathname})).status,404,'The isolated HTTP fixture exposes only the public API.');
    }
    const question=fixtureState.question;
    const ask=(extra={})=>client(port,{body:{message:question},...extra});
    const reset=(config={})=>{mode='success';fixture('reset',config);};

    reset({limits:{network_requests_per_10_minutes:3}});
    const freshStart=calls.length;let cookie;
    for(let i=0;i<3;i++){
      const response=await ask();equal(response.status,200,'A fresh cookie jar uses the remaining shared network allowance.');
      cookie=response.headers['set-cookie']?.[0]?.split(';')[0];check(Boolean(cookie),'Each fresh browser receives a real PHP session cookie.');
    }
    const spoofed=await ask({headers:{Cookie:'hpa_untrusted_cookie=another-jar','X-Forwarded-For':'198.51.100.88',Forwarded:'for=198.51.100.99','X-Real-IP':'192.0.2.9','User-Agent':'Changed browser'}});
    rateLimited(spoofed,'network');
    rateLimited(await ask({headers:{Cookie:cookie}}),'network');
    equal(calls.length-freshStart,3,'Changing cookies and forwarding headers cannot obtain a fourth provider call.');
    const network=fixture('snapshot');
    equal(network.usage.network,3,'Rejected attempts do not consume another intake count.');
    equal(network.buckets.filter(b=>b.bucket_type==='network').length,1,'Spoofable headers do not split a network identity.');
    check(network.buckets.every(b=>/^[a-f0-9]{64}$/.test(b.bucket_hash)),'Shared limiter state contains digests, not raw client addresses.');
    equal(network.leases.length,0,'Successful HTTP completion releases every slot.');

    for(const [config,expected,label] of [
      [{enabled:false},404,'Disabled assistant'],
      [{knowledge:false},200,'No matching public knowledge'],
      [{credential:false},503,'No configured provider'],
      [{systemPrompt:'x'.repeat(6001)},503,'Oversized configured system prompt'],
      [{context:question+' '+'x'.repeat(14001)},200,'Knowledge beyond the context ceiling'],
    ]){
      reset(config);const before=calls.length;const response=await ask();
      equal(response.status,expected,label+' has the documented response.');
      equal(calls.length,before,label+' makes no provider request.');noProviderAllocation(fixture('snapshot'),label);
      if(config.enabled===false)equal(fixture('snapshot').usage.network||0,0,'A disabled assistant does not spend intake quota.');
      if(config.knowledge===false)equal(response.json.sources,[],'No-knowledge fallback has no private or fabricated source.');
    }
    reset();const invalidBefore=calls.length;
    equal((await client(port,{raw:'{invalid'})).status,400,'Invalid JSON is rejected before provider admission.');
    equal((await client(port,{body:{message:['not','text']}})).status,422,'Non-text messages cannot reach the provider.');
    equal((await client(port,{raw:'x'.repeat(65537)})).status,413,'Oversized raw input is rejected.');
    equal(calls.length,invalidBefore,'Invalid input never reaches the provider.');noProviderAllocation(fixture('snapshot'),'Invalid input');

    reset();const unavailableStart=calls.length;
    const keyPath=path.join(stage,'site/storage/keys/application.key'),savedKey=fs.readFileSync(keyPath);
    try{
      fs.writeFileSync(keyPath,'invalid');
      equal((await ask()).status,503,'An unavailable HMAC/encryption key fails closed before admission.');
      noProviderAllocation(fixture('snapshot'),'Unavailable key');
    }finally{fs.writeFileSync(keyPath,savedKey,{mode:0o600});}
    fixture('database-unavailable');
    equal((await ask()).status,503,'An unavailable shared database fails closed.');
    equal(calls.length,unavailableStart,'Unavailable admission dependencies never reach the provider.');
    noProviderAllocation(fixture('snapshot'),'Unavailable shared database');

    reset({systemPrompt:'x'+'\0'.repeat(5998)+'x',context:question+' x'+'\0'.repeat(14000-question.length-3)+'x'});
    const hugeBefore=calls.length;
    equal((await ask({body:{message:question,history:Array.from({length:6},(_,i)=>({role:i%2?'assistant':'user',content:'x'+'\0'.repeat(1198)+'x'}))}})).status,503,'Serialized JSON expansion is bounded across prompt, knowledge, history and output allocation.');
    equal(calls.length,hugeBefore,'An over-budget serialized payload is not sent.');noProviderAllocation(fixture('snapshot'),'Serialized payload bound');

    reset({limits:{network_requests_per_10_minutes:100,organization_requests_per_10_minutes:2}});
    const orgStart=calls.length;
    for(const localAddress of ['127.0.0.2','127.0.0.3'])equal((await ask({localAddress,body:{message:question,organizationId:fixtureState.other,organization_id:fixtureState.other}})).status,200,'Distinct networks share a usable organization allowance.');
    rateLimited(await ask({localAddress:'127.0.0.4'}),'organization_requests');
    equal(calls.length-orgStart,2,'The aggregate allowance holds across different real network peers.');
    const orgSnapshot=fixture('snapshot');equal(orgSnapshot.usage.organization_requests,2,'The server-selected tenant pays for only its admitted requests.');
    equal(orgSnapshot.otherBuckets.length,0,'Client tenant fields cannot redirect budget accounting.');equal(orgSnapshot.leases.length,0,'Organization denial allocates no provider slot.');

    reset({limits:{organization_token_units_per_day:1}});const budgetStart=calls.length;
    rateLimited(await ask(),'organization_token_units');equal(calls.length,budgetStart,'Insufficient conservative token units prevent provider work.');
    noProviderAllocation(fixture('snapshot'),'Rejected token allocation');

    reset({limits:{network_requests_per_10_minutes:100,organization_concurrent_requests:2}});
    // Obtain one real session, then keep it across simultaneously held provider
    // calls. This fails if the controller holds PHP's session lock on transport.
    const primed=await ask();cookie=primed.headers['set-cookie']?.[0]?.split(';')[0];check(Boolean(cookie),'Concurrency uses an actual shared session.');
    mode='hold';const concurrentStart=calls.length;
    const first=ask({headers:{Cookie:cookie}});
    first.catch(()=>{}); // Keep failure cleanup from producing orphaned rejections.
    // A PHP CLI worker can accept both sockets before dispatching the first.
    // Open the second only once the first worker is blocked at the provider.
    await waitFor(()=>held.length===1,'The first same-session request must be held at the stub.');
    const second=ask({headers:{Cookie:cookie}});
    second.catch(()=>{});
    await waitFor(()=>held.length===2,'Both same-session requests must reach the stub concurrently.');
    equal(fixture('snapshot').leases.length,2,'Both held real HTTP requests own durable independent provider leases.');
    const busy=await ask({headers:{Cookie:cookie}});rateLimited(busy,'concurrency');
    equal(calls.length,concurrentStart+2,'A busy response is immediate without opening a third provider request.');
    const heldSnapshot=fixture('snapshot');equal(heldSnapshot.usage.organization_requests,3,'Busy denial does not spend provider request budget.');
    while(held.length){const pending=held.shift();success(pending.response,pending.payload);}
    for(const response of await Promise.all([first,second]))equal(response.status,200,'An admitted held request completes normally.');
    mode='success';equal(fixture('snapshot').leases.length,0,'Completion frees both slots.');
    equal((await ask()).status,200,'A subsequent request can use released concurrency.');

    for(const failure of ['error','invalid','empty','oversized','timeout']){
      reset({limits:{organization_concurrent_requests:1,provider_timeout_seconds:1}});mode=failure;
      const before=calls.length;const started=Date.now();const response=await ask();
      equal(response.status,502,'Provider '+failure+' has a controlled upstream error.');
      equal(calls.length,before+1,'Provider '+failure+' makes one bounded attempt.');
      const snapshot=fixture('snapshot');equal(snapshot.leases.length,0,'Provider '+failure+' releases the slot.');
      equal(snapshot.usage.organization_requests,1,'Provider '+failure+' retains its request charge.');
      equal(snapshot.usage.organization_token_units,calls.at(-1).bytes+500+1024,'Provider '+failure+' retains its full serialized reservation without trusting missing usage.');
      if(failure==='timeout')check(Date.now()-started<3500,'Transport timeout expires before the crash lease.');
      mode='success';equal((await ask()).status,200,'Provider '+failure+' does not strand concurrency.');
    }
    reset({provider:'openai'});const openaiStart=calls.length;
    equal((await ask()).status,200,'The same budget protects the OpenAI response shape.');
    equal(calls.length,openaiStart+1,'OpenAI uses exactly one provider request.');
    equal(calls.at(-1).payload.max_output_tokens,500,'OpenAI output has an explicit bound.');
    equal(calls.at(-1).payload.store,false,'OpenAI storage behavior is preserved.');
    check(calls.every(call=>call.bytes<=131072&&(call.payload.max_tokens===500||call.payload.max_output_tokens===500)),'Every actual provider request stays within serialized and output bounds.');
    console.log(`Public assistant HTTP + local provider stub: ${checks} checks passed.`);
  }finally{
    if(phpServer?.pid){try{process.kill(-phpServer.pid,'SIGTERM');}catch{}}
    for(const socket of sockets)socket.destroy();
    if(provider?.listening)await close(provider);
    if(seeded)fixture('cleanup');
    fs.rmSync(stage,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);if(phpErrors)console.error(phpErrors);process.exitCode=1;});
