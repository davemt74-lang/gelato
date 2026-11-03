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
const sessionStorage = {get length(){return storage.size;}, key: index => [...storage.keys()][index],
  getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)};
async function fetch(url, options = {}) {
  const input = options.body ? JSON.parse(options.body) : null;
  sent.push({url, input});
  let data;
  if (url.includes('action=bootstrap')) data = {ok: true, user: {id: 7}, browserState: {organizationId: 1, userId: 'db-user-7', identity: 'a'.repeat(32)}, activeThread: threadB};
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
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise, resolve, reject};
}
function isolatedTransport(intercept = async () => undefined) {
  const calls = [], saved = new Map(), listeners = new Map();
  const isolatedWindow = {addEventListener(type, listener) {
    if (!listeners.has(type)) listeners.set(type, []);
    listeners.get(type).push(listener);
  }};
  const isolatedStorage = {get length() { return saved.size; }, key: index => [...saved.keys()][index],
    getItem: key => saved.get(key) || null, setItem: (key, value) => saved.set(key, value), removeItem: key => saved.delete(key)};
  const bootstrap = {ok: true, user: {id: 7},
    browserState: {organizationId: 1, userId: 'db-user-7', identity: 'a'.repeat(32)}};
  const userReply = {ok: true, message: {id: userMessage, role: 'user', content: 'Review stock'}};
  const specialistReply = {ok: true, answer: 'Review the proposed stock change.',
    data: {requiresConfirmation: true, proposal: {...proposal, hash: '', messageId: ''}}};
  const fetch = async (url, options = {}) => {
    const input = options.body ? JSON.parse(options.body) : null;
    calls.push({url, input});
    let data = await intercept(url, input);
    if (data === undefined) {
      if (url.includes('action=bootstrap')) data = bootstrap;
      else if (url.includes('action=thread')) data = {ok: true, thread: {public_id: threadA}, messages: [canonical]};
      else if (input?.action === 'new_thread') data = {ok: true, thread: {public_id: threadA}};
      else if (input?.action === 'append') data = input.role === 'user' ? userReply : {ok: true, message: canonical};
      else data = specialistReply;
    }
    return {ok: true, status: 200, json: async () => data};
  };
  vm.runInNewContext(source, {window: isolatedWindow, sessionStorage: isolatedStorage, fetch,
    Date, Map, WeakMap, Object, JSON, Error});
  return {review: isolatedWindow.GelatoAgentReview, calls, saved, userReply, specialistReply,
    emit: (type, event = {}) => { for (const listener of listeners.get(type) || []) listener(event); }};
}
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
  assert.ok([...storage.keys()].every(key => key.startsWith('gelato-private-v1:1:db-user-7:' + 'a'.repeat(32) + ':agent-review:')), 'Review pointers use organization, account and login scope.');
  assert.ok(![...storage.values()].some(value => value.includes(proposal.summary)), 'Browser storage never contains proposal prose.');
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
  review.clear();
  assert.equal(storage.size, 0, 'Sign-out clears persisted review pointers.');
  assert.deepEqual(JSON.parse(JSON.stringify(review.fields(threadA))), {}, 'Sign-out clears in-memory review evidence.');

  // Logout while old history is in flight must not restore the old private review.
  const historyGate = deferred(), historyStarted = deferred();
  const restoring = isolatedTransport(async url => {
    if (url.includes('action=thread')) { historyStarted.resolve(); return historyGate.promise; }
  });
  restoring.saved.set('gelato-private-v1:1:db-user-7:' + 'a'.repeat(32) + ':agent-review:operations',
    JSON.stringify({threadId: threadA, review: {id: proposal.id, hash: proposal.hash, messageId: proposal.messageId}}));
  const restoredRequest = restoring.review.ask('api/operations-agent.php', {message: 'Confirm'}, {csrf: 'test', key: 'operations'});
  const restoreRejected = assert.rejects(restoredRequest, /session changed/i);
  await historyStarted.promise;
  restoring.emit('gelato:private-state-cleared');
  historyGate.resolve({ok: true, thread: {public_id: threadA}, messages: [canonical]});
  await restoreRejected;
  assert.deepEqual(JSON.parse(JSON.stringify(restoring.review.fields(threadA))), {}, 'Late history cannot repopulate cleared review evidence.');
  assert.equal(restoring.saved.size, 0, 'Late history cannot recreate private storage after logout.');
  assert.ok(restoring.calls.every(call => call.input === null), 'Late history cannot launch any follow-up write.');

  // A rejected old initializer must not delete the new same-key conversation.
  const oldBootstrap = deferred(), oldStarted = deferred(), newUserTurn = deferred(), newStarted = deferred();
  let bootstraps = 0;
  const replaced = isolatedTransport(async (url, input) => {
    if (url.includes('action=bootstrap')) {
      bootstraps++;
      if (bootstraps === 1) { oldStarted.resolve(); return oldBootstrap.promise; }
      if (bootstraps > 2) throw new Error('A stale initializer deleted the current conversation.');
    }
    if (input?.action === 'append' && input.role === 'user') { newStarted.resolve(); return newUserTurn.promise; }
  });
  const staleRequest = replaced.review.ask('api/operations-agent.php', {message: 'Old request'}, {csrf: 'test', key: 'operations'});
  const staleRejected = assert.rejects(staleRequest, /old bootstrap failed/);
  await oldStarted.promise;
  replaced.emit('gelato:private-state-cleared');
  const currentRequest = replaced.review.ask('api/operations-agent.php', {message: 'Current request'}, {csrf: 'test', key: 'operations'});
  await newStarted.promise;
  oldBootstrap.reject(new Error('old bootstrap failed'));
  await staleRejected;
  await assert.rejects(replaced.review.ask('api/operations-agent.php', {message: 'Concurrent request'},
    {csrf: 'test', key: 'operations'}), /Wait for this Agent response/);
  assert.equal(bootstraps, 2, 'Old rejection preserves the current same-key initializer and busy guard.');
  assert.equal(replaced.calls.filter(call => call.input?.action === 'new_thread').length, 1, 'Only one new conversation is created after logout.');
  newUserTurn.resolve(replaced.userReply);
  const currentResult = await currentRequest;
  assert.equal(replaced.review.acknowledge(currentResult), true, 'The replacement conversation can complete and acknowledge normally.');

  // Check each asynchronous write boundary with a deterministic late response.
  for (const boundary of ['user', 'specialist', 'agent']) {
    const gate = deferred(), started = deferred();
    const late = isolatedTransport(async (url, input) => {
      const matches = boundary === 'specialist' ? url === 'api/operations-agent.php'
        : input?.action === 'append' && input.role === boundary;
      if (matches) { started.resolve(); return gate.promise; }
    });
    const pending = late.review.ask('api/operations-agent.php', {message: 'Review stock'}, {csrf: 'test', key: 'operations'});
    const rejected = assert.rejects(pending, /session changed/i);
    await started.promise;
    const countAtSwitch = late.calls.length;
    late.emit('storage', {key: 'gelato-workspace-principal-v1', newValue: 'another-account'});
    gate.resolve(boundary === 'user' ? late.userReply : boundary === 'specialist'
      ? late.specialistReply : {ok: true, message: canonical});
    await rejected;
    assert.equal(late.calls.length, countAtSwitch, 'Late ' + boundary + ' response cannot launch follow-up writes.');
    assert.equal(late.saved.size, 0, 'Late ' + boundary + ' response cannot recreate private storage.');
    assert.deepEqual(JSON.parse(JSON.stringify(late.review.fields(threadA))), {}, 'Late ' + boundary + ' response cannot recreate review evidence.');
  }
  const unacknowledged = isolatedTransport();
  const undelivered = await unacknowledged.review.ask('api/operations-agent.php', {message: 'Review stock'}, {csrf: 'test'});
  unacknowledged.emit('gelato:private-state-cleared');
  assert.equal(unacknowledged.review.acknowledge(undelivered), false, 'A pre-logout result cannot be acknowledged in a new generation.');
  assert.equal(unacknowledged.saved.size, 0, 'Late acknowledgement cannot recreate private storage.');
  assert.deepEqual(JSON.parse(JSON.stringify(unacknowledged.review.fields(threadA))), {}, 'Late acknowledgement cannot restore private evidence.');
  console.log('PASS exact browser review identity, scoped pointers, acknowledgement, handoff, tab separation and deterministic logout races.');
})().catch(error => {console.error(error); process.exitCode = 1;});
