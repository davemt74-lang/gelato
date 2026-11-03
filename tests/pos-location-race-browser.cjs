'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const origin = 'http://gelato-pos.test';
const csrf = 'a'.repeat(64);
const locations = [{ id: 11, name: 'North' }, { id: 22, name: 'South' }];
const scripts = new Set(['/js/pos.js', '/js/pos-runtime.js', '/js/pos-agent-context.js', '/js/agent-page-context.js']);
const locationName = id => locations.find(row => row.id === id).name;

function check(id, suffix = 'open', items = []) {
  return {
    publicId: 'check-' + id + '-' + suffix,
    checkNumber: locationName(id) + ' ' + suffix,
    locationId: id,
    locationName: locationName(id),
    status: 'open',
    serviceMode: 'dine_in',
    guestCount: 2,
    openedBy: 'Fixture staff',
    items,
  };
}
function openCheck(row) {
  return {
    public_id: row.publicId, check_number: row.checkNumber, service_mode: 'dine_in',
    table_id: 1, table_name: row.checkNumber, guest_count: 2,
  };
}
function table(id, overrides = {}) {
  return {
    publicId: 'table-' + id,
    floorPlanPublicId: 'plan-' + id,
    floorPlanComponentId: 'component-' + id,
    name: locationName(id) + ' current table',
    capacity: 4, xPercent: 10, yPercent: 10, widthPercent: 10, heightPercent: 10,
    state: 'available', status: 'active', retired: false, seatAllowed: true,
    ...overrides,
  };
}
function bootstrap(id, row = null) {
  const selectedPlan = { publicId: 'plan-' + id, name: locationName(id) + ' plan' };
  const tables = [
    table(id),
    table(id, { publicId: 'manual-' + id, name: 'Manual table', xPercent: 40, floorPlanPublicId: null, floorPlanComponentId: null }),
    table(id, { publicId: 'occupied-' + id, name: 'Occupied table', xPercent: 55, floorPlanComponentId: 'occupied-component',
      state: 'seated', seatAllowed: false, checkPublicId: check(id).publicId }),
    table(id, { publicId: 'blocked-' + id, name: 'Blocked table', xPercent: 75, floorPlanComponentId: 'blocked-component',
      state: 'blocked', seatAllowed: false }),
    table(id, { publicId: 'prior-' + id, floorPlanPublicId: 'old-plan', name: 'Prior-plan table' }),
    table(id, { publicId: 'retired-' + id, floorPlanComponentId: 'removed-component', status: 'retired',
      retired: true, seatAllowed: false, checkPublicId: check(id, 'retired').publicId, name: 'Retired table' }),
  ];
  return {
    ok: true, locationId: id, locations, check: row, settings: { locationId: id, defaultServiceMode: 'dine_in' },
    permissions: { manage: true, customerLink: true, floorView: true, floorManage: true, tableUse: true, kdsAvailable: true },
    menu: [{ id, name: locationName(id) + ' menu', items: [{ id, name: locationName(id) + ' pizza', prices: [{ id, optionName: 'Regular', amount: 12 }] }] }],
    openChecks: [openCheck(check(id)), openCheck(check(id, 'retired'))],
    recentChecks: [],
    floorPlan: { ready: true, selectedPlan, plans: [selectedPlan], structures: [], tables, mappedCount: 1 },
  };
}
function ready(id) {
  return { ok: true, locationId: id, readyTickets: [{
    checkPublicId: check(id).publicId, checkNumber: locationName(id) + ' READY', guestCount: 2, items: [],
  }] };
}
function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

