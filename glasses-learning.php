<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/glasses-learning.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){
    http_response_code(403);
    exit('AR glasses permission required.');
}

$pdo=app_pdo();
$error='';
$catalog=['locations'=>[],'stationsByLocation'=>[],'canExport'=>false,'ready'=>false];
try{
    $catalog=glasses_learning_catalog($pdo,$user);
}catch(Throwable $e){
    $error='Vision Learning could not load. Run Upgrade if this installation is behind.';
    error_log('[gelato-glasses-learning-page] '.$e->getMessage());
}

$boot=[
    'apiUrl'=>'api/glasses-learning.php',
    'catalog'=>$catalog,
    'defaults'=>['days'=>30],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f3f2ed">
<title>AR Vision Learning | Restaurant Admin</title>
<link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1">
<link rel="stylesheet" href="assets/css/glasses-learning.css?v=20260928-1">
</head>
<body class="admin-control gl-page">
<header class="admin-top">
    <a class="admin-brand" href="admin.php">
        <span class="admin-logo">SF</span>
        <span><strong>Restaurant Admin</strong><small>AR vision learning</small></span>
    </a>
    <nav class="admin-top-actions">
        <a class="admin-button dark" href="glasses-vision-lab.php">Vision Lab</a>
        <a class="admin-button" href="glasses-calibration-studio.php">Calibration Studio</a>
        <a class="admin-button" href="admin.php">Admin</a>
    </nav>
</header>

<main class="admin-shell gl-shell">
<section class="gl-hero">
    <div>
        <div class="admin-eyebrow">AR Glasses · Section 16</div>
        <h1>Vision Learning &amp; Accuracy Signals</h1>
        <p>Measure where cooks intervene in AR vision results, compare confidence bands and ingredients, and export human-corrected evidence for model evaluation.</p>
    </div>
    <div class="gl-note">
        <strong>Interpretation rule</strong>
        <span>An uncorrected observation is not automatically verified correct. Correction rate means human intervention rate.</span>
    </div>
</section>

<?php if($error):?><div class="gl-alert error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$catalog['ready']):?><div class="gl-alert warning">Run <strong>Upgrade</strong> before using Vision Learning. Existing KDS and glasses workflows remain available.</div><?php endif;?>

<section class="gl-filter-panel">
    <label><span>Location</span><select id="glLocation"></select></label>
    <label><span>Kitchen station</span><select id="glStation"></select></label>
    <label><span>Window</span>
        <select id="glDays">
            <option value="7">Last 7 days</option>
            <option value="30" selected>Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="180">Last 180 days</option>
            <option value="365">Last 365 days</option>
        </select>
    </label>
    <button class="admin-button dark" type="button" id="glRefresh">Refresh analytics</button>
    <button class="admin-button" type="button" id="glExport" <?=$catalog['canExport']&&$catalog['ready']?'':'disabled'?>>Export corrected JSONL</button>
</section>

<div id="glStatus" class="gl-status">Choose a location to load AR learning signals.</div>

<section class="gl-cards" id="glCards">
    <article><span>Observations</span><strong>—</strong><small>Vision evidence in scope</small></article>
    <article><span>Human corrected</span><strong>—</strong><small>Correction ledger entries</small></article>
    <article><span>Correction rate</span><strong>—</strong><small>Human intervention rate</small></article>
    <article><span>Rejected</span><strong>—</strong><small>False-positive corrections</small></article>
    <article><span>Reclassified</span><strong>—</strong><small>Ingredient label corrections</small></article>
    <article><span>Mean confidence</span><strong>—</strong><small>Model confidence, not accuracy</small></article>
</section>

<div class="gl-grid">
    <section class="gl-panel">
        <div class="gl-panel-head">
            <div><div class="admin-eyebrow">Calibration Signal</div><h2>Confidence bands</h2></div>
        </div>
        <div class="gl-table-wrap">
            <table>
                <thead><tr><th>Confidence</th><th>Observations</th><th>Corrected</th><th>Rejected</th><th>Reclassified</th><th>Correction rate</th></tr></thead>
                <tbody id="glConfidenceBody"><tr><td colspan="6">No data loaded.</td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="gl-panel">
        <div class="gl-panel-head">
            <div><div class="admin-eyebrow">Evidence</div><h2>Evidence types</h2></div>
        </div>
        <div class="gl-table-wrap">
            <table>
                <thead><tr><th>Evidence type</th><th>Observations</th><th>Corrected</th><th>Correction rate</th></tr></thead>
                <tbody id="glEvidenceBody"><tr><td colspan="4">No data loaded.</td></tr></tbody>
            </table>
        </div>
    </section>
</div>

<section class="gl-panel gl-components">
    <div class="gl-panel-head">
        <div><div class="admin-eyebrow">Model Labels</div><h2>Detector label intervention signals</h2></div>
        <small>Profile-mapped and fallback labels are tracked separately.</small>
    </div>
    <div class="gl-table-wrap">
        <table>
            <thead><tr><th>Detector label</th><th>Mapping</th><th>Min confidence</th><th>Observations</th><th>Corrected</th><th>Rejected</th><th>Reclassified</th><th>Correction rate</th></tr></thead>
            <tbody id="glLabelsBody"><tr><td colspan="8">No data loaded.</td></tr></tbody>
        </table>
    </div>
</section>

<section class="gl-panel gl-components">
    <div class="gl-panel-head">
        <div><div class="admin-eyebrow">Ingredient Detail</div><h2>Components requiring intervention</h2></div>
        <small>Sorted by human correction rate, then observation volume.</small>
    </div>
    <div class="gl-table-wrap">
        <table>
            <thead><tr><th>Ingredient / component</th><th>Observations</th><th>Corrected</th><th>Rejected</th><th>Reclassified</th><th>Qty corrected</th><th>Correction rate</th></tr></thead>
            <tbody id="glComponentsBody"><tr><td colspan="7">No data loaded.</td></tr></tbody>
        </table>
    </div>
</section>

<section class="gl-panel gl-export-note">
    <div class="gl-panel-head">
        <div><div class="admin-eyebrow">Governed Dataset</div><h2>What the export contains</h2></div>
    </div>
    <p>The default JSONL export contains only observations with human review. Each row keeps the original model component, action, quantity, confidence and transfer/spatial evidence next to the latest human reject/reclassification/quantity correction. It does not include camera frames or images.</p>
</section>

<div class="gl-toast" id="glToast" hidden></div>
</main>

<script>
window.GELATO_GLASS_LEARNING=<?=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
</script>
<script src="assets/js/glasses-learning.js?v=20260928-1"></script>
<script src="js/universal-admin-page-shell.js?v=20260915-2"></script>
</body>
</html>
