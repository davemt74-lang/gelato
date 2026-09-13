<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/pos-core.php';
require __DIR__.'/../includes/customer-crm-core.php';

$pdo=app_pdo();
function crmci_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function crmci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

crmci_assert(crm_ready($pdo),'Customer CRM must be installed.');
crmci_assert(pos_ready($pdo),'Native POS must be installed before Customer CRM.');
$slug='crm-ci-'.bin2hex(random_bytes(4));

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['CRM CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'CRM Restaurant','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'CRM','Manager','CRM Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$section,'margherita-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'12 inch','12',20.00,'USD',1)")->execute([$menuItem]);$price=(int)$pdo->lastInsertId();
pos_settings_save($pdo,$org,$location,['taxRate'=>0,'serviceChargeRate'=>0,'defaultServiceMode'=>'dine_in'], $user);

$alice=crm_customer_save($pdo,$org,[
    'firstName'=>'Alice','lastName'=>'Rivera','displayName'=>'Alice Rivera',
    'email'=>'Alice.Rivera@Example.Test','phone'=>'(602) 555-0199','birthdayMonth'=>4,'birthdayDay'=>18,
],$user,'manual');
$alicePublic=(string)$alice['public_id'];$aliceId=(int)$alice['id'];
crmci_assert($alice['email_normalized']==='alice.rivera@example.test','Email normalization must lowercase customer email.');
crmci_assert($alice['phone_normalized']==='6025550199','Phone normalization must retain digits only.');
crmci_assert((int)$alice['birthday_month']===4&&(int)$alice['birthday_day']===18,'CRM birthday must preserve month/day only.');

$bob=crm_customer_save($pdo,$org,[
    'firstName'=>'Bob','lastName'=>'Stone','displayName'=>'Bob Stone','phone'=>'602-555-0111'
],$user,'manual');
$bobPublic=(string)$bob['public_id'];

$aliceSearch=crm_search($pdo,$org,'Alice',50,false);
crmci_assert(count($aliceSearch)===1&&($aliceSearch[0]['publicId']??'')===$alicePublic,'Name search must not over-match unrelated customers through an empty phone pattern.');
$minimal=crm_search($pdo,$org,'Alice',10,true);
crmci_assert(count($minimal)===1,'Minimal POS customer search must return the matching customer.');
crmci_assert(array_keys($minimal[0])===['publicId','displayName','email','phone'],'POS customer lookup must expose only minimal identity/contact fields.');

$duplicateEmail=false;try{crm_customer_save($pdo,$org,['displayName'=>'Alice Duplicate','email'=>'ALICE.RIVERA@example.test'],$user);}catch(InvalidArgumentException){$duplicateEmail=true;}
crmci_assert($duplicateEmail,'Exact normalized email duplicates must be blocked instead of auto-merged.');
$duplicatePhone=false;try{crm_customer_save($pdo,$org,['displayName'=>'Phone Duplicate','phone'=>'6025550199'],$user);}catch(InvalidArgumentException){$duplicatePhone=true;}
crmci_assert($duplicatePhone,'Exact normalized phone duplicates must be blocked instead of auto-merged.');
$invalidEmail=false;try{crm_customer_save($pdo,$org,['displayName'=>'Bad Email','email'=>'not-an-email'],$user);}catch(InvalidArgumentException){$invalidEmail=true;}
crmci_assert($invalidEmail,'Invalid email addresses must be rejected.');

crm_consent_set($pdo,$org,$alicePublic,'email','opted_in','manual','Customer asked for email offers',$user);
crm_consent_set($pdo,$org,$alicePublic,'sms','opted_in','manual','Customer asked for text offers',$user);
crm_consent_set($pdo,$org,$alicePublic,'email','opted_out','manual','Customer asked to stop email',$user);
$current=crm_current_consents($pdo,$org,$aliceId);$history=crm_consent_history($pdo,$org,$aliceId);
crmci_assert(($current['email']['status']??'')==='opted_out','Latest email consent event must control current email status.');
crmci_assert(($current['sms']['status']??'')==='opted_in','SMS consent must remain independently opted in.');
crmci_assert(count($history)===3,'Consent changes must be append-only history, not destructive updates.');
$emailOptInWithoutEmail=false;try{crm_consent_set($pdo,$org,$bobPublic,'email','opted_in','manual','Should fail',$user);}catch(InvalidArgumentException){$emailOptInWithoutEmail=true;}
crmci_assert($emailOptInWithoutEmail,'Email opt-in must require an email address.');

$notes=crm_note_add($pdo,$org,$alicePublic,'Prefers patio seating when available.',$user);crmci_assert(count($notes)===1,'Internal CRM note was not stored.');
$tags=crm_tag_add($pdo,$org,$alicePublic,'VIP Gäste',$user);crmci_assert(count($tags)===1&&$tags[0]['slug']==='vip-gäste','Unicode CRM tags must normalize without becoming empty.');
$tagId=(int)$tags[0]['id'];$tags=crm_tag_remove($pdo,$org,$alicePublic,$tagId);crmci_assert(count($tags)===0,'CRM tag removal failed.');
$tags=crm_tag_add($pdo,$org,$alicePublic,'Regular',$user);crmci_assert(count($tags)===1,'CRM tag reassignment failed.');

