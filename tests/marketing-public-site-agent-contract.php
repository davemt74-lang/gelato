<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/package-deals-core.php';
require_once __DIR__.'/../includes/marketing-agent-core.php';
require_once __DIR__.'/../includes/marketing-brain.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';

$pdo=app_pdo();
function mkt_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function mkt_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

mkt_assert(package_deals_ready($pdo),'Package Deals must be installed.');
$slug='marketing-agent-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Marketing Agent CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Marketing','Manager','Marketing Manager']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$uid,$location]);$membership=(int)$pdo->lastInsertId();
$user=['id'=>$uid,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['packages.view','packages.manage','public_pages.edit','sales.view','crm.view'],'display_name'=>'Marketing Manager','first_name'=>'Marketing','role_slug'=>'manager'];

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Marketing Pizza',?,1)")->execute([$org,$section,'marketing-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();

$package=package_deal_save($pdo,$org,null,[
    'name'=>'Marketing CI Special','eyebrow'=>'Agent-tested special','description'=>'One pizza promotional package.','discountMethod'=>'percent','discountValue'=>10,'featured'=>false,
    'groups'=>[['label'=>'Pizza','requiredQuantity'=>1,'priceIds'=>[$price]]],
],$uid);
mkt_assert(($package['status']??'')==='draft','Marketing test package must begin as draft.');
$context=['module'=>'marketing','selectedPackagePublicId'=>(string)$package['id']];

$proposal=marketing_agent_handle($pdo,$user,['message'=>'activate this package','pageContext'=>$context]);
mkt_assert(($proposal['skill']??'')==='marketing.action_proposal','Activation must create a Marketing proposal.');
mkt_assert((string)mkt_one($pdo,'SELECT status FROM package_deals WHERE organization_id=? AND public_id=?',[$org,$package['id']])==='draft','Activation proposal must not write before Confirm.');
mkt_assert(gaw_pending_action_node($org,$uid)==='marketing','Main Agent confirmation ownership must follow the Marketing proposal.');
$confirmed=marketing_agent_handle($pdo,$user,['message'=>'Confirm']);
mkt_assert(($confirmed['skill']??'')==='marketing.action_confirmed','Confirm must execute the Marketing proposal.');
mkt_assert((string)mkt_one($pdo,'SELECT status FROM package_deals WHERE organization_id=? AND public_id=?',[$org,$package['id']])==='active','Confirmed activation must persist active status.');

$signals=marketing_brain_signal_rows($pdo,$user);
mkt_assert(count(array_filter($signals,static fn(array $row):bool=>(string)($row['key']??'')==='marketing:no-featured-package'))===1,'An active unfeatured package must surface a Marketing Brain signal.');

$stale=marketing_agent_handle($pdo,$user,['message'=>'pause this package','pageContext'=>$context]);
mkt_assert(($stale['skill']??'')==='marketing.action_proposal','Pause must create a proposal.');
$pdo->prepare('UPDATE package_deals SET updated_at=DATE_ADD(NOW(6),INTERVAL 5 SECOND) WHERE organization_id=? AND public_id=?')->execute([$org,$package['id']]);
$staleBlocked=false;try{marketing_agent_handle($pdo,$user,['message'=>'Confirm']);}catch(InvalidArgumentException){$staleBlocked=true;}
mkt_assert($staleBlocked,'A package changed after proposal must reject Confirm.');
mkt_assert((string)mkt_one($pdo,'SELECT status FROM package_deals WHERE organization_id=? AND public_id=?',[$org,$package['id']])==='active','Stale confirmation must not change package status.');
mkt_assert(gac_pending_get('marketing',$org,$uid)===null,'Rejected stale confirmation must clear the pending proposal.');

$feature=marketing_agent_handle($pdo,$user,['message'=>'feature this package on the specials page','pageContext'=>$context]);
mkt_assert(($feature['skill']??'')==='marketing.action_proposal','Feature request must create a proposal.');
mkt_assert((int)mkt_one($pdo,'SELECT featured FROM package_deals WHERE organization_id=? AND public_id=?',[$org,$package['id']])===0,'Feature proposal must not write before Confirm.');
marketing_agent_handle($pdo,$user,['message'=>'Confirm']);
mkt_assert((int)mkt_one($pdo,'SELECT featured FROM package_deals WHERE organization_id=? AND public_id=?',[$org,$package['id']])===1,'Confirmed feature action must persist Featured state.');

