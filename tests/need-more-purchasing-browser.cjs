'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const output = process.env.QA_OUTPUT_DIR
    ? path.resolve(process.env.QA_OUTPUT_DIR)
    : fs.mkdtempSync(path.join(os.tmpdir(), 'gelato-purchasing-browser-'));
fs.mkdirSync(output, { recursive: true });
const config = { canCompare: true, canDraft: true, csrf: 'browser-qa-csrf' };
const source = fs.readFileSync(path.join(root, 'need-more-purchasing.php'), 'utf8');
const phpBlocks = source.match(/<\?php[\s\S]*?\?>/g) || [];
assert.equal(phpBlocks.length, 2, 'fixture must explicitly handle every PHP block in the real page');
assert.match(phpBlocks[0], /\$clientConfig\s*=/, 'first PHP block is the authenticated server setup');
assert.match(phpBlocks[1], /^<\?php echo json_encode\(\$clientConfig,/, 'second PHP block only emits client configuration');
const html = source.replace(phpBlocks[0], '').replace(phpBlocks[1], JSON.stringify(config));
assert(!html.includes('<?php'), 'no unrendered PHP can enter the browser fixture');

const staticFiles = new Map([
    ['/js/need-more-purchasing.js', ['js/need-more-purchasing.js', 'text/javascript; charset=utf-8']],
    ['/assets/css/daily-prep.css', ['assets/css/daily-prep.css', 'text/css; charset=utf-8']],
    ['/assets/css/need-more-purchasing.css', ['assets/css/need-more-purchasing.css', 'text/css; charset=utf-8']],
]);
const injection = '<img src=x onerror="window.__qaXss=true">';
let preferred = 'map-a', revision = 1, drafted = false, writes = [];
const serverErrors = [];

function comparison() {
    return {
        requestId: 'need-flour', stockId: 'stock-flour', name: 'Caputo flour', remainingQuantity: 5, unit: 'kg',
        canDraft: !drafted, blockers: drafted ? ['An open order already covers this stock item.'] : [],
        comparisonHash: 'comparison-' + revision, openOrders: [],
        options: [
            { vendorItemId: 'map-a', vendorId: 'vendor-a', vendor: 'Desert Pantry ' + injection,
                preferred: preferred === 'map-a', eligible: true, packSize: 2, packUnit: 'kg', pricePerPack: 10,
                unitPrice: 5, packs: 3, orderedQuantity: 6, overageQuantity: 1, estimatedSubtotal: 30,
                minimumOrderAmount: 50, minimumShortfall: 20, leadTimeDays: 2,
                priceEffectiveAt: '2026-11-02 09:00:00', blockers: [], warnings: [],
                priceHistory: [{ pricePerPack: 9, packSize: 2, unit: 'kg', unitPrice: 4.5,
                    recordedAt: '2026-10-01 10:15:00', source: 'catalog' }] },
            { vendorItemId: 'map-b', vendorId: 'vendor-b', vendor: 'Valley Wholesale',
                preferred: preferred === 'map-b', eligible: true, packSize: 3, packUnit: 'kg', pricePerPack: 12,
                unitPrice: 4, packs: 2, orderedQuantity: 6, overageQuantity: 1, estimatedSubtotal: 24,
                minimumOrderAmount: 50, minimumShortfall: 26, leadTimeDays: 1,
                priceEffectiveAt: '2026-11-02 11:00:00', blockers: [], warnings: [],
                priceHistory: [{ pricePerPack: 11.25, packSize: 3, unit: 'kg', unitPrice: 3.75,
                    recordedAt: '2026-10-15 09:30:00', source: 'receipt' }] },
            { vendorItemId: 'map-c', vendorId: 'vendor-c', vendor: 'Supplier needing a unit conversion',
                preferred: false, eligible: false, packSize: 25, packUnit: 'lb', pricePerPack: null,
                unitPrice: null, packs: null, orderedQuantity: null, overageQuantity: null, estimatedSubtotal: null,
                minimumOrderAmount: null, minimumShortfall: null, leadTimeDays: 3, priceEffectiveAt: null,
                blockers: ['Vendor pack unit differs from the saved stock unit. Configure a verified conversion before ordering.'],
                warnings: ['Vendor price is not saved.'], priceHistory: [] },
        ],
    };
}

function board() {
    return { ok: true, board: {
        totals: { requests: 1, numericOutstanding: 1, withOpenOrders: drafted ? 1 : 0, withRecentDeliveries: 1 },
        requests: [{ id: 'need-flour', title: 'Caputo flour ' + injection, status: 'need_more', quantityNote: 'For tomorrow’s pizza dough',
            progress: { requestedQuantity: 5, receivedQuantity: 0, remainingQuantity: 5, unit: 'kg' },
            openOrders: drafted ? [{ orderId: 'po-qa', orderNumber: 'PO-QA-1', vendor: 'Valley Wholesale',
                status: 'draft', orderedQuantity: 6, receivedQuantity: 0, unit: 'kg', expectedDate: null }] : [],
            recentDeliveries: [{ receiptNumber: 'GR-RECENT', vendor: 'Desert Pantry', quantity: 20,
                unit: 'kg', receivedAt: '2026-10-30 08:30:00', orderNumber: 'PO-RECENT' }],
        }],
    } };
}

function quote(vendorItemId) {
    const option = comparison().options.find(row => row.vendorItemId === vendorItemId && row.eligible);
    assert(option, 'preview requires an eligible exact supplier mapping');
    return { ...option, requestId: 'need-flour', name: 'Caputo flour', remainingQuantity: 5, unit: 'kg',
        quoteHash: 'quote-' + revision + '-' + vendorItemId,
        warnings: [option.minimumShortfall + ' dollars below the supplier minimum; review before submission.'],
    };
}

function json(response, body, status = 200) {
    response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify(body));
}

