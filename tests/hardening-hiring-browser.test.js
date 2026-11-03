'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../js/public-application.js'),'utf8');
async function scenario(responses) {
  const values={jobId:'job-public',firstName:'Remote',lastName:'Applicant',email:'remote@example.test',phone:'6025550100',availability:'Evenings',experience:'Kitchen',consent:'1'};
  const nodes={publicFormFields:{innerHTML:''},submissionMessage:{textContent:'',className:'',setAttribute(){}},applicationCard:{innerHTML:'original form'}};
  const button={disabled:true,textContent:''};let submit;
  const form={values,elements:{jobId:{value:''},'photos[]':{files:[]},resume:{files:[]},video:{files:[]}},querySelector:()=>button,reportValidity:()=>true,addEventListener:(name,handler)=>{if(name==='submit')submit=handler;}};
  nodes.resumeForm=form;
  class FormDataFixture{constructor(f){this.values=new Map(Object.entries(f.values));}set(key,value){this.values.set(key,value);}get(key){return this.values.get(key);}}
  const requests=[];let gets=0;
  const context={document:{getElementById:id=>nodes[id]},location:{search:'?job=job-public'},URLSearchParams,FormData:FormDataFixture,
    fetch:async(url,options)=>{
      if(options.method!=='POST'){gets++;return {ok:true,status:200,json:async()=>({ok:true,jobs:[{id:'job-public',title:'Kitchen'}],csrfToken:'csrf-'+gets,idempotencyKey:gets===1?'a'.repeat(32):'b'.repeat(32)})};}
      requests.push({key:options.body.get('idempotencyKey'),csrf:options.body.get('csrf_token')});
      const next=responses.shift();if(next instanceof Error)throw next;
      return {ok:next.status>=200&&next.status<300,status:next.status,json:async()=>next.data};
    },localStorage:new Proxy({},{get(){throw new Error('Applicant data must not use localStorage');}}),sessionStorage:new Proxy({},{get(){throw new Error('Applicant data must not use sessionStorage');}})};
  vm.runInNewContext(source,context);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(button.disabled,false,'A valid live job must enable submission');
  return {nodes,button,requests,submit:()=>submit({preventDefault(){},currentTarget:form})};
}
(async()=>{
  for(const response of [new Error('Network unavailable'),{status:503,data:{ok:false,message:'Server could not confirm'}},{status:200,data:{ok:true}}]){
    const test=await scenario([response,{status:201,data:{ok:true,receipt:{receipt:'application-confirmed'}}}]);
    await test.submit();
    assert.equal(test.nodes.applicationCard.innerHTML,'original form','Unconfirmed response must not render receipt/success');
    assert.equal(test.button.disabled,false,'A failed request must permit a deliberate retry');
    await test.submit();
    assert.match(test.nodes.applicationCard.innerHTML,/Submission received/);
    assert.match(test.nodes.applicationCard.innerHTML,/application-confirmed/);
    assert.equal(test.requests[0].key,test.requests[1].key,'Retry must preserve its original idempotency key');
  }
  const refreshed=await scenario([{status:419,data:{ok:false}},{status:201,data:{ok:true,receipt:{receipt:'application-after-csrf'}}}]);
  await refreshed.submit();assert.equal(refreshed.nodes.applicationCard.innerHTML,'original form');
  await refreshed.submit();assert.equal(refreshed.requests[1].csrf,'csrf-2');assert.equal(refreshed.requests[0].key,refreshed.requests[1].key,'CSRF refresh must not allocate a second application');
  console.log('Hiring browser behavior passed: no local applicant persistence, no premature success, unchanged retry identity, refreshed CSRF and durable receipt rendering.');
})().catch(error=>{console.error(error);process.exitCode=1;});
