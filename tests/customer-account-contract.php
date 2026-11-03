<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/customer-account-core.php';
require __DIR__.'/customer-account-fixtures.php';

$pdo=app_pdo();
function caci_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function caci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function caci_reject(callable $action,string $message): void
{
    $blocked=false;try{$action();}catch(InvalidArgumentException){$blocked=true;}
    caci_assert($blocked,$message);
}
function caci_request(PDO $pdo,int $org,array $input): array
{
    $r=customer_account_register($pdo,$org,$input);
    caci_assert(customer_account_deliver_verification($pdo,$r,static fn():bool=>true),'Test mailbox must accept the message.');
    return $r;
}

caci_assert(customer_account_verification_ready($pdo),'Customer verification migration must be installed.');
$slug='customer-account-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Customer Account CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$customerRole=(int)caci_one($pdo,"SELECT id FROM roles WHERE organization_id=? AND slug='customer'",[$org]);
caci_assert($customerRole>0,'New organizations must automatically receive the Customer role.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key IN ('customer.portal','online_ordering.use')",[$customerRole])===2,'Customer roles must receive both customer-facing permissions.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key IN ('pos.use','crm.manage','roles.edit','users.edit','audit.view')",[$customerRole])===0,'Customer role must not inherit staff permissions.');

$email=$slug.'@example.test';$phone='6025550188';$public='customer-existing-'.bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO crm_customers (organization_id,public_id,first_name,last_name,display_name,email,email_normalized,phone,phone_normalized,status,source) VALUES (?,?,?,?,?,?,?,?,?,'active','pos')")
    ->execute([$org,$public,'Existing','Guest','Existing Guest',$email,$email,$phone,$phone]);
$password='Customer-CI-Password!42';
$input=['firstName'=>'Existing','lastName'=>'Guest','email'=>$email,'phone'=>'','emailMarketing'=>true,'smsMarketing'=>false,'return'=>'customer-account.php?tab=orders'];
// Supplying a password before the mailbox proof must have no effect.
$request=customer_account_register($pdo,$org,$input+['password'=>'Attacker-Known!123','passwordConfirm'=>'Attacker-Known!123']);
caci_assert(!isset($request['userId'],$request['customerPublicId']),'An enrollment request must not disclose or create identity IDs.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM users WHERE email=?',[$email])===0,'Unverified signup must not create a global user.');
caci_assert(caci_one($pdo,'SELECT user_id FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])===null,'Unverified signup must not claim the existing CRM customer.');
caci_assert((string)caci_one($pdo,'SELECT phone FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])===$phone,'Unverified signup must not alter existing private profile fields.');
caci_assert((string)caci_one($pdo,'SELECT token_hash FROM customer_account_verifications WHERE id=?',[$request['requestId']])===hash('sha256',(string)$request['token']),'Only the token hash may be stored.');
caci_assert(customer_account_verification_request($pdo,$org,(string)$request['token'])===null,'An unaccepted delivery must not activate a token.');
$capture=[];
$sent=customer_account_deliver_verification($pdo,$request,static function(string $to,string $subject,string $body,string $headers)use($pdo,&$capture):bool{
    caci_assert(!$pdo->inTransaction(),'Mail must run after commit, outside a database transaction.');
    $capture=compact('to','subject','body','headers');return true;
});
caci_assert($sent&&$capture['to']===$email&&str_contains($capture['body'],(string)$request['token']),'Mailbox delivery must address the requested email and contain its token.');
caci_assert(!str_contains($capture['body'],$phone)&&!str_contains($capture['body'],'Attacker-Known!123'),'Verification email must not reveal CRM data or retain a pre-verification password.');
caci_assert(customer_account_verification_request($pdo,$org+100000,(string)$request['token'])===null,'Another tenant must not resolve a token.');
caci_assert(customer_account_verification_request($pdo,$org,(string)$request['token'])!==null,'GET inspection may display a valid request.');
caci_assert(customer_account_verification_request($pdo,$org,(string)$request['token'])!==null,'GET inspection must not consume the token.');
caci_reject(fn()=>customer_account_complete_registration($pdo,$org,(string)$request['token'],['password'=>'weak','passwordConfirm'=>'weak']),'New passwords must meet policy.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM users WHERE email=?',[$email])===0,'Invalid completion must roll back user creation.');
$result=customer_account_complete_registration($pdo,$org,(string)$request['token'],['password'=>$password,'passwordConfirm'=>$password,'emailMarketing'=>true]);
$userId=(int)$result['userId'];$membershipId=(int)$result['membershipId'];
caci_assert($userId>0&&$membershipId>0,'Verified signup must create a user and scoped membership.');
caci_assert((bool)caci_one($pdo,'SELECT email_verified_at IS NOT NULL FROM users WHERE id=?',[$userId]),'Successful mailbox proof must be recorded.');
caci_assert(password_verify($password,(string)caci_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$userId])),'The recipient-chosen password must be saved.');
caci_assert(!password_verify('Attacker-Known!123',(string)caci_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$userId])),'A pre-verification password must never control the account.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.membership_id=? AND ur.revoked_at IS NULL AND r.slug='customer'",[$membershipId])===1,'Verified signup must assign exactly one Customer role.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND email_normalized=?',[$org,$email])===1,'Verified linking must not duplicate the CRM profile.');
caci_assert((int)caci_one($pdo,'SELECT user_id FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])===$userId,'Mailbox proof must link the existing profile.');
caci_assert((string)caci_one($pdo,'SELECT source FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])==='pos','Linking must preserve acquisition source.');
caci_assert((string)caci_one($pdo,'SELECT phone_normalized FROM crm_customers WHERE organization_id=? AND public_id=?',[$org,$public])===$phone,'Omitted phone must preserve the existing phone.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_consents c JOIN crm_customers cc ON cc.id=c.customer_id WHERE cc.organization_id=? AND cc.user_id=? AND c.channel='email' AND c.consent_status='opted_in' AND c.source='web'",[$org,$userId])===1,'Recipient-confirmed email consent must be append-only.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_consents c JOIN crm_customers cc ON cc.id=c.customer_id WHERE cc.organization_id=? AND cc.user_id=? AND c.channel='sms'",[$org,$userId])===0,'Unchecked SMS consent must remain unknown.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_tags ct JOIN crm_customers cc ON cc.id=ct.customer_id JOIN crm_tags t ON t.id=ct.tag_id WHERE cc.organization_id=? AND cc.user_id=? AND t.slug='online-customer'",[$org,$userId])===1,'Verified signup must mark Online Customer.');
caci_assert($result['return']==='customer-account.php?tab=orders','Safe return destination must survive verification.');
caci_reject(fn()=>customer_account_complete_registration($pdo,$org,(string)$request['token'],['password'=>$password,'passwordConfirm'=>$password]),'Used token must reject replay.');
caci_reject(fn()=>customer_account_register($pdo,$org,$input),'Repeated email requests must be rate limited.');

$pdo->prepare('UPDATE customer_account_verifications SET last_requested_at=DATE_SUB(NOW(6),INTERVAL 61 SECOND) WHERE id=?')->execute([$request['requestId']]);
$reverify=caci_request($pdo,$org,array_merge($input,['firstName'=>'Replacement','phone'=>'6024440101']));
$again=customer_account_complete_registration($pdo,$org,(string)$reverify['token'],['password'=>$password,'passwordConfirm'=>$password]);
caci_assert((int)$again['userId']===$userId,'Re-verification must not duplicate an existing account.');
caci_assert((string)caci_one($pdo,'SELECT first_name FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId])==='Existing','Re-verifying must preserve an already-linked profile.');
caci_assert((string)caci_one($pdo,'SELECT phone_normalized FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId])===$phone,'Re-verifying must preserve the established CRM phone.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.membership_id=? AND ur.revoked_at IS NULL AND r.slug='customer'",[$membershipId])===1,'Re-verification must not duplicate the Customer role.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Customer Second '.$slug]);$org2=(int)$pdo->lastInsertId();
$r2=caci_request($pdo,$org2,$input);
caci_reject(fn()=>customer_account_complete_registration($pdo,$org2,(string)$r2['token'],['password'=>'Wrong-Password!123','passwordConfirm'=>'Wrong-Password!123']),'Mailbox proof alone must not replace an existing password.');
caci_assert((int)caci_one($pdo,'SELECT failed_login_count FROM users WHERE id=?',[$userId])===1,'Failed verification password must persist in shared login counters.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org2,$userId])===0,'Failed password proof must not create membership.');
for($attempt=0;$attempt<4;$attempt++) {
    caci_reject(fn()=>customer_account_complete_registration($pdo,$org2,(string)$r2['token'],['password'=>'Wrong-Password!123','passwordConfirm'=>'Wrong-Password!123']),'Repeated wrong verification passwords must be rejected.');
}
caci_assert((bool)caci_one($pdo,'SELECT locked_until>NOW(6) FROM users WHERE id=?',[$userId]),'Five signup verification failures must engage shared account lockout.');
caci_reject(fn()=>customer_account_complete_registration($pdo,$org2,(string)$r2['token'],['password'=>$password,'passwordConfirm'=>$password]),'Correct password must not bypass account lockout through signup.');
caci_assert((bool)caci_one($pdo,'SELECT locked_until>NOW(6) FROM users WHERE id=?',[$userId]),'Verification must not clear an active lockout.');
$pdo->prepare('UPDATE users SET locked_until=NULL,failed_login_count=0 WHERE id=?')->execute([$userId]);
$result2=customer_account_complete_registration($pdo,$org2,(string)$r2['token'],['password'=>$password,'passwordConfirm'=>$password]);
caci_assert((int)$result2['userId']===$userId,'A verified existing global user must be reused across organizations.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND user_id=?',[$org2,$userId])===1,'The new restaurant must receive its own scoped CRM linkage.');
caci_assert((int)caci_one($pdo,"SELECT COUNT(*) FROM crm_customer_consents c JOIN crm_customers cc ON cc.id=c.customer_id WHERE cc.organization_id=? AND cc.user_id=?",[$org2,$userId])===0,'Unconfirmed pending marketing choices must never grant consent.');

$expired=caci_request($pdo,$org,array_merge($input,['email'=>'expired-'.$email]));
$pdo->prepare('UPDATE customer_account_verifications SET expires_at=DATE_SUB(NOW(6),INTERVAL 1 SECOND) WHERE id=?')->execute([$expired['requestId']]);
caci_reject(fn()=>customer_account_complete_registration($pdo,$org,(string)$expired['token'],['password'=>$password,'passwordConfirm'=>$password]),'Expired tokens must not create accounts.');
$failure=customer_account_register($pdo,$org,array_merge($input,['email'=>'failure-'.$email]));
caci_assert(!customer_account_deliver_verification($pdo,$failure,static fn():bool=>false),'Delivery failure must be reported, not displayed as success.');
caci_assert(customer_account_verification_request($pdo,$org,(string)$failure['token'])===null,'Delivery failure must invalidate the token.');
$replacement=customer_account_register($pdo,$org,array_merge($input,['email'=>'failure-'.$email]));
caci_assert($replacement['token']!==$failure['token'],'A failed delivery may be retried with a new token.');
caci_assert((int)caci_one($pdo,'SELECT COUNT(*) FROM users WHERE email=?',['failure-'.$email])===0,'Delivery failure/retry must not create an account.');

// Server helper refuses an unverified direct CRM claim even outside the public controller.
$rogueEmail='unverified-'.$email;
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$rogueEmail,app_password_hash($password),'Unverified','Guest','Unverified Guest']);$rogue=(int)$pdo->lastInsertId();
$pdo->beginTransaction();
caci_reject(fn()=>customer_account_link_crm($pdo,$org,$rogue,['firstName'=>'Unverified','lastName'=>'Guest','email'=>$rogueEmail]),'Linkage helper itself must require mailbox proof.');
$pdo->rollBack();

// An account created by the old unverified-claim flow must not keep customer access.
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,job_title,status) VALUES (?,?,'Customer','active')")->execute([$org,$rogue]);
$legacyMembership=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles (membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$legacyMembership,$customerRole,$rogue]);
$legacyPublic='legacy-'.bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO crm_customers (organization_id,user_id,public_id,first_name,last_name,display_name,email,email_normalized,status,source) VALUES (?,?,?,?,?,?,?,?,'active','pos')")
    ->execute([$org,$rogue,$legacyPublic,'Legacy','Profile','Legacy Profile',$rogueEmail,$rogueEmail]);
$legacyCustomer=(int)$pdo->lastInsertId();
caci_reject(fn()=>customer_account_authenticate($pdo,$org,$rogueEmail,$password),'Legacy unverified customers must not sign into the customer portal.');
caci_reject(fn()=>customer_account_start_session($pdo,$org,$rogue),'Customer session helper must reject unverified identity.');
caci_reject(fn()=>customer_account_profile($pdo,$org,$rogue),'Legacy unverified customer profile must not be disclosed.');
caci_reject(fn()=>customer_account_orders($pdo,$org,$legacyCustomer),'Legacy unverified customer order history must not be disclosed.');
$staleAccount=['id'=>$rogue,'organization_id'=>$org,'customer_id'=>$legacyCustomer,'permissions'=>['customer.portal','online_ordering.use'],'email_verified_at'=>'2026-01-01 00:00:00'];
caci_reject(fn()=>customer_account_assert_order_identity($pdo,$org,$staleAccount),'Stale or invented account verification flags must not permit ordering.');
$legacyHash=(string)caci_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$rogue]);
app_session_start_user($pdo,$org,$rogue,'password',$legacyHash);
caci_assert(app_current_user(true)!==null,'Mailbox gating must not globally disable an otherwise valid application session.');
caci_assert(customer_account_current($pdo,$org)===null,'Signing in through the general staff route must not bypass customer verification.');
$legacyRequest=caci_request($pdo,$org,['firstName'=>'Legacy','lastName'=>'Profile','email'=>$rogueEmail]);
$legacyVerified=customer_account_complete_registration($pdo,$org,(string)$legacyRequest['token'],['password'=>$password,'passwordConfirm'=>$password]);
caci_assert((int)$legacyVerified['userId']===$rogue,'Mailbox proof must restore the existing account, not create a duplicate.');
customer_account_authenticate($pdo,$org,$rogueEmail,$password);
app_current_user(true);
$restored=customer_account_current($pdo,$org);
caci_assert(is_array($restored)&&(int)$restored['customer_id']===$legacyCustomer,'Verified legacy identity must regain its original scoped account.');
customer_account_assert_order_identity($pdo,$org,$restored);
$missingActor=$restored;unset($missingActor['id']);
caci_reject(fn()=>customer_account_assert_order_identity($pdo,$org,$missingActor),'Order identity must require an explicit authenticated actor ID.');
$wrongActor=$restored;$wrongActor['id']=$userId;
caci_reject(fn()=>customer_account_assert_order_identity($pdo,$org,$wrongActor),'Another verified actor must not use a different customer ID.');
$missingCustomer=$restored;unset($missingCustomer['customer_id']);
caci_reject(fn()=>customer_account_assert_order_identity($pdo,$org,$missingCustomer),'Order identity must require a customer ID.');
caci_assert(customer_account_profile($pdo,$org,$rogue)['displayName']==='Legacy Profile','Mailbox proof must preserve existing profile data.');

// Verifying a replacement account email cannot unlock a CRM linked under another email.
$replacementEmail='replacement-'.$rogueEmail;
$pdo->prepare('UPDATE users SET email=?,email_verified_at=NULL WHERE id=?')->execute([$replacementEmail,$rogue]);
caci_assert(customer_account_verified_identity($pdo,$org,$rogue)===null,'Changed email must not retain customer identity.');
$changedRequest=caci_request($pdo,$org,['firstName'=>'Replacement','lastName'=>'Email','email'=>$replacementEmail]);
caci_reject(fn()=>customer_account_complete_registration($pdo,$org,(string)$changedRequest['token'],['password'=>$password,'passwordConfirm'=>$password]),'Proof of a replacement email must not reclaim a differently addressed CRM profile.');
caci_assert(caci_one($pdo,'SELECT email_verified_at FROM users WHERE id=?',[$rogue])===null,'Rejected mismatched linkage must roll back verification.');
caci_assert((string)caci_one($pdo,'SELECT email_normalized FROM crm_customers WHERE id=?',[$legacyCustomer])===$rogueEmail,'Verification must not rewrite the original CRM email to bypass mismatch.');
$pdo->prepare('UPDATE users SET email_verified_at=NOW(6) WHERE id=?')->execute([$rogue]);
caci_assert(customer_account_verified_identity($pdo,$org,$rogue)===null,'Even a separately verified replacement email must not unlock the mismatched CRM.');
caci_reject(fn()=>customer_account_profile($pdo,$org,$rogue),'Mismatched verified email must not disclose the customer profile.');
caci_reject(fn()=>customer_account_assert_order_identity($pdo,$org,$restored),'A stale verified customer payload must not bypass changed email.');

caci_assert(customer_account_safe_return('customer-account.php?tab=orders')==='customer-account.php?tab=orders','Safe return URL should be preserved.');
caci_assert(customer_account_safe_return('https://evil.example/')==='customer-account.php','Absolute return URL must be rejected.');
caci_assert(customer_account_safe_return('../admin.php')==='customer-account.php','Traversal return URL must be rejected.');
echo "customer-account email verification and lockout contract passed\n";
