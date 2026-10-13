<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = app_require_auth();
if (!app_has_permission('glasses.view', $user)) {
    http_response_code(403);
    exit('AR glasses viewing permission required.');
}
$canPos = app_has_permission('pos.use', $user);
$canKds = app_has_permission('kds.view', $user);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>POS → KDS → Glasses Test · Gelato</title>
<link rel="stylesheet" href="assets/css/glasses-pos-kds-test.css?v=20261013-test1">
</head>
<body class="workflow-page" data-view="all">
<header class="workflow-header">
  <div class="workflow-title"><a class="workflow-back" href="glasses-vision-lab.php">← Vision Lab</a><div><span class="workflow-overline">AR Glasses · Integration Test</span><h1>POS <span aria-hidden="true">→</span> KDS <span aria-hidden="true">→</span> Glasses</h1><p>Test real restaurant orders across three existing Gelato applications.</p></div></div>
  <div class="workflow-top-actions"><a href="pos.php" target="_blank" rel="noopener">Open POS ↗</a><a href="kds.php" target="_blank" rel="noopener">Open KDS ↗</a><a href="glasses-simulator.php" target="_blank" rel="noopener" class="primary">Open Glasses Simulator ↗</a></div>
</header>
<main class="workflow-shell">
  <div class="workflow-banner" role="note"><strong>Two different simulator modes</strong><p><b>Mock</b> shows a sample Club Sandwich + Fries build. It never reads or changes POS/KDS. The glasses simulator opens in <b>Live Gelato</b> automatically, showing the selected location's KDS tickets through a virtual browser display—<b>no real glasses or pairing required</b>. Only orders sent to KDS will appear; choose a station or use All stations. Live actions can change build/KDS state when authorized; use a test order.</p></div>
  <ol class="workflow-steps">
    <li><b>1 · POS</b><span>Place a test ticket for your intended location.</span></li>
    <li><b>2 · KDS</b><span>Confirm the order arrived at the kitchen station.</span></li>
    <li><b>3 · GLASSES</b><span>Live Gelato opens automatically with All stations. Select the queued item, or narrow to the appropriate station.</span></li>
  </ol>
  <nav class="workflow-tabs" aria-label="Choose an application panel">
    <button type="button" data-view-target="all" aria-pressed="true">All panels</button>
    <button type="button" data-view-target="pos" aria-pressed="false">1 · POS</button>
    <button type="button" data-view-target="kds" aria-pressed="false">2 · KDS</button>
    <button type="button" data-view-target="glasses" aria-pressed="false">3 · Glasses</button>
  </nav>
  <div class="workflow-grid" id="workflowGrid">
    <section class="workflow-pane" data-pane="pos" aria-labelledby="posHeading">
      <div class="workflow-pane-head"><div><small>STEP 1 · ORDER CREATION</small><h2 id="posHeading">POS</h2></div><div class="workflow-pane-actions"><button type="button" data-focus="pos">Focus</button><a href="pos.php" target="_blank" rel="noopener">Full page ↗</a></div></div>
      <?php if ($canPos): ?>
        <iframe src="pos.php" title="Gelato live point-of-sale" loading="eager" referrerpolicy="same-origin"></iframe>
      <?php else: ?>
        <div class="workflow-no-access"><strong>POS permission required</strong><p>This panel needs <code>pos.use</code>. Ask an administrator for access; do not use a mock login.</p></div>
      <?php endif; ?>
    </section>
    <section class="workflow-pane" data-pane="kds" aria-labelledby="kdsHeading">
      <div class="workflow-pane-head"><div><small>STEP 2 · KITCHEN ROUTING</small><h2 id="kdsHeading">KDS</h2></div><div class="workflow-pane-actions"><button type="button" data-focus="kds">Focus</button><a href="kds.php" target="_blank" rel="noopener">Full page ↗</a></div></div>
      <?php if ($canKds): ?>
        <iframe src="kds.php" title="Gelato live kitchen display" loading="eager" referrerpolicy="same-origin"></iframe>
      <?php else: ?>
        <div class="workflow-no-access"><strong>KDS permission required</strong><p>This panel needs <code>kds.view</code>. The glasses simulator remains available for permitted stations.</p></div>
      <?php endif; ?>
    </section>
    <section class="workflow-pane" data-pane="glasses" aria-labelledby="glassesHeading">
      <div class="workflow-pane-head"><div><small>STEP 3 · AR ORDER PREVIEW</small><h2 id="glassesHeading">Glasses Simulator</h2></div><div class="workflow-pane-actions"><button type="button" data-focus="glasses">Focus</button><a href="glasses-simulator.php" target="_blank" rel="noopener">Full page ↗</a></div></div>
      <iframe src="glasses-simulator.php" title="Gelato AR glasses simulator, switch from Mock to Live Gelato for real orders" loading="eager" referrerpolicy="same-origin" allow="camera"></iframe>
    </section>
  </div>
  <p class="workflow-footnote">This workspace embeds the actual POS, KDS and Glasses pages in the same authenticated origin; it does not copy or fake live orders. If a panel is too narrow for its controls, select <b>Focus</b> or <b>Full page</b>. Newly routed KDS work, including additional sent items on existing tickets, is polled by the live glasses preview about every 1.5 seconds while visible. Real device pairing is needed only for write-enabled build actions.</p>
</main>
<script src="assets/js/glasses-pos-kds-test.js?v=20261013-test1"></script>
</body>
</html>
