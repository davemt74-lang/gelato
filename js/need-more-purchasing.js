(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    const configNode = byId('need-more-purchasing-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent);
    const date = byId('board-date'), list = byId('board-list'), summary = byId('board-summary');
    const notice = byId('board-alert'), panel = byId('vendor-workspace');
    const title = byId('vendor-title'), status = byId('vendor-status'), error = byId('vendor-error');
    const options = byId('vendor-options'), preview = byId('vendor-preview');
    const preferReview = byId('vendor-prefer-review'), preferPanel = byId('vendor-prefer-panel');
    const preferConfirm = byId('vendor-prefer-confirm'), draftPanel = byId('draft-review');
    const create = byId('draft-create'), quoteBody = byId('draft-quote');
    const endpoint = 'api/daily-prep.php';
    let boardGeneration = 0, comparisonGeneration = 0, quoteGeneration = 0;
    let boardController, comparisonController, quoteController;
    let requestId = '', comparison = null, selectedId = '', currentQuote = null;
    let preferencePending = null, busy = false;

    const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g,
        character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
    const quantity = value => value == null || !Number.isFinite(Number(value)) ? 'Not set'
        : Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 });
    const money = (value, precision = 2) => value == null || !Number.isFinite(Number(value)) ? 'Not priced'
        : '$' + Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: precision });
    const messages = values => Array.isArray(values) ? values.filter(value => typeof value === 'string' && value) : [];
    const warningHtml = values => messages(values).length ? '<div class="vendor-warning"><ul>'
        + messages(values).map(value => '<li>' + escape(value) + '</li>').join('') + '</ul></div>' : '';
    const metric = (label, value) => '<div><dt>' + escape(label) + '</dt><dd>' + escape(value) + '</dd></div>';
    const selectedOption = () => comparison && comparison.options.find(option => option.vendorItemId === selectedId);

    async function get(action, fields, controller) {
        const query = new URLSearchParams({ action, ...fields });
        const response = await fetch(endpoint + '?' + query, {
            credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        });
        const body = await response.json();
        if (!response.ok || !body.ok) throw new Error(body.message || 'Unable to load current purchasing records.');
        return body;
    }

    async function post(action, fields) {
        const response = await fetch(endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
            body: JSON.stringify({ action, ...fields }),
        });
        const body = await response.json();
        if (!response.ok || !body.ok) throw new Error(body.message || 'The action could not be confirmed. Refresh current records before retrying.');
        return body;
    }

    function invalidateQuote() {
        ++quoteGeneration;
        if (quoteController) quoteController.abort();
        currentQuote = null;
        create.disabled = true;
        draftPanel.hidden = true;
        quoteBody.replaceChildren();
    }

    function cancelPreference() {
        preferencePending = null;
        preferPanel.hidden = true;
        preferConfirm.disabled = true;
    }

    function updateControls() {
        const selected = selectedOption();
        preview.hidden = !config.canDraft;
        preferReview.hidden = !config.canDraft;
        preview.disabled = busy || !selected || !selected.eligible || !comparison.canDraft;
        preferReview.disabled = busy || !selected || !selected.eligible || !!selected.preferred;
        preferConfirm.disabled = busy || !preferencePending;
        create.disabled = busy || !currentQuote;
        date.disabled = busy;
        for (const id of ['vendor-close', 'vendor-refresh', 'draft-cancel', 'vendor-prefer-cancel']) byId(id).disabled = busy;
        options.querySelectorAll('input[name="vendor-choice"]').forEach(input => {
            const option = comparison && comparison.options.find(row => row.vendorItemId === input.value);
            input.disabled = busy || !option || !option.eligible;
        });
        list.querySelectorAll('button[data-compare]').forEach(button => { button.disabled = busy; });
    }

    function resetComparison() {
        ++comparisonGeneration;
        if (comparisonController) comparisonController.abort();
        invalidateQuote();
        cancelPreference();
        comparison = null;
        selectedId = '';
        error.textContent = '';
        status.textContent = '';
        options.replaceChildren();
        byId('vendor-summary').replaceChildren();
        byId('vendor-orders').replaceChildren();
        updateControls();
    }

    function closeComparison() {
        if (busy) return;
        resetComparison();
        requestId = '';
        panel.hidden = true;
    }

    async function loadBoard(successMessage = '') {
        const generation = ++boardGeneration;
        if (boardController) boardController.abort();
        boardController = new AbortController();
        list.textContent = 'Loading current records…';
        list.setAttribute('aria-busy', 'true');
        summary.replaceChildren();
        notice.textContent = successMessage;
        try {
            const body = await get('purchasing_board', { date: date.value }, boardController);
            if (generation !== boardGeneration) return;
            const board = body.board, totals = board.totals;
            summary.innerHTML = [['Requests', totals.requests], ['Numeric outstanding', totals.numericOutstanding],
                ['With open orders', totals.withOpenOrders], ['With recent deliveries', totals.withRecentDeliveries]]
                .map(([label, value]) => '<div class="board-metric"><strong>' + escape(value)
                    + '</strong><span>' + escape(label) + '</span></div>').join('');
            list.innerHTML = board.requests.map(item => {
                const progress = item.progress;
                const balance = progress ? '<strong>' + escape(quantity(progress.receivedQuantity)) + ' / '
                    + escape(quantity(progress.requestedQuantity)) + ' ' + escape(progress.unit) + '</strong><p>'
                    + escape(quantity(progress.remainingQuantity)) + ' ' + escape(progress.unit) + ' remaining</p>'
                    : '<p>No numeric target or linked stock item.</p>';
                const orders = item.openOrders.length ? '<ul>' + item.openOrders.map(order => '<li><b>'
                    + escape(order.orderNumber) + '</b> · ' + escape(order.vendor) + ' · ' + escape(order.status)
                    + '<div class="board-muted">' + escape(quantity(order.receivedQuantity)) + ' / '
                    + escape(quantity(order.orderedQuantity)) + ' ' + escape(order.unit) + ' on PO · Expected '
                    + escape(order.expectedDate || 'not set') + '</div></li>').join('') + '</ul>'
                    : '<p class="board-muted">No open order for this saved stock item.</p>';
                const receipts = item.recentDeliveries.length ? '<ul>' + item.recentDeliveries.map(receipt => '<li><b>'
                    + escape(receipt.receiptNumber) + '</b> · ' + escape(receipt.vendor) + '<div class="board-muted">'
                    + escape(quantity(receipt.quantity)) + ' ' + escape(receipt.unit) + ' · ' + escape(receipt.receivedAt)
                    + ' · ' + escape(receipt.orderNumber) + '</div></li>').join('') + '</ul>'
                    : '<p class="board-muted">No recent purchasing delivery in the displayed history.</p>';
                return '<article class="board-card"><div class="board-top"><div><h2>' + escape(item.title)
                    + '</h2><span class="board-muted">' + escape(item.quantityNote || 'No staff note')
                    + '</span></div><span class="board-chip">' + escape(String(item.status).replace(/_/g, ' '))
                    + '</span></div><div class="board-evidence"><section><h3>Need More balance</h3>' + balance
                    + '</section><section><h3>Open purchase orders</h3>' + orders
                    + '</section><section><h3>Recent vendor deliveries</h3>' + receipts + '</section></div>'
                    + (config.canCompare ? '<div class="board-actions"><button class="board-button" type="button" data-compare="'
                        + escape(item.id) + '">' + (config.canDraft ? 'Compare suppliers &amp; review draft' : 'Compare suppliers')
                        + '</button></div>' : '') + '</article>';
            }).join('') || '<div class="board-card">No outstanding Need More requests for this date.</div>';
            updateControls();
        } catch (failure) {
            if (generation !== boardGeneration || failure.name === 'AbortError') return;
            list.textContent = 'Unable to load the board. Change the working date to retry.';
            notice.textContent = [successMessage, failure.message].filter(Boolean).join(' ');
        } finally {
            if (generation === boardGeneration) list.setAttribute('aria-busy', 'false');
        }
    }

    function historyHtml(option) {
        const history = Array.isArray(option.priceHistory) ? option.priceHistory : [];
        if (!history.length) return '<p class="board-muted">No recorded price history for this supplier item.</p>';
        return '<details class="vendor-history"><summary>Recorded price history (' + history.length + ')</summary><ol>'
            + history.map(row => '<li>' + escape(money(row.pricePerPack)) + ' per ' + escape(quantity(row.packSize))
                + ' ' + escape(row.unit) + ' pack<small>' + (row.unitPrice == null ? 'Unit price not comparable'
                    : escape(money(row.unitPrice, 4)) + ' / ' + escape(row.unit)) + ' · ' + escape(row.recordedAt)
                + ' · ' + escape(row.source || 'saved record') + '</small></li>').join('') + '</ol></details>';
    }

    function renderComparison() {
        title.textContent = comparison.name + ' · suppliers';
        byId('vendor-summary').innerHTML = '<p><strong>' + escape(quantity(comparison.remainingQuantity)) + ' '
            + escape(comparison.unit) + ' outstanding</strong></p><p class="board-muted">Compare the price per stock unit and the total for whole packs. Saved prices exclude unsaved tax, shipping and other vendor charges.</p>'
            + warningHtml(comparison.blockers);
        const open = comparison.openOrders || [];
        byId('vendor-orders').innerHTML = open.length ? '<section class="vendor-open-orders"><h3>Review existing orders before creating another draft</h3><ul>'
            + open.map(order => '<li><strong>' + escape(order.orderNumber) + '</strong> · ' + escape(order.vendor)
                + ' · ' + escape(String(order.status).replace(/_/g, ' ')) + ' · '
                + escape(quantity(order.outstandingQuantity)) + ' ' + escape(order.unit) + ' outstanding · Expected '
                + escape(order.expectedDate || 'not set') + '</li>').join('') + '</ul></section>' : '';
        options.innerHTML = comparison.options.map(option => {
            const selected = option.vendorItemId === selectedId;
            const heading = '<strong>' + escape(option.vendor) + '</strong>'
                + (option.preferred ? '<span class="board-chip">Preferred supplier</span>' : '');
            return '<article class="vendor-option' + (selected ? ' selected' : '') + '">'
                + (config.canDraft ? '<label class="vendor-choice"><input type="radio" name="vendor-choice" value="'
                    + escape(option.vendorItemId) + '"' + (selected ? ' checked' : '') + (!option.eligible || busy ? ' disabled' : '')
                    + '><span>' + heading + '</span></label>' : '<div class="vendor-choice"><span>' + heading + '</span></div>')
                + '<dl class="vendor-metrics">' + metric('Pack size', quantity(option.packSize) + ' ' + (option.packUnit || comparison.unit))
                + metric('Price per pack', money(option.pricePerPack))
                + metric('Price per stock unit', option.unitPrice == null ? 'Not comparable' : money(option.unitPrice, 4) + ' / ' + comparison.unit)
                + metric('Packs required', quantity(option.packs))
                + metric('Order quantity', quantity(option.orderedQuantity) + ' ' + comparison.unit)
                + metric('Pack rounding extra', quantity(option.overageQuantity) + ' ' + comparison.unit)
                + metric('Vendor minimum', option.minimumOrderAmount == null ? 'Not saved' : money(option.minimumOrderAmount))
                + metric('Saved lead time', option.leadTimeDays == null ? 'Not saved' : quantity(option.leadTimeDays) + ' days') + '</dl>'
                + '<div class="vendor-total"><span>Estimated subtotal for these packs</span><strong>' + escape(money(option.estimatedSubtotal)) + '</strong></div>'
                + (Number(option.minimumShortfall) > 0 ? '<p class="vendor-warning">' + escape(money(option.minimumShortfall))
                    + ' below this vendor’s order minimum. Review the order in Purchasing before submission.</p>' : '')
                + warningHtml([...(option.blockers || []), ...(option.warnings || [])])
                + '<p class="board-muted">Price effective: ' + escape(option.priceEffectiveAt || 'not recorded') + '</p>'
                + historyHtml(option) + '</article>';
        }).join('') || '<p class="board-muted">No active supplier mapping is available. Add one in Purchasing to compare suppliers.</p>';
        if (!config.canDraft) status.textContent = 'Supplier comparison is available with your current access. Purchasing management permission is required to change preferences or create drafts.';
        updateControls();
    }

    async function showComparison(id, keepSelected = '') {
        if (busy || !config.canCompare) return;
        resetComparison();
        requestId = id;
        panel.hidden = false;
        title.textContent = 'Review suppliers';
        status.textContent = 'Loading current Need More quantities, suppliers and order history…';
        panel.scrollIntoView({ block: 'start', behavior: 'auto' });
        const generation = comparisonGeneration;
        comparisonController = new AbortController();
        try {
            const body = await get('po_vendor_options', { id }, comparisonController);
            if (generation !== comparisonGeneration || requestId !== id) return;
            if (!body.comparison || body.comparison.requestId !== id || !Array.isArray(body.comparison.options))
                throw new Error('The supplier comparison did not match this request. Refresh supplier data.');
            comparison = body.comparison;
            const candidate = comparison.options.find(option => option.vendorItemId === keepSelected && option.eligible)
                || comparison.options.find(option => option.preferred && option.eligible)
                || comparison.options.find(option => option.eligible);
            selectedId = candidate ? candidate.vendorItemId : '';
            status.textContent = '';
            renderComparison();
            title.focus({ preventScroll: true });
        } catch (failure) {
            if (generation !== comparisonGeneration || failure.name === 'AbortError') return;
            status.textContent = '';
            error.textContent = failure.message;
            updateControls();
        }
    }

    async function previewDraft() {
        if (busy || !config.canDraft || preview.disabled) return;
        invalidateQuote();
        cancelPreference();
        error.textContent = '';
        draftPanel.hidden = false;
        quoteBody.textContent = 'Checking current quantities, supplier terms and overlapping orders…';
        const generation = quoteGeneration, id = requestId, vendorItemId = selectedId;
        quoteController = new AbortController();
        preview.disabled = true;
        try {
            const body = await get('po_draft_preview', { id, vendorItemId }, quoteController);
            if (generation !== quoteGeneration || id !== requestId || vendorItemId !== selectedId) return;
            const quote = body.quote;
            if (!quote || quote.requestId !== id || quote.vendorItemId !== vendorItemId || !quote.quoteHash)
                throw new Error('The draft preview no longer matches your selection. Refresh supplier data.');
            currentQuote = quote;
            quoteBody.innerHTML = '<p><strong>' + escape(quote.name) + ' · ' + escape(quote.vendor) + '</strong></p>'
                + '<dl class="draft-metrics">' + metric('Outstanding request', quantity(quote.remainingQuantity) + ' ' + quote.unit)
                + metric('Proposed vendor packs', quantity(quote.packs) + ' × ' + quantity(quote.packSize) + ' ' + quote.unit)
                + metric('Order quantity', quantity(quote.orderedQuantity) + ' ' + quote.unit)
                + metric('Pack rounding extra', quantity(quote.overageQuantity) + ' ' + quote.unit)
                + metric('Price per pack', money(quote.pricePerPack)) + metric('Estimated subtotal', money(quote.estimatedSubtotal))
                + metric('Vendor minimum', quote.minimumOrderAmount == null ? 'Not saved' : money(quote.minimumOrderAmount))
                + metric('Below minimum by', quote.minimumShortfall == null ? 'Not known' : money(quote.minimumShortfall)) + '</dl>'
                + warningHtml(quote.warnings || (quote.warning ? [quote.warning] : []));
            draftPanel.scrollIntoView({ block: 'start', behavior: 'auto' });
            byId('draft-title').focus({ preventScroll: true });
        } catch (failure) {
            if (generation !== quoteGeneration || failure.name === 'AbortError') return;
            currentQuote = null;
            quoteBody.textContent = 'A new preview is required before you can confirm a draft.';
            error.textContent = failure.message;
        } finally {
            if (generation === quoteGeneration) updateControls();
        }
    }

    async function createDraft() {
        if (busy || !config.canDraft || !currentQuote || create.disabled) return;
        const quote = currentQuote;
        busy = true;
        currentQuote = null;
        cancelPreference();
        error.textContent = '';
        status.textContent = 'Saving the reviewed purchase-order draft…';
        updateControls();
        try {
            const body = await post('po_draft_create', { id: quote.requestId, vendorItemId: quote.vendorItemId, quoteHash: quote.quoteHash });
            busy = false;
            closeComparison();
            await loadBoard('Created ' + body.order.orderNumber + ' as a draft. Open Purchasing to review before submission.');
        } catch (failure) {
            busy = false;
            invalidateQuote();
            status.textContent = '';
            error.textContent = failure.message + ' Review a fresh preview before trying again.';
        } finally {
            updateControls();
        }
    }

    function reviewPreference() {
        if (busy || !config.canDraft || preferReview.disabled) return;
        const selected = selectedOption();
        invalidateQuote();
        error.textContent = '';
        preferencePending = { id: requestId, vendorItemId: selectedId, comparisonHash: comparison.comparisonHash };
        byId('vendor-prefer-copy').textContent = 'Set ' + selected.vendor + ' (' + quantity(selected.packSize) + ' '
            + (selected.packUnit || comparison.unit) + ' per pack) as the preferred supplier for ' + comparison.name
            + '? Future suggestions will use this preference. Existing purchase orders stay as recorded.';
        preferPanel.hidden = false;
        preferPanel.scrollIntoView({ block: 'start', behavior: 'auto' });
        updateControls();
        preferConfirm.focus({ preventScroll: true });
    }

    async function confirmPreference() {
        if (busy || !config.canDraft || !preferencePending || preferConfirm.disabled) return;
        const change = preferencePending;
        busy = true;
        cancelPreference();
        error.textContent = '';
        status.textContent = 'Saving the reviewed supplier preference…';
        updateControls();
        try {
            const body = await post('po_vendor_prefer', change);
            if (!body.comparison || body.comparison.requestId !== requestId || !Array.isArray(body.comparison.options))
                throw new Error('Refresh supplier data to check the current preferred supplier.');
            comparison = body.comparison;
            selectedId = change.vendorItemId;
            status.textContent = 'Preferred supplier updated. Review a separate draft preview when you are ready to order.';
            renderComparison();
        } catch (failure) {
            comparison = null;
            selectedId = '';
            status.textContent = '';
            error.textContent = failure.message + ' Refresh supplier data before making another change.';
        } finally {
            busy = false;
            updateControls();
        }
    }

    list.addEventListener('click', event => {
        const button = event.target.closest('button[data-compare]');
        if (button && !button.disabled) showComparison(button.dataset.compare);
    });
    options.addEventListener('change', event => {
        if (busy || !config.canDraft || !comparison || event.target.name !== 'vendor-choice') return;
        const option = comparison.options.find(row => row.vendorItemId === event.target.value && row.eligible);
        if (!option) return;
        selectedId = option.vendorItemId;
        invalidateQuote();
        cancelPreference();
        error.textContent = '';
        renderComparison();
        const chosen = Array.from(options.querySelectorAll('input[name="vendor-choice"]'))
            .find(input => input.value === selectedId);
        if (chosen) chosen.focus({ preventScroll: true });
    });
    date.addEventListener('change', () => { if (!busy) { closeComparison(); loadBoard(); } });
    byId('vendor-close').addEventListener('click', closeComparison);
    byId('vendor-refresh').addEventListener('click', () => { if (requestId) showComparison(requestId, selectedId); });
    byId('draft-cancel').addEventListener('click', () => { if (!busy) { invalidateQuote(); updateControls(); } });
    byId('vendor-prefer-cancel').addEventListener('click', () => { if (!busy) { cancelPreference(); updateControls(); } });
    preview.addEventListener('click', previewDraft);
    create.addEventListener('click', createDraft);
    preferReview.addEventListener('click', reviewPreference);
    preferConfirm.addEventListener('click', confirmPreference);
    const local = new Date();
    date.value = local.getFullYear() + '-' + String(local.getMonth() + 1).padStart(2, '0') + '-' + String(local.getDate()).padStart(2, '0');
    updateControls();
    loadBoard();
}());