async function main() {
  const pageSource = await fs.readFile(path.join(root, 'pos.php'), 'utf8');
  const html = pageSource.slice(pageSource.indexOf('<!doctype html>'))
    .replace(/<\?php (?:if\([^\n]*?\):|endif;)\?>/g, '')
    .replace(/<\?=json_encode\(app_csrf_token\(\).*?\)\?>/, JSON.stringify(csrf))
    .replace('<?=json_encode($canCrmView)?>', 'true');
  assert.ok(!html.includes('<?'), 'The fixture only replaces the PHP authentication/bootstrap values.');

  const browser = await chromium.launch({ headless: true, executablePath: process.env.GELATO_CHROMIUM_EXECUTABLE || undefined });
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    page.setDefaultTimeout(6000);
    const errors = [];
    const holds = [];
    page.on('pageerror', error => errors.push(error.message));
    const matchPos = (id, publicId = null) => (request, url) => url.pathname === '/api/pos.php'
      && request.method() === 'GET' && Number(url.searchParams.get('locationId')) === id
      && url.searchParams.get('check') === publicId;
    const matchReady = id => (request, url) => url.pathname === '/api/pos-ready.php' && Number(url.searchParams.get('locationId')) === id;
    const hold = (match, json, status = 200) => {
      const seen = deferred();
      const release = deferred();
      holds.push({ match, json, status, seen, release });
      return {
        seen: seen.promise,
        async finish() {
          const delivered = page.waitForResponse(response => match(response.request(), new URL(response.url())));
          release.resolve();
          await (await delivered).finished();
          await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        },
      };
    };

    const oldReady = hold(matchReady(11), ready(11));
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname === '/pos.php') return route.fulfill({ contentType: 'text/html', body: html });
      if (scripts.has(url.pathname)) return route.fulfill({ path: path.join(root, url.pathname) });
      const holdIndex = holds.findIndex(entry => entry.match(request, url));
      if (holdIndex !== -1) {
        const [entry] = holds.splice(holdIndex, 1);
        entry.seen.resolve(request);
        await entry.release.promise;
        return route.fulfill({ json: entry.json, status: entry.status });
      }
      if (url.pathname === '/api/pos.php' && request.method() === 'GET') {
        const id = Number(url.searchParams.get('locationId') || 11);
        const publicId = url.searchParams.get('check');
        const row = publicId ? check(id, publicId.split('-').at(-1)) : null;
        return route.fulfill({ json: bootstrap(id, row) });
      }
      if (url.pathname === '/api/pos-ready.php') return route.fulfill({ json: ready(Number(url.searchParams.get('locationId'))) });
      return route.fulfill({ status: 204 });
    });

    await page.goto(origin + '/pos.php');
    await oldReady.seen;
    const selector = page.locator('#locationSelect');
    const agent = () => page.evaluate(() => window.GelatoPosAgentContext.snapshot());
    const selectLocation = async id => {
      await selector.selectOption(String(id));
      await page.locator('#menuGrid').getByText(locationName(id) + ' pizza', { exact: true }).waitFor({ state: 'attached' });
    };
    const selectCheck = async row => {
      await page.locator('#posMenuButton').click();
      await page.locator('[data-check="' + row.publicId + '"]').click();
    };
    const expectCheck = row => page.locator('#ticketHead h2').getByText(row.checkNumber, { exact: true }).waitFor();

    await page.locator('[data-pos-view="floor"]').click();
    assert.ok(await page.locator('#floorView').isVisible());
    assert.ok(await page.locator('[data-table-public="table-11"]').isEnabled());
    assert.ok(await page.locator('[data-table-public="manual-11"]').isEnabled());
    assert.equal(await page.locator('[data-table-public="blocked-11"]').isEnabled(), false);
    assert.equal(await page.locator('[data-table-public="prior-11"], [data-table-public="retired-11"]').count(), 0,
      'Former plan and retired rows cannot be rendered over the current floor.');
    await page.locator('[data-table-public="occupied-11"]').click();
    await expectCheck(check(11));
    assert.equal(await page.locator('#newModal').isVisible(), false, 'An occupied point opens its existing check.');
    const retired = check(11, 'retired');
    await selectCheck(retired);
    await expectCheck(retired);
    assert.equal((await agent()).checkPublicId, retired.publicId, 'The open check still loads from Active Tickets.');

    const blockedSouth = hold(matchPos(22), bootstrap(22));
    await page.locator('[data-table-public="table-11"]').click();
    assert.equal(await page.locator('#newTablePublicId').inputValue(), 'table-11');
    await selector.selectOption('22');
    await blockedSouth.seen;
    assert.equal(await selector.inputValue(), '22');
    assert.equal(await page.locator('#newModal').isVisible(), false);
    assert.equal(await page.locator('#newTablePublicId').inputValue(), '');
    assert.equal(await page.locator('#floorCanvas [data-table-public]').count(), 0);
    assert.equal(await page.locator('#openChecks [data-check]').count(), 0);
    assert.equal(await page.locator('#ticketHead h2').textContent(), 'No open check');
    assert.equal(await page.locator('#newCheck').isEnabled(), false);
    assert.equal((await agent()).locationId, 22);
    assert.equal((await agent()).checkPublicId, '');

    await selectLocation(11);
    await blockedSouth.finish();
    assert.equal(await selector.inputValue(), '11', 'A late South bootstrap cannot reverse the latest North selection.');
    assert.equal((await agent()).locationId, 11);
    assert.equal(await page.locator('[data-table-public="table-11"]').count(), 1);

    const staleCheck = hold(matchPos(11, check(11).publicId), bootstrap(11, check(11)));
    await selectCheck(check(11));
    await staleCheck.seen;
    await selectLocation(22);
    await page.locator('#readyTickets').getByText('South READY', { exact: true }).waitFor({ state: 'attached' });
    await page.locator('[data-pos-view="ready"]').click();
    assert.ok(await page.locator('#readyView').isVisible());
    assert.equal(await page.locator('#floorView').isVisible(), false);
    await selectCheck(check(22));
    await expectCheck(check(22));
    await staleCheck.finish();
    await oldReady.finish();
    assert.equal(await selector.inputValue(), '22');
    assert.equal(await page.locator('#ticketHead h2').textContent(), check(22).checkNumber);
    assert.match(await page.locator('#readyTickets').textContent(), /South READY/);
    assert.doesNotMatch(await page.locator('#readyTickets').textContent(), /North READY/);
    assert.equal((await agent()).checkPublicId, check(22).publicId);

    await page.locator('#attachCustomer').click();
    const oldCrm = hold((request, url) => url.pathname === '/api/customer-crm.php',
      { ok: true, customers: [{ publicId: 'private-south', displayName: 'Private South customer' }] });
    await page.locator('#customerSearchBtn').click();
    await oldCrm.seen;
    await selectLocation(11);
    await oldCrm.finish();
    assert.equal(await page.locator('#customerModal').isVisible(), false);
    assert.doesNotMatch(await page.locator('#customerResults').textContent(), /Private South customer/);

    await selectCheck(check(11));
    await expectCheck(check(11));
    await page.locator('[data-pos-view="menu"]').click();
    const matchAdd = (request, url) => url.pathname === '/api/pos.php' && request.method() === 'POST';
    const item = { id: 99, menu_item_id: 11, item_name_snapshot: 'Old location pizza', quantity: 1, status: 'active' };
    const lateAdd = hold(matchAdd, bootstrap(11, check(11, 'open', [item])));
    await page.locator('[data-add="11"]').click();
    const addRequest = await lateAdd.seen;
    assert.equal(addRequest.postDataJSON().checkPublicId, check(11).publicId);
    await selectLocation(22);
    await selectCheck(check(22));
    await expectCheck(check(22));
    await lateAdd.finish();
    assert.equal(await selector.inputValue(), '22');
    assert.doesNotMatch(await page.locator('#ticketItems').textContent(), /Old location pizza/);
    assert.equal((await agent()).checkPublicId, check(22).publicId);

    // A second pass through the same location still belongs to a new workspace revision.
    await selectLocation(11);
    await selectCheck(check(11));
    await expectCheck(check(11));
    const previousVisit = hold(matchAdd, bootstrap(11, check(11, 'open', [item])));
    await page.locator('[data-add="11"]').click();
    await previousVisit.seen;
    await selectLocation(22);
    await selectLocation(11);
    await selectCheck(check(11));
    await expectCheck(check(11));
    await previousVisit.finish();
    assert.doesNotMatch(await page.locator('#ticketItems').textContent(), /Old location pizza/);
    assert.deepEqual((await agent()).lineItemIds, []);

    const newItem = { ...item, id: 100, item_name_snapshot: 'Current location pizza' };
    const currentAdd = hold(matchAdd, bootstrap(11, check(11, 'open', [newItem])));
    await page.locator('[data-add="11"]').click();
    await currentAdd.seen;
    await currentAdd.finish();
    await page.locator('#ticketItems').getByText('Current location pizza', { exact: true }).waitFor();
    assert.deepEqual((await agent()).lineItemIds, [100]);
    assert.equal((await agent()).focusedLineId, 100);

    const staleFailure = hold(matchPos(22), { ok: false, message: 'Old South load failure' }, 403);
    await selector.selectOption('22');
    await staleFailure.seen;
    await selectLocation(11);
    await staleFailure.finish();
    assert.doesNotMatch(await page.locator('#toast').textContent(), /Old South load failure/);
    assert.deepEqual(errors, [], 'The shipped POS handlers run without browser errors.');
    console.log('POS Chromium: current location, check, READY, CRM and Agent context survive stale responses. Retired checks remain reachable.');
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
