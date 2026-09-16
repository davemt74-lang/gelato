<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/customer-crm-agent-extensions.php';
require_once __DIR__.'/../includes/customer-crm-brain.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/operations-core.php';

$pdo=app_pdo();
function crmai_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function crmai_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

crmai_assert(crm_ready($pdo),'Customer CRM must be installed.');
crmai_assert(pos_ready($pdo),'Native POS must be installed.');
crmai_assert(operations_core_ready($pdo),'Operations Core must be installed.');
$slug='crm-agent-'.bin2hex(random_bytes(4));

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['CRM Agent CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'CRM','Manager','CRM Manager']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$uid,$location]);$membership=(int)$pdo->lastInsertId();
$user=['id'=>$uid,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['*'],'display_name'=>'CRM Manager','first_name'=>'CRM','role_slug'=>'owner'];

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$section,'margherita-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',22.00,'USD',1)")->execute([$menuItem]);$price=(int)$pdo->lastInsertId();
pos_settings_save($pdo,$org,$location,['taxRate'=>0,'serviceChargeRate'=>0,'defaultServiceMode'=>'dine_in'],$uid);

$today=new DateTimeImmutable('today',new DateTimeZone('America/Phoenix'));
$customer=crm_customer_save($pdo,$org,[
    'displayName'=>'Taylor Regular','email'=>'taylor.'.$slug.'@example.test','phone'=>'602-555-0144',
    'birthdayMonth'=>(int)$today->format('n'),'birthdayDay'=>(int)$today->format('j'),
],$uid,'manual');
$customerPublic=(string)$customer['public_id'];$customerId=(int)$customer['id'];
crm_consent_set($pdo,$org,$customerPublic,'email','opted_in','manual','CI consent',$uid);

for($i=0;$i<5;$i++){
    $check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table '.($i+1),'guestCount'=>1],$uid);
    crm_attach_check($pdo,$org,(string)$check['publicId'],$customerPublic);
    $check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$uid);
    $check=pos_record_tender($pdo,$org,(string)$check['publicId'],['tenderType'=>'cash','amount'=>22,'tipAmount'=>0,'receivedAmount'=>22],$uid);
    crmai_assert($check['status']==='paid','CRM history seed check must close paid.');
    $pdo->prepare('UPDATE pos_checks SET closed_at=DATE_SUB(NOW(6),INTERVAL ? DAY),updated_at=DATE_SUB(NOW(6),INTERVAL ? DAY) WHERE organization_id=? AND public_id=?')->execute([40-($i*8),40-($i*8),$org,(string)$check['publicId']]);
}

$open=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 8','guestCount'=>2],$uid);$openPublic=(string)$open['publicId'];
crm_attach_check($pdo,$org,$openPublic,$customerPublic);
$context=['module'=>'pos','locationId'=>$location,'checkPublicId'=>$openPublic];

$regular=customer_crm_agent_enhanced_handle($pdo,$user,['message'=>'Is this customer a regular?','pageContext'=>$context]);
crmai_assert(($regular['skill']??'')==='crm.relationship_intelligence','POS-context relationship question must resolve through CRM intelligence.');
crmai_assert(($regular['data']['relationship']['stage']??'')==='regular','Five paid visits must produce the Regular relationship stage.');
crmai_assert((int)($regular['data']['relationship']['visits']??0)===5,'Relationship intelligence must use paid POS visit history.');

$birthday=customer_crm_agent_enhanced_handle($pdo,$user,['message'=>'Is it this customer’s birthday?','pageContext'=>$context]);
crmai_assert(($birthday['data']['relationship']['birthday']['today']??false)===true,'Birthday intelligence must resolve month/day in organization timezone.');
$consent=customer_crm_agent_enhanced_handle($pdo,$user,['message'=>'Can I email this customer?','pageContext'=>$context]);
crmai_assert(in_array('email',(array)($consent['data']['relationship']['contactChannels']??[]),true),'Consent-aware relationship read must expose current opted-in channel.');

