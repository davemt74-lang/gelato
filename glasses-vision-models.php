<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/glasses-vision-models.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){
    http_response_code(403);
    exit('AR glasses permission required.');
}

$pdo=app_pdo();
$error='';
$catalog=[
    'ready'=>false,'canManage'=>false,'packages'=>[],'rollouts'=>[],'metricsByRollout'=>[],
    'locations'=>[],'stationsByLocation'=>[]
];
try{
    $catalog=glasses_vision_model_catalog($pdo,$user);
}catch(Throwable $e){
    $error='Vision Model Rollouts could not load. Run Upgrade if this installation is behind.';
    error_log('[gelato-vision-models-page] '.$e->getMessage());
}

$boot=[
    'apiUrl'=>'api/glasses-vision-models.php',
    'csrfToken'=>app_csrf_token(),
    'catalog'=>$catalog,
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>AR Vision Model Rollouts | Restaurant Admin</title>
<link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1">
<link rel="stylesheet" href="assets/css/glasses-vision-models.css?v=20260928-drift1">
</head>
<body class="admin-control gvm-page">
<header class="admin-top">
    <a class="admin-brand" href="admin.php"><span class="admin-logo">SF</span><span><strong>Restaurant Admin</strong><small>AR model rollout governance</small></span></a>
    <nav class="admin-top-actions">
        <a class="admin-button" href="glasses-vision-profiles.php">Vision Labels</a>
        <a class="admin-button" href="glasses-learning.php">Vision Learning</a>
        <a class="admin-button" href="admin.php">Admin</a>
    </nav>
</header>

<main class="admin-shell gvm-shell">
<section class="gvm-hero">
    <div>
        <div class="admin-eyebrow">AR Glasses · Section 19</div>
        <h1>Vision Model Packages &amp; Rollouts</h1>
        <p>Register immutable detector packages, stage deterministic canaries, evaluate live production health, and roll back automatically or manually to a declared baseline. Gelato never treats an unverified model artifact as active.</p>
    </div>
    <div class="gvm-rule">
        <strong>Deployment rule</strong>
        <span>HTTPS artifact + SHA-256 + compatible runtime are mandatory. Comparison-aware canaries advance only through governed stages; severe live regressions auto-roll back with immutable evidence.</span>
    </div>
</section>

<?php if($error):?><div class="gvm-alert error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$catalog['ready']):?><div class="gvm-alert warning">Run <strong>Upgrade</strong> before managing model packages or rollouts.</div><?php endif;?>

<div class="gvm-top-grid">
<section class="gvm-panel">
    <div class="gvm-panel-head">
        <div><div class="admin-eyebrow">Immutable Artifact</div><h2>Register model package</h2></div>
    </div>
    <div class="gvm-form-grid">
        <label><span>Detector name</span><input id="gvmDetector" maxlength="120" placeholder="food-model-v3"></label>
        <label><span>Model name</span><input id="gvmModelName" maxlength="160" placeholder="sandwich-detector"></label>
        <label><span>Model version</span><input id="gvmModelVersion" maxlength="80" placeholder="2.1.0"></label>
        <label><span>Runtime</span><select id="gvmRuntime"><option value="onnx">ONNX</option><option value="tflite">TensorFlow Lite</option><option value="unity_barracuda">Unity Barracuda</option><option value="vendor">Vendor runtime</option></select></label>
        <label><span>Platform</span><input id="gvmPlatform" maxlength="40" value="inmo_air3"></label>
        <label><span>Artifact bytes</span><input id="gvmBytes" type="number" min="1" step="1" placeholder="Optional"></label>
        <label class="wide"><span>HTTPS artifact URL</span><input id="gvmUrl" type="url" maxlength="1000" placeholder="https://models.example.com/model.onnx"></label>
        <label class="wide"><span>Artifact SHA-256</span><input id="gvmSha" maxlength="64" spellcheck="false" placeholder="64 hexadecimal characters"></label>
        <label><span>Minimum SDK version</span><input id="gvmMinSdk" maxlength="80" placeholder="Optional, e.g. 1.4.0"></label>
        <label><span>Minimum app version</span><input id="gvmMinApp" maxlength="80" placeholder="Optional, e.g. 1.9.0"></label>
        <label class="wide"><span>Notes</span><textarea id="gvmPackageNotes" rows="3" maxlength="1000"></textarea></label>
        <div class="gvm-browser-config wide">
            <div class="admin-eyebrow">Browser ONNX preview</div>
            <label><span>Input name</span><input id="gvmBrowserInputName" maxlength="160" value="images"></label>
            <label><span>Output name</span><input id="gvmBrowserOutputName" maxlength="160" value="output0"></label>
            <label><span>Input width</span><input id="gvmBrowserWidth" type="number" min="32" max="4096" value="640"></label>
            <label><span>Input height</span><input id="gvmBrowserHeight" type="number" min="32" max="4096" value="640"></label>
            <label><span>Input layout</span><select id="gvmBrowserInputLayout"><option value="nchw">NCHW</option><option value="nhwc">NHWC</option></select></label>
            <label><span>Output layout</span><select id="gvmBrowserOutputLayout"><option value="channels_first">Channels first</option><option value="rows">Rows</option></select></label>
            <label><span>Box scale</span><select id="gvmBrowserBoxScale"><option value="pixels">Pixels</option><option value="normalized">Normalized</option></select></label>
            <label><span>NMS IoU</span><input id="gvmBrowserNms" type="number" min="0.05" max="0.95" step="0.01" value="0.45"></label>
            <label><span>Max detections</span><input id="gvmBrowserMaxDetections" type="number" min="1" max="100" value="25"></label>
            <label class="wide"><span>Model labels</span><textarea id="gvmBrowserLabels" rows="4" placeholder="One detector label per line"></textarea></label>
            <div class="wide inline-actions"><button type="button" id="gvmPreflightBrowserModel" class="admin-button quiet">Verify browser artifact</button></div>
            <div id="gvmBrowserPreflight" class="gvm-preflight wide"><strong>Not verified</strong><span>Runs in this browser only; no model bytes are stored by Gelato.</span></div>
        </div>
    </div>
    <div id="gvmPackageValidation" class="gvm-validation"></div>
    <button type="button" id="gvmCreatePackage" class="admin-button dark gvm-full" <?=$catalog['canManage']&&$catalog['ready']?'':'disabled'?>>Register immutable package</button>
</section>

<section class="gvm-panel">
    <div class="gvm-panel-head">
        <div><div class="admin-eyebrow">Governed Rollout</div><h2>Create rollout plan</h2></div>
    </div>
    <div class="gvm-form-grid">
        <label><span>Target package</span><select id="gvmTargetPackage"></select></label>
        <label><span>Baseline package</span><select id="gvmBaselinePackage"></select></label>
        <label><span>Location</span><select id="gvmLocation"></select><small>Blank = organization-wide.</small></label>
        <label><span>Station</span><select id="gvmStation"><option value="">All stations in scope</option></select></label>
        <label><span>Initial canary %</span><input id="gvmCanary" type="number" min="0" max="100" step="0.01" value="10"></label>
        <label class="wide"><span>Notes</span><textarea id="gvmRolloutNotes" rows="3" maxlength="1000"></textarea></label>
    </div>
    <div id="gvmRolloutValidation" class="gvm-validation"></div>
    <button type="button" id="gvmCreateRollout" class="admin-button dark gvm-full" <?=$catalog['canManage']&&$catalog['ready']?'':'disabled'?>>Create draft rollout</button>
</section>
</div>

<section class="gvm-panel gvm-table-panel">
    <div class="gvm-panel-head">
        <div><div class="admin-eyebrow">Packages</div><h2>Registered model artifacts</h2></div>
        <input id="gvmPackageSearch" class="gvm-search" type="search" placeholder="Search detector, model, version">
    </div>
    <div class="gvm-table-wrap">
        <table>
            <thead><tr><th>Detector / model</th><th>Runtime</th><th>Compatibility floor</th><th>SHA-256</th><th>Status</th><th></th></tr></thead>
            <tbody id="gvmPackageRows"></tbody>
        </table>
    </div>
</section>

<section class="gvm-panel gvm-table-panel">
    <div class="gvm-panel-head">
        <div><div class="admin-eyebrow">Rollouts</div><h2>Deployment control</h2></div>
        <button type="button" id="gvmRefresh" class="admin-button quiet">Refresh telemetry</button>
    </div>
    <div class="gvm-table-wrap">
        <table>
            <thead><tr><th>Scope</th><th>Target</th><th>Baseline</th><th>Canary</th><th>Status</th><th>Device reports</th><th>Controls</th></tr></thead>
            <tbody id="gvmRolloutRows"></tbody>
        </table>
    </div>
</section>

<section class="gvm-panel gvm-explain">
    <div class="admin-eyebrow">Runtime boundary</div>
    <h2>What Section 19 does — and does not — activate</h2>
    <p>Gelato now determines the desired package per device/build and records rollout telemetry. The Unity client independently validates the assignment manifest and reports that it was received. Actual binary download, checksum verification, runtime loading, atomic activation, and on-device rollback remain blocked until the model-loader API is available from the glasses SDK/runtime. No package is falsely reported as activated by this section.</p>
</section>

<div id="gvmToast" class="gvm-toast" hidden></div>
</main>

<script>
window.GELATO_VISION_MODELS=<?=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
</script>
<script src="assets/js/glasses-vision-models.js?v=20260928-drift1"></script>
<script src="js/universal-admin-page-shell.js?v=20260915-2"></script>
</body>
</html>
