'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const script = fs.readFileSync(path.join(__dirname, '../js/restaurant-agent-bridge.js'), 'utf8');

function makeAgent(permissions, persistedSession = new Map(), actorId = 'ci-manager-1') {
  const storage = new Map();
  const sent = [];
  const window = {
    RESTAURANT_SERVER_SESSION: true,
    RESTAURANT_CSRF_TOKEN: 'contract-test',
    RestaurantAuth: {
      has: key => permissions.includes(key),
      current: () => ({ id: actorId, displayName: 'CI Manager' }),
    },
  };
  const document = {
    getElementById: () => null,
    addEventListener: () => {},
  };
  const localStorage = {
    getItem: key => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, value),
  };
  const sessionStorage = {
    getItem: key => persistedSession.get(key) ?? null,
    setItem: (key, value) => persistedSession.set(key, value),
  };
  const fetch = async (endpoint, options) => {
    const body = JSON.parse(options.body);
    sent.push({ endpoint, body });
    const proposal = /^(create |add |mark |assign |block prep )/i.test(body.message);
    return { ok: true, json: async () => ({
      ok: true,
      skill: proposal ? 'prep.action_proposal' : 'prep.action_confirmed',
      answer: proposal ? 'Reply Confirm to execute.' : 'Confirmed.',
      data: proposal ? { requiresConfirmation: true } : {},
    }) };
  };
  vm.runInNewContext(script, { window, document, localStorage, sessionStorage, fetch, Intl, Date, Math, console });
  return { submit: window.GelatoRestaurantAgent.submit, sent };
}

(async () => {
  const permissions = ['prep.intelligence.agent', 'prep.intelligence.view'];
  const persistedSession = new Map();
  const agent = makeAgent(permissions, persistedSession);
  await agent.submit("create tomorrow's prep list with six pans of dough");
  assert.equal(agent.sent[0].endpoint, 'api/prep-intelligence-agent.php', 'dated creation should go to Prep Intelligence');
  await agent.submit('Confirm');
  assert.equal(agent.sent[1].endpoint, 'api/prep-intelligence-agent.php', 'confirmation must return to proposing specialist');
  await agent.submit('what should we prep today');
  assert.equal(agent.sent[2].endpoint, 'api/prep-intelligence-agent.php', 'prep request takes precedence over timeclock route');
  await agent.submit('check inventory');
  assert.equal(agent.sent[3].endpoint, 'api/operations-agent.php', 'inventory remains with Operations');
  await agent.submit('show prep list');
  await agent.submit("Who's doing sauce?");
  assert.equal(agent.sent[5].endpoint, 'api/prep-intelligence-agent.php', 'short assignee query returns to active prep plan');
  await agent.submit('Add two more pans of dough');
  assert.equal(agent.sent[6].endpoint, 'api/prep-intelligence-agent.php', 'quantity followup routes to dated prep plan');
  const reloaded = makeAgent(permissions, persistedSession);
  await reloaded.submit('Confirm');
  assert.equal(reloaded.sent[0].endpoint, 'api/prep-intelligence-agent.php', 'pending confirmation survives browser reload');
  await reloaded.submit('Mark dough complete');
  assert.equal(reloaded.sent[1].endpoint, 'api/prep-intelligence-agent.php', 'task completion followup survives browser reload');
  await reloaded.submit('Assign dough to Prep CI Staff');
  assert.equal(reloaded.sent[2].endpoint, 'api/prep-intelligence-agent.php', 'name-based assignment followup stays on Prep Intelligence');
  await reloaded.submit('Mark dough in progress');
  assert.equal(reloaded.sent[3].endpoint, 'api/prep-intelligence-agent.php', 'employee progress followup stays on Prep Intelligence');
  await reloaded.submit("I'm blocked on dough because the mixer overheated");
  assert.equal(reloaded.sent[4].endpoint, 'api/prep-intelligence-agent.php',
    'spoken blocker reason stays with dated prep specialist after reload');
  await reloaded.submit('Resolve blocker on dough');
  assert.equal(reloaded.sent[5].endpoint, 'api/prep-intelligence-agent.php',
    'manager resolution followup stays with dated prep specialist');
  await reloaded.submit('Reassign dough to Prep CI Staff');
  assert.equal(reloaded.sent[6].endpoint, 'api/prep-intelligence-agent.php',
    'reassignment followup stays with dated prep specialist');
  await reloaded.submit("What's blocked on prep today?");
  assert.equal(reloaded.sent[7].endpoint, 'api/prep-intelligence-agent.php',
    'blocker summaries use the prep specialist instead of generic Operations');
  await reloaded.submit('Are we ready for dinner prep?');
  assert.equal(reloaded.sent[8].endpoint, 'api/prep-intelligence-agent.php',
    'readiness summary stays in the prep specialist after browser reload');
  await reloaded.submit('How is prep looking today?');
  assert.equal(reloaded.sent[9].endpoint, 'api/prep-intelligence-agent.php',
    'progress review stays in the prep specialist');
  await reloaded.submit('Prep handoff for lunch today');
  assert.equal(reloaded.sent[10].endpoint, 'api/prep-intelligence-agent.php',
    'prep shift handoff keeps the canonical prep permission gate');
  const employeeHandoff = makeAgent(['employee.handoffs.view']);
  await employeeHandoff.submit('What changed before my shift?');
  assert.equal(employeeHandoff.sent[0].endpoint, 'api/employee-agent.php',
    'employee handoff inquiry routes before scheduling');
  await employeeHandoff.submit('Shift handoff notes?');
  assert.equal(employeeHandoff.sent[1].endpoint, 'api/employee-agent.php',
    'shift handoff readback stays with employee communications');
  const differentUser = makeAgent(permissions, persistedSession, 'ci-manager-2');
  await differentUser.submit('Confirm');
  assert.notEqual(differentUser.sent[0].endpoint, 'api/prep-intelligence-agent.php',
    'browser-side prep confirmation context does not cross user identities');
  const noAccess = makeAgent([]);
  await noAccess.submit('show prep list');
  assert.equal(noAccess.sent[0].endpoint, 'api/operations-agent.php', 'prep specialist requires agent and view permissions');
  console.log('prep-agent-bridge-routing contract passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