$favorites=customer_crm_agent_enhanced_handle($pdo,$user,['message'=>'What does this customer usually order?','pageContext'=>$context]);
crmai_assert(($favorites['skill']??'')==='crm.relationship','Favorite-order question must delegate to canonical CRM relationship history.');
crmai_assert(str_contains((string)$favorites['answer'],'Margherita Pizza'),'Favorite-order answer must come from paid POS line history.');

$beforeTasks=(int)crmai_one($pdo,'SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=?',[$org]);
$followup=customer_crm_agent_enhanced_handle($pdo,$user,['message'=>'Create a follow-up task for this customer about catering interest','pageContext'=>$context]);
crmai_assert(($followup['skill']??'')==='operations.action_proposal','CRM follow-up must hand the write to the Operations node.');
crmai_assert(($followup['data']['targetNode']??'')==='operations','Follow-up proposal must identify Operations as the owning node.');
crmai_assert((int)crmai_one($pdo,'SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=?',[$org])===$beforeTasks,'CRM follow-up proposal must not create a task before confirmation.');
crmai_assert(gaw_pending_action_node($org,$uid)==='operations','Main Agent confirmation ownership must follow the Operations proposal.');
$pending=gac_pending_get('operations',$org,$uid);
crmai_assert(($pending['type']??'')==='task_create'&&str_contains((string)($pending['payload']['items'][0]??''),'Taylor Regular'),'Operations proposal must carry the customer-specific follow-up task.');
gac_pending_clear('operations',$org,$uid);

$unlinked=pos_create_check($pdo,$org,$location,['serviceMode'=>'bar','tableName'=>'Bar Seat 2','guestCount'=>1],$uid);
$unlinkedAnswer=customer_crm_agent_enhanced_handle($pdo,$user,['message'=>'Is this customer a regular?','pageContext'=>['module'=>'pos','locationId'=>$location,'checkPublicId'=>$unlinked['publicId']]]);
crmai_assert(($unlinkedAnswer['skill']??'')==='crm.context_unlinked','Unlinked POS checks must not invent customer relationship history.');

$opportunities=cri_global_opportunities($pdo,$user,10);
crmai_assert(count(array_filter($opportunities,static fn(array $row):bool=>(string)$row['type']==='in_house_relationship'&&(string)$row['customer']['publicId']===$customerPublic))===1,'An in-house regular must become a CRM relationship opportunity.');
$brain=customer_crm_brain_signal_rows($pdo,$user);
crmai_assert(count(array_filter($brain,static fn(array $row):bool=>(string)$row['node']==='crm'&&(string)($row['evidence']['customerPublicId']??'')===$customerPublic))>=1,'CRM opportunity must feed the Main Agent Brain.');

$route=gaw_route($user,'What does this customer usually order?');
crmai_assert(($route['domain']??'')==='customer_crm','Shared Agent fallback router must classify customer relationship language as CRM.');
$menuRoute=gaw_route($user,'What toppings are on the Margherita Pizza?');
crmai_assert(($menuRoute['domain']??'')==='restaurant_brain','CRM routing must not steal menu knowledge questions.');

$viewer=['id'=>$uid,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['pos.use'],'display_name'=>'POS Only','first_name'=>'POS','role_slug'=>'staff'];
$blocked=false;try{customer_crm_agent_enhanced_handle($pdo,$viewer,['message'=>'Is this customer a regular?','pageContext'=>$context]);}catch(CrmAgentPermissionException){$blocked=true;}
crmai_assert($blocked,'POS use alone must not grant CRM relationship history.');

crmai_assert((int)crmai_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='crm.agent_followup_handoff_proposed'",[$org])===1,'Cross-node CRM follow-up proposal must be audited.');

echo "customer-crm-agent-intelligence contract passed\n";
