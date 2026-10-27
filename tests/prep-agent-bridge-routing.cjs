'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const script = fs.readFileSync(path.join(__dirname, '../js/restaurant-agent-bridge.js'), 'utf8');

function makeAgent(permissions) {
  const storage = new Map();
  const sent = [];
  const window = {
    RESTAURANT_SERVER_SESSION: true,
    RESTAURANT_CSRF_TOKEN: 'contract-test',
    RestaurantAuth: {
      has: key => permissions.includes(key),
      current: () => ({ displayName: 'CI Manager' }),
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
  const fetch = async (endpoint, options) => {
    const body = JSON.parse(options.body);
    sent.push({ endpoint, body });
    const proposal = body.message.startsWith('create ') || body.message.startsWith('add ');
    return { ok: true, json: async () => ({
      ok: true,
      skill: proposal ? 'prep.action_proposal' : 'prep.action_confirmed',
      answer: proposal ? 'Reply Confirm to execute.' : 'Confirmed.',
      data: proposal ? { requiresConfirmation: true } : {},
    }) };
  };
  vm.runInNewContext(script, { window, document, localStorage, fetch, Intl, Date, Math, console });
  return { submit: window.GelatoRestaurantAgent.submit, sent };
}

(async () => {
  const permissions = ['prep.intelligence.agent', 'prep.intelligence.view'];
  const agent = makeAgent(permissions);
  await agent.submit("create tomorrow's prep list with six pans of dough");
  assert.equal(agent.sent[0].endpoint, 'api/prep-intelligence-agent.php', 'dated creation should go to Prep Intelligence');
  await agent.submit('Confirm');
  assert.equal(agent.sent[1].endpoint, 'api/prep-intelligence-agent.php', 'confirmation must return to proposing specialist');
  await agent.submit('what should we prep today');
  assert.equal(agent.sent[2].endpoint, 'api/prep-intelligence-agent.php', 'prep request takes precedence over timeclock route');
  await agent.submit('check inventory');
  assert.equal(agent.sent[3].endpoint, 'api/operations-agent.php', 'inventory remains with Operations');
  const noAccess = makeAgent([]);
  await noAccess.submit('show prep list');
  assert.equal(noAccess.sent[0].endpoint, 'api/operations-agent.php', 'prep specialist requires agent and view permissions');
  console.log('prep-agent-bridge-routing contract passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
