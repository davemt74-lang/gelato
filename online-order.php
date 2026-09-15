<?php
declare(strict_types=1);

$settings=['restaurant_name'=>'Stonefellows'];
$organizationId=0;
$pdo=null;
$bootstrapError=null;
$locations=[];

try{
    require_once __DIR__.'/includes/public-site.php';
    require_once __DIR__.'/includes/online-order-core.php';

    app_boot_session();
    if(function_exists('public_site_fallback_context')){
        $context=public_site_fallback_context();
        if(isset($context['settings']) && is_array($context['settings'])) $settings=$context['settings'];
    }elseif(function_exists('public_site_defaults')){
        $settings=public_site_defaults();
    }

    $pdo=app_pdo();
    try{
        $context=public_site_context($pdo);
        $settings=$context['settings'];
        $organizationId=(int)$context['organizationId'];
    }catch(Throwable $exception){
        $bootstrapError='Online ordering is temporarily unavailable.';
        error_log('Online ordering bootstrap failed: '.$exception->getMessage());
    }

    if($bootstrapError===null){
        $runtimeReady=false;
        try{
            $runtimeReady=$organizationId>0 && customer_account_ready($pdo) && online_order_ready($pdo);
        }catch(Throwable $exception){
            error_log('Online ordering readiness check failed: '.$exception->getMessage());
        }
        if(!$runtimeReady){
            $bootstrapError='Online ordering is temporarily unavailable.';
            error_log('Online ordering bootstrap failed: required production schema is not installed. Run Gelato Upgrade.');
        }
    }

    if($bootstrapError===null){
        try{
            $locations=online_order_locations($pdo,$organizationId);
        }catch(Throwable $exception){
            $bootstrapError='Online ordering is temporarily unavailable.';
            error_log('Online ordering location bootstrap failed: '.$exception->getMessage());
        }
    }
}catch(Throwable $exception){
    $bootstrapError='Online ordering is temporarily unavailable.';
    error_log('Online ordering bootstrap failed before runtime readiness: '.$exception->getMessage());
}

$account=null;
if($bootstrapError===null && $pdo instanceof PDO && $organizationId>0){
    try{
        $account=customer_account_current($pdo,$organizationId);
    }catch(Throwable $exception){
        error_log('Online ordering customer session lookup failed: '.$exception->getMessage());
    }
}

if($bootstrapError!==null || !$pdo instanceof PDO || $organizationId<1){
    http_response_code(503);
    $escape=static function(mixed $value): string {
        if(function_exists('app_escape')) return app_escape((string)$value);
        return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    };
    $renderHeader=static function(array $settings): void {
        if(function_exists('public_site_render_header')){
            try{
                public_site_render_header($settings,'menu');
                return;
            }catch(Throwable $exception){
                error_log('Online ordering fallback header failed: '.$exception->getMessage());
            }
        }
        echo '<header class="site-header inner"><div class="shell nav"><a class="brand" href="index.php"><strong>Stonefellows</strong><span>Pizzeria + Bar</span></a><nav class="nav-links" aria-label="Primary navigation"><a href="index.php">Home</a><a class="active" href="menu.php">Menu</a><a href="locations.php">Locations</a></nav></div></header>';
    };
    $renderFooter=static function(array $settings): void {
        if(function_exists('public_site_render_footer')){
            try{
                public_site_render_footer($settings);
                return;
            }catch(Throwable $exception){
                error_log('Online ordering fallback footer failed: '.$exception->getMessage());
            }
        }
        echo '<footer><div class="shell"><div class="footer-brand"><strong>Stonefellows</strong><span>Pizzeria + Bar</span></div></div></footer>';
    };
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0b09">
<meta name="robots" content="noindex,nofollow">
<title>Order Online | <?=$escape((string)($settings['restaurant_name']??'Stonefellows'))?></title>
<link rel="stylesheet" href="assets/css/site.css?v=20260914-2">
<style>
.order-unavailable{min-height:72vh;display:grid;place-items:center;padding:130px 0 80px}.order-unavailable-card{width:min(720px,100%);padding:42px;border:1px solid var(--line);background:var(--panel);box-shadow:var(--shadow)}.order-unavailable-card h1{font-size:clamp(2.4rem,6vw,4.4rem);margin:10px 0 18px}.order-unavailable-card p{max-width:620px;color:var(--muted)}.order-unavailable-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:26px}
</style>
</head>
<body>
<?php $renderHeader($settings); ?>
<main class="order-unavailable"><div class="shell"><section class="order-unavailable-card"><div class="eyebrow">Stonefellows Online Ordering</div><h1>Online ordering is temporarily unavailable.</h1><p>The ordering system is being prepared for service. The restaurant website and menu are still available.</p><div class="order-unavailable-actions"><a class="btn btn-primary" href="menu.php">View Menu</a><a class="btn btn-secondary" href="locations.php">Locations</a><a class="btn btn-secondary" href="index.php">Back Home</a></div></section></div></main>
<?php $renderFooter($settings); ?>
<script src="assets/js/site.js?v=20260914"></script>
<script src="assets/js/public-shell.js?v=20260915-1"></script>
</body>
</html>
<?php
    exit;
}

