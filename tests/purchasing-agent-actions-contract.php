<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/purchasing-agent-core.php';

function paa_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$pdo=app_pdo();
$slug='purch-agent-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Purchasing Agent CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'x','CI','Buyer','CI Buyer','active')")->execute(['buyer-'.$slug.'@example.test']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,par_level,reorder_point,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?, 'active',?,?)")->execute([$org,'inv-'.$slug,'mozzarella-'.$slug,'Mozzarella','lb',10,40,15,$uid,$uid]);
$vendor=purchasing_save_vendor($pdo,$org,['name'=>'CI Foods','leadTimeDays'=>1],$uid);
purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendor['public_id'],'inventoryId'=>'inv-'.$slug,'vendorSku'=>'CHEESE-20','packSize'=>20,'packUnit'=>'lb','pricePerPack'=>55,'preferred'=>true],$uid);

$manager=['id'=>$uid,'organization_id'=>$org,'permissions'=>['purchasing.agent','purchasing.view','purchasing.manage','receiving.view','receiving.manage']];
$viewer=['id'=>$uid,'organization_id'=>$org,'permissions'=>['purchasing.agent','purchasing.view']];
$context=['module'=>'purchasing','activeTab'=>'suggestions','selectedPurchaseOrderPublicId'=>''];

$before=(int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE organization_id={$org}")->fetchColumn();
$proposal=purchasing_agent_handle($pdo,$manager,['message'=>'draft a PO for CI Foods','pageContext'=>$context]);
paa_assert(($proposal['skill']??'')==='purchasing.action_proposal','Draft request must return a proposal.');
paa_assert(($proposal['data']['requiresConfirmation']??false)===true,'Draft proposal must require confirmation.');
$afterProposal=(int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE organization_id={$org}")->fetchColumn();
paa_assert($afterProposal===$before,'Draft proposal must not create a purchase order before confirmation.');

$confirmed=purchasing_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);
paa_assert(($confirmed['skill']??'')==='purchasing.action_confirmed'&&($confirmed['data']['action']??'')==='draft_po','Confirm must create the proposed draft PO.');
$poId=(string)$confirmed['data']['purchaseOrderId'];$po=purchasing_order($pdo,$org,$poId);
paa_assert($po!==null&&$po['status']==='draft'&&count($po['items'])===1,'Confirmed draft PO was not created correctly.');
paa_assert((float)$po['items'][0]['ordered_base_quantity']===40.0,'Confirmed draft must preserve the current suggested quantity.');

$context['selectedPurchaseOrderPublicId']=$poId;
$submit=purchasing_agent_handle($pdo,$manager,['message'=>'submit this PO','pageContext'=>$context]);
paa_assert(($submit['skill']??'')==='purchasing.action_proposal','PO submission must require confirmation.');
paa_assert((purchasing_order($pdo,$org,$poId)['status']??'')==='draft','Submission proposal must not change PO status.');
$submitted=purchasing_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);
paa_assert(($submitted['data']['action']??'')==='submit_po'&&(purchasing_order($pdo,$org,$poId)['status']??'')==='submitted','Confirmed PO submission failed.');

$inventoryBefore=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE organization_id={$org} AND public_id='inv-{$slug}'")->fetchColumn();
$receive=purchasing_agent_handle($pdo,$manager,['message'=>'everything arrived, receive this PO in full','pageContext'=>$context]);
paa_assert(($receive['skill']??'')==='purchasing.action_proposal','Full receiving must require confirmation.');
$inventoryAfterProposal=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE organization_id={$org} AND public_id='inv-{$slug}'")->fetchColumn();
paa_assert($inventoryAfterProposal===$inventoryBefore,'Receiving proposal must not change inventory before confirmation.');
$received=purchasing_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);
paa_assert(($received['data']['action']??'')==='receive_po_full','Confirm must execute full receiving.');
paa_assert((purchasing_order($pdo,$org,$poId)['status']??'')==='received','Full receiving must close the PO.');
$inventoryAfter=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE organization_id={$org} AND public_id='inv-{$slug}'")->fetchColumn();
paa_assert(abs($inventoryAfter-50.0)<0.001,'Confirmed full receiving must add the expected 40 lb to usable inventory.');

$blocked=false;try{purchasing_agent_handle($pdo,$viewer,['message'=>'draft a PO for CI Foods','pageContext'=>$context]);}catch(PurchasingAgentPermissionException){$blocked=true;}
paa_assert($blocked,'A user without purchasing.manage must not propose a draft PO write.');

$pdo->prepare("UPDATE inventory_items SET on_hand_quantity=5,updated_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([$org,'inv-'.$slug]);
$context['selectedPurchaseOrderPublicId']='';
$secondProposal=purchasing_agent_handle($pdo,$manager,['message'=>'draft a PO for CI Foods','pageContext'=>$context]);
$second=purchasing_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);$secondId=(string)$second['data']['purchaseOrderId'];$context['selectedPurchaseOrderPublicId']=$secondId;
$staleProposal=purchasing_agent_handle($pdo,$manager,['message'=>'submit this PO','pageContext'=>$context]);
$pdo->prepare("UPDATE purchase_orders SET updated_at=DATE_ADD(NOW(6),INTERVAL 5 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,$secondId]);
$staleBlocked=false;try{purchasing_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);}catch(InvalidArgumentException $e){$staleBlocked=str_contains($e->getMessage(),'changed after I proposed');}
paa_assert($staleBlocked,'A stale PO proposal must be rejected on confirmation.');
paa_assert((purchasing_order($pdo,$org,$secondId)['status']??'')==='draft','Stale confirmation must not submit the PO.');

pac_pending_clear($org,$uid);
$cancelProposal=purchasing_agent_handle($pdo,$manager,['message'=>'cancel this PO','pageContext'=>$context]);
paa_assert(($cancelProposal['skill']??'')==='purchasing.action_proposal','PO cancellation must require confirmation.');
$cancelled=purchasing_agent_handle($pdo,$manager,['message'=>'Cancel','pageContext'=>$context]);
paa_assert(($cancelled['skill']??'')==='purchasing.action_cancelled'&&(purchasing_order($pdo,$org,$secondId)['status']??'')==='draft','Discarding cancellation must leave PO unchanged.');

$events=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE organization_id={$org} AND action LIKE 'purchasing.agent_%'")->fetchColumn();
paa_assert($events>=6,'Purchasing Agent actions must leave an audit trail.');
echo "purchasing-agent-actions-contract-ok po={$poId} audit={$events}\n";