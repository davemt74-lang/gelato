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
    <label class="asset-load">Scene <input id="sceneFile" type="file" accept="image/*"></label>
    <label class="asset-load">Glasses <input id="glassesFile" type="file" accept="image/*"></label>
    <button id="calibrationToggle" type="button" aria-pressed="false">Calibrate</button>
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
      <img id="glassesImage" class="glasses-image" alt="" hidden>
      <div class="glasses-frame" id="cssGlassesFrame" aria-hidden="true">
        <div class="bridge"></div>
        <div class="lens left-lens"></div>
        <div class="lens right-lens"></div>
      </div>

      <div id="leftEyeGuide" class="eye-guide left-eye-guide"><span>LEFT EYE</span></div>
      <div id="rightEyeGuide" class="eye-guide right-eye-guide"><span>RIGHT EYE</span></div>
      <div id="safeAreaGuide" class="safe-area-guide"><span>PROJECTION SAFE AREA</span></div>

      <section class="lens-projection" id="lensProjection" aria-label="Simulated on-lens projection">
        <div class="hud-status editable-region" id="hudStatus" data-region="hudStatus"><button class="region-handle region-move" type="button" tabindex="-1" aria-hidden="true">MOVE</button><button class="region-handle region-resize" type="button" tabindex="-1" aria-hidden="true">↘</button>
          <span id="hudConnection">SIM</span>
          <span id="hudStation">PIZZA LINE</span>
        </div>
        <aside class="hud-right editable-region" id="hudRight" data-region="hudRight">
          <button class="region-handle region-move" type="button" tabindex="-1" aria-hidden="true">MOVE</button>
          <button class="region-handle region-resize" type="button" tabindex="-1" aria-hidden="true">↘</button>
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
    <section class="console-card calibration-card" id="calibrationPanel">
      <div class="console-heading"><div><small>PROJECTION CALIBRATION</small><strong>Lens layout & display tuning</strong></div><span id="calibrationState">LOCKED</span></div>
      <div class="calibration-grid">
        <label><span>HUD opacity</span><input id="hudOpacity" type="range" min="20" max="100" value="100"><output id="hudOpacityValue">100%</output></label>
        <label><span>Brightness</span><input id="hudBrightness" type="range" min="50" max="180" value="100"><output id="hudBrightnessValue">100%</output></label>
        <label><span>HUD scale</span><input id="hudScale" type="range" min="60" max="140" value="100"><output id="hudScaleValue">100%</output></label>
        <label><span>Safe area width</span><input id="safeWidth" type="range" min="45" max="95" value="80"><output id="safeWidthValue">80%</output></label>
        <label><span>Safe area height</span><input id="safeHeight" type="range" min="35" max="90" value="66"><output id="safeHeightValue">66%</output></label>
        <label><span>Left eye X</span><input id="leftEyeX" type="range" min="-20" max="20" value="0"><output id="leftEyeXValue">0%</output></label>
        <label><span>Left eye Y</span><input id="leftEyeY" type="range" min="-20" max="20" value="0"><output id="leftEyeYValue">0%</output></label>
        <label><span>Right eye X</span><input id="rightEyeX" type="range" min="-20" max="20" value="0"><output id="rightEyeXValue">0%</output></label>
        <label><span>Right eye Y</span><input id="rightEyeY" type="range" min="-20" max="20" value="0"><output id="rightEyeYValue">0%</output></label>
      </div>
      <label class="preset-row"><span>LAYOUT PRESET</span><select id="presetSelect"></select></label>
      <div class="preset-actions">
        <input id="presetName" maxlength="48" placeholder="Preset name">
        <button id="savePreset" type="button">Save</button>
        <button id="deletePreset" type="button">Delete</button>
        <button id="resetCalibration" type="button">Factory</button>
      </div>
      <p class="calibration-note">Enable Calibrate, then drag the MOVE handle or resize from ↘. Presets are saved only in this browser.</p>
    </section>
    <section class="console-card">
      <div class="console-heading"><div><small>WORK</small><strong id="consoleItem">Club Sandwich + Fries</strong></div><span id="workStatus">queued</span></div>
      <label class="work-picker"><span>KDS ITEM</span><select id="workItemSelect"><option value="">No work loaded</option></select></label>
      <div class="meta-grid" id="workMeta"></div>
      <div class="inline-actions"><button id="startBuild" type="button">Start selected build</button><button id="resetSimulator" type="button">Reset</button></div>
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
