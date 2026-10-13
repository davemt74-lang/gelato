<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/customer-account-core.php';
require_once __DIR__.'/includes/glasses-customer-photo-notifications.php';
app_boot_session();
$pdo=app_pdo();$context=public_site_context($pdo);
$org=(int)$context['organizationId'];$settings=$context['settings'];
$account=customer_account_require($pdo,$org);
$profile=customer_account_profile($pdo,$org,(int)$account['id']);
$order=trim((string)($_GET['order']??''));
$photos=(strlen($order)<=120&&$order!=='')?glasses_customer_photo_visible($pdo,$org,(int)$profile['id'],$order):[];
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>My Pizza Photos | <?=app_escape((string)$settings['restaurant_name'])?></title>
<link rel="stylesheet" href="assets/css/site.css?v=20260914-2">
<link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-2">
<style>.photo-view-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin-top:20px}.photo-view-grid figure{margin:0;background:#171b15;border:1px solid #3f5841;border-radius:12px;overflow:hidden}.photo-view-grid img{display:block;width:100%;height:270px;object-fit:contain;background:#0c120d}.photo-view-grid figcaption{padding:14px;color:#d9efdb;font-size:14px;line-height:1.6}.photo-view-note{font-size:14px;line-height:1.65}</style></head><body class="customer-page">
<?php public_site_render_header($settings,''); ?>
<main class="customer-main"><div class="shell customer-dashboard"><section class="customer-content" style="width:100%">
<div class="customer-hero-panel"><div class="eyebrow">Your private order photos</div><h2>Finished pizza photos</h2>
<p>Only photos linked to your order, approved by the kitchen and shared with your permission appear here.</p>
<a class="customer-order-cta" href="customer-account.php#inbox">Back to Inbox</a></div>
<section class="customer-panel"><div class="customer-panel-head"><div><h3>Approved photos</h3><p class="photo-view-note">Photos are visible only while your photo preference and the restaurant's sharing setting are enabled.</p></div></div>
<?php if($photos):?><div class="photo-view-grid">
<?php foreach($photos as $photo):?>
<figure><img loading="lazy" alt="Approved photo of <?=app_escape((string)$photo['itemName'])?>" src="customer-order-photo.php?order=<?=rawurlencode($order)?>&amp;share=<?=(int)$photo['shareId']?>">
<figcaption><strong><?=app_escape((string)$photo['itemName'])?></strong><div>Kitchen-approved finished pizza · <?=app_escape(substr((string)$photo['reviewedAt'],0,16))?></div></figcaption></figure>
<?php endforeach;?></div>
<?php else:?><div class="customer-empty">No photos are available. They may not have been approved, sharing may be turned off, or the image may have expired. Your normal order history remains available.</div><?php endif;?>
</section></section></div></main>
<?php public_site_render_footer($settings); ?>
</body></html>