$open=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 4','guestCount'=>2],$user);$openPublic=(string)$open['publicId'];
crmci_assert(crm_attach_check($pdo,$org,$openPublic,$alicePublic)['publicId']===$alicePublic,'CRM customer must attach to an open POS check.');
crmci_assert(crm_check_customer($pdo,$org,$openPublic)['displayName']==='Alice Rivera','Attached customer must be readable through the minimal POS projection.');
crmci_assert(crm_attach_check($pdo,$org,$openPublic,null)===null,'POS detach must work without a customer id.');
crmci_assert(crm_attach_check($pdo,$org,$openPublic,$alicePublic)['publicId']===$alicePublic,'Customer must be attachable again after detach.');
$open=pos_add_item($pdo,$org,$openPublic,$price,1,'',$user);
$open=pos_record_tender($pdo,$org,$openPublic,['tenderType'=>'external_card','amount'=>20,'tipAmount'=>3,'externalReference'=>'crm_ci_txn_1'],$user);
crmci_assert($open['status']==='paid','First CRM-linked POS check must close as paid.');

$closedRelink=false;try{crm_attach_check($pdo,$org,$openPublic,$bobPublic);}catch(InvalidArgumentException){$closedRelink=true;}
crmci_assert($closedRelink,'Customer identity cannot be changed after a POS check closes.');

$visit2=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','tableName'=>'Alice Pickup','guestCount'=>1],$user);$visit2Public=(string)$visit2['publicId'];
crm_attach_check($pdo,$org,$visit2Public,$alicePublic);$visit2=pos_add_item($pdo,$org,$visit2Public,$price,1,'',$user);$visit2=pos_record_tender($pdo,$org,$visit2Public,['tenderType'=>'cash','amount'=>20,'tipAmount'=>0,'receivedAmount'=>20],$user);
crmci_assert($visit2['status']==='paid','Second CRM-linked POS check must close as paid.');

$profile=crm_profile($pdo,$org,$alicePublic);$metrics=$profile['metrics'];
crmci_assert((int)$metrics['visits']===2,'CRM visit count must be derived from paid POS checks.');
crmci_assert(abs((float)$metrics['lifetimeSpend']-40.0)<.01,'CRM lifetime spend must derive from paid POS totals excluding tips.');
crmci_assert(abs((float)$metrics['averageCheck']-20.0)<.01,'CRM average check must derive from paid POS checks.');
crmci_assert((int)$metrics['covers']===3,'CRM covers must derive from paid POS guest counts.');
crmci_assert(count($metrics['recentChecks'])===2,'CRM recent paid-visit history must come from POS checks.');
crmci_assert(count($metrics['favoriteItems'])===1&&(int)$metrics['favoriteItems'][0]['menuItemId']===$menuItem,'CRM favorite item must derive from paid POS line items.');
crmci_assert(abs((float)$metrics['favoriteItems'][0]['quantity']-2.0)<.001,'Favorite item quantity must aggregate paid POS history.');

$emailNeedle='%alice.rivera@example.test%';$phoneNeedle='%6025550199%';
crmci_assert((int)crmci_one($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND (COALESCE(source_metadata_json,'') LIKE ? OR COALESCE(source_metadata_json,'') LIKE ?)",[$org,$emailNeedle,$phoneNeedle])===0,'Customer email/phone must not leak into Sales Intelligence period metadata.');
crmci_assert((int)crmci_one($pdo,"SELECT COUNT(*) FROM sales_item_periods sip JOIN sales_periods sp ON sp.id=sip.sales_period_id WHERE sip.organization_id=? AND sp.organization_id=? AND (COALESCE(sip.item_name,'') LIKE ? OR COALESCE(sip.category_name,'') LIKE ?)",[$org,$org,$emailNeedle,$phoneNeedle])===0,'Customer identity must not leak into Sales Intelligence item rows.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['CRM Other Org '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$isolated=false;try{crm_profile($pdo,$otherOrg,$alicePublic);}catch(InvalidArgumentException){$isolated=true;}crmci_assert($isolated,'Customer profiles must be organization-isolated.');

$archiveCandidate=crm_customer_save($pdo,$org,['displayName'=>'Archived Guest','email'=>'archived.'.$slug.'@example.test'],$user);$archivePublic=(string)$archiveCandidate['public_id'];crm_customer_archive($pdo,$org,$archivePublic,$user);
$open2=pos_create_check($pdo,$org,$location,['serviceMode'=>'bar','tableName'=>'Bar 2','guestCount'=>1],$user);$archivedAttach=false;try{crm_attach_check($pdo,$org,(string)$open2['publicId'],$archivePublic);}catch(InvalidArgumentException){$archivedAttach=true;}crmci_assert($archivedAttach,'Archived CRM customers must not attach to an open POS check.');

crmci_assert((int)crmci_one($pdo,"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_customers' AND column_name IN ('lifetime_spend','visit_count','average_check','favorite_item')")===0,'Derived relationship metrics must not be duplicated into customer columns.');

echo "customer-crm contract passed\n";
