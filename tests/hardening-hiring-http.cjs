'use strict';
const assert = require('node:assert/strict');
const {spawn,spawnSync} = require('node:child_process');
const path = require('node:path');
const net = require('node:net');
const root = path.resolve(__dirname,'..');
const fixture = path.join(__dirname,'hardening-hiring-fixture.php');
function db(mode='seed',argument={}) {
  const result=spawnSync('php',[fixture,mode,Buffer.from(JSON.stringify(argument)).toString('base64')],{cwd:root,encoding:'utf8'});
  assert.equal(result.status,0,result.stderr||result.stdout);
  return JSON.parse(result.stdout);
}
async function main() {
  const data=db();
  const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
  const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
  const server=spawn('php',['-d','upload_max_filesize=12M','-d','post_max_size=28M','-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,stdio:['ignore','ignore','pipe']});
  let logs='',failed=null;server.stderr.on('data',chunk=>{logs=(logs+chunk.toString()).slice(-10000);});server.on('error',error=>{failed=error;});
  const base=`http://127.0.0.1:${port}`;
  function client() {
    let cookie='';
    return async (route,options={})=>{
      const response=await fetch(base+route,{...options,redirect:'manual',signal:AbortSignal.timeout(15000),headers:{...(options.headers||{}),...(cookie?{Cookie:cookie}:{})}});
      const set=response.headers.getSetCookie?.()||[response.headers.get('set-cookie')].filter(Boolean);
      if(set.length)cookie=set.at(-1).split(';')[0];
      return response;
    };
  }
  const applicant=client(),manager=client(),outsider=client(),employee=client(),reader=client();
  const csrf=html=>{const match=html.match(/name="csrf_token" value="([^"]+)"/);assert.ok(match,'Login CSRF must be rendered');return match[1];};
  async function login(request,kind) {
    const page=await request('/login.php');assert.equal(page.status,200);
    const response=await request('/login.php',{method:'POST',body:new URLSearchParams({email:data.users[kind].email,password:data.password,csrf_token:csrf(await page.text())})});
    assert.equal(response.status,302,await response.text());
  }
  const pdf=Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64');
  let config,input;
  function application(overrides={},files=true) {
    const form=new FormData();
    Object.entries({...input,...overrides}).forEach(([key,value])=>form.set(key,String(value)));
    if(files){form.set('resume',new Blob([pdf],{type:'application/pdf'}),'kitchen-resume.pdf');form.append('photos[]',new Blob([png],{type:'image/png'}),'photo.png');}
    return form;
  }
  const apiPost=(request,route,body,token)=>request(route,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(body)});
  try {
    let ready=false;
    for(let i=0;i<100;i++){if(failed)throw failed;if(server.exitCode!==null)throw new Error(logs);try{await fetch(base+'/tests/hardening-hiring-fixture.php',{signal:AbortSignal.timeout(250)});ready=true;break;}catch{await new Promise(resolve=>setTimeout(resolve,30));}}
    assert.ok(ready,'PHP test server must start');
    for(const file of ['hardening-hiring-fixture.php','hardening-hiring-contract.php'])assert.equal((await fetch(base+'/tests/'+file)).status,404,'Fixture must not be reachable through HTTP');
    let response=await applicant('/api/public-applications.php');assert.equal(response.status,200);config=await response.json();
    assert.ok(config.jobs.some(job=>job.id===data.jobs.open));
    assert.ok(!config.jobs.some(job=>job.id===data.jobs.closed||job.id===data.jobs.foreign),'Public config must only show active jobs from its server-selected restaurant');
    input={csrf_token:config.csrfToken,idempotencyKey:config.idempotencyKey,jobId:data.jobs.open,firstName:'Remote',lastName:'Applicant',email:'http-'+data.suffix+'@example.test',phone:'6025550170',availability:'Weekday evenings',experience:'Kitchen and counter service',consent:'1'};
    response=await applicant('/api/public-applications.php',{method:'POST',body:application({csrf_token:'invalid'})});assert.equal(response.status,419);
    assert.equal(db('inspect',{organizationId:data.organizationId,email:input.email}).rows.length,0,'Invalid CSRF must not create a record');
    response=await applicant('/api/public-applications.php',{method:'POST',body:application({organization_id:data.otherOrganizationId})});assert.equal(response.status,201,await response.clone().text());
    const received=await response.json();const id=received.receipt.applicationId;
    assert.match(id,/^application-[a-f0-9]{32}$/);assert.equal(received.receipt.replayed,false);
    assert.ok(!JSON.stringify(received).includes(input.email),'Public receipt must not echo private applicant data');
    let stored=db('inspect',{organizationId:data.organizationId,email:input.email});assert.equal(stored.rows.length,1);assert.equal(stored.attachments.length,2);
    assert.equal(Number(stored.rows[0].organization_id),data.organizationId,'Client tenant override must be ignored');
    response=await applicant('/api/public-applications.php',{method:'POST',body:application()});assert.equal(response.status,200);
    assert.equal((await response.json()).receipt.applicationId,id);assert.equal(db('inspect',{organizationId:data.organizationId,email:input.email}).rows.length,1,'Retry must not duplicate applications');
    response=await applicant('/api/public-applications.php',{method:'POST',body:application({experience:'Different information'})});assert.equal(response.status,409,'One idempotency reference cannot store different content');
    for(const kind of ['closed','foreign']){response=await applicant('/api/public-applications.php',{method:'POST',body:application({jobId:data.jobs[kind],idempotencyKey:crypto.randomUUID().replaceAll('-','')})});assert.equal(response.status,422,'Closed/foreign job must reject intake');}
    const fake=application({idempotencyKey:crypto.randomUUID().replaceAll('-','')},false);fake.set('resume',new Blob(['<script>alert(1)</script>'],{type:'application/pdf'}),'resume.pdf');
    response=await applicant('/api/public-applications.php',{method:'POST',body:fake});assert.equal(response.status,422,'Mislabeled uploaded bytes must be rejected');
    assert.equal((await applicant('/api/resumes.php')).status,401);
    await login(manager,'manager');await login(outsider,'outsider');await login(employee,'employee');await login(reader,'reader');
    assert.equal((await employee('/api/resumes.php')).status,403,'An employee without hiring permission must not read the queue');
    response=await manager('/api/resumes.php');assert.equal(response.status,200);let queue=await response.json();const managerCsrf=queue.csrfToken;assert.ok(queue.resumes.some(record=>record.id===id),'Another browser/session must receive the durable application');
    response=await manager('/api/resumes.php?id='+id);assert.equal(response.status,200);let detail=(await response.json()).resume;
    assert.equal(detail.email,input.email);assert.equal(detail.attachments.length,2);
    const document=detail.attachments.find(file=>file.kind==='resume');
    response=await manager('/'+document.url);assert.equal(response.status,200);assert.deepEqual(Buffer.from(await response.arrayBuffer()),pdf,'Authorized download must reproduce exact uploaded bytes');
    assert.match(response.headers.get('cache-control'),/no-store/);assert.equal(response.headers.get('x-content-type-options'),'nosniff');
    for(const [request,status] of [[applicant,401],[employee,403],[outsider,404]])assert.equal((await request('/'+document.url)).status,status,'Private file authorization must be enforced');
    assert.equal((await outsider('/api/resumes.php?id='+id)).status,404,'Cross-tenant record lookup must be denied');
    response=await reader('/api/resumes.php?id='+id);const readerCsrf=(await response.json()).csrfToken;
    response=await apiPost(reader,'/api/resumes.php',{action:'status',id,status:'hired',expectedStatus:'new'},readerCsrf);assert.equal(response.status,403);
    response=await apiPost(manager,'/api/resumes.php',{action:'status',id,status:'under_review',expectedStatus:'new'},managerCsrf);assert.equal(response.status,200);
    response=await apiPost(manager,'/api/resumes.php',{action:'status',id,status:'rejected',expectedStatus:'new'},managerCsrf);assert.equal(response.status,409,'Stale status changes must not overwrite newer review');
    response=await apiPost(manager,'/api/resumes.php',{action:'note',id,text:'Contact for an interview.'},managerCsrf);assert.equal(response.status,200);
    response=await reader('/api/resumes.php?id='+id);detail=(await response.json()).resume;assert.equal(detail.status,'under_review');assert.equal(detail.notes[0].text,'Contact for an interview.');
    const before=db('private-count',{organizationId:data.organizationId}).count;
    db('fail-writes',{organizationId:data.organizationId});
    const retryKey=crypto.randomUUID().replaceAll('-','');
    response=await applicant('/api/public-applications.php',{method:'POST',body:application({idempotencyKey:retryKey,email:'failed-'+data.suffix+'@example.test'})});
    assert.equal(response.status,503,'DB/audit failure must not acknowledge successful intake');assert.equal((await response.json()).ok,false);
    assert.equal(db('inspect',{organizationId:data.organizationId,email:'failed-'+data.suffix+'@example.test'}).rows.length,0,'Failed intake must roll back its record');
    assert.equal(db('private-count',{organizationId:data.organizationId}).count,before,'Failed intake must remove newly written private files');
    db('restore-writes');
    response=await applicant('/api/public-applications.php',{method:'POST',body:application({idempotencyKey:retryKey,email:'failed-'+data.suffix+'@example.test'})});assert.equal(response.status,201,'The same request can be retried after a rolled-back failure');
    // Exercise the real native-PDO jobs controller, including a collision that must not overwrite another record.
    const job={title:'Native job '+data.suffix,slug:'native-'+data.suffix,status:'draft',department:'Kitchen',location:'Phoenix',summary:'Native PDO create',description:'Durable job record'};
    response=await apiPost(manager,'/api/jobs.php',{job},managerCsrf);assert.equal(response.status,200,await response.clone().text());
    let jobs=(await response.json()).jobs;const saved=jobs.find(value=>value.slug===job.slug);assert.ok(saved);
    response=await apiPost(manager,'/api/jobs.php',{job:{...job,title:'Collision should not win'}},managerCsrf);assert.equal(response.status,409);
    response=await apiPost(manager,'/api/jobs.php',{job:{...saved,title:'Native job edited '+data.suffix,status:'published',publishedAt:new Date().toISOString()}},managerCsrf);assert.equal(response.status,200);
    jobs=(await response.json()).jobs;assert.equal(jobs.find(value=>value.id===saved.id).title,'Native job edited '+data.suffix);
    response=await apiPost(reader,'/api/jobs.php',{job:{...job,slug:'denied-'+data.suffix}},readerCsrf);assert.equal(response.status,403);
    console.log('Hiring HTTP passed: independent candidate/reviewer sessions, durable private PDF/photo bytes, idempotency, tenant/job/CSRF permissions, review notes/status, rollback cleanup, native-PDO job create/edit and collision protection.');
  } finally {
    db('restore-writes');
    server.kill('SIGTERM');await new Promise(resolve=>{if(server.exitCode!==null)resolve();else server.once('exit',resolve);});
  }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
