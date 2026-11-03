'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const origin = 'http://vision-lab.test';
const csrf = 'a'.repeat(64);
const endpoint = '/api/glasses-vision-lab.php';
const simple = { publicId: 'simple-draft', name: 'Pizza review', versionLabel: 'v1', status: 'draft', itemCount: 3, classCount: 1 };
const assembled = { publicId: 'v11-draft', name: 'Assembly review', versionLabel: 'v1', status: 'draft', items: [], assemblyHash: 'a'.repeat(64) };
const guardMessage = 'Dataset freeze blocked: apply a governed group-aware split plan first.';

async function main() {
  const catalog = { canManage: true, datasets: [simple, assembled], v11Datasets: { datasets: [assembled] } };
  const pageSource = await fs.readFile(path.join(root, 'glasses-vision-lab.php'), 'utf8');
  const html = pageSource.slice(pageSource.indexOf('<!doctype html>'))
    .replace(/<\?php if\(\$error\):\?>.*?<\?php endif;\?>/s, '')
    .replace(/<\?=json_encode\(\$boot,.*?\)\?>/, JSON.stringify({ apiUrl: endpoint, csrfToken: csrf, catalog }));
  assert.ok(!html.includes('<?'), 'The shipped markup must render with only the fixture bootstrap replaced.');

  const browser = await chromium.launch({ headless: true, executablePath: process.env.GELATO_CHROMIUM_EXECUTABLE || undefined });
  try {
    const page = await browser.newPage();
    page.setDefaultTimeout(6000);
    const posts = [];
    const errors = [];
    let planApplied = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname === '/glasses-vision-lab.php') return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname === endpoint) {
        if (request.method() === 'GET') return route.fulfill({ json: { ok: true, catalog } });
        const body = request.postDataJSON();
        posts.push(body);
        const dataset = catalog.datasets.find(row => row.publicId === body.datasetPublicId);
        if (!dataset) return route.fulfill({ status: 422, json: { ok: false, message: 'Vision dataset was not found.' } });
        if (dataset === simple && !planApplied) return route.fulfill({ status: 422, json: { ok: false, message: guardMessage } });
        dataset.status = 'frozen';
        dataset.datasetHash = 'b'.repeat(64);
        return route.fulfill({ json: { ok: true, dataset } });
      }
      if (['/assets/js/glasses-vision-lab.js', '/assets/js/glasses-v11-dataset-assembly.js'].includes(url.pathname)) {
        return route.fulfill({ path: path.join(root, url.pathname) });
      }
      return route.fulfill({ status: 204 });
    });

    await page.goto(origin + '/glasses-vision-lab.php');
    const mainFreeze = page.locator(`#vlDatasets button[data-freeze="${simple.publicId}"]`);
    page.once('dialog', dialog => dialog.dismiss());
    await mainFreeze.click();
    assert.equal(posts.length, 0, 'Cancellation does not submit the freeze.');

    const freezeRequest = page.waitForRequest(request => request.url() === origin + endpoint && request.method() === 'POST');
    page.once('dialog', dialog => dialog.accept());
    await mainFreeze.click();
    assert.deepEqual((await freezeRequest).postDataJSON(), { action: 'dataset.freeze', datasetPublicId: simple.publicId, csrf_token: csrf });
    await page.locator('#vlToast.error').getByText(guardMessage).waitFor();
    assert.ok(await mainFreeze.isEnabled(), 'A guard rejection leaves the draft available for retry.');

    planApplied = true;
    page.once('dialog', dialog => dialog.accept());
    await mainFreeze.click();
    await page.locator('#vlToast').getByText('Dataset frozen.').waitFor();
    assert.equal(await mainFreeze.count(), 0, 'An immutable dataset has no Freeze button.');
    assert.equal(await page.locator('#vlDatasets article').filter({ hasText: simple.name }).locator('.vl-state').textContent(), 'frozen');

    const v11Freeze = page.locator(`#vlV11Datasets button[data-freeze="${assembled.publicId}"]`);
    page.once('dialog', dialog => dialog.accept());
    await v11Freeze.click();
    await page.locator('#vlV11Datasets .vl-state.frozen').waitFor();
    assert.deepEqual(posts.map(body => [body.action, body.datasetPublicId, body.csrf_token]), [
      ['dataset.freeze', simple.publicId, csrf],
      ['dataset.freeze', simple.publicId, csrf],
      ['dataset.freeze', assembled.publicId, csrf],
    ]);
    assert.deepEqual(errors, [], 'The shipped freeze handlers run without browser errors.');
    console.log('Vision Lab Freeze Chromium: main and V11 controls, cancellation, split-plan rejection, and immutable refresh passed.');
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
