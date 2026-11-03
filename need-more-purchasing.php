<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/daily-quicklist-purchase-drafts.php';
$user=app_require_auth();
if(!daily_quicklist_receipt_permission($user)){http_response_code(403);exit('Manager and Inventory View permissions required.');}
$clientConfig=[
    'canCompare'=>dqpo_can_compare($user),
    'canDraft'=>dqpo_can_draft($user),
    'csrf'=>app_csrf_token(),
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Need More Purchasing · Gelato</title>
<link rel="stylesheet" href="assets/css/daily-prep.css?v=3k2h">
<link rel="stylesheet" href="assets/css/need-more-purchasing.css?v=3k2ib">
</head>
<body>
<div class="workspace">
<header class="masthead">
    <a href="daily-prep.php" class="back-link">← Daily Prep</a>
    <div class="brand"><span class="brand-mark">g.</span><span>GELATO <small>/ PURCHASING</small></span></div>
    <a class="agent-link" href="agent-canvas.php">✦ Ask Gelato AI</a>
</header>
<main>
    <section class="board-head">
        <div>
            <div class="eyebrow dark">MANAGER WORKSPACE</div>
            <h1>Need More / Purchasing</h1>
            <p>Compare saved supplier prices, pack sizes and order minimums. Choose a supplier and review a purchase-order draft for what the kitchen needs.</p>
        </div>
        <label>Working date <input id="board-date" class="board-date" type="date"></label>
    </section>
    <nav class="board-actions" aria-label="Purchasing workspaces">
        <a href="purchasing.php">Purchasing &amp; Receiving</a>
        <a href="daily-prep.php" class="secondary">Manage Need More</a>
        <a href="operations.php" class="secondary">Operations Inventory</a>
    </nav>
    <div id="board-alert" role="status" aria-live="polite"></div>
    <div id="board-summary" class="board-summary"></div>
    <div id="board-list" class="board-list" aria-busy="true">Loading current records…</div>

    <section id="vendor-workspace" class="vendor-workspace" aria-labelledby="vendor-title" hidden>
        <div class="board-top">
            <div><div class="eyebrow dark">SUPPLIER COMPARISON</div><h2 id="vendor-title" tabindex="-1">Review suppliers</h2></div>
            <button id="vendor-close" class="board-button secondary" type="button">Close comparison</button>
        </div>
        <div id="vendor-status" role="status" aria-live="polite"></div>
        <div id="vendor-summary"></div>
        <div id="vendor-orders"></div>
        <div id="vendor-options" class="vendor-options"></div>
        <div class="board-actions">
            <button id="vendor-refresh" class="board-button secondary" type="button">Refresh supplier data</button>
            <button id="vendor-preview" class="board-button" type="button" hidden disabled>Preview draft with selected supplier</button>
            <button id="vendor-prefer-review" class="board-button secondary" type="button" hidden disabled>Review preferred supplier change</button>
        </div>
        <section id="vendor-prefer-panel" class="vendor-confirm" aria-labelledby="vendor-prefer-title" hidden>
            <h3 id="vendor-prefer-title">Change preferred supplier</h3>
            <p id="vendor-prefer-copy"></p>
            <div class="board-actions">
                <button id="vendor-prefer-confirm" class="board-button" type="button" disabled>Confirm preferred supplier</button>
                <button id="vendor-prefer-cancel" class="board-button secondary" type="button">Cancel change</button>
            </div>
        </section>
        <section id="draft-review" class="vendor-confirm" aria-labelledby="draft-title" hidden>
            <h3 id="draft-title" tabindex="-1">Review purchase-order draft</h3>
            <div id="draft-quote"></div>
            <p class="board-muted">A draft is saved for review in Purchasing. Submission and physical stock receipt each require their own action.</p>
            <div class="board-actions">
                <button id="draft-create" class="board-button" type="button" disabled>Confirm · Create draft only</button>
                <button id="draft-cancel" class="board-button secondary" type="button">Cancel draft preview</button>
            </div>
        </section>
        <div id="vendor-error" class="board-error" role="alert" aria-live="assertive"></div>
    </section>
    <p class="board-muted board-footer">Purchasing evidence uses the saved inventory item. Confirmed physical inventory receipts reconcile Need More through the receipt workflow.</p>
    <noscript><p class="board-error">Enable JavaScript to load the purchasing board and review supplier choices.</p></noscript>
</main>
</div>
<script type="application/json" id="need-more-purchasing-config"><?php echo json_encode($clientConfig,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?></script>
<script src="js/need-more-purchasing.js?v=3k2ib" defer></script>
</body>
</html>
