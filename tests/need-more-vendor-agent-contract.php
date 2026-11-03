<?php
declare(strict_types=1);

/** 3K2I-B: real MariaDB records through the official Food Monster / Prep Agent path. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-b8d18-daily-prep-model-tools.php';
require_once __DIR__.'/../includes/food-monster-skill-registry.php';
require_once __DIR__.'/../includes/food-monster-llm.php';
require_once __DIR__.'/../includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function nmva_check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException('Need More vendor agent: '.$message);
    ++$GLOBALS['checks'];
}
function nmva_reject(callable $call,string $message): void
{
    try{$call();}catch(InvalidArgumentException|DomainException|PrepAgentPermissionException $e){
        nmva_check(true,$message);return;
    }
    throw new RuntimeException('Need More vendor agent accepted '.$message);
}
$pdo=app_pdo();
if(session_status()!==PHP_SESSION_ACTIVE&&!session_start())throw new RuntimeException('Test session unavailable.');
$tag=bin2hex(random_bytes(6));$day=date('Y-m-d');
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Vendor Agent '.$tag]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Other Vendor Agent '.$tag]);$other=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute(['vendor-agent-'.$tag.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Vendor','Manager','Vendor Manager']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$manager=['id'=>$uid,'organization_id'=>$org,'permissions'=>[
    'tasks.manage','inventory.view','purchasing.view','purchasing.manage','prep.intelligence.view','prep.intelligence.agent',
]];
$reader=$manager;$reader['permissions']=array_values(array_diff($manager['permissions'],['purchasing.manage']));
$staff=$manager;$staff['permissions']=['tasks.self','prep.intelligence.view','prep.intelligence.agent'];
$stockId='vendor-agent-flour-'.$tag;
$pdo->prepare('INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,created_by) VALUES (?,?,?,?,?,?,?)')
    ->execute([$org,$stockId,'vendor-agent-flour-'.$tag,'Vendor Test Flour','kg',2,$uid]);
$need=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','inventoryId'=>$stockId,'title'=>'Vendor Test Flour'],false);
daily_quicklist_mutate($pdo,$org,$uid,'update',['id'=>$need,'requestedStockQuantity'=>5],true);
$vendorA=purchasing_save_vendor($pdo,$org,['name'=>'Small Pack Supplier '.$tag,'minimumOrderAmount'=>100],$uid);
$vendorB=purchasing_save_vendor($pdo,$org,['name'=>'Large Pack Supplier '.$tag],$uid);
$mapA=purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendorA['public_id'],'inventoryId'=>$stockId,
    'packSize'=>2,'packUnit'=>'kg','pricePerPack'=>12,'preferred'=>1],$uid);
$mapB=purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendorB['public_id'],'inventoryId'=>$stockId,
    'packSize'=>3,'packUnit'=>'kg','pricePerPack'=>15,'preferred'=>0],$uid);
$context=['threadId'=>'agent-vendor-'.$tag,'channel'=>'text'];
$readArgs=['name'=>'Vendor Test Flour','date'=>$day];
$baseArgs=['list'=>'inventory','reference_mode'=>'name','name'=>'Vendor Test Flour','date'=>$day];
$countOrders=static function()use($pdo,$org):int{
    $q=$pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?');$q->execute([$org]);return (int)$q->fetchColumn();
};
$stockBefore=(float)$pdo->query('SELECT on_hand_quantity FROM inventory_items WHERE organization_id='.$org)->fetchColumn();
$transactionsBefore=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$catalogue=fm8d18j11_catalogue($manager,$context);
nmva_check(isset($catalogue['get_need_more_vendor_options']),'registered native supplier read');
$allTools=fm8d3_catalogue($manager,$context);
nmva_check(isset($allTools['get_need_more_vendor_options'])&&isset($allTools['propose_daily_prep_action']),
    'supplier tools join the full Food Monster runtime catalogue');
nmva_check(in_array('get_need_more_vendor_options',array_column(fm8d3_provider_tools('openai',$allTools),'name'),true)
    &&in_array('get_need_more_vendor_options',array_column(fm8d3_provider_tools('anthropic',$allTools),'name'),true),
    'both supported providers receive the supplier comparison schema');
nmva_check(in_array('create_purchase_draft',fm8d18j11_allowed_actions($manager),true)
    &&in_array('set_preferred_vendor',fm8d18j11_allowed_actions($manager),true),'manager proposal actions offered');
nmva_check(isset(fm8d18j11_catalogue($reader,$context)['get_need_more_vendor_options'])
    &&!in_array('create_purchase_draft',fm8d18j11_allowed_actions($reader),true),'read-only purchasing cannot propose orders');
nmva_check(!isset(fm8d18j11_catalogue($staff,$context)['get_need_more_vendor_options']),'staff cannot discover supplier costs');
nmva_check(!fm8d18j11_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false]),'unverified voice has no private supplier tools');
nmva_reject(static fn()=>fm8d18j11_vendor_read($readArgs,$staff,$context),'staff supplier read');
nmva_reject(static fn()=>fm8d18j11_vendor_read($readArgs,$manager,['channel'=>'voice','privateVoiceApproved'=>false]),'unverified room voice supplier read');
nmva_reject(static fn()=>fm8d18j11_vendor_read($readArgs,$manager,['channel'=>'voice','privateVoiceApproved'=>true]),'voice approval without matching identity event');
nmva_reject(static fn()=>fm8d18j11_propose($baseArgs+['action'=>'create_purchase_draft','vendor_number'=>1],$manager,$context),'draft without displayed supplier selection');
nmva_check(fm8d18j11_action_fields($baseArgs+['action'=>'create_purchase_draft','vendor_number'=>'1'],$manager)===['vendor_number'],
    'model must use a bounded integer selection');
$read=fm8d18j11_vendor_read($readArgs,$reader,$context);
nmva_check($countOrders()===0&&!gac_pending_get('prep',$org,$uid),'comparison performs no purchasing mutation and stages no action');
nmva_check(count($read['data']['options'])===2&&!$read['data']['canDraft'],'authorized reader sees options but cannot draft');
nmva_check(!isset($read['data']['options'][0]['vendorItemId'])&&!isset($read['data']['options'][0]['vendorId']),
    'model output uses displayed numbers instead of database mapping IDs');
nmva_check(!empty($read['data']['options'][0]['priceHistory']),'recorded catalog history is returned with supplier evidence');
$numberB=0;
foreach($read['data']['options'] as $option)if($option['vendor']===$vendorB['name'])$numberB=(int)$option['number'];
nmva_check($numberB>0,'alternate supplier has a displayed option');
$selection=pqa_vendor_context_resolve($org,$uid,$context['threadId'],$need,$numberB);
nmva_check($selection['vendorItemId']===$mapB['public_id'],'number resolves to exact stored alternate mapping');
nmva_reject(static fn()=>pqa_vendor_context_resolve($org,$uid,'another-thread',$need,$numberB),'selection from another conversation');
nmva_reject(static fn()=>pqa_vendor_context_resolve($other,$uid,$context['threadId'],$need,$numberB),'selection from another tenant');
nmva_reject(static fn()=>pqa_vendor_context_resolve($org,$uid+1,$context['threadId'],$need,$numberB),'selection from another operator');
nmva_reject(static fn()=>pqa_vendor_context_resolve($org,$uid,$context['threadId'],'another-request',$numberB),'selection from another Need More request');
$key=pqa_context_key($org,$uid,$context['threadId']);
$_SESSION['prep_vendor_selection'][$key]['expires']=time()-1;
nmva_reject(static fn()=>pqa_vendor_context_resolve($org,$uid,$context['threadId'],$need,$numberB),'expired supplier selection');
fm8d18j11_vendor_read($readArgs,$manager,$context);
nmva_check(gaw_route($manager,'Create a draft with the second supplier',$context['threadId'])['domain']==='daily_prep_model_tools',
    'supplier follow-up stays on the official model-selected path');
nmva_check(gaw_route($manager,'Create a draft with the second supplier','another-thread')['domain']!=='daily_prep_model_tools',
    'another thread does not inherit supplier routing');
$preferArgs=$baseArgs+['action'=>'set_preferred_vendor','vendor_number'=>$numberB];
$proposal=fm8d18j11_propose($preferArgs,$manager,$context);
nmva_check($proposal['data']['requiresConfirmation']===true&&$countOrders()===0,'preferred supplier action only proposes');
$q=$pdo->prepare('SELECT is_preferred FROM inventory_vendor_items WHERE organization_id=? AND public_id=?');
$q->execute([$org,$mapB['public_id']]);nmva_check((int)$q->fetchColumn()===0,'proposal did not change preferred supplier');
prep_agent_handle($pdo,$manager,['message'=>'Cancel']);
$q->execute([$org,$mapB['public_id']]);nmva_check((int)$q->fetchColumn()===0,'Cancel leaves supplier unchanged');
fm8d18j11_propose($preferArgs,$manager,$context);
$preferred=prep_agent_handle($pdo,$manager,['message'=>'Confirm']);
$q->execute([$org,$mapB['public_id']]);
nmva_check($preferred['skill']==='prep.action_confirmed'&&(int)$q->fetchColumn()===1,'existing Confirm applies exact preferred supplier');
nmva_check($countOrders()===0,'preferred change creates no order');

// Preference changed the comparison order and fingerprint. Resolve displayed numbers again.
$read=fm8d18j11_vendor_read($readArgs,$manager,$context);
foreach($read['data']['options'] as $option)if($option['vendor']===$vendorB['name'])$numberB=(int)$option['number'];
$draftArgs=$baseArgs+['action'=>'create_purchase_draft','vendor_number'=>$numberB];
$proposal=fm8d18j11_propose($draftArgs,$manager,$context);
nmva_check($proposal['data']['requiresConfirmation']===true&&$countOrders()===0,'draft proposal leaves database untouched');
nmva_check(str_contains($proposal['data']['answer'],$vendorB['name'])&&str_contains($proposal['data']['answer'],'$30.00'),
    'proposal discloses selected supplier and exact rounded pack subtotal');
nmva_reject(static fn()=>prep_agent_handle($pdo,$reader,['message'=>'Confirm']),'confirmation after Purchasing Manage revoked');
nmva_check($countOrders()===0&&!gac_pending_get('prep',$org,$uid),'denied confirmation clears pending proposal without creating order');
fm8d18j11_propose($draftArgs,$manager,$context);
$pdo->prepare('UPDATE inventory_vendor_items SET price_per_pack=16,updated_at=NOW(6) WHERE organization_id=? AND public_id=?')
    ->execute([$org,$mapB['public_id']]);
nmva_reject(static fn()=>prep_agent_handle($pdo,$manager,['message'=>'Confirm']),'supplier price changed after proposal');
nmva_check($countOrders()===0&&!gac_pending_get('prep',$org,$uid),'stale quote cannot create order');
nmva_reject(static fn()=>fm8d18j11_propose($draftArgs,$manager,$context),'stale displayed supplier comparison');
$read=fm8d18j11_vendor_read($readArgs,$manager,$context);
foreach($read['data']['options'] as $option)if($option['vendor']===$vendorB['name'])$numberB=(int)$option['number'];
$draftArgs['vendor_number']=$numberB;
fm8d18j11_propose($draftArgs,$manager,$context);
$confirmed=prep_agent_handle($pdo,$manager,['message'=>'Confirm']);
nmva_check($confirmed['skill']==='prep.action_confirmed'&&$countOrders()===1,'only separate Confirm creates one draft');
$order=purchasing_order($pdo,$org,$confirmed['data']['result']['orderId']);
nmva_check($order['status']==='draft'&&$order['vendor_public_id']===$vendorB['public_id'],
    'confirmed draft uses the exact reviewed supplier');
nmva_check(count($order['items'])===1&&(int)$order['items'][0]['vendor_item_id']===(int)$mapB['id']
    &&(float)$order['items'][0]['ordered_base_quantity']===6.0&&(float)$order['subtotal']===32.0,
    'selected mapping, pack rounding and current confirmed price reach canonical purchase order');
nmva_check(($confirmed['data']['proof']['url']??'')==='need-more-purchasing.php'
    &&str_contains($confirmed['answer'],$order['order_number']),'reply links to evidence and states actual created order');
nmva_reject(static fn()=>prep_agent_handle($pdo,$manager,['message'=>'Confirm']),'replayed confirmation');
$read=fm8d18j11_vendor_read($readArgs,$manager,$context);
nmva_check(!$read['data']['canDraft']&&!empty($read['data']['openOrders']),'Food Monster receives open-order blocker after draft');
nmva_check((float)$pdo->query('SELECT on_hand_quantity FROM inventory_items WHERE organization_id='.$org)->fetchColumn()===$stockBefore
    &&(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn()===$transactionsBefore,
    'all supplier reads, preferences, proposals and draft confirmation leave physical inventory unchanged');
$foreign=$manager;$foreign['organization_id']=$other;
nmva_reject(static fn()=>fm8d18j11_vendor_read($readArgs,$foreign,$context),'foreign tenant supplier evidence');
$skills=array_column(fm_skill_registry_for_user($manager),'id');
nmva_check(in_array('need_more_purchasing',$skills,true),'authorized purchasing discovery appears in Agent Brain skills');
nmva_check(!in_array('need_more_purchasing',array_column(fm_skill_registry_for_user($staff),'id'),true),
    'staff discovery omits manager supplier skill');
echo 'Need More vendor agent contract: '.$checks." checks passed\n";
