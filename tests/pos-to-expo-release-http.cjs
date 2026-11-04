'use strict';

const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { createServer } = require('node:net');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');

const root = resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
const posPath = '/api/pos.php';
const kdsPath = '/api/kds.php';
const floorPath = '/floor-plan-api.php';
const simPath = '/api/glasses-simulator.php';
const devicePath = '/api/glasses-device.php';

function fixture(action, input) {
  const result = spawnSync(php, ['-d', 'display_errors=stderr', join(__dirname, 'pos-to-expo-release-fixture.php'), action], {
    cwd: root, env: { ...process.env, GELATO_POS_EXPO_FIXTURE: '1' },
    input: JSON.stringify(input), encoding: 'utf8', timeout: 30000, killSignal: 'SIGKILL'
  });
  if (result.error) throw result.error;
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

async function availablePort() {
  const server = createServer();
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const { port } = server.address();
  await new Promise(resolve => server.close(resolve));
  return port;
}

function client(origin, journeyDeadline) {
  const cookies = new Map();
  let csrf = '';
  async function request(path, { body, form, token } = {}) {
    const data = form ?? (body === undefined ? undefined : JSON.stringify(body));
    const response = await fetch(origin + path, {
      method: data === undefined ? 'GET' : 'POST', body: data, redirect: 'manual',
      signal: AbortSignal.any([journeyDeadline, AbortSignal.timeout(10000)]),
      headers: {
        ...(token ? { Authorization: 'Bearer ' + token }
          : { Cookie: [...cookies].map(([key, value]) => key + '=' + value).join('; ') }),
        ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf })
      }
    });
    for (const header of response.headers.getSetCookie()) {
      const cookie = header.split(';', 1)[0];
      const separator = cookie.indexOf('=');
      cookies.set(cookie.slice(0, separator), cookie.slice(separator + 1));
    }
    const text = await response.text();
    const json = response.headers.get('content-type')?.includes('application/json') ? JSON.parse(text) : null;
    return { status: response.status, text, json };
  }
  return { request, setCsrf: token => { csrf = token; } };
}

function expect(response, status, label) {
  assert.equal(response.status, status, label + ' — ' + (response.json?.message || response.text.slice(0, 240)));
  if (response.json) assert.equal(response.json.ok, status < 400, label);
  return response.json;
}

