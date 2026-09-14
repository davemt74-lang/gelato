<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/table-service-core.php';

$pdo=app_pdo();
function opaudit_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function opaudit_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

opaudit_assert(pos_ready($pdo),'Native POS must be installed.');
opaudit_assert(kds_ready($pdo),'KDS must be installed.');
opaudit_assert(table_service_ready($pdo),'Table Service must be installed.');

$slug='opaudit-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Operational Audit '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Location A','Phoenix','AZ','active')")->execute([$org]);
$locationA=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Location B','Tempe','AZ','active')")->execute([$org]);
$locationB=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Ops','Auditor','Ops Auditor']);
$userId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$userId,$locationA]);
$membership=(int)$pdo->lastInsertId();

$localPermissionKeys=['pos.use','pos.void','table_service.view','table_service.use','kds.view','kds.update'];
$pdo->prepare("INSERT INTO roles (organization_id,name,slug,description,is_system_role,is_owner_role,is_assignable) VALUES (?,?,?,'CI location-scoped role',0,0,1)")->execute([$org,'Location A Operator','location-a-'.$slug]);
$localRole=(int)$pdo->lastInsertId();
$permissionId=$pdo->prepare('SELECT id FROM permissions WHERE permission_key=? LIMIT 1');
$linkPermission=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) VALUES (?,?)');
foreach($localPermissionKeys as $key){$permissionId->execute([$key]);$id=(int)$permissionId->fetchColumn();opaudit_assert($id>0,'Missing seeded permission '.$key);$linkPermission->execute([$localRole,$id]);}
$pdo->prepare('INSERT INTO user_roles (membership_id,role_id,location_id,assigned_by) VALUES (?,?,?,?)')->execute([$membership,$localRole,$locationA,$userId]);

$pdo->prepare("INSERT INTO roles (organization_id,name,slug,description,is_system_role,is_owner_role,is_assignable) VALUES (?,?,?,'CI organization-wide KDS view role',0,0,1)")->execute([$org,'Global KDS Viewer','global-kds-'.$slug]);
$globalRole=(int)$pdo->lastInsertId();
$permissionId->execute(['kds.view']);$kdsViewId=(int)$permissionId->fetchColumn();$linkPermission->execute([$globalRole,$kdsViewId]);
$pdo->prepare('INSERT INTO user_roles (membership_id,role_id,location_id,assigned_by) VALUES (?,?,NULL,?)')->execute([$membership,$globalRole,$userId]);

$actor=['membership_id'=>$membership,'permissions'=>$localPermissionKeys];
opaudit_assert(operational_location_allowed($pdo,$actor,'pos.use',$locationA),'Location-scoped POS permission must allow its assigned location.');
opaudit_assert(!operational_location_allowed($pdo,$actor,'pos.use',$locationB),'Location-scoped POS permission must deny another location.');
opaudit_assert(operational_location_allowed($pdo,$actor,'kds.view',$locationB),'A NULL role location must grant organization-wide access for that permission.');
opaudit_assert(!operational_location_allowed($pdo,$actor,'kds.update',$locationB),'Organization-wide KDS view must not broaden a separately location-scoped KDS update permission.');
$filtered=operational_filter_locations($pdo,$actor,'pos.use',pos_locations($pdo,$org));
opaudit_assert(count($filtered)===1&&(int)$filtered[0]['id']===$locationA,'Operational location filtering must expose only permitted POS locations.');
opaudit_assert(operational_location_allowed($pdo,['membership_id'=>$membership,'permissions'=>['*']],'pos.use',$locationB),'Owner wildcard permission must remain organization-wide.');
opaudit_assert(operational_safe_error(new RuntimeException('internal database detail'),'Safe fallback')==='Safe fallback','Production API errors must not expose internal exception messages when debug is disabled.');

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Kitchen',?,'active',1)")->execute([$org,'kitchen-'.$slug]);
$sectionId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Audit Pizza',?,1)")->execute([$org,$sectionId,'audit-pizza-'.$slug]);
$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$menuItem]);
$price=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$locationA,['name'=>'Oven','slug'=>'oven-'.$slug,'targetSeconds'=>600,'sortOrder'=>10],$userId);
kds_route_save($pdo,$org,$locationA,$menuItem,(string)$station['public_id'],$userId);
$serviceSection=table_service_section_save($pdo,$org,$locationA,['name'=>'Dining Room','sortOrder'=>10],$userId);
table_service_section_assign($pdo,$org,$locationA,(string)$serviceSection['publicId'],$userId,pos_clock($pdo,$org,$locationA)->format('Y-m-d'),$userId);

