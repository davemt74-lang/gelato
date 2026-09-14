<?php
declare(strict_types=1);
require_once __DIR__.'/includes/admin-control-core.php';

$user=app_require_auth();
if(!admin_online_orders_allowed($user)){
    http_response_code(403);
    exit('Online order operations permission required.');
}
$pdo=app_pdo();
$organizationId=(int)$user['organization_id'];
$locations=admin_location_options($pdo,$organizationId);
$locationId=max(0,(int)($_GET['location']??0));
$state=trim((string)($_GET['state']??''));
$query=mb_strtolower(trim((string)($_GET['q']??'')),'UTF-8');
$allowedStates=['','Submitted','In kitchen','Preparing','Ready','Kitchen complete','Completed','Cancelled'];
if(!in_array($state,$allowedStates,true))$state='';
$orders=admin_online_orders($pdo,$organizationId,$locationId?:null,200);
if($state!=='')$orders=array_values(array_filter($orders,static fn(array $row):bool=>(string)$row['displayStatus']===$state));
if($query!==''){
    $orders=array_values(array_filter($orders,static function(array $row)use($query):bool{
        $haystack=mb_strtolower(implode(' ',[(string)$row['check_number'],(string)$row['customer_name'],(string)($row['customer_email']??''),(string)$row['location_name'],(string)$row['displayStatus']]),'UTF-8');
        return str_contains($haystack,$query);
    }));
}
$totalValue=0.0;$open=0;$ready=0;$preparing=0;$completed=0;
foreach($orders as $row){
    if((string)$row['check_status']!=='cancelled')$totalValue+=(float)$row['total_amount'];
    if((string)$row['check_status']==='open')$open++;
    if((string)$row['displayStatus']==='Ready')$ready++;
    if(in_array((string)$row['displayStatus'],['Preparing','In kitchen'],true))$preparing++;
    if((string)$row['displayStatus']==='Completed')$completed++;
}
function ooa_status_class(string $status): string{return strtolower(str_replace(' ','-',trim($status)));}
function ooa_time(?string $value): string{if(!$value)return '—';$ts=strtotime($value);return $ts===false?$value:date('M j, g:i A',$ts);}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#f3f2ed"><title>Online Orders | Restaurant Admin</title><link rel="stylesheet" href="assets/css/admin-control.css?v=20260914-1"></head><body class="admin-control">
<header class="admin-top"><a class="admin-brand" href="admin.php"><span class="admin-logo">SF</span><span><strong>Online Orders</strong><small><?=app_escape((string)$user['organization_name'])?> commerce operations</small></span></a><nav class="admin-top-actions"><a class="admin-button hide-mobile" href="pos.php">POS</a><?php if(app_has_permission('kds.view',$user)):?><a class="admin-button hide-mobile" href="kds.php">Kitchen</a><?php endif;?><a class="admin-button dark" href="admin.php">Admin</a></nav></header>
<main class="admin-shell"><section class="admin-section" style="margin-top:0"><div class="admin-section-head"><div><div class="admin-eyebrow">Customer Commerce</div><h2 style="font-size:32px">Online order center</h2><p>Monitor web orders after they enter the canonical POS and kitchen workflow. Payment is currently collected at pickup.</p></div><a class="admin-button accent" href="online-order.php" target="_blank" rel="noopener">Preview customer ordering ↗</a></div>
<div class="admin-note">Online orders are not a separate restaurant ticket system. Every submitted order is attached to a CRM customer, creates a native POS check, and is sent through KDS. This screen is the management view across those systems.</div>
<form class="admin-orders-toolbar" method="get"><select name="location"><option value="0">All locations</option><?php foreach($locations as $location):?><option value="<?=(int)$location['id']?>" <?=$locationId===(int)$location['id']?'selected':''?>><?=app_escape((string)$location['name'])?></option><?php endforeach;?></select><select name="state"><?php foreach($allowedStates as $option):?><option value="<?=app_escape($option)?>" <?=$state===$option?'selected':''?>><?=app_escape($option===''?'All statuses':$option)?></option><?php endforeach;?></select><input type="search" name="q" value="<?=app_escape((string)($_GET['q']??''))?>" placeholder="Search order, customer, email or location"><button class="admin-button dark" type="submit">Filter</button><?php if($locationId||$state!==''||$query!==''):?><a class="admin-button" href="online-orders-admin.php">Clear</a><?php endif;?></form>
<div class="admin-order-summary"><article class="admin-kpi"><span>Displayed orders</span><strong><?=number_format(count($orders))?></strong></article><article class="admin-kpi"><span>Open</span><strong><?=number_format($open)?></strong></article><article class="admin-kpi attention"><span>Preparing / kitchen</span><strong><?=number_format($preparing)?></strong></article><article class="admin-kpi good"><span>Ready</span><strong><?=number_format($ready)?></strong></article><article class="admin-kpi"><span>Displayed value</span><strong>$<?=number_format($totalValue,2)?></strong></article></div>
<div class="admin-panel"><div class="admin-panel-head"><div><h3>Customer online orders</h3><p>Newest submissions first · up to 200 records before filters.</p></div><div><span class="admin-status completed"><?=number_format($completed)?> completed</span></div></div><?php if($orders):?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Location</th><th>Status</th><th>Submitted</th><th>Requested ready</th><th>Payment</th><th>Total</th></tr></thead><tbody><?php foreach($orders as $order):?><tr><td class="admin-order-name"><strong><?=app_escape((string)$order['check_number'])?></strong><small><?=app_escape((string)$order['order_public_id'])?></small></td><td class="admin-order-name"><strong><?=app_escape((string)$order['customer_name'])?></strong><small><?=app_escape((string)($order['customer_email']?:'No email'))?></small></td><td><?=app_escape((string)$order['location_name'])?></td><td><span class="admin-status <?=app_escape(ooa_status_class((string)$order['displayStatus']))?>"><?=app_escape((string)$order['displayStatus'])?></span></td><td><?=app_escape(ooa_time((string)$order['submitted_at']))?></td><td><?=app_escape(ooa_time($order['requested_ready_at']!==null?(string)$order['requested_ready_at']:null))?></td><td><?=app_escape(ucwords(str_replace('_',' ',(string)$order['payment_mode'])))?></td><td class="admin-money">$<?=number_format((float)$order['total_amount'],2)?></td></tr><?php endforeach;?></tbody></table></div><div class="admin-order-card-grid"><?php foreach($orders as $order):?><article class="admin-order-card"><div class="admin-order-card-head"><div><h3><?=app_escape((string)$order['check_number'])?></h3><p><?=app_escape((string)$order['customer_name'])?> · <?=app_escape((string)$order['location_name'])?></p></div><span class="admin-status <?=app_escape(ooa_status_class((string)$order['displayStatus']))?>"><?=app_escape((string)$order['displayStatus'])?></span></div><div class="admin-order-card-meta"><div><span>Submitted</span><strong><?=app_escape(ooa_time((string)$order['submitted_at']))?></strong></div><div><span>Ready</span><strong><?=app_escape(ooa_time($order['requested_ready_at']!==null?(string)$order['requested_ready_at']:null))?></strong></div><div><span>Payment</span><strong><?=app_escape(ucwords(str_replace('_',' ',(string)$order['payment_mode'])))?></strong></div><div><span>Total</span><strong>$<?=number_format((float)$order['total_amount'],2)?></strong></div></div><?php if($order['customer_note']):?><p><strong>Customer note:</strong> <?=app_escape((string)$order['customer_note'])?></p><?php endif;?></article><?php endforeach;?></div><?php else:?><div class="admin-empty">No online orders match these filters.</div><?php endif;?></div>
</section>
<section class="admin-section"><div class="admin-section-head"><div><div class="admin-eyebrow">Related Workspaces</div><h2>Act on the order</h2><p>Use the canonical system responsible for each operational action.</p></div></div><div class="admin-modules"><?php if(app_has_permission('pos.use',$user)):?><a class="admin-module" href="pos.php"><span class="admin-module-icon">▦</span><span><strong>POS</strong><p>Payments, check handling and pickup completion.</p></span></a><?php endif;?><?php if(app_has_permission('kds.view',$user)):?><a class="admin-module" href="kds.php"><span class="admin-module-icon">⌁</span><span><strong>Kitchen</strong><p>Preparation state, routing and ready status.</p></span></a><?php endif;?><?php if(app_has_permission('crm.view',$user)):?><a class="admin-module" href="customer-crm.php"><span class="admin-module-icon">◎</span><span><strong>Customer CRM</strong><p>Customer identity and relationship history.</p></span></a><?php endif;?><?php if(app_has_permission('locations.manage',$user)):?><a class="admin-module" href="locations-admin.php"><span class="admin-module-icon">⌖</span><span><strong>Locations</strong><p>Pickup, delivery and online-order settings.</p></span></a><?php endif;?></div></section>
</main></body></html>