async function journey(origin, seed, deadline) {
  const { org, location, price, pizza, option } = seed;
  const owner = client(origin, deadline);
  const pairedClient = client(origin, deadline);
  const login = await owner.request('/login.php');
  expect(login, 200, 'Load login');
  const loginToken = login.text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
  assert.ok(loginToken);
  expect(await owner.request('/login.php', { form: new URLSearchParams({
    email: seed.owner.email, password: seed.password, csrf_token: loginToken, return: 'workspace.php'
  }) }), 302, 'Sign in the fixture owner');
  const workspace = await owner.request('/workspace.php');
  expect(workspace, 200, 'Load authenticated workspace');
  const csrf = workspace.text.match(/RESTAURANT_CSRF_TOKEN="([a-f0-9]+)"/)?.[1];
  assert.ok(csrf);
  owner.setCsrf(csrf);

  const post = (path, action, data = {}) => owner.request(path, { body: { action, ...data } });
  const pos = (action, data = {}) => post(posPath, action, { locationId: location, ...data });
  const kds = (action, data = {}) => post(kdsPath, action, { locationId: location, ...data });
  const component = (build, key) => build.components.find(row => row.componentKey === key);
  const ingredient = 'ingredient:' + seed.pepperoni;
  const customization = 'manual:order-customization';

  const oven = expect(await kds('station.save', { name: 'Pizza Oven', targetSeconds: 300 }), 200, 'Create oven station').station.public_id;
  const finish = expect(await kds('station.save', { name: 'Pizza Finish', targetSeconds: 300 }), 200, 'Create finishing station').station.public_id;
  expect(await kds('route.save', { menuItemId: pizza, stationPublicId: oven }), 200, 'Route pizza to the oven');
  async function pair(stationPublicId, name) {
    const { pairing } = expect(await post('/api/glasses-admin.php', 'pairing.create', {
      locationId: location, stationPublicId
    }), 201, 'Create pairing grant for ' + name);
    return expect(await pairedClient.request(devicePath, { body: {
      action: 'pair', pairingCode: pairing.pairingCode, hardwareIdentifier: 'expo-release-' + org + '-' + name
    } }), 201, 'Pair fixture device for ' + name);
  }
  const oldPair = await pair(oven, 'oven');
  const newPair = await pair(finish, 'finish');
  const work = async paired => expect(await owner.request(simPath + '?' + new URLSearchParams({
    action: 'work', devicePublicId: paired.device.publicId
  })), 200, 'Read live simulator station work').work;
  const sim = (paired, action, data = {}) => post(simPath, action, { devicePublicId: paired.device.publicId, ...data });
  const device = (paired, action, data = {}) => pairedClient.request(devicePath, {
    token: paired.deviceToken, body: { action, ...data }
  });

  const tableId = 'pizza-release-table';
  function saveTable(xPx, label, plan = {}) {
    return post(floorPath, 'save', { plan: {
      id: plan.id, version: plan.version, name: 'Pizza release floor',
      data: { version: 7, planWft: 50, planHft: 34, scale: 24, items: [{
        id: tableId, type: 'table', label, xPx, yPx: 110, wPx: 96, hPx: 72, seats: 4
      }] }
    } });
  }
  const plan = expect(await saveTable(200, 'Table 8'), 200, 'Build floor plan').plan;
  expect(await pos('floor.select', { floorPlanPublicId: plan.id }), 200, 'Select the live POS floor plan');
  const tableFrom = floorPlan => floorPlan.tables.find(row => row.floorPlanComponentId === tableId);
  const table = tableFrom(expect(await pos('floor.sync'), 200, 'Sync floor to POS').floorPlan);
  assert.ok(table?.seatAllowed);
  const { check: seated } = expect(await pos('table.seat', {
    tablePublicId: table.publicId, guestCount: 2
  }), 201, 'Seat two guests at the floor-plan table');
  const check = seated.publicId;
  const state = () => fixture('state', { org, check });
  const ticket = async (completed = false) => expect(await owner.request(kdsPath + '?' + new URLSearchParams({
    locationId: String(location), ...(completed ? { completed: '1' } : {})
  })), 200, 'Read Expo board').board.tickets.find(row => row.checkPublicId === check);

  const pizzaCheck = expect(await pos('item.add', { checkPublicId: check, priceId: price, quantity: 2 }),
    200, 'Ring up two large pizzas').check;
  const liveLine = pizzaCheck.items[0].id;
  fixture('attach-modifier', { org, check, price, option, location });
  assert.equal(Number(state().lines[0].modifier_amount), 3);
  const twoLines = expect(await pos('item.add', {
    checkPublicId: check, priceId: price, quantity: 1, specialInstructions: 'Duplicate to void'
  }), 200, 'Ring up a second line to void later').check.items;
  const voidLine = twoLines.find(row => row.id !== liveLine).id;
  const { kitchen } = expect(await pos('kitchen.send', { checkPublicId: check }), 200, 'Send POS ticket to the oven');
  assert.equal(kitchen.sent, 2);
  const ovenWork = await work(oldPair);
  const orderedPizza = ovenWork.items.find(row => row.posLine.id === liveLine);
  const duplicate = ovenWork.items.find(row => row.posLine.id === voidLine);
  assert.ok(orderedPizza && duplicate);
  assert.equal(orderedPizza.ticket.checkPublicId, check);
  assert.equal(orderedPizza.ticket.tableName, 'Table 8');
  assert.equal(orderedPizza.station.publicId, oven);
  assert.equal(orderedPizza.posLine.quantity, 2);
  assert.deepEqual(orderedPizza.posLine.modifiers, [{ group: 'Toppings', name: 'Extra cheese',
    quantity: 2, unitAmount: 1.5, totalAmount: 3 }]);

  expect(await saveTable(440, 'Table 8 Window', plan), 200, 'Move and rename the occupied table');
  const moved = tableFrom(expect(await pos('floor.sync'), 200, 'Sync occupied table geometry').floorPlan);
  assert.equal(moved.publicId, table.publicId);
  assert.equal(moved.checkPublicId, check);
  assert.equal(moved.seatAllowed, false);
  assert.equal(moved.name, 'Table 8 Window');
  assert.ok(moved.xPercent > table.xPercent);
  const afterMove = await ticket();
  assert.equal(afterMove.tableName, moved.name);
  assert.equal(afterMove.items.find(row => row.public_id === orderedPizza.kdsItemPublicId).station_public_id, oven,
    'Changing table placement must preserve the routed kitchen item');

  const afterMoveWork = await work(oldPair);
  async function start(paired, row, sourceRevision) {
    const { buildSession } = expect(await sim(paired, 'build.start', {
      kdsItemPublicId: row.kdsItemPublicId, sourceRevision
    }), 200, 'Start the live build from the station revision');
    return buildSession;
  }
  const original = await start(oldPair, orderedPizza, afterMoveWork.revision);
  const voidBuild = await start(oldPair, duplicate, afterMoveWork.revision);
  const inBuild = (paired, build, action, data = {}) => sim(paired, action, {
    buildSessionPublicId: build.publicId, ...data
  });
  const evaluate = async (paired, build) => expect(await inBuild(paired, build, 'validation.evaluate'),
    200, 'Evaluate product validation').validation;
  async function confirm(paired, build, componentKey) {
    return expect(await inBuild(paired, build, 'build.confirm', { componentKey }), 200, 'Confirm ' + componentKey).buildSession;
  }
  async function prepare(paired, build, validation, status = 200) {
    return expect(await inBuild(paired, build, 'handoff.prepare', {
      validationPublicId: validation.publicId, validationSnapshotHash: validation.snapshotHash
    }), status, 'Review the current Expo handoff snapshot');
  }
  const sendToExpo = (paired, build, token) => inBuild(paired, build, 'handoff.expo', { confirmationToken: token });
  const manualCue = (observationKey, quantity) => ({
    observationKey, componentKey: ingredient, quantity, confidence: 0.96,
    metadata: { source: 'web_glasses_simulator_camera', manualTarget: true }
  });
  async function reviewedReceipt(paired, build) {
    for (const required of build.components) {
      if (!required.optional) await confirm(paired, build, required.componentKey);
    }
    const validation = await evaluate(paired, build);
    assert.equal(validation.status, 'ready_for_finishing');
    return (await prepare(paired, build, validation)).handoffConfirmation;
  }
  const originalReview = await reviewedReceipt(oldPair, original);
  const voidReview = await reviewedReceipt(oldPair, voidBuild);

  expect(await kds('item.reassign', {
    itemPublicId: orderedPizza.kdsItemPublicId, stationPublicId: finish
  }), 200, 'Reassign the live pizza to finishing');
  expect(await inBuild(oldPair, original, 'build.observe', manualCue('after-reassignment', 6)),
    422, 'Old station cannot submit a new observation');
  expect(await sendToExpo(oldPair, original, originalReview.token), 422, 'Old station cannot deliver a reviewed build');
  assert.ok(!(await work(oldPair)).items.some(row => row.kdsItemPublicId === orderedPizza.kdsItemPublicId));
  const finishWork = await work(newPair);
  const reassigned = finishWork.items.find(row => row.kdsItemPublicId === orderedPizza.kdsItemPublicId);
  assert.equal(reassigned.station.publicId, finish);
  assert.equal(reassigned.ticket.tableName, moved.name);
  const fresh = await start(newPair, reassigned, finishWork.revision);
  assert.notEqual(fresh.publicId, original.publicId);
  assert.equal(component(fresh, ingredient).expectedQuantity, 6);
  assert.equal(component(fresh, ingredient).metadata.advisoryForCustomizedOrder, true);
  assert.equal(component(fresh, ingredient).detectedQuantity, 0, 'Previous station evidence must not carry over');
  assert.equal(component(fresh, customization).expectedQuantity, 2);

  expect(await pos('item.void', { checkPublicId: check, itemId: voidLine, reason: 'Duplicate order' }),
    200, 'Void the second line after kitchen routing');
  expect(await inBuild(oldPair, voidBuild, 'build.observe', manualCue('after-void', 3)),
    422, 'Voided line cannot accept a new observation');
  expect(await sendToExpo(oldPair, voidBuild, voidReview.token), 422, 'Voided line cannot deliver its reviewed build');
  assert.ok(!(await work(oldPair)).items.some(row => row.kdsItemPublicId === duplicate.kdsItemPublicId));
  assert.deepEqual((await ticket()).items.map(row => [row.public_id, row.status]), [[orderedPizza.kdsItemPublicId, 'queued']]);
  assert.deepEqual(state().handoffs, [], 'Rejected handoffs must leave no receipt');

  const camera = {
    observationKey: 'camera-cue', componentKey: ingredient, quantity: 6, confidence: 0.96
  };
  expect(await inBuild(newPair, fresh, 'build.observe', { ...camera,
    metadata: { source: 'web_glasses_simulator' }
  }), 422, 'Scripted browser observation cannot advance a live order');
  expect(await inBuild(newPair, fresh, 'build.observe', { ...camera,
    metadata: { source: 'web_glasses_simulator_auto_vision', automatic: true }
  }), 422, 'Single browser track cannot satisfy a two-pizza order');
  const cue = expect(await inBuild(newPair, fresh, 'build.observe', manualCue(camera.observationKey, camera.quantity)),
    200, 'Store a live manual camera cue').buildSession;
  assert.equal(component(cue, ingredient).detectedQuantity, 0);

  const onDevice = (action, data) => device(newPair, action, { buildSessionPublicId: fresh.publicId, ...data });
  const mistaken = expect(await onDevice('build.observe', {
    observationKey: 'mistaken-pepperoni', componentKey: ingredient, quantity: 1, confidence: 0.97
  }), 200, 'Record an incorrect detection through the paired device endpoint').buildSession;
  assert.equal(component(mistaken, ingredient).detectedQuantity, 1);
  const correction = { observationKey: 'mistaken-pepperoni', correctionKey: 'operator-rejected-pepperoni',
    resolution: 'reject', reason: 'Operator identified a false positive' };
  const corrected = expect(await onDevice('build.correct_observation', correction), 200, 'Apply the operator correction');
  assert.equal(component(corrected.buildSession, ingredient).detectedQuantity, 0);
  const repeatedCorrection = expect(await onDevice('build.correct_observation', correction), 200, 'Repeat the same correction request');
  assert.deepEqual(repeatedCorrection.correction, corrected.correction);
  assert.equal(state().corrections.length, 1);
  const pending = await evaluate(newPair, fresh);
  assert.equal(pending.status, 'pending');
  await prepare(newPair, fresh, pending, 422);
  expect(await sendToExpo(newPair, fresh, undefined), 422, 'A build cannot hand off without a reviewed snapshot');

  await confirm(newPair, fresh, ingredient);
  const beforeCustomization = await evaluate(newPair, fresh);
  assert.equal(beforeCustomization.status, 'pending', 'Two ordered modifiers require explicit customization review');
  await prepare(newPair, fresh, beforeCustomization, 422);
  await confirm(newPair, fresh, customization);
  const reviewed = await evaluate(newPair, fresh);
  assert.equal(reviewed.status, 'ready_for_finishing');
  const receipt = (await prepare(newPair, fresh, reviewed)).handoffConfirmation;
  const first = expect(await sendToExpo(newPair, fresh, receipt.token), 200, 'Send the corrected pizza to Expo').handoff;
  const readyState = state();
  assert.deepEqual(readyState.observations, ['camera-cue', 'mistaken-pepperoni'].map(observation_key => ({
    session_public_id: fresh.publicId, observation_key
  })), 'Rejected stale and scripted observations must leave no evidence');
  const replay = expect(await sendToExpo(newPair, fresh, receipt.token), 200, 'Repeat identical Expo delivery').handoff;
  assert.deepEqual(replay, first);
  assert.deepEqual(state(), readyState, 'Exact replay must not change kitchen revision, events, or receipts');
  assert.deepEqual(readyState.handoffs, [{ pos_check_item_id: liveLine, public_id: first.publicId,
    validation_snapshot_hash: reviewed.snapshotHash, validation_status: 'handed_off' }]);
  assert.deepEqual(readyState.builds.find(row => row.public_id === original.publicId), {
    pos_check_item_id: liveLine, public_id: original.publicId, status: 'cancelled'
  });
  assert.deepEqual(readyState.events.filter(row => row.event_type === 'status_changed')
    .map(row => [row.pos_check_item_id, row.from_status, row.to_status, row.actor_user_id]), [
    [liveLine, 'queued', 'in_progress', seed.owner.id], [liveLine, 'in_progress', 'ready', seed.owner.id]
  ]);
  assert.equal(readyState.events.filter(row => row.event_type === 'pos_void' && row.pos_check_item_id === voidLine).length, 1);
  assert.deepEqual(readyState.lines.map(row => [row.id, row.pos_status, row.kitchen_status]), [
    [liveLine, 'active', 'ready'], [voidLine, 'voided', 'cancelled']
  ]);

  const ready = await ticket();
  assert.equal(ready.ready, 1);
  assert.equal(ready.readyToBump, true);
  assert.equal(ready.tableName, moved.name);
  const bump = { checkPublicId: check, ticketAction: 'bump' };
  assert.equal(expect(await kds('ticket.action', bump), 200, 'Expo completes the corrected ticket').result.affected, 1);
  const completedState = state();
  expect(await kds('ticket.action', bump), 422, 'Repeated Expo bump cannot complete the ticket twice');
  const finalReplay = expect(await sendToExpo(newPair, fresh, receipt.token), 200,
    'Retry original handoff after Expo completion').handoff;
  assert.equal(finalReplay.publicId, first.publicId);
  assert.deepEqual(state(), completedState);
  assert.equal(completedState.events.filter(row => row.event_type === 'ticket_bumped').length, 1);
  assert.equal((await ticket(true)).completed, 1);
  console.log('POS to Expo passed: two pizzas with paid modifier, occupied table move, reassignment, void, manual correction, handoff replay, and Expo completion.');
}

