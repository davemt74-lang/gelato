<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/public-site.php';
require_once __DIR__ . '/includes/location-core.php';

$context = public_site_fallback_context();
$pdo = null;
try {
    $pdo = app_pdo();
    $context = public_site_context($pdo);
} catch (Throwable $e) {
    error_log('Public locations load failed: ' . $e->getMessage());
}
$settings = $context['settings'];
$locations = $context['locations'];
$canonicalReady = false;

if ($pdo instanceof PDO && (int)($context['organizationId'] ?? 0) > 0) {
    try {
        $locations = location_list($pdo, (int)$context['organizationId'], false);
        $canonicalReady = true;
    } catch (Throwable $e) {
        error_log('Canonical public location load failed: ' . $e->getMessage());
    }
}

if (!$canonicalReady) {
    foreach ($locations as $index => &$location) {
        $location['public_slug'] = (string)($location['public_slug'] ?? ('location-' . (int)($location['id'] ?? $index + 1)));
        $location['status'] = 'active';
        $location['is_primary'] = $index === 0;
        $location['dine_in_enabled'] = true;
        $location['pickup_enabled'] = true;
        $location['delivery_enabled'] = false;
        $location['online_ordering_enabled'] = false;
        $location['hoursText'] = (string)$settings['hours_text'];
        $location['email'] = '';
    }
    unset($location);
}

$selectionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_location') {
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $selectionError = 'Your location-selection session expired. Refresh the page and try again.';
    } else {
        $slug = trim((string)($_POST['location_slug'] ?? ''));
        $match = null;
        foreach ($locations as $location) {
            if (($location['status'] ?? 'active') === 'active' && hash_equals((string)$location['public_slug'], $slug)) {
                $match = $location;
                break;
            }
        }
        if ($match) {
            location_set_public_selection($match);
            app_redirect('locations.php?selected=1');
        }
        $selectionError = 'That location is not currently available.';
    }
}

$selected = location_selected($locations);
$staffCanManage = false;
try {
    $current = app_current_user();
    $staffCanManage = $current !== null && app_has_permission('locations.manage', $current);
} catch (Throwable) {
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Locations | <?=app_escape((string)$settings['restaurant_name'])?></title><link rel="stylesheet" href="assets/css/site.css?v=20260914-2"><link rel="stylesheet" href="assets/css/page-headers.css?v=20260914-1"><style>
.location-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}.location-current{padding:12px 14px;border:1px solid rgba(255,255,255,.15);border-radius:12px;background:rgba(255,255,255,.04)}.location-current strong{display:block;color:#fff}.location-card.selected-location{outline:2px solid #fff;outline-offset:3px}.location-card .service-badges{display:flex;flex-wrap:wrap;gap:7px;margin:16px 0}.location-card .service-badge{display:inline-flex;padding:6px 9px;border:1px solid rgba(255,255,255,.17);border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.location-card .location-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:18px}.location-card .selected-chip{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}.location-select-button{border:0;cursor:pointer}.location-note{font-size:13px;opacity:.76}.location-admin-link{font-weight:800}.location-error{padding:12px 14px;border:1px solid #8f3731;border-radius:10px;margin-bottom:18px}.location-primary{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;margin-left:8px;opacity:.72}@media(max-width:700px){.location-toolbar{align-items:flex-start;flex-direction:column}}
</style></head><body class="page-locations"><?php public_site_render_header($settings,'locations');?><main class="page-main"><section class="page-hero"><div class="shell"><div class="eyebrow">Locations</div><h1>Find Stonefellows.</h1><p>Choose your Stonefellows location. This selection will carry into pickup, delivery and online ordering as those services come online.</p></div></section><section class="section"><div class="shell">
<?php if($selectionError):?><div class="location-error"><?=app_escape($selectionError)?></div><?php endif;?>
<div class="location-toolbar"><div><?php if($selected):?><div class="location-current"><span class="eyebrow">Current location</span><strong><?=app_escape((string)$selected['name'])?></strong></div><?php endif;?></div><?php if($staffCanManage):?><a class="btn btn-secondary location-admin-link" href="locations-admin.php">Manage Locations</a><?php endif;?></div>
<?php if($locations):?><div class="location-grid"><?php foreach($locations as $location):$addr=public_site_format_address($location);$isSelected=$selected&&(int)$selected['id']===(int)$location['id'];$hoursText=trim((string)($location['hoursText']??''))?:trim((string)$settings['hours_text']);?><article class="location-card<?=$isSelected?' selected-location':''?>"><div class="eyebrow">Location<?php if(!empty($location['is_primary'])):?><span class="location-primary">Primary</span><?php endif;?></div><h2><?=app_escape((string)$location['name'])?></h2><div class="service-badges"><?php foreach(location_service_labels($location) as $label):?><span class="service-badge"><?=app_escape($label)?></span><?php endforeach;?></div><ul class="detail-list"><?php if($addr!==''):?><li><strong>Address</strong><br><?=app_escape($addr)?></li><?php endif;?><?php if(!empty($location['phone'])):?><li><strong>Phone</strong><br><a class="contact-link" href="tel:<?=app_escape(preg_replace('/[^+0-9]/','',(string)$location['phone'])??'')?>"><?=app_escape((string)$location['phone'])?></a></li><?php endif;?><?php if(!empty($location['email'])):?><li><strong>Email</strong><br><a class="contact-link" href="mailto:<?=app_escape((string)$location['email'])?>"><?=app_escape((string)$location['email'])?></a></li><?php endif;?><?php if($hoursText!==''):?><li><strong>Hours</strong><br><?=nl2br(app_escape($hoursText))?></li><?php endif;?><?php if(!empty($location['delivery_enabled'])&&!empty($location['delivery_radius_miles'])):?><li><strong>Delivery area</strong><br>Up to <?=app_escape(rtrim(rtrim(number_format((float)$location['delivery_radius_miles'],2), '0'), '.'))?> miles<?php if($location['delivery_minimum']!==null&&$location['delivery_minimum']!==''):?> · $<?=number_format((float)$location['delivery_minimum'],2)?> minimum<?php endif;?><?php if($location['delivery_fee']!==null&&$location['delivery_fee']!==''):?> · $<?=number_format((float)$location['delivery_fee'],2)?> delivery fee<?php endif;?></li><?php endif;?></ul><div class="location-actions"><?php if($isSelected):?><span class="selected-chip">✓ Selected location</span><?php else:?><form method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="select_location"><input type="hidden" name="location_slug" value="<?=app_escape((string)$location['public_slug'])?>"><button class="btn btn-secondary location-select-button" type="submit">Use this location</button></form><?php endif;?></div></article><?php endforeach;?></div><?php else:$fallback=public_site_format_address($settings);?><article class="location-card"><div class="eyebrow">Primary Location</div><h2><?=app_escape((string)$settings['restaurant_name'])?></h2><p><?=$fallback!==''?app_escape($fallback):'Location details have not been published yet.'?></p><?php if($settings['hours_text']!==''):?><p><?=nl2br(app_escape((string)$settings['hours_text']))?></p><?php endif;?></article><?php endif;?></div></section></main><?php public_site_render_footer($settings);?><script src="assets/js/site.js?v=20260914"></script><script src="assets/js/public-shell.js?v=20260915-1"></script>
</body></html>
