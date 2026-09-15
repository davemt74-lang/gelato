<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/discount-core.php';
require_once __DIR__.'/includes/operational-access.php';

$user=app_require_permission('discounts.view');
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$userId=(int)$user['id'];
$canManage=app_has_permission('discounts.manage',$user);
$message=null;$error=null;

function discount_admin_allowed_check(PDO $pdo,array $user,string $permission,string $checkPublicId): array
{
    $check=pos_check_base($pdo,(int)$user['organization_id'],$checkPublicId,false);
    if(!operational_location_allowed($pdo,$user,$permission,(int)$check['location_id'])) throw new RuntimeException('That check is outside your assigned discount location scope.');
    return $check;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$canManage){http_response_code(403);exit('You do not have permission to manage discounts.');}
    if(!app_verify_csrf($_POST['csrf_token']??null))$error='The request expired. Refresh the page and try again.';
    else{
        try{
            $action=(string)($_POST['action']??'apply');
            if($action==='apply'){
                $checkPublic=trim((string)($_POST['check_public_id']??''));
                discount_admin_allowed_check($pdo,$user,'discounts.manage',$checkPublic);
                $type=(string)($_POST['discount_type']??'');
                if(!isset(discount_manual_types()[$type])) throw new InvalidArgumentException('Choose a manual discount type. Package Deal discounts are created by the Package builder.');
                $result=discount_apply($pdo,$org,$checkPublic,$type,(string)($_POST['method']??'fixed'),(float)($_POST['value']??0),(string)($_POST['reason']??''),$userId,'manual',sales_public_id('manual-discount'));
                app_audit($pdo,$org,$userId,'discount.applied','pos_discount',(string)$result['discount']['public_id'],null,['checkPublicId'=>$checkPublic,'type'=>$type,'amount'=>(float)$result['discount']['amount'],'reason'=>(string)$result['discount']['reason']]);
                $message='Discount applied to check '.(string)$result['check']['checkNumber'].'.';
            }elseif($action==='void'){
                $discountPublic=trim((string)($_POST['discount_public_id']??''));
                $q=$pdo->prepare('SELECT c.public_id FROM pos_discounts d JOIN pos_checks c ON c.id=d.pos_check_id AND c.organization_id=d.organization_id WHERE d.organization_id=? AND d.public_id=? LIMIT 1');
                $q->execute([$org,$discountPublic]);$checkPublic=(string)($q->fetchColumn()?:'');
                if($checkPublic==='') throw new InvalidArgumentException('Discount was not found.');
                discount_admin_allowed_check($pdo,$user,'discounts.manage',$checkPublic);
                $result=discount_void($pdo,$org,$discountPublic,$userId);
                app_audit($pdo,$org,$userId,'discount.voided','pos_discount',$discountPublic,null,['checkPublicId'=>$checkPublic]);
                $message='Discount voided.';
            }
        }catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'The discount action could not be completed.';error_log('Discount admin failed: '.$e->getMessage());}
    }
}