(async () => {
  const seed = fixture('seed');
  assert.equal(seed.nativePrepares, true, 'Exercise native PDO prepares');
  const sessions = mkdtempSync(join(tmpdir(), 'gelato-pos-expo-'));
  let server, startupError;
  let log = '';
  try {
    const port = await availablePort();
    const origin = 'http://127.0.0.1:' + port;
    server = spawn(php, ['-d', 'session.save_path=' + sessions, '-S', '127.0.0.1:' + port, '-t', root], {
      cwd: root, stdio: ['ignore', 'ignore', 'pipe']
    });
    server.once('error', error => { startupError = error; });
    server.stderr.on('data', chunk => { log = (log + chunk).slice(-8000); });
    const startupDeadline = AbortSignal.timeout(6000);
    while (true) {
      if (startupError) throw startupError;
      try {
        if ((await client(origin, startupDeadline).request('/login.php')).status === 200) break;
      } catch {}
      assert.ok(!startupDeadline.aborted, 'PHP fixture server did not start');
      await delay(50);
    }
    await journey(origin, seed, AbortSignal.timeout(90000));
  } catch (error) {
    console.error(log);
    throw error;
  } finally {
    if (server?.exitCode === null && server.signalCode === null && server.pid) {
      const exited = new Promise(resolve => server.once('exit', resolve));
      server.kill();
      const forceExit = setTimeout(() => server.kill('SIGKILL'), 2000);
      await exited;
      clearTimeout(forceExit);
    }
    rmSync(sessions, { recursive: true, force: true });
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