$table1=table_service_table_save($pdo,$org,$locationA,['name'=>'Audit Table 1','sectionPublicId'=>$serviceSection['publicId'],'capacity'=>4,'shape'=>'round'],$userId);
$check1=table_service_seat($pdo,$org,$locationA,(string)$table1['publicId'],2,$userId,'Atomic cancel test',$userId);
$check1=pos_add_item($pdo,$org,(string)$check1['publicId'],$price,1,'',$userId);
$line1=(int)$check1['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check1['publicId'],$userId,false);
$check1Id=(int)$check1['id'];
$forced=false;
try{
    operational_db_wrap($pdo,function()use($pdo,$org,$check1,$check1Id,$userId):void{
        pos_cancel_check($pdo,$org,(string)$check1['publicId'],'Forced rollback test',$userId);
        kds_cancel_check($pdo,$org,$check1Id,'Forced rollback test',$userId);
        table_service_release_closed_check($pdo,$org,(string)$check1['publicId'],$userId);
        app_audit($pdo,$org,$userId,'audit.forced_cancel','pos_check',(string)$check1['publicId']);
        throw new RuntimeException('Force outer rollback');
    });
}catch(RuntimeException $error){$forced=$error->getMessage()==='Force outer rollback';}
opaudit_assert($forced,'Forced cancellation rollback must throw from the outer transaction.');
opaudit_assert((string)opaudit_one($pdo,'SELECT status FROM pos_checks WHERE organization_id=? AND id=?',[$org,$check1Id])==='open','Outer rollback must restore the POS check to open.');
opaudit_assert((string)opaudit_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line1])==='queued','Outer rollback must restore the KDS line.');
opaudit_assert((int)opaudit_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$table1['publicId']])===$check1Id,'Outer rollback must keep the dining table attached to its check.');
opaudit_assert((int)opaudit_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='audit.forced_cancel'",[$org])===0,'Outer rollback must also roll back its audit record.');

operational_db_wrap($pdo,function()use($pdo,$org,$check1,$check1Id,$userId):void{
    pos_cancel_check($pdo,$org,(string)$check1['publicId'],'Committed cancel test',$userId);
    kds_cancel_check($pdo,$org,$check1Id,'Committed cancel test',$userId);
    table_service_release_closed_check($pdo,$org,(string)$check1['publicId'],$userId);
    app_audit($pdo,$org,$userId,'audit.committed_cancel','pos_check',(string)$check1['publicId']);
});
opaudit_assert((string)opaudit_one($pdo,'SELECT status FROM pos_checks WHERE organization_id=? AND id=?',[$org,$check1Id])==='cancelled','Committed cancellation must close the POS check.');
opaudit_assert((string)opaudit_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line1])==='cancelled','Committed cancellation must cancel KDS work.');
opaudit_assert(opaudit_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$table1['publicId']])===null,'Committed cancellation must release the table.');
opaudit_assert((string)opaudit_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$table1['publicId']])==='dirty','Committed cancellation must move the released table into reset/dirty state.');