$beforePending=gac_pending_get('marketing',$org,$uid);
mkt_assert($beforePending===null,'No Marketing proposal should remain after confirmed feature action.');
$read=marketing_agent_handle($pdo,$user,['message'=>'What is featured on the public site?']);
mkt_assert(in_array(($read['skill']??''),['marketing.public_site','marketing.overview'],true),'Featured question must stay read-only.');
mkt_assert(gac_pending_get('marketing',$org,$uid)===null,'Read-only featured question must not create a pending write.');

$taglineBefore=(int)mkt_one($pdo,'SELECT COUNT(*) FROM public_site_settings WHERE organization_id=?',[$org]);
$taglineProposal=marketing_agent_handle($pdo,$user,['message'=>'set the public site tagline to Pizza, people, and good nights.']);
mkt_assert(($taglineProposal['skill']??'')==='marketing.action_proposal','Tagline update must create a proposal.');
mkt_assert((int)mkt_one($pdo,'SELECT COUNT(*) FROM public_site_settings WHERE organization_id=?',[$org])===$taglineBefore,'Tagline proposal must not write before Confirm.');
marketing_agent_handle($pdo,$user,['message'=>'Confirm']);
mkt_assert((string)mkt_one($pdo,'SELECT tagline FROM public_site_settings WHERE organization_id=?',[$org])==='Pizza, people, and good nights','Confirmed tagline must persist through public-site settings.');

marketing_agent_handle($pdo,$user,['message'=>'change the website tagline to A second approved tagline.']);
$pdo->prepare("UPDATE public_site_settings SET tagline='External concurrent edit',updated_at=DATE_ADD(NOW(6),INTERVAL 5 SECOND) WHERE organization_id=?")->execute([$org]);
$taglineStale=false;try{marketing_agent_handle($pdo,$user,['message'=>'Confirm']);}catch(InvalidArgumentException){$taglineStale=true;}
mkt_assert($taglineStale,'Concurrent public-site edit must reject stale tagline Confirm.');
mkt_assert((string)mkt_one($pdo,'SELECT tagline FROM public_site_settings WHERE organization_id=?',[$org])==='External concurrent edit','Stale tagline confirmation must preserve the newer public-site value.');

$consentBoundary=marketing_agent_handle($pdo,$user,['message'=>'Send an SMS blast to customers about this promotion.']);
mkt_assert(($consentBoundary['skill']??'')==='marketing.consent_boundary','Marketing Agent must refuse direct outreach/consent mutation.');
mkt_assert(gac_pending_get('marketing',$org,$uid)===null,'Consent-boundary request must not create a Marketing write proposal.');
$core=(string)file_get_contents(__DIR__.'/../includes/marketing-agent-core.php');
mkt_assert(!str_contains($core,'crm_consent_set('),'Marketing Agent must not mutate CRM consent directly.');

$route=gaw_route($user,'What package should we promote this week?');
mkt_assert(($route['domain']??'')==='marketing','Shared Agent router must classify package-promotion questions as Marketing.');
$viewer=['id'=>$uid,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['sales.view','crm.view'],'display_name'=>'Sales CRM Viewer','first_name'=>'Viewer','role_slug'=>'staff'];
$blocked=false;try{marketing_agent_handle($pdo,$viewer,['message'=>'What should we promote?']);}catch(MarketingAgentPermissionException){$blocked=true;}
mkt_assert($blocked,'Sales/CRM permissions alone must not grant Marketing or public-site access.');

mkt_assert((int)mkt_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='marketing.agent_package_status'",[$org])===1,'Confirmed package status action must be audited.');
mkt_assert((int)mkt_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='marketing.agent_package_featured'",[$org])===1,'Confirmed feature action must be audited.');
mkt_assert((int)mkt_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='marketing.agent_public_tagline'",[$org])===1,'Confirmed tagline action must be audited.');

echo "marketing-public-site-agent contract passed\n";
