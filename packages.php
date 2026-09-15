<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/package-deals-core.php';

$context=public_site_fallback_context();$pdo=null;$organizationId=0;$packages=[];$locations=[];$error=null;$account=null;
try{
    app_boot_session();$pdo=app_pdo();$context=public_site_context($pdo);$organizationId=(int)$context['organizationId'];
    if(package_deals_ready($pdo)){
        $packages=package_deal_public_list($pdo,$organizationId);$locations=online_order_locations($pdo,$organizationId);$account=customer_account_current($pdo,$organizationId);
    }
}catch(Throwable $e){error_log('Packages public page bootstrap failed: '.$e->getMessage());$error='Package ordering is temporarily unavailable.';}
$settings=$context['settings'];$selected=location_selected($locations);$requestedSlug=trim((string)($_GET['location']??''));
if($requestedSlug!==''){foreach($locations as $location)if(hash_equals((string)$location['public_slug'],$requestedSlug)){$selected=$location;location_set_public_selection($location);break;}}

if($_SERVER['REQUEST_METHOD']==='POST'&&$pdo instanceof PDO&&$organizationId>0){
    $packageSlug=trim((string)($_POST['package']??''));
    if(!is_array($account)){
        $return='packages.php?package='.rawurlencode($packageSlug);if($requestedSlug!=='')$return.='&location='.rawurlencode($requestedSlug);
        app_redirect('customer-signup.php?return='.rawurlencode($return));
    }
    if(!app_verify_csrf($_POST['csrf_token']??null))$error='The package checkout expired. Refresh the page and try again.';
    else{
        try{
            $selections=json_decode((string)($_POST['selections_json']??'{}'),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($selections))throw new InvalidArgumentException('Package selections could not be read.');
            $result=package_deal_submit_pickup($pdo,$organizationId,$account,[
                'package'=>$packageSlug,'locationId'=>(int)($_POST['location_id']??0),'idempotencyKey'=>$_POST['idempotency_key']??'','selections'=>$selections,'note'=>$_POST['order_note']??'',
            ]);
            app_redirect('customer-account.php?submitted=1&order='.rawurlencode((string)$result['public_id']).'#orders');
        }catch(JsonException){$error='Package selections could not be read. Refresh and try again.';}
        catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'The package order could not be submitted. Please try again.';error_log('Package order failed: '.$e->getMessage());}
    }
}

function package_page_price_range(array $package): array
{
    $min=0.0;$max=0.0;
    foreach($package['groups'] as $group){
        $prices=array_map(static fn(array $i):float=>(float)$i['amount'],array_filter($group['items'],static fn(array $i):bool=>!empty($i['available'])));
        if(!$prices)continue;
        $min+=min($prices)*(int)$group['requiredQuantity'];$max+=max($prices)*(int)$group['requiredQuantity'];
    }
    $discountMin=discount_calculate($min,(string)$package['discountMethod'],(float)$package['discountValue']);$discountMax=discount_calculate($max,(string)$package['discountMethod'],(float)$package['discountValue']);
    return ['minRetail'=>pos_money($min),'maxRetail'=>pos_money($max),'minDiscount'=>$discountMin,'maxDiscount'=>$discountMax,'minPrice'=>pos_money($min-$discountMin),'maxPrice'=>pos_money($max-$discountMax)];
}
$packagesForJs=[];foreach($packages as $package){$package['pricing']=package_page_price_range($package);$packagesForJs[]=$package;}
$packages=$packagesForJs;$address=public_site_format_address($settings);$openPackage=trim((string)($_GET['package']??''));
ob_start();public_site_render_header($settings,'');$packageHeader=(string)ob_get_clean();
$aboutLink='<a href="about.php">About</a>';$specialLink='<a class="active" href="packages.php">Specials</a>';
if(str_contains($packageHeader,$aboutLink))$packageHeader=str_replace($aboutLink,$specialLink.$aboutLink,$packageHeader);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#080907"><meta name="description" content="Stonefellows pickup-only family dinner package deals."><title>Family Dinner Specials | <?=app_escape((string)$settings['restaurant_name'])?></title><link rel="stylesheet" href="assets/css/site.css?v=20260914-2"><link rel="stylesheet" href="assets/css/packages.css?v=20260915-2"></head>
<body class="packages-page">
<?=$packageHeader?>
<main>
<section class="packages-hero"><img src="<?=app_escape(public_site_asset('packages-hero.webp'))?>" alt="Stonefellows family dinner package with wood-fired pizza, salads, garlic bread and drinks"><div class="packages-hero-shade"></div><div class="shell packages-hero-inner"><div class="packages-hero-copy"><span class="packages-kicker">Good pizza brings people together</span><h1>Family Dinner<br>Specials</h1><p>Bundle your favorites and save on family-style takeout meals.</p><div class="pickup-note"><i></i><strong>Takeout Only · No Delivery</strong></div><div class="packages-hero-actions"><a class="package-btn gold" href="#packages">View Packages</a><a class="package-btn outline" href="online-order.php">Order From Menu</a></div></div></div></section>
<section class="packages-grid-section" id="packages"><div class="shell">
  <div class="packages-section-head"><span>Pickup packages</span><h2>Choose Your Family Meal</h2><p>Every package is built from the current Stonefellows menu and prepared through the same kitchen system as a regular pickup order.</p></div>
  <?php if(count($locations)>1):?><form class="package-location-select" method="get"><label><span>Pickup location</span><select name="location" onchange="this.form.submit()"><?php foreach($locations as $location):?><option value="<?=app_escape((string)$location['public_slug'])?>" <?=((int)$location['id']===(int)($selected['id']??0))?'selected':''?>><?=app_escape((string)$location['name'])?></option><?php endforeach;?></select></label></form><?php endif;?>
  <?php if(!$selected&&$packages):?><div class="packages-message bad">No Stonefellows location currently has online pickup enabled for package ordering.</div><?php endif;?>
  <?php if($error):?><div class="packages-message bad"><?=app_escape($error)?></div><?php endif;?>
  <?php if(!$packages):?><div class="packages-empty"><strong>New package deals are coming soon.</strong><span>Check the regular menu for current pickup ordering.</span><a class="package-btn gold" href="online-order.php">Order Pickup</a></div><?php else:?><div class="deal-grid">
  <?php foreach($packages as $package):$pricing=$package['pricing'];$exact=abs($pricing['minPrice']-$pricing['maxPrice'])<0.01;$retailExact=abs($pricing['minRetail']-$pricing['maxRetail'])<0.01; ?>
  <article class="deal-card <?=!empty($package['featured'])?'featured':''?>">
    <?php if(!empty($package['featured'])):?><span class="featured-flag">Featured</span><?php endif;?>
    <span class="deal-eyebrow"><?=app_escape($package['eyebrow']?:'Family takeout')?></span><h3><?=app_escape((string)$package['name'])?></h3>
    <?php if($package['description']!==''):?><p class="deal-description"><?=app_escape((string)$package['description'])?></p><?php endif;?>
    <div class="deal-rule"><i></i></div><ul class="deal-includes"><?php foreach($package['groups'] as $group):?><li><strong><?=number_format((int)$group['requiredQuantity'])?></strong> <?=app_escape((string)$group['label'])?></li><?php endforeach;?></ul>
    <div class="deal-pricing"><div><span>Retail</span><strong><?=app_escape(($retailExact?'$':'From $').number_format($pricing['minRetail'],2))?></strong></div><div><span>Save</span><strong><?=$package['discountMethod']==='percent'?app_escape(number_format((float)$package['discountValue'],0).'%'):app_escape('$'.number_format((float)$package['discountValue'],2))?></strong></div><div class="special"><span>Special Price</span><strong><?=app_escape(($exact?'$':'From $').number_format($pricing['minPrice'],2))?></strong></div></div>
    <button class="deal-order" type="button" data-order-package="<?=app_escape((string)$package['slug'])?>" <?=!$selected?'disabled':''?>>Order This Package</button>
  </article>
  <?php endforeach;?></div><?php endif;?>
