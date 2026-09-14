<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/customer-account-core.php';

$pdo=app_pdo();
function caci_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function caci_one(PDO $pdo,string $sql,array $args=[]): mixed { $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn(); }

caci_assert(customer_account_ready($pdo),'Customer account foundation must be installed.');
$slug='customer-account-ci-'.bin2hex(random_bytes(4));

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Customer Account CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$customerRole=(int)caci_one($pdo,"SELECT id FROM roles WHERE organization_id=? AND slug='customer'",[$org]);
caci_assert($customerRole>0,'New organizations must automatically receive the Customer role.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key IN ('customer.portal','online_ordering.use')",[$customerRole])===2,'Future Customer roles must inherit both customer-facing permissions.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key IN ('pos.use','crm.manage','roles.edit','users.edit','audit.view')",[$customerRole])===0,'Customer role must not inherit staff or administration permissions.');

$email=$slug.'@example.test';
$phone='6025550188';
$public='customer-existing-'.bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO crm_customers (organization_id,public_id,first_name,last_name,display_name,email,email_normalized,phone,phone_normalized,status,source) VALUES (?,?,?,?,?,?,?,?,?,'active','pos')")
    ->execute([$org,$public,'Existing','Guest','Existing Guest',$email,$email,$phone,$phone]);

$password='Customer-CI-Password!42';
$result=customer_account_register($pdo,$org,[
    'firstName'=>'Existing','lastName'=>'Guest','email'=>$email,'phone'=>'',
    'password'=>$password,'passwordConfirm'=>$password,'emailMarketing'=>true,'smsMarketing'=>false,
]);
$userId=(int)$result['userId'];
$membershipId=(int)$result['membershipId'];
caci_assert($userId>0&&$membershipId>0,'Signup must create an application user and organization membership.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM users WHERE id=? AND email=?',[$userId,$email])===1,'Signup user was not persisted.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.membership_id=? AND ur.revoked_at IS NULL AND r.slug='customer'",[$membershipId])===1,'Signup must assign the Customer role.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND email_normalized=?',[$org,$email])===1,'Signup must not duplicate a CRM customer already known by email.');
caci_assert((int)caci_one($pdo,'SELECT user_id FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])===$userId,'Existing CRM customer must be linked to the application user.');
caci_assert((string)caci_one($pdo,'SELECT source FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])==='pos','Linking an existing customer must preserve its original acquisition source.');
caci_assert((string)caci_one($pdo,'SELECT phone_normalized FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])===$phone,'Omitting an optional phone at signup must not erase an existing CRM phone number.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_consents c JOIN crm_customers cc ON cc.id=c.customer_id WHERE cc.organization_id=? AND cc.user_id=? AND c.channel='email' AND c.consent_status='opted_in' AND c.source='web'",[$org,$userId])===1,'Explicit email marketing opt-in must be recorded append-only in CRM consent history.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_consents c JOIN crm_customers cc ON cc.id=c.customer_id WHERE cc.organization_id=? AND cc.user_id=? AND c.channel='sms'",[$org,$userId])===0,'Unchecked SMS marketing must remain unknown instead of being treated as an opt-in.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_tags ct JOIN crm_customers cc ON cc.id=ct.customer_id JOIN crm_tags t ON t.id=ct.tag_id WHERE cc.organization_id=? AND cc.user_id=? AND t.slug='online-customer'",[$org,$userId])===1,'Signup must mark the CRM profile as an Online Customer.');

$duplicateBlocked=false;
try{
    customer_account_register($pdo,$org,['firstName'=>'Existing','lastName'=>'Guest','email'=>$email,'phone'=>$phone,'password'=>$password,'passwordConfirm'=>$password]);
}catch(InvalidArgumentException){$duplicateBlocked=true;}
caci_assert($duplicateBlocked,'A second signup for an existing Customer membership must be directed to login, not duplicated.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Customer Account Second '.$slug]);
$org2=(int)$pdo->lastInsertId();
$result2=customer_account_register($pdo,$org2,['firstName'=>'Existing','lastName'=>'Guest','email'=>$email,'phone'=>'','password'=>$password,'passwordConfirm'=>$password]);
caci_assert((int)$result2['userId']===$userId,'A verified existing global user should be reused rather than duplicated across restaurant organizations.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE organization_id=? AND user_id=? AND status=\'active\'',[$org2,$userId])===1,'Verified existing user must receive a scoped membership in the second organization.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND user_id=?',[$org2,$userId])===1,'Each merchant must receive its own CRM customer link for the shared application user.');

$weakBlocked=false;
try{
    customer_account_register($pdo,$org,['firstName'=>'Weak','lastName'=>'Password','email'=>'weak-'.$email,'password'=>'password','passwordConfirm'=>'password']);
}catch(InvalidArgumentException){$weakBlocked=true;}
caci_assert($weakBlocked,'Customer signup must enforce the production password policy.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Customer Account Third '.$slug]);
$org3=(int)$pdo->lastInsertId();
$wrongPasswordBlocked=false;
try{
    customer_account_register($pdo,$org3,['firstName'=>'Existing','lastName'=>'Guest','email'=>$email,'password'=>'Wrong-Password!123','passwordConfirm'=>'Wrong-Password!123']);
}catch(InvalidArgumentException){$wrongPasswordBlocked=true;}
caci_assert($wrongPasswordBlocked,'An existing global user must prove the existing password before a new merchant membership can be added.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org3,$userId])===0,'Wrong-password signup attempt must not create a merchant membership.');

caci_assert(customer_account_safe_return('customer-account.php?tab=orders')==='customer-account.php?tab=orders','Safe same-site return URL should be preserved.');
caci_assert(customer_account_safe_return('https://evil.example/')==='customer-account.php','Absolute return URLs must be rejected.');
caci_assert(customer_account_safe_return('../admin.php')==='customer-account.php','Traversal return URLs must be rejected.');

echo "customer-account contract passed\n";
