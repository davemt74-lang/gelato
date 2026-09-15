<?php
declare(strict_types=1);
require_once __DIR__.'/includes/order-recovery-core.php';
$user=app_require_auth();
if(!order_recovery_can_view($user)){http_response_code(403);exit('Order recovery permission required.');}
$pdo=app_pdo();
if(!order_recovery_ready($pdo)){http_response_code(503);exit('Order recovery is not installed. Run upgrade.php first.');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#f3f2ed"><title>Order Recovery | Stonefellows</title><link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1"><link rel="stylesheet" href="assets/css/order-recovery.css?v=20260915-1"></head>
<body class="admin-control recovery-ops">
<header class="admin-top"><a class="admin-brand" href="workspace.php"><span class="admin-logo">SF</span><span><strong>Order Recovery</strong><small><?=app_escape((string)$user['organization_name'])?> exception operations</small></span></a><nav class="admin-top-actions"><a class="admin-button" href="pickup-fulfillment.php">Pickup Counter</a><a class="admin-button" href="online-orders-admin.php">Online Orders</a><?php if(app_has_permission('pos.use',$user)):?><a class="admin-button dark" href="pos.php">POS</a><?php endif;?></nav></header>
<main class="admin-shell recovery-shell">
<section class="recovery-hero"><div><div class="admin-eyebrow">Exception Operations</div><h1>Recover the order, not just the ticket.</h1><p>Delays, remakes, substitutions, missing items, no-shows, escalation and recorded refunds stay tied to the same online order, POS check and kitchen history.</p></div><div class="recovery-live"><span></span><strong>Recovery queue</strong><small id="recoveryUpdated">Loading…</small></div></section>
<section class="recovery-summary"><article><span>Active issues</span><strong id="sumActive">—</strong></article><article class="escalated"><span>Escalated</span><strong id="sumEscalated">—</strong></article><article><span>Refunds recorded</span><strong id="sumRefunds">—</strong></article><article><span>Orders shown</span><strong id="sumOrders">—</strong></article></section>
<section class="recovery-toolbar"><label><span>Location</span><select id="recoveryLocation"><option value="0">All assigned locations</option></select></label><label><span>Queue</span><select id="recoveryFilter"><option value="active">Active issues</option><option value="escalated">Escalated</option><option value="ready">Ready orders</option><option value="completed">Paid / completed</option><option value="all">All online orders</option></select></label><label class="recovery-search"><span>Search</span><input id="recoverySearch" type="search" placeholder="Order, customer or issue"></label><button class="admin-button" id="recoveryRefresh" type="button">Refresh</button></section>
<div class="recovery-message" id="recoveryMessage" hidden></div>
<section class="recovery-layout"><aside class="recovery-list" id="recoveryList" aria-busy="true"></aside><section class="recovery-detail" id="recoveryDetail"><div class="recovery-placeholder"><strong>Select an order</strong><span>Choose an order from the queue to view its exception history and recovery actions.</span></div></section></section>
</main>
<script>window.ORDER_RECOVERY={manage:<?=order_recovery_can_manage($user)?'true':'false'?>,refund:<?=order_recovery_can_refund($user)?'true':'false'?>};</script><script src="js/order-recovery.js?v=20260915-1"></script></body></html>
