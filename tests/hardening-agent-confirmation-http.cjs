'use strict';
const assert=require('node:assert/strict');
const {start}=require('./hardening-agent-confirmation-server.cjs');
const evidence=p=>({threadId:p.threadId,proposalId:p.id,proposalHash:p.hash,proposalMessageId:p.messageId});
(async()=>{
  const s=await start();const {data,Client,fixture}=s;const owner=new Client(),coworker=new Client(),foreign=new Client();
  const snapshot=()=>fixture('snapshot',data.organizationId);
  const pending=()=>fixture('pending',owner.sessionId,'operations');
  let checks=0;
  const same=(actual,expected,message)=>{assert.deepEqual(actual,expected,message);checks++;};
  const status=(r,n)=>{assert.equal(r.status,n,r.text);checks++;return r;};
  async function stage(client,thread,item,quantity=1){
    const message=`receive ${quantity} kg of ${item.name}`;const turn=await client.userMessage(thread,message);
    return client.request('/api/operations-agent.php',{threadId:thread,sourceMessageId:turn.id,message});
  }
  async function decide(client,proposal,message='Confirm',extra={}){
    const fields={...evidence(proposal),...extra};
    const turn=await client.userMessage(fields.threadId,message);
    return client.request('/api/operations-agent.php',{...fields,sourceMessageId:turn.id,message});
  }
  try{
    await owner.login('owner');await coworker.login('coworker');await foreign.login('foreign');
    status(await new Client().request('/api/operations-agent.php',{message:'Confirm'}),302);
    let before=snapshot();
    status(await owner.request('/api/operations-agent.php',{message:`receive 1 kg of ${data.items.A.name}`}),409);
    same(snapshot(),before,'Threadless legacy creation cannot save an action.');
    const threadA=await owner.thread(),threadB=await owner.thread();
    const rawA=status(await stage(owner,threadA,data.items.A),200).json;
    assert.equal(rawA.data.proposal.hash,'','Unrecorded proposal cannot be approved.');checks++;
    status(await owner.request('/api/operations-agent.php',{threadId:threadA,message:'Confirm',proposalId:rawA.data.proposal.id}),409);
    const a=await owner.bind(threadA,rawA);assert.match(a.hash,/^[a-f0-9]{64}$/);checks++;
    const originalPending=pending();
    status(await stage(owner,threadB,data.items.B),409);
    same(pending(),originalPending,'Tab B cannot overwrite unresolved Tab A.');
    const changed=[{proposalId:'gap-'+ '0'.repeat(16)},{proposalHash:'0'.repeat(64)},
      {proposalMessageId:'msg-'+ '0'.repeat(20)},{threadId:threadB}];
    for(const alteration of changed){status(await decide(owner,a,'Confirm',alteration),409);same(pending(),originalPending,'Invalid evidence does not consume current proposal.');}
    for(const field of ['proposalId','proposalHash','proposalMessageId','threadId']){
      const request={message:'Confirm',...evidence(a)};delete request[field];
      status(await owner.request('/api/operations-agent.php',request),409);
    }
    status(await coworker.request('/api/operations-agent.php',{message:'Confirm',...evidence(a)}),409);
    status(await foreign.request('/api/operations-agent.php',{message:'Confirm',...evidence(a)}),409);
    status(await owner.request('/api/operations-agent.php',{message:'Confirm',...evidence(a)},false,'invalid'),419);
    same(snapshot(),before,'Wrong IDs/hashes/message/thread, owners, tenants, and CSRF cannot mutate business rows.');
    status(await decide(owner,a,'Cancel'),200);
    same(snapshot(),before,'Explicit exact cancellation only removes its proposal.');
    const b=await owner.bind(threadB,status(await stage(owner,threadB,data.items.B,2),200).json);
    status(await decide(owner,a),409);
    same(snapshot(),before,'Stale Tab A does not execute newly reviewed Tab B.');
    status(await decide(owner,b),200);
    let after=snapshot();assert.equal(Number(after.inventory_items.find(x=>x.public_id===data.items.A.id).on_hand_quantity),10);
    assert.equal(Number(after.inventory_items.find(x=>x.public_id===data.items.B.id).on_hand_quantity),12);checks+=2;
    status(await decide(owner,b),409);same(snapshot(),after,'Replay cannot repeat completed mutation.');
    const expired=await owner.bind(threadA,status(await stage(owner,threadA,data.items.A),200).json);
    fixture('expire-pending',owner.sessionId,'operations');status(await decide(owner,expired),409);
    same(snapshot(),after,'Expired confirmation cannot execute.');

    const concurrent=await owner.bind(threadA,status(await stage(owner,threadA,data.items.A),200).json);
    const duplicate=new Client(owner.cookie);duplicate.csrf=owner.csrf;
    const t=await owner.userMessage(threadA,'Confirm');const input={message:'Confirm',sourceMessageId:t.id,...evidence(concurrent)};
    const pair=await Promise.all([owner.request('/api/operations-agent.php',input),duplicate.request('/api/operations-agent.php',input)]);
    same(pair.map(x=>x.status).sort(),[200,409],'Concurrent workers allow exactly one claim.');
    const counted=snapshot();assert.equal(counted.inventory_transactions.length-after.inventory_transactions.length,1);checks++;
    assert.equal(Number(counted.inventory_items.find(x=>x.public_id===data.items.A.id).on_hand_quantity),11);checks++;

    const modelTurn=await owner.userMessage(threadA,`Please receive one kg of ${data.items.A.name}.`);
    const model=fixture('seed-model',owner.sessionId,threadA,modelTurn.id,data.items.A.name,1);
    const modelProposal=await owner.bind(threadA,model);
    status(await stage(owner,threadB,data.items.B),409);
    const followup=await owner.userMessage(threadA,'Yes, please go ahead with that exact proposal.');
    const ctx={...evidence(modelProposal),messageId:followup.id};
    same(fixture('continue-model',owner.sessionId,JSON.stringify({...ctx,threadId:threadB}),'confirm'),{denied:true},'Natural-language continuation rejects a different thread.');
    const continuation=fixture('continue-model',owner.sessionId,JSON.stringify(ctx),'confirm');
    const delegation=continuation.data.delegation;
    same({threadId:delegation.threadId,proposalId:delegation.proposalId,proposalHash:delegation.proposalHash,proposalMessageId:delegation.proposalMessageId},evidence(modelProposal),'Continuation forwards reviewed identity unchanged.');
    status(await owner.request(delegation.route,{message:delegation.forwardMessage,sourceMessageId:delegation.sourceMessageId,
      threadId:delegation.threadId,proposalId:delegation.proposalId,proposalHash:delegation.proposalHash,proposalMessageId:delegation.proposalMessageId}),200);
    after=snapshot();assert.equal(Number(after.inventory_items.find(x=>x.public_id===data.items.A.id).on_hand_quantity),12);checks++;
    status(await decide(owner,modelProposal),409);same(snapshot(),after,'Model proposal executes once through existing authorized specialist.');
    for(const name of ['hardening-agent-confirmation-fixture.php','hardening-agent-confirmation-test-support.php','hardening-agent-confirmation-contract.php'])
      status(await new Client().request('/tests/'+name),404);
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(s.logs()),s.logs());checks++;
    console.log(`Agent confirmation HTTP: PASS (${checks} assertions; real canonical messages, two threads, stale/forged evidence, expiry, cross-owner/tenant, concurrent workers, legacy and model continuation).`);
  }finally{await s.stop();}
})().catch(error=>{console.error(error);process.exitCode=1;});
