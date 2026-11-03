'use strict';

const assert = require('node:assert/strict');
const { chromium } = require('playwright');

async function verifyFloorEditorBrowser({ origin, cookies, planId }) {
  const base = new URL(`${origin.replace(/\/$/, '')}/`);
  const editor = new URL('floor-planner-v2.php', base);
  editor.searchParams.set('plan', planId);
  const apiPaths = [new URL('floor-plan-api.php', base).pathname, new URL('api/floor-plans.php', base).pathname];
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.GELATO_CHROMIUM_EXECUTABLE || undefined,
  });

  function isPlanResponse(response, method) {
    const url = new URL(response.url());
    return url.origin === base.origin && apiPaths.includes(url.pathname) && response.request().method() === method;
  }

  async function readPlan(page, navigate) {
    const pending = page.waitForResponse(response => isPlanResponse(response, 'GET') &&
      new URL(response.url()).searchParams.get('id') === planId);
    await navigate();
    const response = await pending;
    assert.equal(response.status(), 200);
    const { plan } = await response.json();
    assert.equal(plan.id, planId);
    assert.ok(Number.isInteger(plan.version) && plan.version > 0);
    await page.waitForFunction(name => document.querySelector('#planName')?.value === name, plan.name);
    await page.waitForFunction(() => document.querySelector('#savePlanBtn:enabled') !== null);
    return plan;
  }

  async function writePlan(page, action, click) {
    const pending = page.waitForResponse(response => isPlanResponse(response, 'POST') &&
      response.request().postDataJSON()?.action === action);
    await click();
    const response = await pending;
    return { status: response.status(), body: await response.json(), sent: response.request().postDataJSON() };
  }

  async function confirmClick(page, selector, accept) {
    const pending = page.waitForEvent('dialog');
    const click = page.locator(selector).click();
    const dialog = await pending;
    assert.equal(dialog.type(), 'confirm');
    if (accept) await dialog.accept();
    else await dialog.dismiss();
    await click;
  }

  try {
    const context = await browser.newContext();
    await context.addCookies(Array.from(cookies, cookie => Array.isArray(cookie)
      ? { name: cookie[0], value: cookie[1], url: base.origin }
      : cookie));
    const [first, second] = await Promise.all([context.newPage(), context.newPage()]);
    for (const page of [first, second]) page.setDefaultTimeout(10000);
    await second.clock.install();

    const [firstPlan, secondPlan] = await Promise.all([
      readPlan(first, () => first.goto(editor.href)),
      readPlan(second, () => second.goto(editor.href)),
    ]);
    assert.equal(firstPlan.version, secondPlan.version, 'Both shipped editors load the same saved revision.');

    const firstName = `${firstPlan.name.slice(0, 128)} first save`;
    const secondName = `${firstPlan.name.slice(0, 128)} second draft`;
    const originalTables = await second.locator('#stage .structure[data-type="table"]').count();
    await second.locator('#planName').fill(secondName);
    await second.locator('[data-structure="table"]').click();
    assert.equal(await second.locator('#stage .structure[data-type="table"]').count(), originalTables + 1);

    await first.locator('#planName').fill(firstName);
    const saved = await writePlan(first, 'save', () => first.locator('#savePlanBtn').click());
    assert.equal(saved.status, 200, saved.body.message);
    assert.equal(saved.sent.plan.version, firstPlan.version);
    assert.equal(saved.body.plan.name, firstName);
    assert.equal(saved.body.plan.version, firstPlan.version + 1);

    const stale = await writePlan(second, 'save', () => second.locator('#savePlanBtn').click());
    assert.equal(stale.sent.plan.version, secondPlan.version);
    assert.equal(stale.status, 409, stale.body.message);
    assert.equal(stale.body.code, 'floor_plan_version_conflict');
    assert.equal(stale.body.currentVersion, saved.body.plan.version);
    await second.locator('#planConflict').waitFor({ state: 'visible' });
    assert.equal(await second.locator('#planName').inputValue(), secondName);
    assert.equal(await second.locator('#stage .structure[data-type="table"]').count(), originalTables + 1);

    await second.clock.runFor(3000);
    assert.ok(await second.locator('#planConflict').isVisible(), 'The conflict remains visible after ordinary status messages expire.');
    await confirmClick(second, '#reloadPlanBtn', false);
    assert.ok(await second.locator('#planConflict').isVisible());
    assert.equal(await second.locator('#planName').inputValue(), secondName, 'Cancel keeps the unsaved editor draft.');
    assert.equal(await second.locator('#stage .structure[data-type="table"]').count(), originalTables + 1);

    const reloaded = await readPlan(second, () => confirmClick(second, '#reloadPlanBtn', true));
    assert.equal(reloaded.name, firstName);
    assert.equal(reloaded.version, saved.body.plan.version);
    await second.locator('#planConflict').waitFor({ state: 'hidden' });
    assert.equal(await second.locator('#stage .structure[data-type="table"]').count(), originalTables);

    await second.locator('#planName').fill(secondName);
    const retried = await writePlan(second, 'save', () => second.locator('#savePlanBtn').click());
    assert.equal(retried.status, 200, retried.body.message);
    assert.equal(retried.sent.plan.version, reloaded.version);
    assert.equal(retried.body.plan.version, reloaded.version + 1);
    assert.equal(retried.body.plan.name, secondName);

    const staleArchive = await writePlan(first, 'archive', () => confirmClick(first, '#archivePlanBtn', true));
    assert.equal(staleArchive.sent.version, saved.body.plan.version);
    assert.equal(staleArchive.status, 409, staleArchive.body.message);
    assert.equal(staleArchive.body.code, 'floor_plan_version_conflict');
    assert.equal(staleArchive.body.currentVersion, retried.body.plan.version);
    await first.locator('#planConflict').waitFor({ state: 'visible' });
    assert.equal(await first.locator('#planName').inputValue(), firstName, 'A stale archive does not clear the current editor.');
    console.log('Floor Planner Chromium: stale saves and archives preserve the draft, and explicit reload adopts the saved revision.');
  } finally {
    await browser.close();
  }
}

module.exports = { verifyFloorEditorBrowser };
