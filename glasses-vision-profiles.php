<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/glasses-vision-profiles.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){
    http_response_code(403);
    exit('AR glasses permission required.');
}

$pdo=app_pdo();
$error='';
$catalog=['ready'=>false,'canManage'=>false,'profiles'=>[],'ingredients'=>[]];
try{
    $catalog=glasses_vision_profile_catalog($pdo,$user);
}catch(Throwable $e){
    $error='Vision Label Profiles could not load. Run Upgrade if this installation is behind.';
    error_log('[gelato-vision-label-page] '.$e->getMessage());
}

$boot=[
    'apiUrl'=>'api/glasses-vision-profiles.php',
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
<title>AR Vision Label Profiles | Restaurant Admin</title>
<link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1">
<link rel="stylesheet" href="assets/css/glasses-vision-profiles.css?v=20260928-1">
</head>
<body class="admin-control gvp-page">
<header class="admin-top">
    <a class="admin-brand" href="admin.php"><span class="admin-logo">SF</span><span><strong>Restaurant Admin</strong><small>AR vision label profiles</small></span></a>
    <nav class="admin-top-actions">
        <a class="admin-button" href="glasses-learning.php">Vision Learning</a>
        <a class="admin-button" href="glasses-calibration-studio.php">Calibration Studio</a>
        <a class="admin-button" href="admin.php">Admin</a>
    </nav>
</header>

<main class="admin-shell gvp-shell">
<section class="gvp-hero">
    <div>
        <div class="admin-eyebrow">AR Glasses · Section 18</div>
        <h1>Vision Label Registry</h1>
        <p>Map detector class labels to Gelato ingredients and optionally raise the minimum confidence required for a specific label. Generic mappings use <code>*</code>; detector-specific mappings override them.</p>
    </div>
    <div class="gvp-rule"><strong>Safety rule</strong><span>Profile thresholds can tighten the global vision confidence floor, never lower it.</span></div>
</section>

<?php if($error):?><div class="gvp-alert error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$catalog['ready']):?><div class="gvp-alert warning">Run <strong>Upgrade</strong> before managing label profiles.</div><?php endif;?>

<div class="gvp-layout">
<section class="gvp-panel">
    <div class="gvp-panel-head">
        <div><div class="admin-eyebrow">Mapping Editor</div><h2 id="gvpFormTitle">Add model label</h2></div>
        <button type="button" class="admin-button quiet" id="gvpReset">New mapping</button>
    </div>
    <input type="hidden" id="gvpPublicId">
    <div class="gvp-form-grid">
        <label><span>Detector</span><input id="gvpDetector" value="*" maxlength="120" placeholder="* or detector-name"><small><code>*</code> applies to every detector unless overridden.</small></label>
        <label><span>Model label</span><input id="gvpLabel" maxlength="160" placeholder="turkey_slice"></label>
        <label><span>Gelato ingredient</span><select id="gvpIngredient"></select></label>
        <label><span>Minimum confidence</span><input id="gvpConfidence" type="number" min="0.50" max="1" step="0.01" placeholder="Use global floor"><small>Optional. 0.50–1.00.</small></label>
        <label><span>Status</span><select id="gvpStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <label class="wide"><span>Notes</span><textarea id="gvpNotes" rows="3" maxlength="1000" placeholder="Example: food-model-v3 class 14; validated under lunch-line lighting."></textarea></label>
    </div>
    <div id="gvpValidation" class="gvp-validation"></div>
    <button type="button" class="admin-button dark gvp-save" id="gvpSave" <?=$catalog['canManage']&&$catalog['ready']?'':'disabled'?>>Save mapping</button>
</section>

<section class="gvp-panel">
    <div class="gvp-panel-head">
        <div><div class="admin-eyebrow">Registry</div><h2>Active &amp; historical mappings</h2></div>
        <input id="gvpSearch" class="gvp-search" type="search" placeholder="Search label, detector, ingredient">
    </div>
    <div class="gvp-table-wrap">
        <table>
            <thead><tr><th>Detector</th><th>Model label</th><th>Ingredient</th><th>Min confidence</th><th>Status</th><th></th></tr></thead>
            <tbody id="gvpRows"></tbody>
        </table>
    </div>
</section>
</div>

<section class="gvp-panel gvp-explain">
    <div class="admin-eyebrow">Runtime precedence</div>
    <h2>How a detector label is resolved</h2>
    <p>For the current build, Gelato sends only mappings whose ingredients are actually required by that recipe. A detector-specific label overrides a generic <code>*</code> mapping with the same normalized label. If the mapped ingredient is not expected by the active build, the detection is rejected. When no registry mapping exists, the existing exact recipe-name fallback remains available.</p>
</section>

<div class="gvp-toast" id="gvpToast" hidden></div>
</main>
<script>
window.GELATO_VISION_PROFILES=<?=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
</script>
<script src="assets/js/glasses-vision-profiles.js?v=20260928-1"></script>
<script src="js/universal-admin-page-shell.js?v=20260915-2"></script>
</body>
</html>