$table2=table_service_table_save($pdo,$org,$locationA,['name'=>'Audit Table 2','sectionPublicId'=>$serviceSection['publicId'],'capacity'=>2,'shape'=>'square'],$userId);
$check2=table_service_seat($pdo,$org,$locationA,(string)$table2['publicId'],1,$userId,'Atomic payment test',$userId);
$check2=pos_add_item($pdo,$org,(string)$check2['publicId'],$price,1,'',$userId);
$check2Id=(int)$check2['id'];
$balance=(float)$check2['balanceDue'];
$forced=false;
try{
    operational_db_wrap($pdo,function()use($pdo,$org,$check2,$balance,$userId):void{
        $paid=pos_record_tender($pdo,$org,(string)$check2['publicId'],['tenderType'=>'cash','amount'=>$balance,'receivedAmount'=>$balance],$userId);
        table_service_release_closed_check($pdo,$org,(string)$check2['publicId'],$userId);
        app_audit($pdo,$org,$userId,'audit.forced_tender','pos_check',(string)$check2['publicId'],null,['status'=>$paid['status']]);
        throw new RuntimeException('Force tender rollback');
    });
}catch(RuntimeException $error){$forced=$error->getMessage()==='Force tender rollback';}
opaudit_assert($forced,'Forced tender rollback must throw from the outer transaction.');
opaudit_assert((string)opaudit_one($pdo,'SELECT status FROM pos_checks WHERE organization_id=? AND id=?',[$org,$check2Id])==='open','Tender rollback must restore the POS check to open.');
opaudit_assert((int)opaudit_one($pdo,'SELECT COUNT(*) FROM pos_tenders WHERE organization_id=? AND check_id=?',[$org,$check2Id])===0,'Tender rollback must remove the captured tender.');
opaudit_assert((int)opaudit_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$table2['publicId']])===$check2Id,'Tender rollback must keep the table attached.');
opaudit_assert((int)opaudit_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='audit.forced_tender'",[$org])===0,'Tender rollback must remove the audit record.');

$paid=operational_db_wrap($pdo,function()use($pdo,$org,$check2,$balance,$userId):array{
    $paid=pos_record_tender($pdo,$org,(string)$check2['publicId'],['tenderType'=>'cash','amount'=>$balance,'receivedAmount'=>$balance],$userId);
    table_service_release_closed_check($pdo,$org,(string)$check2['publicId'],$userId);
    app_audit($pdo,$org,$userId,'audit.committed_tender','pos_check',(string)$check2['publicId'],null,['status'=>$paid['status']]);
    return $paid;
});
opaudit_assert($paid['status']==='paid','Committed tender must close the POS check as paid.');
opaudit_assert((int)opaudit_one($pdo,'SELECT COUNT(*) FROM pos_tenders WHERE organization_id=? AND check_id=?',[$org,$check2Id])===1,'Committed tender must persist exactly one captured tender.');
opaudit_assert(opaudit_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$table2['publicId']])===null,'Committed tender must release the dining table.');
opaudit_assert((int)opaudit_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='audit.committed_tender'",[$org])===1,'Committed tender must persist its audit record.');

$raw500Pattern='message\'=>$e->getMessage()],500';
foreach(['api/pos.php','api/kds.php','api/table-service.php'] as $relative){
    $source=(string)file_get_contents(__DIR__.'/../'.$relative);
    opaudit_assert(str_contains($source,'operational_safe_error'),'Operational API '.$relative.' must use production-safe unexpected error handling.');
    opaudit_assert(!str_contains($source,$raw500Pattern),'Operational API '.$relative.' must not return raw unexpected exception messages.');
}

$posApi=(string)file_get_contents(__DIR__.'/../api/pos.php');
$tableApi=(string)file_get_contents(__DIR__.'/../api/table-service.php');
$kdsApi=(string)file_get_contents(__DIR__.'/../api/kds.php');
opaudit_assert(str_contains($posApi,"operational_location_allowed($pdo,$user,'pos.use'"),'POS API must enforce location-scoped POS access.');
opaudit_assert(str_contains($tableApi,"operational_location_allowed($pdo,$user,'table_service.view'"),'Table Service API must enforce location-scoped access.');
opaudit_assert(str_contains($kdsApi,"operational_location_allowed($pdo,$user,'kds.view'"),'KDS API must enforce location-scoped access.');
opaudit_assert(str_contains((string)file_get_contents(__DIR__.'/../includes/pos-core.php'),'function pos_transaction'),'POS core mutations must remain transaction-composable.');
opaudit_assert(str_contains((string)file_get_contents(__DIR__.'/../includes/pos-floor-plan.php'),'$owns=!$pdo->inTransaction()'),'Floor-plan sync must remain transaction-composable.');

echo "operational-stack-hardening-ok\n";
