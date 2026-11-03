'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const page = fs.readFileSync(path.join(__dirname, '../need-more-purchasing.php'), 'utf8');
const script = fs.readFileSync(path.join(__dirname, '../js/need-more-purchasing.js'), 'utf8');

// Minimal DOM event surface. Tests exercise the shipped script and its HTTP requests;
// the harness deliberately allows aborted responses to arrive to expose race bugs.
function start(config = { canCompare: true, canDraft: true, csrf: 'ui-contract-csrf' }) {
    const elements = new Map(), requests = [];
    let focused = null;
    class Element {
        constructor(attributes = '') {
            this.listeners = {};
            this.hidden = /\bhidden\b/.test(attributes);
            this.disabled = /\bdisabled\b/.test(attributes);
            this.attributes = {};
            this.dataset = {};
            this.children = [];
            this.value = (attributes.match(/\bvalue="([^"]*)"/) || [])[1] || '';
            this.name = (attributes.match(/\bname="([^"]*)"/) || [])[1] || '';
            const compare = attributes.match(/\bdata-compare="([^"]*)"/);
            if (compare) this.dataset.compare = compare[1];
            this._html = '';
            this._text = '';
        }
        set textContent(value) { this._text = value; this._html = ''; this.children = []; }
        get textContent() { return this._text; }
        set innerHTML(value) {
            this._html = value;
            this._text = '';
            this.children = [...value.matchAll(/<(?:input|button)\b([^>]*)>/g)].map(match => new Element(match[1]));
        }
        get innerHTML() { return this._html; }
        replaceChildren() { this.textContent = ''; }
        addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
        dispatch(type, target = this) { for (const listener of this.listeners[type] || []) listener({ target }); }
        setAttribute(name, value) { this.attributes[name] = value; }
        querySelectorAll(selector) {
            if (selector === 'input[name="vendor-choice"]') return this.children.filter(child => child.name === 'vendor-choice');
            if (selector === 'button[data-compare]') return this.children.filter(child => child.dataset.compare);
            throw new Error('Unexpected selector: ' + selector);
        }
        closest(selector) { return selector === 'button[data-compare]' && this.dataset.compare ? this : null; }
        scrollIntoView() {}
        focus() { focused = this; }
    }
    for (const match of page.matchAll(/<[^>]+\bid="([^"]+)"[^>]*>/g)) elements.set(match[1], new Element(match[0]));
    elements.get('need-more-purchasing-config').textContent = JSON.stringify(config);
    const fetch = (url, options) => new Promise(resolve => {
        const query = new URL(url, 'https://gelato.example/').searchParams;
        const request = {
            url, options, query,
            action: options.method === 'POST' ? JSON.parse(options.body).action : query.get('action'),
            body: options.method === 'POST' ? JSON.parse(options.body) : null,
            respond(body, ok = true) { this.done = true; resolve({ ok, json: async () => body }); },
        };
        requests.push(request);
    });
    vm.runInNewContext(script, {
        document: { getElementById: id => elements.get(id) }, fetch, URLSearchParams, AbortController, Date, Intl,
    }, { filename: 'js/need-more-purchasing.js' });
    const element = id => elements.get(id);
    const click = id => element(id).dispatch('click');
    const pending = action => requests.filter(request => request.action === action && !request.done);
    const compare = id => element('board-list').dispatch('click', new Element('data-compare="' + id + '"'));
    const select = id => element('vendor-options').dispatch('change', new Element('name="vendor-choice" value="' + id + '"'));
    return { requests, element, click, pending, compare, select, focused: () => focused };
}

