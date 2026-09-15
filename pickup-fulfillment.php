<?php
declare(strict_types=1);
require_once __DIR__.'/includes/pickup-fulfillment-core.php';

$user=app_require_auth();
if(!pickup_fulfillment_can_view($user)){
    http_response_code(403);
    exit('Pickup operations permission required.');
}
$pdo=app_pdo();
if(!pickup_fulfillment_ready($pdo)){
    http_response_code(503);
    exit('Pickup fulfillment is not installed. Run upgrade.php first.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f3f2ed">
<title>Pickup Fulfillment | Restaurant Admin</title>
<link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1">
<link rel="stylesheet" href="assets/css/pickup-fulfillment.css?v=20260915-1">
</head>
<body class="admin-control pickup-ops">
<header class="admin-top"><a class="admin-brand" href="admin.php"><span class="admin-logo">SF</span><span><strong>Pickup Fulfillment</strong><small><?=app_escape((string)$user['organization_name'])?> handoff operations</small></span></a><nav class="admin-top-actions"><a class="admin-button" href="online-orders-admin.php">Online Orders</a><?php if(app_has_permission('pos.use',$user)):?><a class="admin-button dark" href="pos.php">POS</a><?php endif;?></nav></header>
<main class="admin-shell">
<section class="pickup-hero">
    <div><div class="admin-eyebrow">Pickup Operations</div><h1>Ready counter</h1><p>Track promised pickup times, kitchen readiness, payment and physical handoff from one live queue. Use <strong>Handed to Customer</strong> only when the complete ticket is ready and payment is complete.</p></div>
    <div class="pickup-live"><span class="pickup-live-dot"></span><strong>Live queue</strong><small id="lastUpdated">Loading…</small></div>
</section>
<section class="pickup-summary" id="pickupSummary" aria-live="polite">
    <article><span>Active pickups</span><strong id="sumActive">—</strong></article>
    <article class="ready"><span>Ready now</span><strong id="sumReady">—</strong></article>
    <article class="attention"><span>Payment due</span><strong id="sumPayment">—</strong></article>
    <article class="late"><span>Past promise</span><strong id="sumLate">—</strong></article>
</section>
<section class="pickup-toolbar">
    <label><span>Location</span><select id="locationFilter"><option value="0">All assigned locations</option></select></label>
    <label><span>Queue</span><select id="stateFilter"><option value="active">Active</option><option value="ready">Ready now</option><option value="fulfilled">Fulfilled</option><option value="cancelled">Cancelled</option><option value="all">All</option></select></label>
    <label class="pickup-search"><span>Search</span><input id="searchOrders" type="search" placeholder="Order, customer, phone or email"></label>
    <button class="admin-button" id="refreshQueue" type="button">Refresh</button>
</section>
<div class="pickup-message" id="pickupMessage" hidden></div>
<section class="pickup-board" id="pickupBoard" aria-live="polite" aria-busy="true"></section>
<section class="pickup-empty" id="pickupEmpty" hidden><strong>No pickup orders match this view.</strong><span>Orders will appear here as online pickup tickets enter the restaurant workflow.</span></section>
<section class="admin-section pickup-links"><div class="admin-section-head"><div><div class="admin-eyebrow">Related Workspaces</div><h2>Restaurant workflow</h2></div></div><div class="admin-modules"><a class="admin-module" href="online-orders-admin.php"><span class="admin-module-icon">↗</span><span><strong>Online Orders</strong><p>Management history and customer order records.</p></span></a><?php if(app_has_permission('pos.use',$user)):?><a class="admin-module" href="pos.php"><span class="admin-module-icon">▦</span><span><strong>POS</strong><p>Collect payment before pay-at-pickup handoff.</p></span></a><?php endif;?><?php if(app_has_permission('kds.view',$user)):?><a class="admin-module" href="kds.php"><span class="admin-module-icon">⌁</span><span><strong>Kitchen</strong><p>Preparation, Expo and Ready state.</p></span></a><?php endif;?></div></section>
</main>
<script>window.PICKUP_FULFILLMENT={canFulfill:<?=pickup_fulfillment_can_fulfill($user)?'true':'false'?>};</script>
<script src="js/pickup-fulfillment.js?v=20260915-1"></script>
</body>
</html>
