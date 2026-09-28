<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){http_response_code(403);exit('AR glasses permission required.');}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Web Glasses Simulator · Gelato</title>
<link rel="stylesheet" href="assets/css/glasses-web-simulator.css?v=20260928-s1">
</head>
<body>
<header class="sim-topbar">
  <div>
    <strong>Gelato Web Glasses Simulator</strong>
    <span>POS + KDS + AR build projection</span>
  </div>
  <div class="sim-top-actions">
    <label>Mode <select id="modeSelect"><option value="mock">Mock</option><option value="live">Live Gelato</option></select></label>
    <label>Device <select id="deviceSelect"><option value="">Loading…</option></select></label>
    <button id="refreshWork" type="button">Refresh work</button>
    <a href="kds.php">KDS</a>
    <a href="pos.php">POS</a>
  </div>
</header>

<main class="sim-layout">
  <section class="sim-stage-panel">
    <div class="sim-status-row">
      <span id="connectionBadge" class="sim-badge">MOCK</span>
      <span id="stationBadge" class="sim-muted">Simulator station</span>
      <span id="buildBadge" class="sim-muted">No active build</span>
    </div>

    <div class="glasses-stage" id="glassesStage">
      <div class="scene-placeholder">
        <img id="sceneImage" alt="" hidden>
        <div class="scene-copy" id="scenePlaceholder">Drop your final kitchen/glasses artwork in later — the HUD is already live.</div>
      </div>
      <div class="glasses-frame" aria-hidden="true">
        <div class="bridge"></div>
        <div class="lens left-lens"></div>
        <div class="lens right-lens"></div>
      </div>

      <section class="lens-projection" aria-label="Simulated on-lens projection">
        <div class="hud-status">
          <span id="hudConnection">SIM</span>
          <span id="hudStation">PIZZA LINE</span>
        </div>
        <aside class="hud-right">
          <div class="hud-card">
            <small>ITEM</small>
            <strong id="hudItem">Club Sandwich + Fries</strong>
            <span id="hudTicket">Mock ticket · Table 12</span>
          </div>
          <div class="hud-card hud-steps">
            <small>BUILD STEPS</small>
            <ol id="hudSteps"></ol>
          </div>
          <div class="hud-card next-card">
            <small>NEXT</small>
            <strong id="hudNext">Add Toasted Bread</strong>
          </div>
          <div class="hud-card validation-card" id="validationCard">
            <small>PRODUCT VALIDATION</small>
            <strong id="hudValidation">0 / 6 accounted</strong>
            <span id="hudValidationDetail">Build in progress</span>
          </div>
        </aside>
        <div id="detectionLayer" class="detection-layer"></div>
      </section>
    </div>
  </section>

  <aside class="sim-console">
    <section class="console-card">
      <div class="console-heading"><div><small>WORK</small><strong id="consoleItem">Club Sandwich + Fries</strong></div><span id="workStatus">queued</span></div>
      <div class="meta-grid" id="workMeta"></div>
      <button id="startBuild" type="button">Start focused build</button>
    </section>

    <section class="console-card">
      <div class="console-heading"><div><small>SIMULATED VISION</small><strong>Ingredient detections</strong></div><span id="componentCount">0</span></div>
      <div id="componentControls" class="component-controls"></div>
      <div class="inline-actions">
        <button id="injectUnexpected" type="button">Inject unexpected</button>
        <button id="evaluateBuild" type="button">Validate</button>
      </div>
    </section>

    <section class="console-card">
      <div class="console-heading"><div><small>RECOVERY / REVIEW</small><strong>Build exceptions</strong></div></div>
      <div id="exceptions" class="exceptions"><span class="sim-muted">No build exceptions.</span></div>
      <button id="handoffExpo" type="button" disabled>Send to Expo / Finishing</button>
    </section>

    <section class="console-card event-card">
      <div class="console-heading"><div><small>EVENT LOG</small><strong>Simulator activity</strong></div><button id="clearLog" type="button">Clear</button></div>
      <div id="eventLog" class="event-log"></div>
    </section>
  </aside>
</main>

<script>
window.GELATO_GLASSES_SIMULATOR={
  csrf:<?=json_encode(app_csrf_token(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
  api:'api/glasses-simulator.php'
};
</script>
<script src="assets/js/glasses-web-simulator.js?v=20260928-s1"></script>
</body>
</html>