const server = http.createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://127.0.0.1');
        if (url.pathname === '/need-more-purchasing.php') {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            response.end(html);
            return;
        }
        if (staticFiles.has(url.pathname)) {
            const [file, type] = staticFiles.get(url.pathname);
            response.writeHead(200, { 'Content-Type': type });
            response.end(fs.readFileSync(path.join(root, file)));
            return;
        }
        if (url.pathname === '/favicon.ico') { response.writeHead(204); response.end(); return; }
        assert.equal(url.pathname, '/api/daily-prep.php', 'unexpected local page request');
        if (request.method === 'GET') {
            const action = url.searchParams.get('action');
            if (action === 'purchasing_board') { json(response, board()); return; }
            assert.equal(url.searchParams.get('id'), 'need-flour', 'API read retains exact Need More identity');
            if (action === 'po_vendor_options') { json(response, { ok: true, comparison: comparison() }); return; }
            assert.equal(action, 'po_draft_preview', 'only known read actions are supported');
            json(response, { ok: true, quote: quote(url.searchParams.get('vendorItemId')) });
            return;
        }
        assert.equal(request.method, 'POST');
        assert.equal(request.headers['x-csrf-token'], config.csrf, 'mutations preserve the page CSRF token');
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        assert.equal(body.id, 'need-flour');
        assert.equal(body.vendorItemId, 'map-b', 'mutations use the reviewed selected supplier');
        if (body.action === 'po_vendor_prefer') {
            assert.equal(body.comparisonHash, 'comparison-' + revision);
            writes.push(body);
            preferred = body.vendorItemId;
            revision += 1;
            json(response, { ok: true, comparison: comparison() });
            return;
        }
        assert.equal(body.action, 'po_draft_create', 'only explicit draft creation is permitted');
        assert.equal(body.quoteHash, quote(body.vendorItemId).quoteHash, 'draft uses a freshly reviewed quote');
        assert.equal(drafted, false, 'the UI must not submit duplicate drafts');
        writes.push(body);
        drafted = true;
        json(response, { ok: true, order: { orderId: 'po-qa', orderNumber: 'PO-QA-1', status: 'draft' } }, 201);
    } catch (error) {
        serverErrors.push(error.message);
        json(response, { ok: false, message: error.message }, 500);
    }
});

async function checkLayout(page, label) {
    const layout = await page.evaluate(() => {
        const viewport = document.documentElement.clientWidth;
        const elements = [...document.querySelectorAll('body *')].filter(element => element.getClientRects().length);
        return {
            viewport,
            width: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
            outside: elements.filter(element => {
                const rect = element.getBoundingClientRect();
                return rect.left < -1 || rect.right > viewport + 1;
            }).slice(0, 12).map(element => element.id || element.className || element.tagName),
            nestedScrollers: elements.filter(element => {
                const style = getComputedStyle(element);
                return /^(auto|scroll)$/.test(style.overflowY) && element.scrollHeight > element.clientHeight + 1;
            }).map(element => element.id || element.className || element.tagName),
        };
    });
    assert(layout.width <= layout.viewport + 1, label + ' has horizontal document overflow: ' + JSON.stringify(layout));
    assert.deepEqual(layout.outside, [], label + ' has content outside the viewport');
    assert.deepEqual(layout.nestedScrollers, [], label + ' has nested vertical scrolling');
}

async function actionable(page, selector) {
    const control = page.locator(selector);
    await control.waitFor({ state: 'visible' });
    await control.scrollIntoViewIfNeeded();
    assert.equal(await control.isEnabled(), true, selector + ' must be enabled');
    const box = await control.boundingBox();
    const viewport = page.viewportSize();
    assert(box && box.width >= 32 && box.height >= 32, selector + ' must have a usable target size');
    assert(box.x >= -1 && box.x + box.width <= viewport.width + 1, selector + ' must fit the viewport');
    assert(box.y >= -1 && box.y + box.height <= viewport.height + 1, selector + ' must be visible after scrolling');
    return control;
}

