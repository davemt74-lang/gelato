<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/customer-crm-core.php';

final class CustomerAccountVerificationRequired extends InvalidArgumentException {}

function customer_account_ready(PDO $pdo): bool
{
    if (!crm_ready($pdo)) return false;
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_customers' AND column_name='user_id'");
    if ((int)$q->fetchColumn()!==1) return false;
    $q=$pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('customer.portal','online_ordering.use')");
    return (int)$q->fetchColumn()===2;
}

function customer_account_verification_ready(PDO $pdo): bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='customer_account_verifications'");
    return customer_account_ready($pdo) && (int)$q->fetchColumn()===1;
}

function customer_account_safe_return(?string $value,string $fallback='customer-account.php'): string
{
    $value=trim((string)$value);
    if($value===''||strlen($value)>500||str_contains($value,'..')||str_starts_with($value,'//')||preg_match('/^[a-z][a-z0-9+.-]*:/i',$value)) return $fallback;
    return preg_match('/^[A-Za-z0-9_\/.?=&%#-]+$/',$value)?$value:$fallback;
}

function customer_account_password_error(string $password): ?string
{
    if(strlen($password)<12 || strlen($password)>200
        || !preg_match('/[A-Z]/',$password)
        || !preg_match('/[a-z]/',$password)
        || !preg_match('/\d/',$password)
        || !preg_match('/[^A-Za-z0-9]/',$password)) {
        return 'Use 12 to 200 characters with uppercase, lowercase, a number, and a symbol.';
    }
    return null;
}

function customer_account_role_id(PDO $pdo,int $organizationId): int
{
    $q=$pdo->prepare("SELECT r.id FROM roles r JOIN organizations o ON o.id=r.organization_id AND o.status='active' WHERE r.organization_id=? AND r.slug='customer' AND r.is_assignable=1 LIMIT 1");
    $q->execute([$organizationId]);
    $id=(int)$q->fetchColumn();
    if($id<1) throw new RuntimeException('Customer accounts are not configured for this restaurant. Run Upgrade first.');
    return $id;
}

function customer_account_membership(PDO $pdo,int $organizationId,int $userId): ?array
{
    $q=$pdo->prepare('SELECT * FROM organization_memberships WHERE organization_id=? AND user_id=? LIMIT 1');
    $q->execute([$organizationId,$userId]);
    return $q->fetch()?:null;
}

function customer_account_has_role(PDO $pdo,int $membershipId): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN organization_memberships om ON om.id=ur.membership_id JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id WHERE ur.membership_id=? AND ur.revoked_at IS NULL AND r.slug='customer'");
    $q->execute([$membershipId]);
    return (int)$q->fetchColumn()>0;
}

function customer_account_start_session(PDO $pdo,int $organizationId,int $userId,?string $expectedPasswordHash=null): void
{
    if(!customer_account_verified_identity($pdo,$organizationId,$userId)) {
        throw new CustomerAccountVerificationRequired('Verify your email before opening your customer account.');
    }
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
        app_session_start_user($pdo,$organizationId,$userId,'customer_password',$expectedPasswordHash);
        $pdo->prepare('UPDATE users SET failed_login_count=0,locked_until=NULL,last_login_at=NOW(6) WHERE id=?')->execute([$userId]);
        if($owns)$pdo->commit();
    } catch(Throwable $e) {
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        app_session_forget_user();
        throw $e;
    }
}

