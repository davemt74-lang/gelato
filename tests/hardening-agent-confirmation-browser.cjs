'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');
const {chromium} = require('playwright');
const {start} = require('./hardening-agent-confirmation-server.cjs');

(async () => {
  const env = await start(), {data, fixture, Client} = env;
  const owner = new Client();
  let browser;
  try {
    await owner.login('owner');
    browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    await context.addCookies(owner.cookie.split(';').filter(Boolean).map(part => {
      const at = part.indexOf('=');
      return {name: part.slice(0, at).trim(), value: part.slice(at + 1), url: env.origin};
    }));
    const calls = [];
    // Replace only the external-model boundary. Canonical messages, actual
    // specialist APIs, shared PHP session, permissions and MariaDB remain real.
    await context.route('**/api/food-monster-llm.php', async route => {
      const input = route.request().postDataJSON();
      const history = await owner.request('/api/agent-workspace.php?action=thread&id=' + input.threadId);
      assert.equal(history.status, 200, history.text);
      const source = history.json.messages.at(-1);
      assert.equal(source.role, 'user');
      calls.push({...input, sourceMessageId: source.public_id});
      let result;
      if (input.message.startsWith('Model receive')) {
        const observation = fixture('seed-model', owner.sessionId, input.threadId, source.public_id, data.items.A.name, 1);
        result = {ok: true, skill: 'agent.llm.tools', answer: observation.data.answer, data: observation.data, sources: []};
      } else {
        const continuation = /^(Confirm|Cancel)$/i.test(input.message);
        result = {ok: true, answer: 'Verified specialist handoff', data: {delegation: {
          route: 'api/operations-agent.php', domain: 'operations', node: 'operations', mode: 'read_write',
          threadId: input.threadId, sourceMessageId: source.public_id,
          ...(continuation ? {continuation: true, forwardMessage: /^Confirm$/i.test(input.message) ? 'Confirm' : 'Cancel',
            proposalId: input.proposalId, proposalHash: input.proposalHash, proposalMessageId: input.proposalMessageId} : {})
        }}};
      }
      await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(result)});
    });
    const tabA = await context.newPage(), tabB = await context.newPage();
    async function open(page) {
      await page.goto(env.origin + '/agent-canvas.php');
      await page.waitForFunction(() => !!window.GelatoGlobalAgent?.user?.id);
      const previous = await page.evaluate(() => window.GelatoGlobalAgent.threadId);
      await page.locator('#newThread').click();
      await page.waitForFunction(previous => window.GelatoGlobalAgent.threadId !== previous
        && document.querySelector('#threadList .thread.active')?.dataset.thread === window.GelatoGlobalAgent.threadId, previous);
      return page.evaluate(() => window.GelatoGlobalAgent.threadId);
    }
    async function ask(page, text) {
      return page.evaluate(async text => {
        try { return {result: await window.GelatoGlobalAgent.send(text, false)}; }
        catch (error) { return {error: error.message}; }
      }, text);
    }
    const stock = label => Number(fixture('snapshot', data.organizationId).inventory_items
      .find(item => item.public_id === data.items[label].id).on_hand_quantity);
    const threadA = await open(tabA), threadB = await open(tabB);
    assert.notEqual(threadA, threadB, 'Tabs have distinct canonical conversations.');

    // Exercise the real composer and render of the initial reviewed proposal.
    await tabA.locator('#canvasInput').fill('receive 2 kg of ' + data.items.A.name);
    await tabA.locator('#canvasSend').click();
    await tabA.waitForFunction(() => !!window.GelatoAgentReview?.fields(window.GelatoGlobalAgent.threadId).proposalHash);
    const reviewedA = await tabA.evaluate(() => window.GelatoAgentReview.fields(window.GelatoGlobalAgent.threadId));
    await tabA.waitForFunction(() => [...document.querySelectorAll('#canvasMessages .bubble')].some(el => el.textContent.includes('Reply Confirm')));
    assert.equal(stock('A'), 10, 'Showing a proposal does not adjust stock.');

    let response = await ask(tabB, 'receive 7 kg of ' + data.items.B.name);
    assert.match(response.error, /already a pending action/);
    response = await ask(tabB, 'Confirm');
    assert.match(response.error, /proposal shown|could not be verified/);
    assert.equal(stock('A'), 10); assert.equal(stock('B'), 10);

    // Reload and explicitly reopen the original saved proposal. Evidence is
    // restored only from the canonical messages actually rendered in that tab.
    await tabA.reload();
    await tabA.waitForFunction(() => !!window.GelatoGlobalAgent?.user?.id);
    await tabA.locator('[data-thread="' + threadA + '"]').click();
    await tabA.waitForFunction(id => window.GelatoAgentReview?.fields(id).proposalHash, threadA);
    assert.deepEqual(await tabA.evaluate(id => window.GelatoAgentReview.fields(id), threadA), reviewedA);
    response = await ask(tabA, 'Confirm');
    assert.equal(response.error, undefined, JSON.stringify(response));
    assert.equal(stock('A'), 12); assert.equal(stock('B'), 10);

    response = await ask(tabB, 'receive 3 kg of ' + data.items.B.name);
    assert.ok(response.result?.data?.proposal?.hash, JSON.stringify(response));
    const reviewedB = response.result.data.proposal;
    response = await ask(tabA, 'Confirm');
    assert.match(response.error, /stale|already used|proposal shown|could not be verified/);
    assert.equal(fixture('pending', owner.sessionId, 'operations').id, reviewedB.id,
      'A stale confirmation cannot consume another tab\'s pending action.');
    assert.equal(stock('B'), 10);
    response = await ask(tabB, 'Confirm');
    assert.equal(response.error, undefined, JSON.stringify(response));
    assert.equal(stock('B'), 13);

    // Native model proposal uses the same final canonical binding as delegation.
    response = await ask(tabA, 'Model receive one kg');
    assert.ok(response.result?.data?.proposal?.hash, JSON.stringify(response));
    const native = response.result.data.proposal;
    assert.equal(native.threadId, threadA);
    const lastModelCall = calls.at(-1);
    assert.equal(native.sourceMessageId, lastModelCall.sourceMessageId);
    response = await ask(tabA, 'Cancel');
    assert.equal(response.error, undefined, JSON.stringify(response));
    assert.equal(stock('A'), 12, 'Cancelling a native model proposal leaves stock unchanged.');

    // Standalone Operations uses the shared canonical transport, including reload.
    const legacy = await context.newPage();
    // Isolate the real legacy composer from the global presentation shell.
    // Normal Operations uses that shell's global composer instead. Blocking
    // its loader also prevents a late global toolbar from covering this one.
    await legacy.route('**/js/universal-admin-page-shell.js*', route => route.abort());
    await legacy.goto(env.origin + '/operations.php');
    assert.equal(await legacy.locator('#gelato-agent-bar').count(), 0, 'Standalone fixture excludes the global composer.');
    await legacy.locator('#agentInput').fill('receive 1 kg of ' + data.items.A.name);
    await legacy.locator('#agentSend').click();
    await legacy.waitForFunction(() => document.getElementById('agentStatus')?.textContent.includes('Reply Confirm'));
    assert.equal(stock('A'), 12, 'The standalone rendered proposal does not adjust stock.');
    await legacy.reload();
    assert.equal(await legacy.locator('#gelato-agent-bar').count(), 0, 'Standalone reload stays isolated from the global composer.');
    await legacy.locator('#agentInput').fill('Confirm');
    await legacy.locator('#agentSend').click();
    await legacy.waitForFunction(() => document.getElementById('agentStatus')?.textContent.includes('Confirmed'));
    assert.equal(stock('A'), 13, 'Reloaded standalone transport confirms its original reviewed action once.');
    const snapshot = fixture('snapshot', data.organizationId);
    assert.equal(snapshot.inventory_transactions.length, 3, 'Exactly three intended stock changes were executed.');
    const output = process.env.QA_OUTPUT_DIR || '/tmp/gelato-hardening-c-browser';
    fs.mkdirSync(output, {recursive: true});
    await tabA.screenshot({path: path.join(output, 'proposal-conversation-a.png'), fullPage: true});
    await tabB.screenshot({path: path.join(output, 'proposal-conversation-b.png'), fullPage: true});
    await context.close();
    console.log('PASS actual-browser canonical proposals, two tabs, stale decisions, reload, model adapter, cancellation and standalone transport.');
  } catch (error) {
    console.error(env.logs());
    throw error;
  } finally {
    if (browser) await browser.close();
    await env.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