const flush = () => new Promise(resolve => setImmediate(resolve));
const board = () => ({ ok: true, board: {
    totals: { requests: 2, numericOutstanding: 2, withOpenOrders: 0, withRecentDeliveries: 0 },
    requests: ['need-1', 'need-2'].map(id => ({ id, title: 'Flour <img src=x>', status: 'need_more', quantityNote: '',
        progress: { receivedQuantity: 0, requestedQuantity: 5, remainingQuantity: 5, unit: 'kg' },
        openOrders: [], recentDeliveries: [],
    })),
} });
function comparison(id = 'need-1', additions = {}) {
    return { requestId: id, stockId: 'stock-1', name: id + ' flour', remainingQuantity: 5, unit: 'kg',
        canDraft: true, blockers: [], openOrders: [], comparisonHash: 'comparison-current',
        options: ['map-a', 'map-b'].map((vendorItemId, index) => ({ vendorItemId, vendorId: 'vendor-' + index,
            vendor: index ? 'Value Vendor' : 'Preferred <script>alert(1)</script>', preferred: !index,
            eligible: true, packSize: index ? 3 : 2, packUnit: 'kg', pricePerPack: index ? 12 : 10,
            unitPrice: index ? 4 : 5, packs: index ? 2 : 3, orderedQuantity: 6, overageQuantity: 1,
            estimatedSubtotal: index ? 24 : 30, minimumOrderAmount: 50, minimumShortfall: index ? 26 : 20,
            leadTimeDays: 2, priceEffectiveAt: '2026-11-02 12:00:00', blockers: [], warnings: [],
            priceHistory: [{ pricePerPack: 9, packSize: 2, unit: 'kg', unitPrice: 4.5,
                recordedAt: '2026-10-01 12:00:00', source: 'catalog' }],
        })), ...additions };
}
function quote(id = 'need-1', vendorItemId = 'map-b') {
    return { requestId: id, vendorItemId, name: 'Flour', vendor: vendorItemId, remainingQuantity: 5,
        unit: 'kg', packs: 2, packSize: 3, orderedQuantity: 6, overageQuantity: 1,
        pricePerPack: 12, estimatedSubtotal: 24, minimumOrderAmount: 50, minimumShortfall: 26,
        quoteHash: 'reviewed-' + vendorItemId, warnings: ['Below vendor minimum.'] };
}
async function ready(ui, id = 'need-1', data = comparison(id)) {
    ui.pending('purchasing_board')[0].respond(board());
    await flush();
    ui.compare(id);
    ui.pending('po_vendor_options')[0].respond({ ok: true, comparison: data });
    await flush();
}

