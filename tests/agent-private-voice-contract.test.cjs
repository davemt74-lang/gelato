'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const agent=read('js/global-agent.js');
const browser=read('js/food-monster-llm.js');
const workspace=read('api/agent-workspace.php');
const llm=read('api/food-monster-llm.php');
const voice=read('includes/agent-voice-conversation.php');
const runtime=read('includes/food-monster-b8d3-tool-runtime.php');
const helper=read('includes/agent-private-voice.php');

assert(agent.includes('approvedPrivateVoiceToken(text,submitted)'),'client must request approval for the exact committed message');
assert(agent.includes('requestPrivateVoiceConfirmation(text)'),'operator must deliberately confirm the request');
assert(agent.includes("action:'authorize_private_voice'"),'private approval goes to authenticated Agent Workspace');
assert(agent.includes('privateTurnToken,csrf_token:S.csrf'),'direct model path passes one-turn grant');
assert(agent.includes('csrf:S.csrf,privateTurnToken'),'model widget path passes one-turn grant');
assert(browser.includes('provider:selected,privateTurnToken,csrf_token:csrf'),'model widget preserves token');
assert(!agent.includes('S.verifiedSpeaker.eventId||'), 'no manual voice event from earlier turn may be borrowed');
assert(!agent.includes("action:'route',message:text,threadId:S.thread"),'no private agent phrase routing without provider');
assert(!browser.includes('offlineFallback:true'),'browser may not invent an offline private-tool grant');
assert(workspace.includes('avc_claim_unused_event($pdo,$org,$user,$voiceEvent)'),'canonical turn claims voice event atomically');
assert(voice.includes("JSON_EXTRACT(COALESCE(metadata_json,'{}'),'$.agentTurnConsumedAt') IS NULL"),'voice event claim must be atomic');
assert(voice.includes('INTERVAL 30 SECOND'),'voice attribution expires quickly');
assert(workspace.includes("($last['channel']??'')!=='voice'"),'approval must be attached to a spoken message');
assert(workspace.includes("'privateTurnToken'=>$token"),'server issues opaque token after explicit approval');
assert(llm.includes('gavp_consume($org,$uid,$threadId'),'private tool authorization consumes session-bound grant');
assert(!llm.includes('avc_verified_speaker($pdo,$org,$user,$event)'),'provisional biometrics cannot authorize private tools');
assert(runtime.includes("($context['privateVoiceApproved']??false)!==true"),'tool runtime fails closed when unapproved');
assert(helper.includes("unset($_SESSION['gelato_private_voice_turn'])"),'grant one-use consumption on any attempt');

// This classification only decides when to ASK for approval. It never selects
// a restaurant tool or bypasses server permission checks.
const i=agent.indexOf('  function voiceMayNeedPrivateAccess(text){');
const j=agent.indexOf('  function requestPrivateVoiceConfirmation(question){',i);
assert(i>=0&&j>i,'private prompt classification is available');
const needs=new Function(agent.slice(i,j)+'return voiceMayNeedPrivateAccess;')();
for(const phrase of ['Show my schedule','Who works tomorrow?','Who is on staff tomorrow?','Employee roster','Give me our pizza recipe','How much inventory is left?','My sales report']){
 assert.equal(needs(phrase),true,phrase+' should request human approval');
}
for(const phrase of ['Hello Gelato','How does yeast work?','What is on the public menu?']){
 assert.equal(needs(phrase),false,phrase+' should remain ordinary conversation');
}
process.stdout.write('Private voice auth contract: 26 positive and negative assertions passed.\n');