</div></section>
<section class="package-benefits"><div class="shell"><div class="benefit-head"><span>Good food creates great memories</span><h2>Why Families Love It</h2></div><div class="benefit-grid"><article><b>◎</b><h3>Easy Group Ordering</h3><p>Feed the whole family with one simple pickup order.</p></article><article><b>△</b><h3>Fresh Wood-Fired Pizza</h3><p>Choose directly from the current restaurant menu.</p></article><article><b>▢</b><h3>Perfect for Takeout</h3><p>Packages are pickup-only, built for an easy meal at home.</p></article><article><b>◇</b><h3>Add Gelato for Dessert</h3><p>Packages can include dessert selections when the deal offers them.</p></article></div></div></section>
<section class="package-visit"><div class="package-visit-shade"></div><div class="shell visit-inner"><div><span>Visit us or order today</span><h2><?=app_escape((string)$settings['restaurant_name'])?> Pizzeria | Bar</h2><?php if($address!==''):?><p>⌖ <?=app_escape($address)?></p><?php endif;?><?php if($settings['phone']!==''):?><p>☎ <?=app_escape((string)$settings['phone'])?></p><?php endif;?><p>Pickup Only · No Delivery</p></div><a class="package-btn gold" href="online-order.php">Order Regular Menu</a></div></section>
</main>
<div class="package-order-backdrop" id="packageBackdrop" hidden></div><aside class="package-order-drawer" id="packageDrawer" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Configure package"><div class="drawer-head"><div><span>Build your package</span><h2 id="drawerTitle">Package</h2><p id="drawerSubtitle">Pickup only</p></div><button type="button" id="drawerClose" aria-label="Close">×</button></div><div class="drawer-body"><div id="drawerGroups"></div></div><div class="drawer-footer"><div class="drawer-totals"><span>Retail <strong id="drawerRetail">$0.00</strong></span><span>Package savings <strong id="drawerSavings">$0.00</strong></span><span class="drawer-total">Package total <strong id="drawerTotal">$0.00</strong></span><small>Tax and final total are recalculated by the restaurant POS at checkout.</small></div><form method="post" id="packageCheckout"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="package" id="packageSlug"><input type="hidden" name="location_id" value="<?=app_escape((string)($selected['id']??0))?>"><input type="hidden" name="idempotency_key" id="packageIdempotency"><input type="hidden" name="selections_json" id="packageSelections" value="{}"><label class="package-order-note"><span>Order note</span><textarea name="order_note" maxlength="800" rows="2" placeholder="Pickup notes or special requests"></textarea></label><button type="submit" class="deal-order checkout" id="packageSubmit" disabled><?=is_array($account)?'Place Pickup Order':'Continue to Checkout'?></button></form></div></aside>
<?php public_site_render_footer($settings); ?>
<script>window.STONEFELLOWS_PACKAGES={packages:<?=json_encode($packages,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG)?>,openPackage:<?=json_encode($openPackage)?>,locationId:<?=json_encode((int)($selected['id']??0))?>,authenticated:<?=json_encode(is_array($account))?>};</script><script src="assets/js/packages.js?v=20260915-1"></script><script src="assets/js/site.js?v=20260914"></script><script src="assets/js/public-shell.js?v=20260915-1"></script>
</body></html>
