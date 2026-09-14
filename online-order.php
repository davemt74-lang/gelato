<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/online-order-core.php';

app_boot_session();
$pdo=app_pdo();
$context=public_site_context($pdo);
$organizationId=(int)$context['organizationId'];
$settings=$context['settings'];
$account=customer_account_require($pdo,$organizationId);
$error=null;
$locations=online_order_locations($pdo,$organizationId);
$selected=null;

$requestedSlug=trim((string)($_GET['location']??''));
if($requestedSlug!==''){
    foreach($locations as $location){
        if(hash_equals((string)$location['public_slug'],$requestedSlug)){
            $selected=$location;
            location_set_public_selection($location);
            break;
        }
    }
}
if(!$selected) $selected=location_selected($locations);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!app_verify_csrf($_POST['csrf_token']??null)){
        $error='The checkout form expired. Refresh the page and try again.';
    }else{
        try{
            $cart=json_decode((string)($_POST['cart_json']??'[]'),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($cart)) throw new InvalidArgumentException('Your cart could not be read.');
            $result=online_order_submit_pickup($pdo,$organizationId,$account,[
                'locationId'=>(int)($_POST['location_id']??0),
                'idempotencyKey'=>$_POST['idempotency_key']??'',
                'items'=>$cart,
                'note'=>$_POST['order_note']??'',
            ]);
            app_redirect('customer-account.php?submitted=1&order='.rawurlencode((string)$result['public_id']).'#orders');
        }catch(JsonException){
            $error='Your cart could not be read. Refresh the page and try again.';
        }catch(Throwable $exception){
            $error=$exception instanceof InvalidArgumentException?$exception->getMessage():'The order could not be submitted. Please try again.';
            error_log('Online order failed: '.$exception->getMessage());
        }
    }
}

$menu=$selected?online_order_menu($pdo,$organizationId):[];
$initials=mb_strtoupper(mb_substr((string)($account['first_name']??''),0,1,'UTF-8').mb_substr((string)($account['last_name']??''),0,1,'UTF-8'),'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0b09"><meta name="robots" content="noindex,nofollow"><title>Order Online | <?=app_escape((string)$settings['restaurant_name'])?></title><link rel="stylesheet" href="assets/css/site.css?v=20260914-2"><link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-2"><link rel="stylesheet" href="assets/css/online-order.css?v=20260914-1"></head><body class="customer-page online-order-page">
<?php public_site_render_header($settings,'menu'); ?>
<main class="customer-main"><div class="shell order-shell"><section class="order-main"><div class="order-heading"><div><div class="eyebrow">Stonefellows Online Ordering</div><h1>Pickup, built into the restaurant.</h1><p>Choose a location, build your order from the current restaurant menu, and pay when you pick it up. Final pricing is revalidated by the restaurant server when you submit.</p></div><div class="order-user"><span class="customer-avatar"><?=app_escape($initials?:'SF')?></span><div><strong><?=app_escape((string)($account['display_name']??'Customer'))?></strong><a href="customer-account.php">My account</a></div></div></div>
<?php if($error):?><div class="customer-message bad order-error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$locations):?><div class="customer-panel"><div class="customer-empty">No Stonefellows location currently has online pickup enabled. Check back soon or use the Locations page for store details.</div></div><?php else:?>
<section class="order-location customer-panel"><div><span>Pickup location</span><strong><?=app_escape((string)$selected['name'])?></strong><small><?=app_escape(trim(implode(', ',array_filter([(string)($selected['city']??''),(string)($selected['state']??'')]))))?> · about <?=number_format((int)$selected['pickup_lead_minutes'])?> min</small></div><?php if(count($locations)>1):?><form method="get"><select name="location" aria-label="Pickup location" onchange="this.form.submit()"><?php foreach($locations as $location):?><option value="<?=app_escape((string)$location['public_slug'])?>" <?=((int)$location['id']===(int)$selected['id'])?'selected':''?>><?=app_escape((string)$location['name'])?></option><?php endforeach;?></select></form><?php endif;?></section>
<?php if($menu):?><div class="order-menu"><?php foreach($menu as $section):?><section class="order-section"><div class="order-section-head"><div class="eyebrow">Menu</div><h2><?=app_escape((string)$section['name'])?></h2></div><div class="order-items"><?php foreach($section['items'] as $item):?><article class="order-item"><div class="order-item-copy"><h3><?=app_escape((string)$item['name'])?></h3><?php if(trim((string)($item['description']??''))!==''):?><p><?=app_escape((string)$item['description'])?></p><?php endif;?></div><div class="order-options"><?php foreach($item['prices'] as $price):?><button type="button" class="order-add" data-price-id="<?=app_escape((string)$price['id'])?>" data-item-name="<?=app_escape((string)$item['name'])?>" data-option-name="<?=app_escape((string)$price['optionName'])?>" data-price="<?=app_escape(number_format((float)$price['amount'],2,'.',''))?>"><span><?=app_escape((string)$price['optionName'])?></span><strong>$<?=number_format((float)$price['amount'],2)?></strong><b>+</b></button><?php endforeach;?></div></article><?php endforeach;?></div></section><?php endforeach;?></div><?php else:?><div class="customer-empty">The active ordering menu is unavailable right now.</div><?php endif;?>
<?php endif;?></section>
<aside class="order-cart" aria-label="Order cart"><div class="order-cart-head"><div><span>Your order</span><strong><?=app_escape($selected?(string)$selected['name']:'Choose a location')?></strong></div><em id="cartCount">0 items</em></div><div id="cartLines" class="order-cart-lines"><div class="customer-empty">Your cart is empty.</div></div><div class="order-cart-totals"><span>Menu subtotal <strong id="cartSubtotal">$0.00</strong></span><small>Tax, service charges and the final total are calculated by the POS when you place the order.</small></div><form method="post" id="checkoutForm"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="location_id" value="<?=app_escape((string)($selected['id']??0))?>"><input type="hidden" name="idempotency_key" id="idempotencyKey" value=""><input type="hidden" name="cart_json" id="cartJson" value="[]"><label class="order-note"><span>Order note</span><textarea name="order_note" maxlength="1000" rows="3" placeholder="Pickup notes or special requests for the order"></textarea></label><div class="order-payment"><span>Payment</span><strong>Pay at pickup</strong><small>No card information is collected by this ordering pass.</small></div><button class="customer-submit" id="placeOrder" type="submit" disabled>Place pickup order</button></form><a class="order-account-link" href="customer-account.php#inbox">Open customer Inbox</a></aside></div></main>
<?php public_site_render_footer($settings); ?><script>window.STONEFELLOWS_ORDER={locationId:<?=json_encode((int)($selected['id']??0))?>};</script><script src="assets/js/online-order.js?v=20260914-1"></script><script src="assets/js/site.js?v=20260914"></script></body></html>