async function scenario(browser, origin, name, width, height) {
    preferred = 'map-a'; revision = 1; drafted = false; writes = [];
    const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, locale: 'en-US' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    // Use the shipped CSS while keeping this fixture independent of external font services.
    await page.route('https://fonts.googleapis.com/**', route => route.fulfill({ status: 200, contentType: 'text/css', body: '' }));
    try {
        await page.goto(origin + '/need-more-purchasing.php', { waitUntil: 'networkidle' });
        await page.locator('#board-list button[data-compare]').waitFor({ state: 'visible' });
        assert.match(await page.locator('#board-list').innerText(), /Caputo flour <img/);
        await checkLayout(page, name + ' board');
        await (await actionable(page, '#board-list button[data-compare]')).click();
        await page.locator('#vendor-options .vendor-option').first().waitFor({ state: 'visible' });
        assert.equal(await page.locator('#vendor-options .vendor-option').count(), 3);
        assert.equal(await page.locator('#vendor-options img, #board-list img').count(), 0, 'untrusted strings must not create image nodes');
        assert.equal(await page.evaluate(() => window.__qaXss), undefined, 'injected event-handler text must not execute');
        assert.match(await page.locator('#vendor-options').innerText(), /\$24\.00/);
        assert.match(await page.locator('#vendor-options').innerText(), /\$26\.00 below/);
        assert.equal(await page.locator('input[value="map-c"]').isDisabled(), true, 'incompatible mapping is visibly unavailable');
        assert.equal(writes.length, 0, 'loading comparison is read-only');
        await checkLayout(page, name + ' suppliers');

        await page.locator('input[value="map-b"]').click();
        assert.equal(await page.locator('input[value="map-b"]').isChecked(), true);
        await page.locator('.vendor-option.selected .vendor-history summary').click();
        assert.match(await page.locator('.vendor-option.selected .vendor-history').innerText(), /2026-10-15/);
        await (await actionable(page, '#vendor-prefer-review')).click();
        assert.match(await page.locator('#vendor-prefer-copy').innerText(), /Valley Wholesale.*preferred supplier/);
        assert.equal(writes.length, 0, 'reviewing a preference does not submit it');
        await checkLayout(page, name + ' preference review');
        await (await actionable(page, '#vendor-prefer-confirm')).click();
        await page.waitForFunction(() => document.getElementById('vendor-status').textContent.includes('Preferred supplier updated'));
        assert.equal(writes.length, 1);
        assert.equal(writes[0].action, 'po_vendor_prefer');
        assert.equal(await page.locator('#vendor-prefer-review').isDisabled(), true);
        assert.equal(await page.locator('#draft-review').isVisible(), false, 'preference does not create a draft review');

        await (await actionable(page, '#vendor-preview')).click();
        await page.waitForFunction(() => !document.getElementById('draft-create').disabled);
        assert.match(await page.locator('#draft-quote').innerText(), /Valley Wholesale/);
        assert.match(await page.locator('#draft-quote').innerText(), /\$24\.00/);
        assert.equal(writes.length, 1, 'previewing the draft is read-only');
        await checkLayout(page, name + ' draft review');
        const confirm = await actionable(page, '#draft-create');
        assert.equal(await confirm.innerText(), 'Confirm · Create draft only');
        await page.screenshot({ path: path.join(output, name + '-review.png'), fullPage: true });
        await confirm.click();
        await page.waitForFunction(() => document.getElementById('board-alert').textContent.includes('PO-QA-1'));
        await page.waitForFunction(() => document.getElementById('board-list').getAttribute('aria-busy') === 'false');
        assert.equal(await page.locator('#vendor-workspace').isVisible(), false);
        assert.equal(writes.length, 2, 'only the explicitly confirmed preference and draft may be written');
        assert.equal(writes[1].action, 'po_draft_create');
        assert.equal(writes[1].vendorItemId, 'map-b');
        assert.match(await page.locator('#board-alert').innerText(), /PO-QA-1.*draft/);
        await checkLayout(page, name + ' completed board');
        assert.deepEqual(errors, [], name + ' must have no browser console or runtime errors');
        assert.deepEqual(serverErrors, [], 'browser requests must satisfy the API contract');
        console.log(name + ': layout, one document scroll, supplier choice, explicit confirmations and XSS checks passed');
    } catch (error) {
        await page.screenshot({ path: path.join(output, name + '-failure.png'), fullPage: true }).catch(() => {});
        throw error;
    } finally {
        await context.close();
    }
}

(async () => {
    let browser;
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    try {
        browser = await chromium.launch({ headless: true });
        const origin = 'http://127.0.0.1:' + server.address().port;
        await scenario(browser, origin, 'desktop-1280', 1280, 900);
        await scenario(browser, origin, 'mobile-390', 390, 844);
        await scenario(browser, origin, 'mobile-360', 360, 780);
        console.log('need-more-purchasing browser contract passed; screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
