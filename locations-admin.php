<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/location-core.php';

$user = app_require_auth();
if (!app_has_permission('locations.manage', $user)) {
    http_response_code(403);
    exit('You do not have permission to manage locations.');
}
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$error = '';
$locations = [];
$migrationReady = true;

try {
    $locations = location_list($pdo, $organizationId, true);
} catch (Throwable $exception) {
    $migrationReady = false;
    $error = 'Location management needs the latest database upgrade. Run Upgrade once, then return here.';
    error_log('Location admin load failed: ' . $exception->getMessage());
}

if ($migrationReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session token expired. Reload the page and try again.');
        }
        $action = (string)($_POST['action'] ?? 'save');
        $id = max(0, (int)($_POST['id'] ?? 0));
        if ($action === 'save') {
            $payload = [
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'public_slug' => $_POST['public_slug'] ?? '',
                'address_line_1' => $_POST['address_line_1'] ?? '',
                'address_line_2' => $_POST['address_line_2'] ?? '',
                'city' => $_POST['city'] ?? '',
                'state' => $_POST['state'] ?? '',
                'postal_code' => $_POST['postal_code'] ?? '',
                'country_code' => $_POST['country_code'] ?? 'US',
                'phone' => $_POST['phone'] ?? '',
                'email' => $_POST['email'] ?? '',
                'timezone' => $_POST['timezone'] ?? '',
                'status' => $_POST['status'] ?? 'active',
                'sort_order' => $_POST['sort_order'] ?? 0,
                'is_primary' => isset($_POST['is_primary']),
                'dine_in_enabled' => isset($_POST['dine_in_enabled']),
                'pickup_enabled' => isset($_POST['pickup_enabled']),
                'delivery_enabled' => isset($_POST['delivery_enabled']),
                'online_ordering_enabled' => isset($_POST['online_ordering_enabled']),
                'delivery_radius_miles' => $_POST['delivery_radius_miles'] ?? '',
                'delivery_minimum' => $_POST['delivery_minimum'] ?? '',
                'delivery_fee' => $_POST['delivery_fee'] ?? '',
                'pickup_lead_minutes' => $_POST['pickup_lead_minutes'] ?? 20,
                'delivery_lead_minutes' => $_POST['delivery_lead_minutes'] ?? 45,
                'latitude' => $_POST['latitude'] ?? '',
                'longitude' => $_POST['longitude'] ?? '',
            ];
            if (isset($_POST['manage_hours'])) {
                $hours = [];
                foreach (location_day_names() as $day => $_name) {
                    $hours[$day] = [
                        'is_closed' => isset($_POST['hours'][$day]['is_closed']),
                        'opens_at' => $_POST['hours'][$day]['opens_at'] ?? '',
                        'closes_at' => $_POST['hours'][$day]['closes_at'] ?? '',
                    ];
                }
                $payload['hours'] = $hours;
            }
            $saved = location_save($pdo, $organizationId, (int)$user['id'], $payload);
            app_redirect('locations-admin.php?saved=1&edit=' . (int)$saved['id']);
        }
        if ($id < 1) throw new RuntimeException('Choose a location first.');
        if ($action === 'archive') location_archive($pdo,$organizationId,$id,(int)$user['id']);
        elseif ($action === 'restore') location_restore($pdo,$organizationId,$id,(int)$user['id']);
        elseif ($action === 'primary') location_set_primary($pdo,$organizationId,$id,(int)$user['id']);
        else throw new RuntimeException('Unknown location action.');
        app_redirect('locations-admin.php?saved=1');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        try { $locations = location_list($pdo, $organizationId, true); } catch (Throwable) {}
    }
}

