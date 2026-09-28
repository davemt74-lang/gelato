<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/glasses-vision-operations.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){http_response_code(403);exit('AR glasses permission required.');}
$pdo=app_pdo();$error='';
try{$catalog=glasses_vision_ops_catalog($pdo,$user);}catch(Throwable $e){$catalog=['ready'=>false,'devices'=>[],'summary'=>[],'needsAttention'=>[],'timeline'=>[],'locations'=>[]];$error='Vision Operations could not load. Run Upgrade if this installation is behind.';error_log('[gelato-vision-operations] '.$e->getMessage());}
$boot=['apiUrl'=>'api/glasses-vision-operations.php','catalog'=>$catalog];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Vision Operations | Restaurant Admin</title>
<link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1">
<link rel="stylesheet" href="assets/css/glasses-vision-operations.css?v=20260928-1">
</head>
<body class="admin-control gvo-page">
<header class="admin-top"><a class="admin-brand" href="admin.php"><span class="admin-logo">SF</span><span><strong>Restaurant Admin</strong><small>Vision fleet operations</small></span></a><nav class="admin-top-actions"><a class="admin-button" href="glasses-vision-models.php">Models</a><a class="admin-button" href="glasses-calibration-studio.php">Calibration</a><a class="admin-button" href="glasses-learning.php">Learning</a><a class="admin-button" href="admin.php">Admin</a></nav></header>
<main class="admin-shell gvo-shell">
<section class="gvo-hero"><div><div class="admin-eyebrow">AR Glasses · Fleet Operations</div><h1>Vision Operations &amp; Fleet Health</h1><p>One read-only operational view across devices, stations, model assignments, runtime health, calibration, drift and recovery. Use the governed workspaces for changes.</p></div><div class="gvo-rule"><strong>Authority boundary</strong><span>This dashboard aggregates existing ledgers only. It cannot mutate POS, KDS, build observations, validation, rollout selection or calibration.</span></div></section>
<?php if($error):?><div class="gvo-alert error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$catalog['ready']):?><div class="gvo-alert warning">Run <strong>Upgrade</strong> before using Vision Operations.</div><?php endif;?>
<section id="gvoSummary" class="gvo-kpis"></section>
<section class="gvo-panel gvo-filters"><div class="gvo-panel-head"><div><div class="admin-eyebrow">Fleet Filters</div><h2>Find operational problems quickly</h2></div><button id="gvoRefresh" class="admin-button quiet" type="button">Refresh</button></div><div class="gvo-filter-grid"><label><span>Location</span><select id="gvoLocation"><option value="">All locations</option></select></label><label><span>Station</span><select id="gvoStation"><option value="">All stations</option></select></label><label><span>Health</span><select id="gvoHealth"><option value="">All health states</option><option>healthy</option><option>warning</option><option>critical</option><option>stale</option><option>offline</option><option>revoked</option></select></label><label><span>Model</span><input id="gvoModel" type="search" placeholder="Model / detector"></label><label><span>Device</span><input id="gvoDevice" type="search" placeholder="Device / hardware"></label></div></section>
<section class="gvo-grid">
 <section class="gvo-panel"><div class="gvo-panel-head"><div><div class="admin-eyebrow">Needs Attention</div><h2>Operational queue</h2></div><span id="gvoAttentionCount"></span></div><div id="gvoAttention" class="gvo-attention"></div></section>
 <section class="gvo-panel"><div class="gvo-panel-head"><div><div class="admin-eyebrow">Recent Activity</div><h2>Fleet health timeline</h2></div></div><div id="gvoTimeline" class="gvo-timeline"></div></section>
</section>
<section class="gvo-panel gvo-table-panel"><div class="gvo-panel-head"><div><div class="admin-eyebrow">Fleet</div><h2>Devices &amp; current vision state</h2></div><span id="gvoDeviceCount"></span></div><div class="gvo-table-wrap"><table><thead><tr><th>Device</th><th>Location / station</th><th>Health</th><th>Model / cohort</th><th>Calibration</th><th>Drift / recovery</th><th>Runtime</th><th>Last seen</th></tr></thead><tbody id="gvoRows"></tbody></table></div></section>
<section id="gvoDetails" class="gvo-panel gvo-details"><div class="gvo-empty">Select a fleet device for drill-down details.</div></section>
<section class="gvo-panel gvo-links"><div><div class="admin-eyebrow">Governed Actions</div><h2>Open the source workspace</h2></div><a class="admin-button dark" href="glasses-vision-models.php">Model rollout &amp; remediation</a><a class="admin-button" href="glasses-calibration-studio.php">Station calibration</a><a class="admin-button" href="glasses-learning.php">Vision learning</a><a class="admin-button" href="glasses-vision-profiles.php">Label profiles</a></section>
</main>
<script>window.GELATO_VISION_OPERATIONS=<?=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script>
<script src="assets/js/glasses-vision-operations.js?v=20260928-1"></script><script src="js/universal-admin-page-shell.js?v=20260915-2"></script>
</body></html>