/** Called inside the verification transaction, after mailbox and password proof. */
function customer_account_link_crm(PDO $pdo,int $organizationId,int $userId,array $data): array
{
    if(!$pdo->inTransaction()) throw new LogicException('Customer linkage requires a transaction.');
    $email=(string)crm_normalize_email((string)$data['email']);
    $q=$pdo->prepare('SELECT email,email_verified_at FROM users WHERE id=? AND archived_at IS NULL FOR UPDATE');
    $q->execute([$userId]);
    $identity=$q->fetch();
    if(!$identity || empty($identity['email_verified_at']) || !hash_equals($email,(string)crm_normalize_email((string)$identity['email']))) {
        throw new InvalidArgumentException('Verify your account email before linking a customer profile.');
    }
    $first=mb_substr(trim((string)$data['firstName']),0,100,'UTF-8');
    $last=mb_substr(trim((string)$data['lastName']),0,100,'UTF-8');
    $display=mb_substr(trim($first.' '.$last),0,160,'UTF-8');
    $phoneRaw=mb_substr(trim((string)($data['phone']??'')),0,40,'UTF-8');
    $phone=crm_normalize_phone($phoneRaw);
    $q=$pdo->prepare('SELECT * FROM crm_customers WHERE organization_id=? AND user_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$organizationId,$userId]);
    $customer=$q->fetch()?:null;
    // Re-verifying an existing account must not overwrite its established profile.
    if($customer) {
        $linkedEmail=(string)crm_normalize_email((string)($customer['email_normalized']??$customer['email']??''));
        if(!hash_equals($email,$linkedEmail)) {
            throw new CustomerAccountVerificationRequired('Your linked customer profile uses a different email. Contact the restaurant to review the account link.');
        }
        return $customer;
    }
    $q=$pdo->prepare("SELECT * FROM crm_customers WHERE organization_id=? AND status='active' AND email_normalized=? ORDER BY id LIMIT 1 FOR UPDATE");
    $q->execute([$organizationId,$email]);
    $customer=$q->fetch()?:null;
    if($customer && !empty($customer['user_id']) && (int)$customer['user_id']!==$userId) {
        throw new InvalidArgumentException('That customer profile is already linked to another account. Contact the restaurant.');
    }
    if($customer && !$phone) {
        $phoneRaw=trim((string)($customer['phone']??''));
        $phone=$customer['phone_normalized']!==null?(string)$customer['phone_normalized']:null;
    }
    if($phone) {
        $args=[$organizationId,$phone];
        $sql="SELECT id FROM crm_customers WHERE organization_id=? AND status='active' AND phone_normalized=?";
        if($customer){$sql.=' AND id<>?';$args[]=(int)$customer['id'];}
        $q=$pdo->prepare($sql.' ORDER BY id LIMIT 1');$q->execute($args);
        if($q->fetchColumn()) throw new InvalidArgumentException('That phone number is already attached to another customer profile.');
    }
    if($customer) {
        $pdo->prepare('UPDATE crm_customers SET user_id=?,first_name=?,last_name=?,display_name=?,email=?,email_normalized=?,phone=?,phone_normalized=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND user_id IS NULL')
            ->execute([$userId,$first,$last,$display,$email,$email,$phoneRaw?:null,$phone,$userId,$organizationId,(int)$customer['id']]);
        return crm_customer_row($pdo,$organizationId,(string)$customer['public_id']);
    }
    $public=crm_public_id();
    $pdo->prepare("INSERT INTO crm_customers (organization_id,user_id,public_id,first_name,last_name,display_name,email,email_normalized,phone,phone_normalized,status,source,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,'active','web',?,?)")
        ->execute([$organizationId,$userId,$public,$first,$last,$display,$email,$email,$phoneRaw?:null,$phone,$userId,$userId]);
    return crm_customer_row($pdo,$organizationId,$public);
}

/**
 * Begin email-first enrollment. This creates no user, membership, session, or CRM
 * linkage. The raw token is an internal delivery value and must never be echoed.
 */
function customer_account_register(PDO $pdo,int $organizationId,array $input): array
{
    if(!customer_account_verification_ready($pdo)) throw new RuntimeException('Customer email verification is not installed. Run Upgrade first.');
    if($pdo->inTransaction()) throw new LogicException('Begin customer registration outside a transaction.');
    $first=mb_substr(trim((string)($input['firstName']??'')),0,100,'UTF-8');
    $last=mb_substr(trim((string)($input['lastName']??'')),0,100,'UTF-8');
    if($first===''||$last==='') throw new InvalidArgumentException('Enter your first and last name.');
    $email=(string)crm_normalize_email((string)($input['email']??''));
    if($email===''||mb_strlen($email,'UTF-8')>254) throw new InvalidArgumentException('Enter an email address of 254 characters or fewer.');
    $phoneRaw=mb_substr(trim((string)($input['phone']??'')),0,40,'UTF-8');
    $phone=crm_normalize_phone($phoneRaw);
    if(!empty($input['smsMarketing'])&&!$phone) throw new InvalidArgumentException('Add a mobile phone number before opting in to SMS updates.');
    $return=customer_account_safe_return($input['return']??null);
    $token=bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        customer_account_role_id($pdo,$organizationId);
        // The unique organization/email row serializes rate limits and token replacement.
        $pdo->prepare("INSERT INTO customer_account_verifications (organization_id,email,first_name,last_name) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")
            ->execute([$organizationId,$email,$first,$last]);
        $q=$pdo->prepare("SELECT *,TIMESTAMPDIFF(SECOND,last_requested_at,NOW(6)) request_age,window_started_at<=DATE_SUB(NOW(6),INTERVAL 1 HOUR) reset_window FROM customer_account_verifications WHERE organization_id=? AND email=? FOR UPDATE");
        $q->execute([$organizationId,$email]);$request=$q->fetch();
        if(!$request) throw new RuntimeException('Unable to create the email verification request.');
        $count=(int)$request['reset_window']===1?0:(int)$request['request_count'];
        if($count>=5 || ($request['last_requested_at']!==null && (int)$request['request_age']<60 && $request['delivery_status']!=='failed')) {
            throw new InvalidArgumentException('A verification email was requested recently. Please wait before trying again.');
        }
        $pdo->prepare("UPDATE customer_account_verifications SET first_name=?,last_name=?,phone=?,email_marketing=?,sms_marketing=?,return_path=?,token_hash=?,delivery_status='pending',request_count=?,window_started_at=IF(?,NOW(6),window_started_at),last_requested_at=NOW(6),expires_at=DATE_ADD(NOW(6),INTERVAL 60 MINUTE),used_at=NULL,verification_attempts=0 WHERE id=? AND organization_id=?")
            ->execute([$first,$last,$phoneRaw?:null,!empty($input['emailMarketing'])?1:0,!empty($input['smsMarketing'])?1:0,$return,hash('sha256',$token),$count+1,(int)$request['reset_window'],(int)$request['id'],$organizationId]);
        $pdo->commit();
        return ['verificationRequired'=>true,'requestId'=>(int)$request['id'],'organizationId'=>$organizationId,'email'=>$email,'token'=>$token];
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** Mail runs after commit. A test transport captures messages without real delivery. */
function customer_account_deliver_verification(PDO $pdo,array $request,?callable $transport=null): bool
{
    if($pdo->inTransaction()) throw new LogicException('Email must be sent after the registration transaction commits.');
    $config=app_config();
    $appName=str_replace(["\r","\n"],'',(string)($config['app']['name']??'Restaurant'));
    $mailConfig=$config['mail']??[];
    $host=(string)(parse_url((string)($config['app']['url']??''),PHP_URL_HOST)?:'localhost');
    $fromName=str_replace(["\r","\n"],'',trim((string)($mailConfig['from_name']??$appName)));
    $fromEmail=str_replace(["\r","\n"],'',trim((string)($mailConfig['from_email']??('no-reply@'.$host))));
    $url=app_url('customer-verify-email.php?token='.rawurlencode((string)$request['token']));
    $subject=$appName.' customer email verification';
    $body="Someone requested a customer account for this email at {$appName}.\n\n"
        ."If you requested it, open this link and choose your own password (or enter your existing account password) to confirm:\n{$url}\n\n"
        ."This link expires in 60 minutes and can be used once. No account or customer profile has been linked yet. If you did not request this, ignore this message.\n";
    $headers=implode("\r\n",['From: '.$fromName.' <'.$fromEmail.'>','Reply-To: '.$fromEmail,'Content-Type: text/plain; charset=UTF-8']);
    $sent=false;
    try {$sent=$transport!==null?(bool)$transport((string)$request['email'],$subject,$body,$headers):(function_exists('mail')&&@mail((string)$request['email'],$subject,$body,$headers));} catch(Throwable) {$sent=false;}
    $q=$pdo->prepare("UPDATE customer_account_verifications SET delivery_status=?,used_at=IF(?,NULL,NOW(6)) WHERE id=? AND organization_id=? AND token_hash=? AND delivery_status='pending'");
    $q->execute([$sent?'sent':'failed',$sent?1:0,(int)$request['requestId'],(int)$request['organizationId'],hash('sha256',(string)$request['token'])]);
    return $sent && $q->rowCount()===1;
}

function customer_account_verification_request(PDO $pdo,int $organizationId,string $token,bool $forUpdate=false): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
    $q=$pdo->prepare("SELECT * FROM customer_account_verifications WHERE organization_id=? AND token_hash=? AND delivery_status='sent' AND used_at IS NULL AND expires_at>NOW(6) AND verification_attempts<10 LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$organizationId,hash('sha256',$token)]);
    return $q->fetch()?:null;
}

/** Account row must be locked; caller commits failed counters before reporting failure. */
function customer_account_password_matches(PDO $pdo,array $user,string $password): bool
{
    if((string)$user['status']!=='active'||!empty($user['archived_at'])||!empty($user['login_locked'])) return false;
    if(strlen($password)<=200 && password_verify($password,(string)$user['password_hash'])) return true;
    app_auth_record_failure($pdo,(int)$user['id']);
    return false;
}

function customer_account_complete_registration(PDO $pdo,int $organizationId,string $token,array $input): array
{
    if($pdo->inTransaction()) throw new LogicException('Complete customer registration outside a transaction.');
    $password=(string)($input['password']??'');
    if(!hash_equals($password,(string)($input['passwordConfirm']??''))) throw new InvalidArgumentException('The password confirmation does not match.');
    $pdo->beginTransaction();
    try {
        $request=customer_account_verification_request($pdo,$organizationId,$token,true);
        if(!$request) throw new InvalidArgumentException('This verification link is invalid, expired, or already used. Request another email.');
        $pdo->prepare('UPDATE customer_account_verifications SET verification_attempts=verification_attempts+1 WHERE id=?')->execute([(int)$request['id']]);
        $q=$pdo->prepare('SELECT *,locked_until>NOW(6) login_locked FROM users WHERE email=? LIMIT 1 FOR UPDATE');
        $q->execute([(string)$request['email']]);$user=$q->fetch()?:null;
        $newUser=$user===null;
        if($user && !customer_account_password_matches($pdo,$user,$password)) {
            // The token remains unconsumed, but shared account lockout is durable.
            $pdo->commit();
            usleep(random_int(180000,420000));
            throw new InvalidArgumentException('The account password is incorrect or the account is unavailable. Use sign-in recovery or try again later.');
        }
        if($newUser && ($error=customer_account_password_error($password))) throw new InvalidArgumentException($error);
        $roleId=customer_account_role_id($pdo,$organizationId);
        if($newUser) {
            $hash=app_password_hash($password);
            $display=mb_substr(trim((string)$request['first_name'].' '.(string)$request['last_name']),0,160,'UTF-8');
            $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,phone,status,email_verified_at,password_changed_at) VALUES (?,?,?,?,?,?,'active',NOW(6),NOW(6))")
                ->execute([$request['email'],$hash,$request['first_name'],$request['last_name'],$display,$request['phone']]);
            $userId=(int)$pdo->lastInsertId();
        } else {
            $userId=(int)$user['id'];$hash=(string)$user['password_hash'];
            $pdo->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW(6)) WHERE id=?')->execute([$userId]);
        }
        $membership=customer_account_membership($pdo,$organizationId,$userId);
        if($membership && (string)$membership['status']!=='active') throw new InvalidArgumentException('This account is not active for this restaurant. Contact the restaurant for help.');
        if(!$membership) {
            $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,NULL,'Customer','active')")->execute([$organizationId,$userId]);
            $membershipId=(int)$pdo->lastInsertId();
        } else {$membershipId=(int)$membership['id'];}
        if(!customer_account_has_role($pdo,$membershipId)) {
            $pdo->prepare('INSERT INTO user_roles (membership_id,role_id,location_id,assigned_by) VALUES (?,?,NULL,?)')->execute([$membershipId,$roleId,$userId]);
        }
        $customer=customer_account_link_crm($pdo,$organizationId,$userId,['firstName'=>$request['first_name'],'lastName'=>$request['last_name'],'email'=>$request['email'],'phone'=>$request['phone']]);
        $publicId=(string)$customer['public_id'];
        if(!empty($request['email_marketing']) && !empty($input['emailMarketing'])) {
            crm_consent_set($pdo,$organizationId,$publicId,'email','opted_in','web','Customer selected email updates during account signup.',$userId);
        }
        if(!empty($request['sms_marketing']) && !empty($input['smsMarketing'])) {
            crm_consent_set($pdo,$organizationId,$publicId,'sms','opted_in','web','Customer selected SMS updates during account signup.',$userId);
        }
        crm_tag_add($pdo,$organizationId,$publicId,'Online Customer',$userId);
        $claim=$pdo->prepare('UPDATE customer_account_verifications SET used_at=NOW(6) WHERE id=? AND organization_id=? AND used_at IS NULL AND expires_at>NOW(6)');
        $claim->execute([(int)$request['id'],$organizationId]);
        if($claim->rowCount()!==1) throw new InvalidArgumentException('The verification link expired. Request another email.');
        app_audit($pdo,$organizationId,$userId,'customer.account_verified','crm_customer',$publicId,null,['source'=>'web','customerRole'=>true,'onlineOrdering'=>true,'emailVerified'=>true,'newUser'=>$newUser]);
        $pdo->commit();
        return ['userId'=>$userId,'membershipId'=>$membershipId,'customerPublicId'=>$publicId,'newUser'=>$newUser,'expectedPasswordHash'=>$hash,'return'=>customer_account_safe_return((string)$request['return_path'])];
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function customer_account_authenticate(PDO $pdo,int $organizationId,string $email,string $password): array
{
    if($pdo->inTransaction()) throw new LogicException('Authenticate outside a transaction.');
    $email=mb_strtolower(trim($email),'UTF-8');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT u.*,u.locked_until>NOW(6) login_locked,om.id membership_id FROM users u JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active' JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id AND r.slug='customer' WHERE u.email=? AND u.archived_at IS NULL LIMIT 1 FOR UPDATE");
        $q->execute([$organizationId,$email]);$user=$q->fetch()?:null;
        if(!$user || !customer_account_password_matches($pdo,$user,$password)) {
            $pdo->commit();usleep(random_int(180000,420000));
            throw new InvalidArgumentException('The email or password is incorrect, or the account is temporarily unavailable.');
        }
        if(empty($user['email_verified_at'])) {
            throw new CustomerAccountVerificationRequired('Verify your email before opening your customer account. Request a verification email below.');
        }
        if(password_needs_rehash((string)$user['password_hash'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT)) {
            $hash=app_password_hash($password);
            $rehash=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND password_hash=?');
            $rehash->execute([$hash,(int)$user['id'],(string)$user['password_hash']]);
            if($rehash->rowCount()!==1) throw new InvalidArgumentException('Your password changed. Please sign in again.');
            $user['password_hash']=$hash;
        }
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    customer_account_start_session($pdo,$organizationId,(int)$user['id'],(string)$user['password_hash']);
    app_audit($pdo,$organizationId,(int)$user['id'],'customer.authentication.login','user',(string)$user['id']);
    return ['userId'=>(int)$user['id'],'membershipId'=>(int)$user['membership_id']];
}
/** Fresh database proof protects legacy sessions and every customer-facing entrypoint. */
function customer_account_verified_identity(PDO $pdo,int $organizationId,int $userId,?int $customerId=null): ?array
{
    if($organizationId<1 || ($userId<1 && ($customerId??0)<1)) return null;
    $sql="SELECT c.id customer_id,c.public_id customer_public_id,c.display_name customer_display_name,c.user_id,u.email_verified_at
        FROM crm_customers c JOIN users u ON u.id=c.user_id AND u.email_verified_at IS NOT NULL AND BINARY c.email_normalized=BINARY LOWER(TRIM(u.email)) AND u.status='active' AND u.archived_at IS NULL
        JOIN organizations o ON o.id=c.organization_id AND o.status='active'
        JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=c.organization_id AND om.status='active'
        JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
        JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id AND r.slug='customer'
        WHERE c.organization_id=? AND c.status='active'";
    $args=[$organizationId];
    if($userId>0){$sql.=' AND c.user_id=?';$args[]=$userId;}
    if($customerId!==null){$sql.=' AND c.id=?';$args[]=$customerId;}
    $q=$pdo->prepare($sql.' LIMIT 1');$q->execute($args);
    return $q->fetch()?:null;
}

function customer_account_assert_order_identity(PDO $pdo,int $organizationId,array $account): void
{
    if((int)($account['id']??0)<1 || (int)($account['customer_id']??0)<1
        || (int)($account['organization_id']??0)!==$organizationId
        || !customer_account_verified_identity($pdo,$organizationId,(int)($account['id']??0),(int)($account['customer_id']??0))) {
        throw new CustomerAccountVerificationRequired('Verify your email before placing a customer order.');
    }
}

function customer_account_current(PDO $pdo,int $organizationId): ?array
{
    $user=app_current_user();
    if(!$user||(int)$user['organization_id']!==$organizationId||!app_has_permission('customer.portal',$user)) return null;
    $customer=customer_account_verified_identity($pdo,$organizationId,(int)$user['id']);
    return $customer?array_merge($user,$customer):null;
}

function customer_account_require(PDO $pdo,int $organizationId): array
{
    $account=customer_account_current($pdo,$organizationId);
    if(!$account){
        $return=customer_account_safe_return(basename((string)($_SERVER['REQUEST_URI']??'customer-account.php')));
        $user=app_current_user();
        $verification=$user && empty($user['email_verified_at'])?'&verify=required':'';
        app_redirect('customer-login.php?return='.rawurlencode($return).$verification);
    }
    return $account;
}

function customer_account_profile(PDO $pdo,int $organizationId,int $userId): array
{
    if(!customer_account_verified_identity($pdo,$organizationId,$userId)) throw new CustomerAccountVerificationRequired('Verify your email before viewing your customer profile.');
    $q=$pdo->prepare("SELECT c.id,c.public_id,c.first_name,c.last_name,c.display_name,c.email,c.phone,c.created_at FROM crm_customers c WHERE c.organization_id=? AND c.user_id=? AND c.status='active' LIMIT 1");
    $q->execute([$organizationId,$userId]);
    $c=$q->fetch();
    if(!$c)throw new RuntimeException('Customer profile is not linked.');
    return [
        'id'=>(int)$c['id'],'publicId'=>(string)$c['public_id'],'firstName'=>$c['first_name'],'lastName'=>$c['last_name'],
        'displayName'=>(string)$c['display_name'],'email'=>$c['email'],'phone'=>$c['phone'],'createdAt'=>(string)$c['created_at'],
        'metrics'=>crm_metrics($pdo,$organizationId,(int)$c['id']),
    ];
}

function customer_account_orders(PDO $pdo,int $organizationId,int $customerId,int $limit=25): array
{
    if(!customer_account_verified_identity($pdo,$organizationId,0,$customerId)) throw new CustomerAccountVerificationRequired('Verify your email before viewing customer orders.');
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT public_id checkPublicId,check_number checkNumber,business_date businessDate,service_mode orderType,table_name label,status,total_amount totalAmount,opened_at openedAt,closed_at closedAt FROM pos_checks WHERE organization_id=? AND customer_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$organizationId,$customerId]);
    return $q->fetchAll();
}