$error=null;
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
    if(!is_array($account)){
        $checkoutReturn='online-order.php?checkout=1';
        if($requestedSlug!=='') $checkoutReturn.='&location='.rawurlencode($requestedSlug);
        app_redirect('customer-signup.php?return='.rawurlencode($checkoutReturn));
    }
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

$menu=[];
if($selected){
    try{
        $menu=online_order_menu($pdo,$organizationId);
    }catch(Throwable $exception){
        $error=$error?:'The ordering menu is temporarily unavailable. Please try again shortly.';
        error_log('Online ordering menu failed: '.$exception->getMessage());
    }
}
$initials=is_array($account)?mb_strtoupper(mb_substr((string)($account['first_name']??''),0,1,'UTF-8').mb_substr((string)($account['last_name']??''),0,1,'UTF-8'),'UTF-8'):'';
$checkoutReturn='online-order.php?checkout=1'.($requestedSlug!==''?'&location='.rawurlencode($requestedSlug):'');
$signupUrl='customer-signup.php?return='.rawurlencode($checkoutReturn);
$loginUrl='customer-login.php?return='.rawurlencode($checkoutReturn);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0b09"><meta name="robots" content="noindex,nofollow"><title>Order Online | <?=app_escape((string)$settings['restaurant_name'])?></title><link rel="stylesheet" href="assets/css/site.css?v=20260914-2"><link rel="stylesheet" href="assets/css/customer-account.css?v=20260914-2"><link rel="stylesheet" href="assets/css/online-order.css?v=20260915-1"></head><body class="customer-page online-order-page">
<?php public_site_render_header($settings,'menu'); ?>
<main class="customer-main"><div class="shell order-shell"><section class="order-main"><div class="order-heading"><div><div class="eyebrow">Stonefellows Online Ordering</div><h1>Pickup, built into the restaurant.</h1><p>Choose a location, build your order from the current restaurant menu, and pay when you pick it up. Final pricing is revalidated by the restaurant server when you submit.</p></div><?php if(is_array($account)):?><div class="order-user"><span class="customer-avatar"><?=app_escape($initials?:'SF')?></span><div><strong><?=app_escape((string)($account['display_name']??'Customer'))?></strong><a href="customer-account.php">My account</a></div></div><?php else:?><div class="order-user order-guest"><span class="customer-avatar">SF</span><div><strong>Guest order</strong><span>Account at checkout</span><a href="<?=app_escape($loginUrl)?>">Sign in</a></div></div><?php endif;?></div>
<?php if($error):?><div class="customer-message bad order-error"><?=app_escape($error)?></div><?php endif;?>
<?php if(!$locations):?><div class="customer-panel"><div class="customer-empty">No Stonefellows location currently has online pickup enabled. Check back soon or use the Locations page for store details.</div></div><?php else:?>
<section class="order-location customer-panel"><div><span>Pickup location</span><strong><?=app_escape((string)$selected['name'])?></strong><small><?=app_escape(trim(implode(', ',array_filter([(string)($selected['city']??''),(string)($selected['state']??'')]))))?> · about <?=number_format((int)$selected['pickup_lead_minutes'])?> min</small></div><?php if(count($locations)>1):?><form method="get"><select name="location" aria-label="Pickup location" onchange="this.form.submit()"><?php foreach($locations as $location):?><option value="<?=app_escape((string)$location['public_slug'])?>" <?=((int)$location['id']===(int)$selected['id'])?'selected':''?>><?=app_escape((string)$location['name'])?></option><?php endforeach;?></select></form><?php endif;?></section>
<?php if($menu):?><div class="order-menu"><?php foreach($menu as $section):?><section class="order-section"><div class="order-section-head"><div class="eyebrow">Menu</div><h2><?=app_escape((string)$section['name'])?></h2></div><div class="order-items"><?php foreach($section['items'] as $item):?><article class="order-item"><div class="order-item-copy"><h3><?=app_escape((string)$item['name'])?></h3><?php if(trim((string)($item['description']??''))!==''):?><p><?=app_escape((string)$item['description'])?></p><?php endif;?></div><div class="order-options"><?php foreach($item['prices'] as $price):?><button type="button" class="order-add" data-price-id="<?=app_escape((string)$price['id'])?>" data-item-name="<?=app_escape((string)$item['name'])?>" data-option-name="<?=app_escape((string)$price['optionName'])?>" data-price="<?=app_escape(number_format((float)$price['amount'],2,'.',''))?>"><span><?=app_escape((string)$price['optionName'])?></span><strong>$<?=number_format((float)$price['amount'],2)?></strong><b>+</b></button><?php endforeach;?></div></article><?php endforeach;?></div></section><?php endforeach;?></div><?php else:?><div class="customer-empty">The active ordering menu is unavailable right now.</div><?php endif;?>
<?php endif;?></section></div></main>
<div class="order-cart-backdrop" id="orderCartBackdrop" aria-hidden="true"></div>
<aside class="order-cart" id="orderCartDrawer" role="dialog" aria-modal="true" aria-label="Your order" aria-hidden="true"><div class="order-cart-head"><div><span>Your order</span><strong><?=app_escape($selected?(string)$selected['name']:'Choose a location')?></strong><em id="cartCount">0 items</em></div><button class="order-cart-close" id="orderCartClose" type="button" aria-label="Close cart">×</button></div><div id="cartLines" class="order-cart-lines"><div class="customer-empty">Your cart is empty.</div></div><div class="order-cart-totals"><span>Menu subtotal <strong id="cartSubtotal">$0.00</strong></span><small>Tax, service charges and the final total are calculated by the POS when you place the order.</small></div><form method="post" id="checkoutForm"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="location_id" value="<?=app_escape((string)($selected['id']??0))?>"><input type="hidden" name="idempotency_key" id="idempotencyKey" value=""><input type="hidden" name="cart_json" id="cartJson" value="[]"><label class="order-note"><span>Order note</span><textarea name="order_note" maxlength="1000" rows="3" placeholder="Pickup notes or special requests for the order"></textarea></label><div class="order-payment"><span>Payment</span><strong>Pay at pickup <em>Active</em></strong><small>No card information is collected. Payment is due when you pick up your order.</small></div><button class="customer-submit" id="placeOrder" type="submit" disabled><?=is_array($account)?'Place pickup order':'Continue to checkout'?></button></form><?php if(is_array($account)):?><a class="order-account-link" href="customer-account.php#inbox">Open customer Inbox</a><?php else:?><a class="order-account-link" href="<?=app_escape($loginUrl)?>">Already have an account? Sign in</a><?php endif;?></aside>
<?php public_site_render_footer($settings); ?><script>window.STONEFELLOWS_ORDER={locationId:<?=json_encode((int)($selected['id']??0))?>,authenticated:<?=json_encode(is_array($account))?>,signupUrl:<?=json_encode($signupUrl,JSON_UNESCAPED_SLASHES)?>,loginUrl:<?=json_encode($loginUrl,JSON_UNESCAPED_SLASHES)?>};</script><script src="assets/js/online-order.js?v=20260915-2"></script><script src="assets/js/site.js?v=20260914"></script></body></html>