$openChecks=[];$recent=[];
if(discount_table_ready($pdo)){
    foreach(pos_open_checks($pdo,$org) as $check){
        if(operational_location_allowed($pdo,$user,$canManage?'discounts.manage':'discounts.view',(int)$check['location_id']))$openChecks[]=$check;
    }
    foreach(discount_recent($pdo,$org,120) as $discount){
        if(operational_location_allowed($pdo,$user,'discounts.view',(int)$discount['location_id']))$recent[]=$discount;
    }
}
$types=discount_manual_types();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Discounts | Gelato</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f5f2;color:#171815;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.discount-shell{max-width:1500px;margin:auto;padding:26px}.discount-hero{display:flex;justify-content:space-between;gap:24px;align-items:end;padding-bottom:22px;border-bottom:1px solid #dedfd9}.eyebrow{display:block;color:#9d6920;text-transform:uppercase;letter-spacing:.14em;font-size:10px;font-weight:900}.discount-hero h1{font:700 clamp(34px,5vw,58px)/1 Georgia,serif;margin:7px 0}.discount-hero p{max-width:740px;color:#6d716a;line-height:1.6;margin:0}.package-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 14px;border:1px solid #d8d9d3;border-radius:10px;background:#fff;color:#171815;text-decoration:none;font-size:11px;font-weight:900}.notice{padding:13px 15px;margin:16px 0;border-radius:12px;border:1px solid #d7d8d2;background:#fff}.notice.good{border-color:#afd3bb;background:#eef9f2;color:#226441}.notice.bad{border-color:#e3b9b0;background:#fff2ef;color:#922f24}.layout{display:grid;grid-template-columns:minmax(340px,520px) minmax(0,1fr);gap:16px;margin-top:18px}.panel{background:#fff;border:1px solid #dedfd9;border-radius:15px;overflow:hidden}.panel-head{padding:15px 17px;border-bottom:1px solid #ebebe6}.panel-head h2{font:700 24px Georgia,serif;margin:4px 0}.panel-head p{margin:0;color:#747871;font-size:11px;line-height:1.5}.form{padding:17px}.form label{display:block;margin-bottom:13px}.form label span{display:block;margin-bottom:5px;color:#666a63;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:900}.form select,.form input,.form textarea{width:100%;padding:11px;border:1px solid #d9dbd5;border-radius:10px;background:#fff;font:inherit;font-size:12px}.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.btn{width:100%;min-height:45px;border:0;border-radius:11px;background:#171815;color:#fff;font-weight:900;cursor:pointer}.btn:disabled{opacity:.45}.helper{padding:11px;border:1px solid #e6dcc4;background:#fffaf0;border-radius:10px;font-size:10px;line-height:1.5;color:#6f6657}.discount-list{padding:8px}.discount-row{display:grid;grid-template-columns:minmax(180px,1fr) 120px 110px 110px auto;gap:10px;align-items:center;padding:12px;border-bottom:1px solid #ededE8}.discount-row:last-child{border-bottom:0}.discount-row strong,.discount-row span,.discount-row small{display:block}.discount-row strong{font-size:12px}.discount-row span{font-size:10px;color:#6f746d;margin-top:3px}.discount-row small{font-size:9px;color:#868a83}.amount{font-weight:900}.status{display:inline-flex!important;width:max-content;padding:4px 7px;border-radius:999px;background:#eaf6ee;color:#256c46;font-size:8px!important;text-transform:uppercase;font-weight:900}.status.voided{background:#eee;color:#777}.void-btn{border:1px solid #e2b8af;background:#fff4f1;color:#982f22;border-radius:8px;padding:7px 9px;font-size:9px;font-weight:900;cursor:pointer}.empty{padding:28px;text-align:center;color:#777c75}@media(max-width:900px){.layout{grid-template-columns:1fr}.discount-row{grid-template-columns:1fr 90px}.discount-row>*:nth-child(3),.discount-row>*:nth-child(4){display:none}.discount-hero{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.discount-shell{padding:14px}.row{grid-template-columns:1fr}}
</style></head><body>
<main class="discount-shell">
<section class="discount-hero"><div><span class="eyebrow">Sales controls</span><h1>Add Discount</h1><p>Apply a tracked discount to an open POS check. Discounts are classified by business reason so Package Deals, Make Goods, coupons and manager overrides can be reported separately.</p></div><a class="package-link" href="packages-admin.php">Package Deals →</a></section>
<?php if($message):?><div class="notice good"><?=app_escape($message)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=app_escape($error)?></div><?php endif;?>
<div class="layout"><section class="panel"><div class="panel-head"><span class="eyebrow">New action</span><h2>Apply Discount</h2><p>Choose an open check, classify the discount and record a required reason.</p></div>
<?php if(!$canManage):?><div class="empty">You have view access only.</div><?php elseif(!$openChecks):?><div class="empty">There are no open POS checks in your assigned locations.</div><?php else:?><form class="form" method="post"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="apply">
<label><span>Open check</span><select name="check_public_id" required><?php foreach($openChecks as $check):?><option value="<?=app_escape((string)$check['public_id'])?>"><?=app_escape((string)$check['location_name'])?> · #<?=app_escape((string)$check['check_number'])?> · <?=app_escape(ucwords(str_replace('_',' ',(string)$check['service_mode'])))?> · $<?=number_format((float)$check['subtotal'],2)?></option><?php endforeach;?></select></label>
<label><span>Discount type</span><select name="discount_type" required><?php foreach($types as $key=>$label):?><option value="<?=app_escape($key)?>"><?=app_escape($label)?></option><?php endforeach;?></select></label>
<div class="row"><label><span>Method</span><select name="method"><option value="fixed">Fixed amount off</option><option value="percent">Percent off</option></select></label><label><span>Value</span><input type="number" name="value" min="0.01" step="0.01" required placeholder="10.00"></label></div>
<label><span>Required reason</span><textarea name="reason" maxlength="500" rows="3" required placeholder="Customer recovery, coupon code, manager courtesy, loyalty reward..."></textarea></label><div class="helper"><strong>Package Deal discounts are not applied manually here.</strong> Build them from Add Package so each redemption remains tied to the package and order history.</div><br><button class="btn" type="submit">Apply Discount</button></form><?php endif;?></section>
<section class="panel"><div class="panel-head"><span class="eyebrow">Tracked activity</span><h2>Recent Discounts</h2><p>Each record retains its type, reason, amount, check and applying staff member.</p></div><div class="discount-list"><?php if(!$recent):?><div class="empty">No typed discounts have been recorded yet.</div><?php else:?><?php foreach($recent as $d):$typeLabel=discount_types()[(string)$d['discount_type']]??(string)$d['discount_type'];?><article class="discount-row"><div><strong><?=app_escape($typeLabel)?> · #<?=app_escape((string)$d['check_number'])?></strong><span><?=app_escape((string)$d['reason'])?></span><small><?=app_escape((string)$d['location_name'])?> · <?=app_escape((string)$d['applied_by_name'])?> · <?=app_escape((string)$d['applied_at'])?></small></div><div class="amount">−$<?=number_format((float)$d['amount'],2)?></div><div><?=app_escape((string)$d['method'])?> <?=app_escape((string)$d['value'])?></div><div><span class="status <?=app_escape((string)$d['status'])?>"><?=app_escape((string)$d['status'])?></span></div><div><?php if($canManage&&(string)$d['status']==='active'&&(string)$d['check_status']==='open'):?><form method="post" onsubmit="return confirm('Void this discount?');"><input type="hidden" name="csrf_token" value="<?=app_escape(app_csrf_token())?>"><input type="hidden" name="action" value="void"><input type="hidden" name="discount_public_id" value="<?=app_escape((string)$d['public_id'])?>"><button class="void-btn" type="submit">Void</button></form><?php endif;?></div></article><?php endforeach;?><?php endif;?></div></section></div>
</main><script src="js/universal-admin-page-shell.js?v=20260915-shell2"></script></body></html>