$editId = max(0, (int)($_GET['edit'] ?? $_POST['id'] ?? 0));
$editing = $editId > 0 && $migrationReady ? location_get($pdo, $organizationId, $editId) : null;
$defaults = [
    'id'=>0,'name'=>'','public_slug'=>'','address_line_1'=>'','address_line_2'=>'','city'=>'','state'=>'','postal_code'=>'','country_code'=>'US',
    'phone'=>'','email'=>'','timezone'=>'America/Phoenix','status'=>'active','is_primary'=>!array_filter($locations,fn($l)=>$l['status']==='active'),
    'sort_order'=>0,'dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>false,
    'delivery_radius_miles'=>'','delivery_minimum'=>'','delivery_fee'=>'','pickup_lead_minutes'=>20,'delivery_lead_minutes'=>45,
    'latitude'=>'','longitude'=>'','hours'=>[],'hoursText'=>'',
];
$form = array_merge($defaults, $editing ?: []);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save' && $error !== '') {
    foreach (['name','public_slug','address_line_1','address_line_2','city','state','postal_code','country_code','phone','email','timezone','status','sort_order','delivery_radius_miles','delivery_minimum','delivery_fee','pickup_lead_minutes','delivery_lead_minutes','latitude','longitude'] as $key) {
        if (array_key_exists($key, $_POST)) $form[$key] = $_POST[$key];
    }
    foreach (['is_primary','dine_in_enabled','pickup_enabled','delivery_enabled','online_ordering_enabled'] as $key) $form[$key]=isset($_POST[$key]);
}
$hoursByDay = [];
foreach (($form['hours'] ?? []) as $hour) $hoursByDay[(int)$hour['day_of_week']] = $hour;
$manageHours = !empty($hoursByDay) || isset($_POST['manage_hours']);
$savedNotice = ($_GET['saved'] ?? '') === '1';
function la_checked(bool $value): string { return $value ? ' checked' : ''; }
function la_value(mixed $value): string { return app_escape((string)$value); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Locations | Restaurant Admin</title>
<style>
:root{--bg:#f4f3ef;--panel:#fff;--ink:#171815;--muted:#6b6e68;--line:#dddcd5;--accent:#d94a2b;--dark:#171b1a;--good:#157347;--bad:#a62a24}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 Inter,system-ui,sans-serif}.shell{width:min(1180px,calc(100vw - 32px));margin:auto}.top{padding:18px 0;background:#fff;border-bottom:1px solid var(--line)}.top .shell,.actions,.row-actions,.check-row{display:flex;align-items:center;gap:12px}.top .shell{justify-content:space-between}.top a,.btn{font-weight:800;text-decoration:none}.top a{color:var(--ink)}main{padding:38px 0 70px}.eyebrow{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.13em;color:var(--accent)}h1{font-size:clamp(34px,5vw,54px);margin:6px 0}.lead{color:var(--muted);max-width:760px}.notice,.error{padding:13px 15px;border-radius:12px;margin:18px 0}.notice{background:#eaf7ef;color:var(--good)}.error{background:#fff0ef;color:var(--bad)}.layout{display:grid;grid-template-columns:minmax(280px,.75fr) minmax(0,1.65fr);gap:22px;margin-top:28px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px}.location-list{display:grid;gap:12px}.location-item{padding:16px;border:1px solid var(--line);border-radius:14px;background:#fff}.location-item.primary{border-color:#171815}.location-item.archived{opacity:.66}.location-item h3{margin:4px 0}.meta,.hint{color:var(--muted);font-size:12px}.badges{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0}.badge{padding:4px 8px;border:1px solid var(--line);border-radius:999px;font-size:11px;font-weight:800}.badge.primary{background:#171815;color:#fff;border-color:#171815}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 13px;border:0;border-radius:9px;background:var(--dark);color:#fff;cursor:pointer}.btn.light{background:#fff;color:var(--ink);border:1px solid var(--line)}.btn.accent{background:var(--accent)}.btn.danger{background:#fff;color:var(--bad);border:1px solid #efc0bc}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field.full{grid-column:1/-1}.field label,.section-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}.field input,.field select{width:100%;padding:11px;border:1px solid var(--line);border-radius:9px;background:#fff}.panel-section{padding-top:20px;margin-top:20px;border-top:1px solid var(--line)}.checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.check-row{padding:10px 12px;border:1px solid var(--line);border-radius:10px}.hours{display:grid;gap:8px;margin-top:12px}.hour-row{display:grid;grid-template-columns:110px 90px 1fr 1fr;gap:10px;align-items:center}.hour-row input[type=time]{padding:9px;border:1px solid var(--line);border-radius:8px}.actions{margin-top:22px;flex-wrap:wrap}.row-actions{flex-wrap:wrap;margin-top:10px}.row-actions form{margin:0}.empty{padding:20px;border:1px dashed var(--line);border-radius:12px;color:var(--muted)}@media(max-width:860px){.layout{grid-template-columns:1fr}.fields,.checks{grid-template-columns:1fr}.field.full{grid-column:auto}.hour-row{grid-template-columns:1fr 80px 1fr 1fr}}
</style></head><body>
<header class="top"><div class="shell"><div><strong>Restaurant Admin</strong> · Locations</div><div><a href="locations.php" target="_blank" rel="noopener">View public locations ↗</a>&nbsp;&nbsp;<a href="public-site-settings.php">Public Site</a>&nbsp;&nbsp;<a href="workspace.php">Workspace</a></div></div></header>
<main><div class="shell"><div class="eyebrow">Store Network</div><h1>Locations</h1><p class="lead">Each location is a durable restaurant record used by staff assignments, POS, sales, equipment and the upcoming online-ordering flow. Archive locations instead of deleting them so historical records remain intact.</p>
<?php if($savedNotice):?><div class="notice">Location settings saved.</div><?php endif;?><?php if($error):?><div class="error"><?=app_escape($error)?></div><?php endif;?>
<?php if($migrationReady):?><div class="layout"><aside><div class="actions" style="margin:0 0 14px"><a class="btn accent" href="locations-admin.php">+ Add location</a></div><div class="location-list">
<?php if(!$locations):?><div class="empty">No locations yet. Add the first restaurant location.</div><?php endif;?>
<?php foreach($locations as $location):$address=trim(implode(', ',array_filter([(string)$location['address_line_1'],trim((string)$location['city'].' '.(string)$location['state'].' '.(string)$location['postal_code'])])));?>
<article class="location-item<?=!empty($location['is_primary'])?' primary':''?><?=$location['status']==='archived'?' archived':''?>"><div class="eyebrow"><?=app_escape($location['status'])?></div><h3><?=app_escape($location['name'])?></h3><div class="meta"><?=app_escape($address?:'Address not set')?></div><div class="badges"><?php if(!empty($location['is_primary'])):?><span class="badge primary">Primary</span><?php endif;?><?php foreach(location_service_labels($location) as $label):?><span class="badge"><?=app_escape($label)?></span><?php endforeach;?></div><div class="row-actions"><a class="btn light" href="locations-admin.php?edit=<?=(int)$location['id']?>">Edit</a><?php if($location['status']==='active'&&!$location['is_primary']):?><form method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="id" value="<?=(int)$location['id']?>"><button class="btn light">Make primary</button></form><?php endif;?><?php if($location['status']==='active'):?><form method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=(int)$location['id']?>"><button class="btn danger">Archive</button></form><?php else:?><form method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?=(int)$location['id']?>"><button class="btn light">Restore</button></form><?php endif;?></div></article>
<?php endforeach;?></div></aside>
<section class="card"><div class="eyebrow"><?=$editing?'Edit Location':'New Location'?></div><h2 style="margin-top:5px"><?=$editing?app_escape($editing['name']):'Add restaurant location'?></h2>
<form method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)$form['id']?>"><input type="hidden" name="status" value="<?=app_escape((string)$form['status'])?>">
<div class="fields"><div class="field"><label>Name</label><input name="name" maxlength="160" required value="<?=la_value($form['name'])?>"></div><div class="field"><label>Public slug</label><input name="public_slug" maxlength="180" placeholder="generated-from-name" value="<?=la_value($form['public_slug'])?>"></div><div class="field full"><label>Address</label><input name="address_line_1" maxlength="200" value="<?=la_value($form['address_line_1'])?>"></div><div class="field full"><label>Suite / Unit</label><input name="address_line_2" maxlength="200" value="<?=la_value($form['address_line_2'])?>"></div><div class="field"><label>City</label><input name="city" maxlength="100" value="<?=la_value($form['city'])?>"></div><div class="field"><label>State / Region</label><input name="state" maxlength="100" value="<?=la_value($form['state'])?>"></div><div class="field"><label>Postal code</label><input name="postal_code" maxlength="30" value="<?=la_value($form['postal_code'])?>"></div><div class="field"><label>Country code</label><input name="country_code" maxlength="2" value="<?=la_value($form['country_code'])?>"></div><div class="field"><label>Phone</label><input name="phone" maxlength="40" value="<?=la_value($form['phone'])?>"></div><div class="field"><label>Email</label><input name="email" type="email" maxlength="254" value="<?=la_value($form['email'])?>"></div><div class="field"><label>Timezone</label><input name="timezone" maxlength="64" value="<?=la_value($form['timezone'])?>"></div><div class="field"><label>Sort order</label><input name="sort_order" type="number" value="<?=la_value($form['sort_order'])?>"></div></div>
<div class="panel-section"><div class="section-label">Location status</div><div class="checks" style="margin-top:10px"><label class="check-row"><input type="checkbox" name="is_primary" value="1"<?=la_checked((bool)$form['is_primary'])?>> Primary location</label></div><p class="hint">The primary location is the default for public visitors until they choose another store.</p></div>
<div class="panel-section"><div class="section-label">Services</div><div class="checks" style="margin-top:10px"><label class="check-row"><input type="checkbox" name="dine_in_enabled" value="1"<?=la_checked((bool)$form['dine_in_enabled'])?>> Dine In</label><label class="check-row"><input type="checkbox" name="pickup_enabled" value="1"<?=la_checked((bool)$form['pickup_enabled'])?>> Pickup</label><label class="check-row"><input type="checkbox" name="delivery_enabled" value="1"<?=la_checked((bool)$form['delivery_enabled'])?>> Delivery</label><label class="check-row"><input type="checkbox" name="online_ordering_enabled" value="1"<?=la_checked((bool)$form['online_ordering_enabled'])?>> Online Ordering</label></div></div>
<div class="panel-section"><div class="section-label">Ordering + delivery</div><div class="fields" style="margin-top:12px"><div class="field"><label>Pickup lead minutes</label><input name="pickup_lead_minutes" type="number" min="0" max="1440" value="<?=la_value($form['pickup_lead_minutes'])?>"></div><div class="field"><label>Delivery lead minutes</label><input name="delivery_lead_minutes" type="number" min="0" max="1440" value="<?=la_value($form['delivery_lead_minutes'])?>"></div><div class="field"><label>Delivery radius (miles)</label><input name="delivery_radius_miles" type="number" min="0" max="200" step="0.01" value="<?=la_value($form['delivery_radius_miles'])?>"></div><div class="field"><label>Delivery minimum ($)</label><input name="delivery_minimum" type="number" min="0" step="0.01" value="<?=la_value($form['delivery_minimum'])?>"></div><div class="field"><label>Delivery fee ($)</label><input name="delivery_fee" type="number" min="0" step="0.01" value="<?=la_value($form['delivery_fee'])?>"></div></div></div>
<div class="panel-section"><label class="check-row" style="max-width:360px"><input type="checkbox" name="manage_hours" value="1"<?=la_checked($manageHours)?>> Configure weekly hours</label><p class="hint">Until location hours are configured, the public site can continue using the existing global hours as a fallback.</p><div class="hours"><?php foreach(location_day_names() as $day=>$name):$hour=$hoursByDay[$day]??['is_closed'=>1,'opens_at'=>'','closes_at'=>''];?><div class="hour-row"><strong><?=app_escape($name)?></strong><label><input type="checkbox" name="hours[<?=$day?>][is_closed]" value="1"<?=la_checked(!empty($hour['is_closed']))?>> Closed</label><input type="time" name="hours[<?=$day?>][opens_at]" value="<?=app_escape(substr((string)($hour['opens_at']??''),0,5))?>"><input type="time" name="hours[<?=$day?>][closes_at]" value="<?=app_escape(substr((string)($hour['closes_at']??''),0,5))?>"></div><?php endforeach;?></div></div>
<div class="panel-section"><div class="section-label">Map + routing coordinates</div><div class="fields" style="margin-top:12px"><div class="field"><label>Latitude</label><input name="latitude" type="number" min="-90" max="90" step="0.0000001" value="<?=la_value($form['latitude'])?>"></div><div class="field"><label>Longitude</label><input name="longitude" type="number" min="-180" max="180" step="0.0000001" value="<?=la_value($form['longitude'])?>"></div></div><p class="hint">Optional now; these coordinates will support delivery-zone and routing logic later.</p></div>
<div class="actions"><button class="btn accent" type="submit">Save Location</button><?php if($editing):?><a class="btn light" href="locations-admin.php">Add another</a><?php endif;?></div></form></section></div><?php endif;?></div></main><script src="js/universal-admin-page-shell.js?v=20260915-1"></script>
</body></html>
