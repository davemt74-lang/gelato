<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/customer-account-core.php';
require_once __DIR__.'/includes/glasses-customer-photo-feedback.php';
app_boot_session();
$pdo=app_pdo();$context=public_site_context($pdo);
$org=(int)$context['organizationId'];$settings=$context['settings'];
$account=customer_account_require($pdo,$org);
$profile=customer_account_profile($pdo,$org,(int)$account['id']);
$customerId=(int)$profile['id'];
$order=trim((string)($_GET['order']??$_POST['order']??''));
if(strlen($order)>120)$order='';
$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!app_verify_csrf($_POST['csrf_token']??null)){
        http_response_code(419);$message='Your session expired. Refresh before submitting a review.';
    }else{
        try{
            if(($_POST['action']??'')!=='review_photo')throw new InvalidArgumentException('Invalid photo action.');
            $shareId=filter_var($_POST['share_id']??null,FILTER_VALIDATE_INT);
            $rating=filter_var($_POST['rating']??null,FILTER_VALIDATE_INT);
            if(!$shareId||!$rating)throw new InvalidArgumentException('Select a photo and 1–5-star rating.');
            glasses_customer_photo_feedback_save($pdo,$org,$customerId,$order,(int)$shareId,(int)$rating,
                (string)($_POST['comment']??''));
            app_redirect('customer-order-photos.php?order='.rawurlencode($order).'&saved=1');
        }catch(Throwable $e){
            error_log('[customer-photo-feedback] '.$e->getMessage());
            $message=$e instanceof InvalidArgumentException?$e->getMessage():'Photo review could not be saved.';
        }
    }
}
$photos=($order!=='')?glasses_customer_photo_visible($pdo,$org,$customerId,$order):[];
$reviews=glasses_customer_photo_feedback_list($pdo,$org,$customerId,$photos);
$groups=[];
foreach($photos as $p){
    $itemKey=(string)$p['kdsItemPublicId'];
    if(!isset($groups[$itemKey]))$groups[$itemKey]=['name'=>(string)$p['itemName'],'pre_oven'=>null,'post_oven'=>null];
    $stage=(string)$p['stage'];
    if($stage==='pre_oven'||$stage==='post_oven')$groups[$itemKey][$stage]=$p;
}
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>My Pizza Photos | <?=app_escape((string)$settings['restaurant_name'])?></title>
<link rel="stylesheet" href="assets/css/site.css?v=20260914-2">
<link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-2">
<link rel="stylesheet" href="assets/css/customer-photo-gallery.css?v=20261013-262">
</head><body class="customer-page">
<?php public_site_render_header($settings,''); ?>
<main class="customer-main"><div class="shell customer-dashboard"><section class="customer-content" style="width:100%">
<div class="customer-hero-panel"><div class="eyebrow">Your private order photos</div><h2>Before &amp; After · Pizza Gallery</h2>
<p>Only photos confirmed for your order, explicitly approved by the kitchen and shared with your permission are visible.</p>
<a class="customer-order-cta" href="customer-account.php#inbox">Back to Inbox</a></div>
<section class="customer-panel">
<div class="customer-panel-head"><div><h3>Approved production photos</h3>
<p class="photo-view-note">Photos can be viewed only while you remain opted in and restaurant photo sharing is enabled. Your feedback is private to the restaurant.</p></div></div>
<?php if(isset($_GET['saved'])):?><p class="photo-feedback-status" role="status">Your photo rating was saved.</p><?php endif;?>
<?php if($message!==''):?><p class="photo-feedback-error" role="alert"><?=app_escape($message)?></p><?php endif;?>
<?php if($groups):?>
<div class="photo-item-groups">
<?php foreach($groups as $item):?>
<article class="photo-item-pair"><h4><?=app_escape($item['name'])?></h4><div class="photo-pair-grid">
<?php foreach(['pre_oven'=>'Before Oven','post_oven'=>'After Oven'] as $stage=>$stageLabel):?>
<?php $photo=$item[$stage];?>
<div class="photo-stage-card">
<h5><?=app_escape($stageLabel)?></h5>
<?php if($photo):?>
<img loading="lazy" alt="<?=app_escape($stageLabel.' photo of '.$item['name'])?>"
 src="customer-order-photo.php?order=<?=rawurlencode($order)?>&amp;share=<?=(int)$photo['shareId']?>">
<div class="photo-stage-meta">Kitchen approved · <?=app_escape(substr((string)$photo['reviewedAt'],0,16))?></div>
<?php $old=$reviews[(int)$photo['shareId']]??null;?>
<form method="post" class="photo-review-form">
<input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>">
<input type="hidden" name="action" value="review_photo">
<input type="hidden" name="order" value="<?=app_escape($order)?>">
<input type="hidden" name="share_id" value="<?=(int)$photo['shareId']?>">
<label for="photoRating<?=(int)$photo['shareId']?>">How does this photo look?</label>
<select id="photoRating<?=(int)$photo['shareId']?>" name="rating" required>
<option value="">Choose a rating</option>
<?php for($rating=1;$rating<=5;$rating++):?>
<option value="<?=$rating?>" <?=$old&&$old['rating']===$rating?'selected':''?>><?=$rating?> / 5 stars</option>
<?php endfor;?>
</select>
<label for="photoNote<?=(int)$photo['shareId']?>">Optional feedback</label>
<textarea id="photoNote<?=(int)$photo['shareId']?>" name="comment" maxlength="500" rows="2"
 placeholder="Photo clarity, presentation, etc."><?=app_escape((string)($old['comment']??''))?></textarea>
<button type="submit"><?=$old?'Update rating':'Submit feedback'?></button>
</form>
<?php else:?><div class="photo-stage-empty">No approved <?=app_escape(strtolower($stageLabel))?> photo is available.</div><?php endif;?>
</div>
<?php endforeach;?></div></article>
<?php endforeach;?></div>
<?php else:?><div class="customer-empty">No approved photos are available for this order. Sharing may be off, the kitchen may not have approved a photo, or its retention period may have ended.</div><?php endif;?>
</section></section></div></main>
<?php public_site_render_footer($settings); ?>
</body></html>