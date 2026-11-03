'use strict';
const assert=require('node:assert/strict'),path=require('node:path'),{spawnSync}=require('node:child_process');
const {start}=require('./hardening-agent-confirmation-server.cjs');
const root=path.resolve(__dirname,'..');
function voiceFixture(...args){const r=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'hardening-legacy-voice.php'),'fixture',...args.map(String)],{cwd:root,encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return JSON.parse(r.stdout);}
const evidence=p=>({threadId:p.threadId,proposalId:p.id,proposalHash:p.hash,proposalMessageId:p.messageId});
(async()=>{
  const s=await start(),{Client,data}=s,owner=new Client(),coworker=new Client(),foreign=new Client();let checks=0;
  const expect=(r,n)=>{assert.equal(r.status,n,r.text);checks++;return r.json;};
  const snapshot=()=>voiceFixture('snapshot',data.organizationId);
  async function consent(client,thread,message){
    const approved=expect(await client.request('/api/agent-workspace.php',{action:'authorize_private_voice',threadId:thread,messageId:message.id}),200);
    assert.equal(voiceFixture('approve',client.sessionId,thread,message.id,approved.privateTurnToken).approved,true);checks++;
  }
  async function spoken(client,thread,text,{event=null,approve=true}={}){
    if(event===null){const check=expect(await client.request('/api/voice-identity.php',{action:'verify',features:voiceFixture('profile',data.users.owner.organizationId,data.users.owner.id).features,matchAny:false}),200);event=check.match.eventId;}
    const saved=expect(await client.request('/api/agent-workspace.php',{action:'append',threadId:thread,role:'user',channel:'voice',content:text,voiceEventId:event}),200).message;
    if(approve)await consent(client,thread,saved);
    return {message:text,threadId:thread,sourceMessageId:saved.id,voice:true,voiceEventId:event};
  }
  async function typed(thread,text){const m=await owner.userMessage(thread,text);return {message:text,threadId:thread,sourceMessageId:m.id,voice:false,voiceEventId:''};}
  async function denied(input,label){const before=snapshot();const pending=s.fixture('pending',owner.sessionId,'timeclock');expect(await owner.request('/api/timeclock-agent.php',input),409);assert.deepEqual(snapshot(),before,label+' leaves clock state unchanged');assert.deepEqual(s.fixture('pending',owner.sessionId,'timeclock'),pending,label+' leaves proposal unchanged');checks+=2;}
  try{
    await owner.login('owner');await coworker.login('coworker');await foreign.login('foreign');
    for(const name of ['owner','coworker','foreign'])voiceFixture('profile',data.users[name].organizationId,data.users[name].id);
    expect(await new Client().request('/api/timeclock-agent.php',{message:'Confirm'}),302);
    const thread=await owner.thread(),second=await owner.thread();
    const raw=expect(await owner.request('/api/timeclock-agent.php',await typed(thread,'clock me in')),200),proposal=await owner.bind(thread,raw);
    await denied({...await spoken(owner,thread,'Confirm',{event:''}),...evidence(proposal),voice:false},'unknown spoken confirmation downgraded by caller flag');
    const valid=await spoken(owner,thread,'Confirm');expect(await owner.request('/api/timeclock-agent.php',{...valid,...evidence(proposal)}),200);
    let state=snapshot();assert.equal(state.time_clock_entries.length,1);assert.equal(state.time_clock_entries[0].clock_in_source,'voice');checks+=2;
    await denied({...valid,...evidence(proposal)},'completed spoken confirmation replay');
    expect(await owner.request('/api/timeclock.php',{action:'clock_out'}),200);
    await denied({...await spoken(owner,thread,'clock me in',{event:''}),voice:false},'unknown microphone action');
    const noConsent=await spoken(owner,thread,'clock me in',{approve:false});await denied(noConsent,'recognized speech without per-turn operator consent');
    await consent(owner,thread,{id:noConsent.sourceMessageId});
    const action=expect(await owner.request('/api/timeclock-agent.php',{...noConsent,voice:false}),200),review=await owner.bind(thread,action);
    assert.equal(s.fixture('pending',owner.sessionId,'timeclock').payload.source,'voice','Saved channel overrides voice:false.');checks++;
    await denied({...await spoken(owner,thread,'Confirm',{event:noConsent.voiceEventId}),...evidence(review)},'proposal event replay');
    const stale=await spoken(owner,thread,'Confirm');voiceFixture('age',data.organizationId,stale.voiceEventId);
    await denied({...stale,...evidence(review)},'expired event');
    const otherEvent=voiceFixture('event',data.organizationId,data.users.coworker.id).eventId;
    await denied({...await spoken(owner,thread,'Confirm',{event:otherEvent}),...evidence(review)},'another employee receipt');
    const foreignEvent=voiceFixture('event',data.users.foreign.organizationId,data.users.foreign.id).eventId;
    await denied({...await spoken(owner,thread,'Confirm',{event:foreignEvent}),...evidence(review)},'another tenant receipt');
    const wrong=await spoken(owner,second,'Confirm');
    await denied({...wrong,...evidence(review)},'source from another conversation');
    const mismatch=await spoken(owner,thread,'show my schedule');
    await denied({...mismatch,...evidence(review),message:'Confirm'},'voice receipt for a different command');
    const forgedTyped=await typed(thread,'Confirm');await spoken(owner,thread,'Confirm',{event:''});
    await denied({...forgedTyped,...evidence(review)},'older typed source concealing current room speech');
    const fresh=await spoken(owner,thread,'Confirm');
    expect(await owner.request('/api/timeclock-agent.php',{...fresh,...evidence(review)}),200);
    state=snapshot();assert.equal(state.time_clock_entries.length,2);assert.equal(state.time_clock_entries.filter(e=>e.status==='open').length,1);checks+=2;
    const out=expect(await owner.request('/api/timeclock-agent.php',await typed(thread,'clock me out')),200),outReview=await owner.bind(thread,out);
    expect(await owner.request('/api/timeclock-agent.php',{...await typed(thread,'Confirm'),...evidence(outReview)}),200);
    assert.equal(snapshot().time_clock_entries.filter(e=>e.status==='open').length,0);checks++;
    expect(await owner.request('/tests/hardening-legacy-voice.php'),404);
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(s.logs()),s.logs());checks++;
    console.log(`Legacy voice HTTP: PASS (${checks} assertions; authenticated API, canonical spoken append, consent, protected action/Confirm, tenant/principal/command/thread, stale/replayed event, typed/manual compatibility).`);
  }finally{await s.stop();}
})().catch(error=>{console.error(error);process.exitCode=1;});