(async () => {
    assert.equal((page.match(/<\/html>/g) || []).length, 1, 'page has one document ending, with no prematurely closed inline script');
    assert.match(page, /src="js\/need-more-purchasing\.js\?v=3k2ib"/, 'page loads the syntax-checked external script');

    const ui = start();
    await ready(ui);
    assert.match(ui.element('board-list').innerHTML, /Flour &lt;img src=x&gt;/, 'staff text is escaped in board rendering');
    assert.match(ui.element('vendor-options').innerHTML, /Preferred &lt;script&gt;alert\(1\)&lt;\/script&gt;/, 'supplier text is escaped');
    assert.match(ui.element('vendor-options').innerHTML, /\$24\.00/, 'whole-pack subtotal is visible for comparison');
    assert.match(ui.element('vendor-options').innerHTML, /\$26\.00 below/, 'minimum-order shortfall is visible');
    assert.match(ui.element('vendor-options').innerHTML, /Recorded price history/, 'recorded price evidence is available');
    assert.equal(ui.element('vendor-prefer-review').disabled, true, 'already-preferred mapping cannot be needlessly changed');
    assert.equal(ui.requests.filter(request => request.body).length, 0, 'reading suppliers never changes preferences or creates an order');

    ui.click('vendor-preview');
    const oldQuote = ui.pending('po_draft_preview')[0];
    assert.equal(oldQuote.query.get('vendorItemId'), 'map-a', 'preview includes the exact chosen mapping');
    ui.select('map-b');
    oldQuote.respond({ ok: true, quote: quote('need-1', 'map-a') });
    await flush();
    assert.equal(ui.element('draft-create').disabled, true, 'late quote for former supplier cannot be confirmed');
    assert.equal(ui.element('draft-review').hidden, true, 'changing supplier removes the old review');

    ui.click('vendor-preview');
    ui.pending('po_draft_preview')[0].respond({ ok: true, quote: quote() });
    await flush();
    assert.equal(ui.element('draft-create').disabled, false, 'matching reviewed quote enables explicit confirmation');
    ui.click('draft-create');
    ui.click('draft-create');
    assert.equal(ui.pending('po_draft_create').length, 1, 'double-clicking confirmation submits one request');
    const draftRequest = ui.pending('po_draft_create')[0];
    assert.deepEqual(draftRequest.body, { action: 'po_draft_create', id: 'need-1', vendorItemId: 'map-b', quoteHash: 'reviewed-map-b' });
    assert.equal(draftRequest.options.headers['X-CSRF-Token'], 'ui-contract-csrf', 'draft action retains CSRF protection');
    assert.equal(ui.element('board-date').disabled, true, 'context is frozen while a mutation is pending');
    draftRequest.respond({ ok: false, message: 'Vendor pricing changed.' }, false);
    await flush();
    assert.equal(ui.element('draft-create').disabled, true, 'failed confirmation invalidates the consumed quote');
    assert.match(ui.element('vendor-error').textContent, /fresh preview/, 'stale server result asks for a new review');
    ui.click('draft-create');
    assert.equal(ui.requests.filter(request => request.action === 'po_draft_create').length, 1, 'old quote cannot be resent after failure');

    ui.click('vendor-preview');
    ui.pending('po_draft_preview')[0].respond({ ok: true, quote: quote() });
    await flush();
    ui.click('draft-create');
    ui.pending('po_draft_create')[0].respond({ ok: true, order: { orderNumber: 'PO-REVIEWED' } });
    await flush();
    ui.pending('purchasing_board')[0].respond(board());
    await flush();
    assert.equal(ui.element('vendor-workspace').hidden, true, 'successful draft closes its consumed review');
    assert.match(ui.element('board-alert').textContent, /PO-REVIEWED.*draft/, 'success states the actual draft result');

    const preference = start();
    await ready(preference);
    preference.select('map-b');
    preference.click('vendor-prefer-review');
    assert.match(preference.element('vendor-prefer-copy').textContent, /Value Vendor.*preferred supplier/, 'preference review names the exact supplier');
    assert.equal(preference.requests.filter(request => request.body).length, 0, 'reviewing preference is not a mutation');
    preference.click('vendor-prefer-confirm');
    preference.click('vendor-prefer-confirm');
    assert.equal(preference.pending('po_vendor_prefer').length, 1, 'preference confirmation cannot be double-posted');
    assert.equal(preference.pending('po_vendor_prefer')[0].body.comparisonHash, 'comparison-current', 'preference uses the reviewed comparison fingerprint');
    const changed = comparison();
    changed.options.forEach(option => { option.preferred = option.vendorItemId === 'map-b'; });
    preference.pending('po_vendor_prefer')[0].respond({ ok: true, comparison: changed });
    await flush();
    assert.equal(preference.element('vendor-prefer-review').disabled, true, 'saved preference refreshes selected state');
    assert.equal(preference.requests.filter(request => request.action === 'po_draft_create').length, 0, 'preference never implicitly creates an order');

    const race = start();
    race.pending('purchasing_board')[0].respond(board());
    await flush();
    race.compare('need-1');
    const earlier = race.pending('po_vendor_options')[0];
    race.compare('need-2');
    race.pending('po_vendor_options')[1].respond({ ok: true, comparison: comparison('need-2') });
    await flush();
    earlier.respond({ ok: true, comparison: comparison('need-1') });
    await flush();
    assert.equal(race.element('vendor-title').textContent, 'need-2 flour · suppliers', 'older comparison response cannot replace the current request');
    race.click('vendor-preview');
    const closingQuote = race.pending('po_draft_preview')[0];
    race.click('vendor-close');
    closingQuote.respond({ ok: true, quote: quote('need-2', 'map-a') });
    await flush();
    assert.equal(race.element('vendor-workspace').hidden, true, 'closed comparison cannot reopen from a late response');
    assert.equal(race.element('draft-create').disabled, true, 'late response after close cannot restore confirmation');

    const blocked = start();
    await ready(blocked, 'need-1', comparison('need-1', { canDraft: false, blockers: ['An open order already covers this stock item.'],
        openOrders: [{ orderNumber: 'PO-EXISTING', status: 'draft', vendor: 'Saved supplier', outstandingQuantity: 6, unit: 'kg', expectedDate: null }] }));
    assert.equal(blocked.element('vendor-preview').disabled, true, 'overlap blocks new draft preview');
    assert.match(blocked.element('vendor-orders').innerHTML, /PO-EXISTING/, 'overlap retains the exact existing-order evidence');
    blocked.select('map-b');
    assert.equal(blocked.element('vendor-prefer-review').disabled, false, 'future preference can be reviewed without creating another order');

    const readOnly = start({ canCompare: true, canDraft: false, csrf: 'readonly' });
    await ready(readOnly);
    assert.equal(readOnly.element('vendor-preview').hidden, true, 'read-only comparison does not display draft controls');
    assert.equal(readOnly.element('vendor-prefer-review').hidden, true, 'read-only comparison does not display preference controls');
    assert.equal(readOnly.element('vendor-options').querySelectorAll('input[name="vendor-choice"]').length, 0, 'read-only options contain no mutation selection controls');
    readOnly.click('vendor-preview');
    readOnly.click('vendor-prefer-review');
    readOnly.click('vendor-prefer-confirm');
    readOnly.click('draft-create');
    assert.equal(readOnly.requests.filter(request => request.body || request.action === 'po_draft_preview').length, 0, 'synthetic clicks cannot trigger UI writes without permission');

    console.log('need-more-purchasing UI contract passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
