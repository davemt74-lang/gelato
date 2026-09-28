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
<link rel="stylesheet" href="assets/css/glasses-web-simulator.css?v=20260928-camera1">
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
    <label>Scene <select id="sceneSourceSelect"><option value="image">Image</option><option value="camera">Browser Camera</option></select></label>
    <label class="asset-load" id="sceneFileLabel">Scene Image <input id="sceneFile" type="file" accept="image/*"></label>
    <label class="asset-load">Glasses <input id="glassesFile" type="file" accept="image/*"></label>
    <label>Frame <select id="frameModeSelect"><option value="svg">SVG</option><option value="image">Uploaded</option><option value="none">None</option></select></label>
    <label class="mask-toggle"><input id="opticalMaskToggle" type="checkbox" checked> Lens mask</label>
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
      <span id="syncBadge" class="sim-sync-badge paused">SYNC PAUSED</span>
    </div>

    <div class="glasses-stage" id="glassesStage">
      <div class="scene-placeholder">
        <img id="sceneImage" alt="" hidden>
        <video id="cameraVideo" class="camera-video" autoplay muted playsinline hidden></video>
        <canvas id="cameraCaptureCanvas" hidden></canvas>
        <div class="scene-copy" id="scenePlaceholder">Drop your final kitchen artwork in or switch Scene to Browser Camera.</div>
      </div>
      <img id="glassesImage" class="glasses-image" alt="" hidden>
      <svg id="svgGlassesLayer" class="svg-glasses-layer" viewBox="0 0 1200 700" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
        <defs>
          <linearGradient id="frameMetal" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#555f62"/>
            <stop offset="35%" stop-color="#1d2325"/>
            <stop offset="70%" stop-color="#050708"/>
            <stop offset="100%" stop-color="#394246"/>
          </linearGradient>
          <linearGradient id="lensTint" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#7fa2a8" stop-opacity=".15"/>
            <stop offset="60%" stop-color="#d8ffff" stop-opacity=".04"/>
            <stop offset="100%" stop-color="#3b545b" stop-opacity=".12"/>
          </linearGradient>
          <filter id="frameShadow" x="-20%" y="-20%" width="140%" height="160%">
            <feDropShadow dx="0" dy="18" stdDeviation="14" flood-color="#000" flood-opacity=".5"/>
          </filter>
          <mask id="outsideLensMask">
            <rect width="1200" height="700" fill="white"/>
            <path d="M115 160 C215 112 398 108 535 143 C552 148 564 165 562 186 L541 455 C538 500 505 535 460 541 C350 558 248 547 170 515 C126 497 100 459 96 414 L76 230 C72 197 86 175 115 160 Z" fill="black"/>
            <path d="M665 143 C802 108 985 112 1085 160 C1114 175 1128 197 1124 230 L1104 414 C1100 459 1074 497 1030 515 C952 547 850 558 740 541 C695 535 662 500 659 455 L638 186 C636 165 648 148 665 143 Z" fill="black"/>
          </mask>
          <clipPath id="leftLensClip">
            <path d="M115 160 C215 112 398 108 535 143 C552 148 564 165 562 186 L541 455 C538 500 505 535 460 541 C350 558 248 547 170 515 C126 497 100 459 96 414 L76 230 C72 197 86 175 115 160 Z"/>
          </clipPath>
          <clipPath id="rightLensClip">
            <path d="M665 143 C802 108 985 112 1085 160 C1114 175 1128 197 1124 230 L1104 414 C1100 459 1074 497 1030 515 C952 547 850 558 740 541 C695 535 662 500 659 455 L638 186 C636 165 648 148 665 143 Z"/>
          </clipPath>
        </defs>
        <g id="svgOpticalMask" class="svg-optical-mask">
          <rect width="1200" height="700" fill="#020403" fill-opacity=".68" mask="url(#outsideLensMask)"/>
          <path d="M115 160 C215 112 398 108 535 143 C552 148 564 165 562 186 L541 455 C538 500 505 535 460 541 C350 558 248 547 170 515 C126 497 100 459 96 414 L76 230 C72 197 86 175 115 160 Z" fill="url(#lensTint)"/>
          <path d="M665 143 C802 108 985 112 1085 160 C1114 175 1128 197 1124 230 L1104 414 C1100 459 1074 497 1030 515 C952 547 850 558 740 541 C695 535 662 500 659 455 L638 186 C636 165 648 148 665 143 Z" fill="url(#lensTint)"/>
        </g>
        <g id="svgFrameArtwork" class="svg-frame-artwork" filter="url(#frameShadow)">
          <path d="M78 188 C120 126 245 96 393 99 C473 101 531 112 574 134 C590 142 610 142 626 134 C669 112 727 101 807 99 C955 96 1080 126 1122 188" fill="none" stroke="url(#frameMetal)" stroke-width="30" stroke-linecap="round"/>
          <path d="M115 160 C215 112 398 108 535 143 C552 148 564 165 562 186 L541 455 C538 500 505 535 460 541 C350 558 248 547 170 515 C126 497 100 459 96 414 L76 230 C72 197 86 175 115 160 Z" fill="none" stroke="url(#frameMetal)" stroke-width="26"/>
          <path d="M665 143 C802 108 985 112 1085 160 C1114 175 1128 197 1124 230 L1104 414 C1100 459 1074 497 1030 515 C952 547 850 558 740 541 C695 535 662 500 659 455 L638 186 C636 165 648 148 665 143 Z" fill="none" stroke="url(#frameMetal)" stroke-width="26"/>
          <path d="M551 175 C575 155 625 155 649 175" fill="none" stroke="url(#frameMetal)" stroke-width="24" stroke-linecap="round"/>
          <path d="M74 196 C35 212 18 238 7 282" fill="none" stroke="url(#frameMetal)" stroke-width="24" stroke-linecap="round"/>
          <path d="M1126 196 C1165 212 1182 238 1193 282" fill="none" stroke="url(#frameMetal)" stroke-width="24" stroke-linecap="round"/>
          <path d="M158 147 C268 112 423 115 523 143" fill="none" stroke="#aeb8ba" stroke-opacity=".28" stroke-width="4" stroke-linecap="round"/>
          <path d="M677 143 C777 115 932 112 1042 147" fill="none" stroke="#aeb8ba" stroke-opacity=".28" stroke-width="4" stroke-linecap="round"/>
        </g>
      </svg>

      <div id="leftEyeGuide" class="eye-guide left-eye-guide"><span>LEFT EYE</span></div>
      <div id="rightEyeGuide" class="eye-guide right-eye-guide"><span>RIGHT EYE</span></div>
      <div id="safeAreaGuide" class="safe-area-guide"><span>PROJECTION SAFE AREA</span></div>

      <section class="lens-projection" id="lensProjection" aria-label="Simulated on-lens projection">
        <div class="hud-topbar editable-region" id="hudStatus" data-region="hudStatus">
          <button class="region-handle region-move" type="button" tabindex="-1" aria-hidden="true">MOVE</button>
          <button class="region-handle region-resize" type="button" tabindex="-1" aria-hidden="true">↘</button>
          <div class="hud-clock"><strong id="hudClock">10:24</strong><span id="hudStatusTemp">HOT LINE · 74°F</span></div>
          <div class="hud-system">
            <span id="hudRuntimeState">READY</span>
            <span id="hudConnectivity">BT · LIVE</span>
            <span id="hudBattery">87%</span>
          </div>
        </div>

        <aside class="hud-orders-left editable-region" id="hudOrdersRegion" data-region="hudOrdersRegion">
          <button class="region-handle region-move" type="button" tabindex="-1" aria-hidden="true">MOVE</button>
          <button class="region-handle region-resize" type="button" tabindex="-1" aria-hidden="true">↘</button>
          <div class="hud-panel-title"><small>ACTIVE ORDERS</small><span id="hudOrdersCount">0</span></div>
          <div id="hudOrders" class="hud-orders-list"></div>
        </aside>

        <div class="hud-next-center editable-region" id="hudNextRegion" data-region="hudNextRegion">
          <button class="region-handle region-move" type="button" tabindex="-1" aria-hidden="true">MOVE</button>
          <button class="region-handle region-resize" type="button" tabindex="-1" aria-hidden="true">↘</button>
          <small>NEXT</small>
          <strong id="hudNextCenter">Add Toasted Bread</strong>
          <span id="hudNextTarget">Target ingredient</span>
          <div class="hud-leader-line" id="hudLeaderLine"><span></span></div>
        </div>

        <aside class="hud-right-build editable-region" id="hudRight" data-region="hudRight">
          <button class="region-handle region-move" type="button" tabindex="-1" aria-hidden="true">MOVE</button>
          <button class="region-handle region-resize" type="button" tabindex="-1" aria-hidden="true">↘</button>
          <div class="hud-card hud-build-card">
            <div class="hud-panel-title"><small>ACTIVE BUILD</small><span id="hudBuildTicket">#1042</span></div>
            <strong id="hudBuildItem">Club Sandwich + Fries</strong>
            <span id="hudBuildMeta">Table 12 · Regular</span>
            <ol id="hudBuildSteps" class="hud-build-steps"></ol>
          </div>
          <div class="hud-card hud-validation-panel" id="validationCard">
            <div class="hud-panel-title"><small>INGREDIENT VALIDATION</small><span id="hudValidationState">BUILDING</span></div>
            <strong id="hudValidationCount">0 / 6 accounted</strong>
            <div class="hud-validation-grid">
              <span>Missing <b id="hudValidationMissing">6</b></span>
              <span>Unexpected <b id="hudValidationUnexpected">0</b></span>
            </div>
            <span id="hudValidationDetail">Build in progress</span>
          </div>
        </aside>

        <div id="detectionLayer" class="detection-layer"></div>
        <div id="visionDetectionLayer" class="vision-detection-layer"></div>
        <div id="datasetAnnotationLayer" class="dataset-annotation-layer"></div>
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
    <section class="console-card camera-card">
      <div class="console-heading"><div><small>BROWSER CAMERA</small><strong>Vision preview source</strong></div><span id="cameraHealth">OFF</span></div>
      <label class="work-picker"><span>CAMERA</span><select id="cameraDeviceSelect"><option value="">Default camera</option></select></label>
      <div class="camera-grid">
        <label><span>Resolution</span><select id="cameraResolution"><option value="1280x720">1280×720</option><option value="1920x1080">1920×1080</option><option value="640x480">640×480</option></select></label>
        <label><span>Fit</span><select id="cameraFit"><option value="cover">Cover</option><option value="contain">Contain</option></select></label>
      </div>
      <div class="camera-toggle-row">
        <label><input id="cameraMirror" type="checkbox"> Mirror</label>
        <label><input id="cameraManualTarget" type="checkbox"> Manual target</label>
      </div>
      <div class="inline-actions">
        <button id="startCamera" type="button">Start Camera</button>
        <button id="stopCamera" type="button" disabled>Stop Camera</button>
      </div>
      <div class="inline-actions">
        <button id="captureFrame" type="button" disabled>Capture Frame</button>
        <button id="clearCameraTarget" type="button" disabled>Clear Target</button>
      </div>
      <div class="camera-meta" id="cameraMeta"><span>No active camera stream.</span></div>
      <div class="vision-runtime-panel">
        <div class="console-heading"><div><small>BROWSER VISION</small><strong>Detection runtime</strong></div><span id="visionHealth">VISION IDLE</span></div>
        <div class="camera-grid">
          <label><span>Mode</span><select id="visionMode"><option value="manual">Manual</option><option value="assisted">Assisted</option><option value="automatic">Automatic</option></select></label>
          <label><span>FPS limit</span><select id="visionFpsLimit"><option value="2">2 FPS</option><option value="4" selected>4 FPS</option><option value="6">6 FPS</option><option value="8">8 FPS</option></select></label>
          <label><span>Detector adapter</span><select id="visionAdapterSelect"><option value="fixture">Deterministic Fixture</option><option value="onnx">Governed ONNX</option></select></label>
          <label><span>Detector name</span><input id="visionDetectorName" value="food-detector" maxlength="120" autocomplete="off"></label>
        </div>
        <div class="inline-actions">
          <button id="loadVisionModel" type="button">Load Governed Model</button>
          <button id="unloadVisionModel" type="button" disabled>Unload Model</button>
        </div>
        <div id="visionModelStatus" class="vision-model-status"><strong>FIXTURE</strong><span>No governed browser model loaded.</span></div>
        <label class="vision-confidence"><span>Auto confidence threshold</span><input id="visionConfidenceThreshold" type="range" min="40" max="99" value="75"><output id="visionConfidenceValue">75%</output></label>
        <div id="visionMetrics" class="vision-metrics">FPS 0.0 · 0ms · 0 det</div>
        <p class="calibration-note">Governed ONNX loads only the model package assigned to the selected device/build, verifies its registered byte size and SHA-256 in the browser, and applies the active Gelato label profile before detections enter temporal tracking.</p>
        <div class="temporal-validation-panel">
          <div class="console-heading"><div><small>TEMPORAL PRODUCT VALIDATION</small><strong>Multi-ingredient state</strong></div><span id="temporalValidationState">IDLE</span></div>
          <div id="temporalValidationDetail" class="vision-metrics">0 missing · 0 verify · 0 unexpected · 0 sequence · 0 pending</div>
          <div id="temporalEventList" class="temporal-event-list"><span class="sim-muted">No temporal events yet.</span></div>
        </div>
        <div class="dataset-capture-panel">
          <div class="console-heading"><div><small>TRAINING DATASET</small><strong>Camera capture &amp; box labeling</strong></div><span id="datasetState">0 SAMPLES</span></div>
          <div class="camera-toggle-row"><label><input id="datasetLabelMode" type="checkbox"> Label mode</label><span class="sim-muted">Drag boxes on the camera image.</span></div>
          <label class="work-picker"><span>CLASS</span><select id="datasetClassSelect"><option value="">Start a build to load ingredient classes</option></select></label>
          <div class="inline-actions">
            <button id="datasetFreezeFrame" type="button" disabled>Freeze Frame</button>
            <button id="datasetResumeFrame" type="button" disabled>Resume</button>
            <button id="datasetCaptureSample" type="button" disabled>Capture Labeled Sample</button>
          </div>
          <div class="inline-actions">
            <button id="datasetUndoBox" type="button" disabled>Undo Box</button>
            <button id="datasetClearBoxes" type="button" disabled>Clear Boxes</button>
            <button id="datasetExport" type="button" disabled>Export YOLO ZIP</button>
          </div>
          <div id="datasetStats" class="vision-metrics">0 boxes on frame · 0 samples · 0 classes</div>
          <div id="datasetAnnotationList" class="dataset-annotation-list"><span class="sim-muted">No annotations on the current frame.</span></div>
          <p class="calibration-note">Dataset capture stays local to this browser. Export contains JPEG frames, YOLO labels, data.yaml and a Gelato manifest; it does not modify POS, KDS, builds or validation.</p>
        </div>
      </div>
    </section>

    <section class="console-card">
      <div class="console-heading"><div><small>WORK</small><strong id="consoleItem">Club Sandwich + Fries</strong></div><span id="workStatus">queued</span></div>
      <label class="work-picker"><span>KDS ITEM</span><select id="workItemSelect"><option value="">No work loaded</option></select></label>
      <div class="meta-grid" id="workMeta"></div>
      <div class="inline-actions"><button id="startBuild" type="button">Start selected build</button><button id="resetSimulator" type="button">Reset</button></div>
    </section>

    <section class="console-card">
      <div class="console-heading"><div><small>SIMULATED VISION</small><strong>Ingredient detections</strong></div><span id="componentCount">0</span></div>
      <div class="demo-controls">
        <button id="autoPlayBuild" type="button">Auto Play</button>
        <button id="nextDetection" type="button">Next Detection</button>
        <button id="lowConfidenceDetection" type="button">Low Confidence</button>
        <button id="completeMockBuild" type="button">Complete Mock</button>
      </div>
      <div class="camera-target-controls" id="cameraTargetControls">
        <label><span>Manual component</span><select id="cameraTargetComponent"><option value="">Current required component</option></select></label>
        <label><span>Confidence</span><input id="cameraTargetConfidence" type="range" min="40" max="100" value="96"><output id="cameraTargetConfidenceValue">96%</output></label>
        <button id="submitCameraTarget" type="button" disabled>Submit Target Observation</button>
      </div>
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
<script src="assets/js/glasses-web-simulator.js?v=20260928-dataset1"></script>
</body>
</html>
