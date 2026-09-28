<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/glasses-calibration-studio.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){
    http_response_code(403);
    exit('AR glasses permission required.');
}

$pdo=app_pdo();
$error='';
$catalog=['locations'=>[],'stationsByLocation'=>[],'ingredients'=>[],'canManage'=>false,'schemaReady'=>false];
try{
    $catalog=glasses_calibration_studio_catalog($pdo,$user);
}catch(Throwable $e){
    $error='Calibration Studio could not load. Run Upgrade if this installation is behind.';
    error_log('[gelato-calibration-studio] '.$e->getMessage());
}

$boot=[
    'apiUrl'=>'api/glasses-calibrations.php',
    'csrfToken'=>app_csrf_token(),
    'catalog'=>$catalog,
    'defaults'=>[
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f3f2ed">
<title>AR Glasses Calibration Studio | Restaurant Admin</title>
<link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1">
<link rel="stylesheet" href="assets/css/glasses-calibration-studio.css?v=20260928-1">
</head>
<body class="admin-control gcs-page">
<header class="admin-top">
    <a class="admin-brand" href="admin.php">
        <span class="admin-logo">SF</span>
        <span><strong>Restaurant Admin</strong><small>AR glasses calibration</small></span>
    </a>
    <nav class="admin-top-actions">
        <a class="admin-button" href="admin.php">Admin</a>
        <a class="admin-button" href="kds.php">Kitchen Display</a>
    </nav>
</header>

<main class="admin-shell gcs-shell">
<section class="gcs-hero">
    <div>
        <div class="admin-eyebrow">AR Glasses · Section 14</div>
        <h1>Station Calibration Studio</h1>
        <p>Map ingredient source pans and the active build surface directly over a reference camera frame. Every save creates a new versioned station profile used by the glasses vision pipeline.</p>
    </div>
    <div class="gcs-status-card">
        <span>Calibration schema</span>
        <strong class="<?=$catalog['schemaReady']?'ready':'warning'?>"><?=$catalog['schemaReady']?'Ready':'Upgrade required'?></strong>
        <small><?=$catalog['canManage']?'You can edit and save profiles.':'View-only access.'?></small>
    </div>
</section>

<?php if($error):?><div class="gcs-alert error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$catalog['schemaReady']):?><div class="gcs-alert warning">Run <strong>Upgrade</strong> before saving calibration profiles. Existing kitchen features remain available.</div><?php endif;?>

<section class="gcs-toolbar-panel">
    <div class="gcs-toolbar-grid">
        <label>
            <span>Location</span>
            <select id="gcsLocation"></select>
        </label>
        <label>
            <span>Kitchen station</span>
            <select id="gcsStation"></select>
        </label>
        <label>
            <span>Platform</span>
            <input id="gcsPlatform" maxlength="40" value="inmo_air3">
        </label>
        <label>
            <span>Pixel format</span>
            <select id="gcsPixelFormat">
                <option value="grayscale8">grayscale8</option>
                <option value="rgb24">rgb24</option>
                <option value="rgba32">rgba32</option>
                <option value="nv21">nv21</option>
                <option value="yuv420">yuv420</option>
            </select>
        </label>
        <label>
            <span>Frame width</span>
            <input id="gcsFrameWidth" type="number" min="16" max="8192" value="640">
        </label>
        <label>
            <span>Frame height</span>
            <input id="gcsFrameHeight" type="number" min="16" max="8192" value="480">
        </label>
    </div>
    <div class="gcs-toolbar-actions">
        <label class="admin-button gcs-file-button">
            Load reference frame
            <input id="gcsReferenceFile" type="file" accept="image/jpeg,image/png,image/webp">
        </label>
        <button class="admin-button" type="button" id="gcsRefreshVersions">Refresh versions</button>
        <button class="admin-button quiet" type="button" id="gcsClear">Clear drawing</button>
    </div>
    <p class="gcs-help">The reference image stays in your browser and is not uploaded. Its job is to help you draw normalized geometry. The saved camera width, height and pixel format must match the glasses runtime for the profile to be used.</p>
</section>

<div class="gcs-layout">
    <section class="gcs-editor-panel">
        <div class="gcs-editor-head">
            <div>
                <div class="admin-eyebrow">Reference View</div>
                <h2 id="gcsEditorTitle">Choose a station</h2>
            </div>
            <div class="gcs-zoom-label"><span id="gcsShapeCount">0 shapes</span></div>
        </div>

        <div class="gcs-editor-tools">
            <label class="gcs-tool-group">
                <span>Ingredient source</span>
                <select id="gcsIngredient"></select>
                <button type="button" class="admin-button dark" id="gcsDrawIngredient">Draw source zone</button>
            </label>
            <div class="gcs-tool-group">
                <span>Station region</span>
                <div class="gcs-region-buttons">
                    <button type="button" class="admin-button" data-region-tool="build_surface">Build surface</button>
                    <button type="button" class="admin-button" data-region-tool="plate_surface">Plate surface</button>
                    <button type="button" class="admin-button" data-region-tool="handoff_surface">Handoff</button>
                    <button type="button" class="admin-button" data-region-tool="discard_surface">Discard</button>
                </div>
            </div>
        </div>

        <div class="gcs-canvas-shell">
            <div class="gcs-canvas" id="gcsCanvas" tabindex="0" aria-label="Calibration drawing surface">
                <img id="gcsReferenceImage" alt="" hidden>
                <div class="gcs-empty-frame" id="gcsEmptyFrame">
                    <strong>No reference frame loaded</strong>
                    <span>You can still draw on the blank camera frame.</span>
                </div>
                <div class="gcs-overlay" id="gcsOverlay"></div>
                <div class="gcs-draw-hint" id="gcsDrawHint" hidden>Drag on the frame to draw</div>
            </div>
        </div>
        <div class="gcs-canvas-footer">
            <span>Center coordinates and dimensions are stored as normalized 0–1 values.</span>
            <span>Delete selected shape: <kbd>Delete</kbd></span>
        </div>
    </section>

    <aside class="gcs-side">
        <section class="gcs-panel">
            <div class="gcs-panel-head">
                <div>
                    <div class="admin-eyebrow">Geometry</div>
                    <h2>Zones &amp; regions</h2>
                </div>
            </div>
            <div id="gcsShapeList" class="gcs-shape-list"></div>
        </section>

        <section class="gcs-panel">
            <div class="gcs-panel-head">
                <div>
                    <div class="admin-eyebrow">Publish</div>
                    <h2>Save new version</h2>
                </div>
            </div>
            <label class="gcs-notes">
                <span>Calibration notes</span>
                <textarea id="gcsNotes" rows="3" maxlength="1000" placeholder="Example: sandwich line after cold-rail reposition."></textarea>
            </label>
            <div class="gcs-validation" id="gcsValidation"></div>
            <button type="button" class="admin-button accent gcs-save" id="gcsSave" <?=$catalog['canManage']&&$catalog['schemaReady']?'':'disabled'?>>Save calibration version</button>
        </section>

        <section class="gcs-panel">
            <div class="gcs-panel-head">
                <div>
                    <div class="admin-eyebrow">History</div>
                    <h2>Saved versions</h2>
                </div>
            </div>
            <div id="gcsVersions" class="gcs-version-list"></div>
        </section>
    </aside>
</div>

<div class="gcs-toast" id="gcsToast" hidden></div>
</main>

<script>
window.GELATO_CALIBRATION_STUDIO=<?=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
</script>
<script src="assets/js/glasses-calibration-studio.js?v=20260928-1"></script>
<script src="js/universal-admin-page-shell.js?v=20260915-2"></script>
</body>
</html>
