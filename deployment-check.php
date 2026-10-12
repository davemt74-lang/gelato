<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/admin-shell-core.php';
$user=app_require_auth();
if ((int)($user['is_owner_role']??0)!==1) {http_response_code(403);exit('Owner / Super Admin access required.');}
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
$shaFile=__DIR__.'/DEPLOY_SHA.txt';
$sha=is_file($shaFile)?trim((string)file_get_contents($shaFile)):'';
$routes=[
 'Floor Planner 2.0'=>'floor-planner-v2.php',
 'Floor Planner core'=>'floor-planner-ops-legacy.php',
 'Floor Planner JavaScript'=>'js/floor-planner-v2.js',
 'Floor Planner API'=>'api/floor-plans.php',
 'Native POS'=>'pos.php',
 'Kitchen Display'=>'kds.php',
 'Equipment Catalog'=>'equipment.php',
 'Customer CRM'=>'customer-crm.php',
 'Vision Lab'=>'glasses-vision-lab.php',
 'Upgrade'=>'upgrade.php',
 'Admin navigation core'=>'includes/admin-shell-core.php'];
$files=[];
foreach($routes as $label=>$path) $files[]=['label'=>$label,'path'=>$path,'exists'=>is_file(__DIR__.'/'.$path)];
$tables=[];
try {
 $stmt=app_pdo()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
 foreach(['floor_plans','equipment_assets','roles','user_roles','online_orders'] as $table) {
  $stmt->execute([$table]);$tables[$table]=(int)$stmt->fetchColumn()===1;
 }
} catch(Throwable) {$tables['Database']=false;}
$permissions=[
 'Unrestricted owner'=>app_has_permission('floorplans.edit',$user)&&app_has_permission('glasses.view',$user),
 'Floor Planner page'=>admin_route_access_allowed($user,'floor-planner-v2.php'),
 'POS page'=>admin_route_access_allowed($user,'pos.php'),
 'KDS page'=>admin_route_access_allowed($user,'kds.php'),
 'Vision Lab page'=>admin_route_access_allowed($user,'glasses-vision-lab.php')];
$nav=admin_shell_filtered_navigation($user);
$navCount=array_sum(array_map(static fn(array $group):int=>count($group['items']),$nav));
$esc=static fn(string $s):string=>app_escape($s);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Gelato Deployment Check</title><style>
body{font:15px/1.55 system-ui,sans-serif;background:#f4f5f3;color:#1b2520;margin:0;padding:30px 16px}main{max-width:900px;margin:auto;background:white;padding:25px;border:1px solid #ddd;border-radius:14px}h1{margin-top:0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.cell{border:1px solid #ddd;border-radius:9px;padding:12px}small{color:#616e65}strong{display:block;overflow-wrap:anywhere}.ok{color:#137646}.bad{color:#b32626}table{border-collapse:collapse;width:100%;margin-top:12px}td,th{text-align:left;border-bottom:1px solid #ddd;padding:8px}a{color:#246544}
</style></head><body><main><h1>Gelato Deployment & Permissions Check</h1><p>Reads the active website, not GitHub. Only a signed-in owner can view it.</p><div class="grid">
<div class="cell"><small>Deployed commit</small><strong><?= $esc($sha?:'Missing DEPLOY_SHA.txt') ?></strong></div>
<div class="cell"><small>PHP bootstrap fingerprint</small><strong><?= $esc(substr(hash_file('sha256',__DIR__.'/includes/bootstrap.php')?:'',0,16)) ?></strong></div>
<div class="cell"><small>Current role</small><strong><?= $esc((string)($user['role_name']??'Unknown')) ?> (<?= $esc((string)($user['role_slug']??'')) ?>)</strong></div>
<div class="cell"><small>Owner authorization</small><strong><?= (int)($user['is_owner_role']??0)===1?'YES':'NO' ?></strong></div>
<div class="cell"><small>Menu links available to server</small><strong><?= $navCount ?></strong></div>
<div class="cell"><small>Website directory</small><strong><?= $esc(__DIR__) ?></strong></div>
<div class="cell"><small>Web document root</small><strong><?= $esc((string)($_SERVER['DOCUMENT_ROOT']??'')) ?></strong></div></div>
<h2>Application files</h2><table><thead><tr><th>Module</th><th>File</th><th>Found</th></tr></thead><tbody>
<?php foreach($files as $item): ?><tr><td><?= $esc($item['label']) ?></td><td><?= $esc($item['path']) ?></td><td class="<?= $item['exists']?'ok':'bad' ?>"><?= $item['exists']?'YES':'MISSING' ?></td></tr><?php endforeach; ?></tbody></table>
<h2>Database schema</h2><div class="grid"><?php foreach($tables as $table=>$installed): ?><div class="cell"><small><?= $esc((string)$table) ?></small><strong class="<?= $installed?'ok':'bad' ?>"><?= $installed?'Present':'Missing / unavailable' ?></strong></div><?php endforeach; ?></div>
<h2>Live route authorization</h2><div class="grid"><?php foreach($permissions as $label=>$granted): ?><div class="cell"><small><?= $esc((string)$label) ?></small><strong class="<?= $granted?'ok':'bad' ?>"><?= $granted?'ALLOWED':'DENIED' ?></strong></div><?php endforeach; ?></div>
<p><a href="admin.php">Admin</a> · <a href="workspace.php">Workspace</a> · <a href="floor-planner-v2.php">Floor Planner</a></p></main></body></html>
