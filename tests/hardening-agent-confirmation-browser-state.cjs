'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/agent-confirmation-transport.js'), 'utf8');
const threadA = 'agent-' + 'a'.repeat(20), threadB = 'agent-' + 'b'.repeat(20);
const userMessage = 'msg-' + '1'.repeat(20), proposalMessage = 'msg-' + '2'.repeat(20);
const proposal = {id: 'gap-' + '3'.repeat(32), node: 'operations', hash: '4'.repeat(64),
  threadId: threadA, sourceMessageId: userMessage, messageId: proposalMessage,
  summary: 'Receive the reviewed amount.', type: 'inventory_adjust', expiresAt: new Date(Date.now() + 600000).toISOString()};
const canonical = {id: proposalMessage, public_id: proposalMessage, role: 'agent',
  content: 'The exact saved proposal.', structured: {requiresConfirmation: true, proposal}};
const storage = new Map(), sent = [];
const window = {};
const sessionStorage = {getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value)};
async function fetch(url, options = {}) {
  const input = options.body ? JSON.parse(options.body) : null;
  sent.push({url, input});
  let data;
  if (url.includes('action=bootstrap')) data = {ok: true, user: {id: 7}, activeThread: threadB};
  else if (url.includes('action=thread')) data = {ok: true, thread: {public_id: threadA}, messages: [canonical]};
  else if (input?.action === 'new_thread') data = {ok: true, thread: {public_id: threadA}};
  else if (input?.action === 'append') data = {ok: true, message: input.role === 'user'
    ? {id: userMessage, role: 'user', content: input.content} : canonical};
  else data = {ok: true, answer: 'Unbound proposal copy.', data: {requiresConfirmation: true,
    proposal: {...proposal, hash: '', messageId: ''}}};
  return {ok: true, status: 200, json: async () => data};
}
vm.runInNewContext(source, {window, sessionStorage, fetch, Date, Map, WeakMap, Object, JSON, Error});
const review = window.GelatoAgentReview;
(async () => {
  const result = await review.ask('api/operations-agent.php', {message: 'Receive stock',
    threadId: threadB, proposalId: 'spoof', proposalHash: 'spoof', proposalMessageId: 'spoof'}, {csrf: 'test', key: 'operations'});
  assert.equal(result.answer, canonical.content, 'Display text comes from saved server message.');
  assert.deepEqual(JSON.parse(JSON.stringify(review.fields(threadA))), {}, 'An undisplayed response cannot authorize Confirm.');
  const specialist = sent.find(row => row.url === 'api/operations-agent.php').input;
  assert.equal(specialist.threadId, threadA, 'Transport binds the actual created conversation, not latest server thread or caller input.');
  assert.equal(specialist.sourceMessageId, userMessage);
  for (const field of ['proposalId', 'proposalHash', 'proposalMessageId']) assert.equal(specialist[field], undefined);
  assert.ok(review.acknowledge(result));
  assert.equal(review.fields(threadA).proposalHash, proposal.hash);
  assert.equal(review.acknowledge(result), false, 'Display acknowledgement is one-use.');
  assert.deepEqual(JSON.parse(JSON.stringify(review.fields(threadB))), {}, 'Other conversations receive no inherited review.');
  assert.equal(review.remember(threadB, canonical), false);
  assert.equal(review.remember(threadA, {...canonical, id: userMessage}), false, 'A different saved message cannot rebind the proposal.');
  const before = JSON.stringify(review.fields(threadA));
  review.forget(threadA, 'some-other-proposal');
  assert.equal(JSON.stringify(review.fields(threadA)), before, 'Clearing an old decision cannot erase a newer review.');
  const original = await review.handoff(threadA, proposal, 'test');
  assert.equal(original.public_id, proposal.messageId, 'Approval continuation restores the original saved message.');
  await assert.rejects(review.handoff(threadB, proposal, 'test'), /different conversation/);
  await assert.rejects(review.handoff(threadA, {...proposal, hash: 'f'.repeat(64)}, 'test'), /original saved proposal/);
  review.forget(threadA, proposal.id);
  assert.deepEqual(JSON.parse(JSON.stringify(review.fields(threadA))), {});
  const highImpact = {...proposal, id: 'gap-' + '5'.repeat(32), hash: '6'.repeat(64),
    node: 'food_monster_high_impact', messageId: 'msg-' + '7'.repeat(20)};
  const highImpactMessage = {id: highImpact.messageId, role: 'agent', structured: {proposal: highImpact}};
  review.restore(threadA, [canonical, highImpactMessage,
    {id: 'msg-' + '8'.repeat(20), role: 'agent', structured: {reviewedProposal: proposal}}]);
  assert.equal(review.fields(threadA).proposalId, proposal.id,
    'Reload after approval restores the original purchasing proposal, not the later approval request.');
  review.restore(threadA, [highImpactMessage,
    {id: 'msg-' + '9'.repeat(20), role: 'agent', structured: {reviewedProposal: {...proposal, hash: '0'.repeat(64)}}}]);
  assert.equal(review.fields(threadA).proposalId, highImpact.id, 'Forged or absent handoff source cannot replace review evidence.');
  const secondTab = {};
  vm.runInNewContext(source, {window: secondTab, sessionStorage: {getItem: () => null, setItem: () => {}}, fetch, Date, Map, WeakMap, Object, JSON, Error});
  assert.deepEqual(JSON.parse(JSON.stringify(secondTab.GelatoAgentReview.fields(threadA))), {}, 'Separate tabs do not share in-memory review state.');
  console.log('PASS exact browser review identity, no latest-thread inheritance, acknowledgement, handoff and tab separation.');
})().catch(error => {console.error(error); process.exitCode = 1;});
