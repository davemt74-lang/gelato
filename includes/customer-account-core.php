<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/customer-crm-core.php';

function customer_account_ready(PDO $pdo): bool
{
    if (!crm_ready($pdo)) return false;
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_customers' AND column_name='user_id'");
    if ((int)$q->fetchColumn()!==1) return false;
    $q=$pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('customer.portal','online_ordering.use')");
    return (int)$q->fetchColumn()===2;
}

function customer_account_safe_return(?string $value,string $fallback='customer-account.php'): string
{
    $value=trim((string)$value);
    if($value===''||str_contains($value,'..')||str_starts_with($value,'//')||preg_match('/^[a-z][a-z0-9+.-]*:/i',$value)) return $fallback;
    return preg_match('/^[A-Za-z0-9_\/.?=&%#-]+$/',$value)?$value:$fallback;
}

function customer_account_password_error(string $password): ?string
{
    if(strlen($password)<12
        || !preg_match('/[A-Z]/',$password)
        || !preg_match('/[a-z]/',$password)
        || !preg_match('/\d/',$password)
        || !preg_match('/[^A-Za-z0-9]/',$password)) {
        return 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.';
    }
    return null;
}

function customer_account_role_id(PDO $pdo,int $organizationId): int
{
    $q=$pdo->prepare("SELECT id FROM roles WHERE organization_id=? AND slug='customer' AND is_assignable=1 LIMIT 1");
    $q->execute([$organizationId]);
    $id=(int)$q->fetchColumn();
    if($id<1) throw new RuntimeException('Customer accounts are not configured for this restaurant. Run Upgrade first.');
    return $id;
}

function customer_account_membership(PDO $pdo,int $organizationId,int $userId): ?array
{
    $q=$pdo->prepare('SELECT * FROM organization_memberships WHERE organization_id=? AND user_id=? LIMIT 1');
    $q->execute([$organizationId,$userId]);
    $row=$q->fetch();
    return $row?:null;
}

function customer_account_has_role(PDO $pdo,int $membershipId): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.membership_id=? AND ur.revoked_at IS NULL AND r.slug='customer'");
    $q->execute([$membershipId]);
    return (int)$q->fetchColumn()>0;
}

function customer_account_start_session(PDO $pdo,int $organizationId,int $userId): void
{
    app_boot_session();
    session_regenerate_id(true);
    $_SESSION['user_id']=$userId;
    $_SESSION['organization_id']=$organizationId;
    $_SESSION['authenticated_at']=time();
    $pdo->prepare('UPDATE users SET failed_login_count=0,locked_until=NULL,last_login_at=NOW(6) WHERE id=?')->execute([$userId]);
}

function customer_account_link_crm(PDO $pdo,int $organizationId,int $userId,array $data): array
{
    $first=mb_substr(trim((string)$data['firstName']),0,120,'UTF-8');
    $last=mb_substr(trim((string)$data['lastName']),0,120,'UTF-8');
    $display=mb_substr(trim($first.' '.$last),0,240,'UTF-8');
    $emailRaw=mb_substr(trim((string)$data['email']),0,320,'UTF-8');
    $email=crm_normalize_email($emailRaw);
    $phoneRaw=mb_substr(trim((string)($data['phone']??'')),0,64,'UTF-8');
    $phone=crm_normalize_phone($phoneRaw);

    $q=$pdo->prepare('SELECT * FROM crm_customers WHERE organization_id=? AND user_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$organizationId,$userId]);
    $customer=$q->fetch()?:null;

    if(!$customer){
        $q=$pdo->prepare("SELECT * FROM crm_customers WHERE organization_id=? AND status='active' AND email_normalized=? ORDER BY id LIMIT 1 FOR UPDATE");
        $q->execute([$organizationId,$email]);
        $customer=$q->fetch()?:null;
        if($customer && !empty($customer['user_id']) && (int)$customer['user_id']!==$userId){
            throw new InvalidArgumentException('That customer profile is already linked to another account. Sign in or contact the restaurant.');
        }
    }

    if($phone){
        $args=[$organizationId,$phone];
        $sql="SELECT id FROM crm_customers WHERE organization_id=? AND status='active' AND phone_normalized=?";
        if($customer){$sql.=' AND id<>?';$args[]=(int)$customer['id'];}
        $sql.=' ORDER BY id LIMIT 1';
        $q=$pdo->prepare($sql);$q->execute($args);
        if($q->fetchColumn()) throw new InvalidArgumentException('That phone number is already attached to another customer profile.');
    }

    if($customer){
        $pdo->prepare('UPDATE crm_customers SET user_id=?,first_name=?,last_name=?,display_name=?,email=?,email_normalized=?,phone=?,phone_normalized=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
            ->execute([$userId,$first,$last,$display,$emailRaw,$email,$phoneRaw?:null,$phone,$userId,$organizationId,(int)$customer['id']]);
        return crm_customer_row($pdo,$organizationId,(string)$customer['public_id']);
    }

    $public=crm_public_id();
    $pdo->prepare("INSERT INTO crm_customers (organization_id,user_id,public_id,first_name,last_name,display_name,email,email_normalized,phone,phone_normalized,status,source,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,'active','web',?,?)")
        ->execute([$organizationId,$userId,$public,$first,$last,$display,$emailRaw,$email,$phoneRaw?:null,$phone,$userId,$userId]);
    return crm_customer_row($pdo,$organizationId,$public);
}

function customer_account_register(PDO $pdo,int $organizationId,array $input): array
{
    if(!customer_account_ready($pdo)) throw new RuntimeException('Customer account setup is not installed. Run Upgrade first.');
    $first=mb_substr(trim((string)($input['firstName']??'')),0,100,'UTF-8');
    $last=mb_substr(trim((string)($input['lastName']??'')),0,100,'UTF-8');
    if($first===''||$last==='') throw new InvalidArgumentException('Enter your first and last name.');
    $email=(string)crm_normalize_email((string)($input['email']??''));
    if($email==='') throw new InvalidArgumentException('Enter your email address.');
    $phoneRaw=trim((string)($input['phone']??''));
    $phone=crm_normalize_phone($phoneRaw);
    $password=(string)($input['password']??'');
    $confirm=(string)($input['passwordConfirm']??'');
    if($error=customer_account_password_error($password)) throw new InvalidArgumentException($error);
    if(!hash_equals($password,$confirm)) throw new InvalidArgumentException('The password confirmation does not match.');
    if(!empty($input['smsMarketing'])&&!$phone) throw new InvalidArgumentException('Add a mobile phone number before opting in to SMS updates.');

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $roleId=customer_account_role_id($pdo,$organizationId);
        $q=$pdo->prepare('SELECT * FROM users WHERE email=? AND archived_at IS NULL LIMIT 1 FOR UPDATE');
        $q->execute([$email]);
        $user=$q->fetch()?:null;
        $newUser=false;
        if($user){
            if((string)$user['status']!=='active'||!password_verify($password,(string)$user['password_hash'])){
                throw new InvalidArgumentException('An account with that email already exists. Sign in or use password reset.');
            }
            $userId=(int)$user['id'];
        }else{
            $algorithm=defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT;
            $hash=password_hash($password,$algorithm);
            if(!is_string($hash))throw new RuntimeException('Unable to secure the account password.');
            $display=trim($first.' '.$last);
            $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,phone,status,password_changed_at) VALUES (?,?,?,?,?,?,'active',NOW(6))")
                ->execute([$email,$hash,$first,$last,$display,$phoneRaw?:null]);
            $userId=(int)$pdo->lastInsertId();
            $newUser=true;
        }

        $membership=customer_account_membership($pdo,$organizationId,$userId);
        if($membership && (string)$membership['status']!=='active') throw new InvalidArgumentException('This account is not active for this restaurant. Contact the restaurant for help.');
        if(!$membership){
            $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,NULL,'Customer','active')")
                ->execute([$organizationId,$userId]);
            $membershipId=(int)$pdo->lastInsertId();
        }else{
            $membershipId=(int)$membership['id'];
        }

        if(customer_account_has_role($pdo,$membershipId)){
            if(!$newUser) throw new InvalidArgumentException('A customer account with that email already exists. Sign in instead.');
        }else{
            $pdo->prepare('INSERT INTO user_roles (membership_id,role_id,location_id,assigned_by) VALUES (?,?,NULL,?)')
                ->execute([$membershipId,$roleId,$userId]);
        }

        $customer=customer_account_link_crm($pdo,$organizationId,$userId,[
            'firstName'=>$first,'lastName'=>$last,'email'=>$email,'phone'=>$phoneRaw,
        ]);
        $publicId=(string)$customer['public_id'];

        if(!empty($input['emailMarketing'])){
            crm_consent_set($pdo,$organizationId,$publicId,'email','opted_in','web','Customer selected email updates during account signup.',$userId);
        }
        if(!empty($input['smsMarketing'])){
            crm_consent_set($pdo,$organizationId,$publicId,'sms','opted_in','web','Customer selected SMS updates during account signup.',$userId);
        }
        crm_tag_add($pdo,$organizationId,$publicId,'Online Customer',$userId);
        app_audit($pdo,$organizationId,$userId,'customer.account_created','crm_customer',$publicId,null,['source'=>'web','customerRole'=>true,'onlineOrdering'=>true]);

        if($owns)$pdo->commit();
        return ['userId'=>$userId,'membershipId'=>$membershipId,'customerPublicId'=>$publicId,'newUser'=>$newUser];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function customer_account_authenticate(PDO $pdo,int $organizationId,string $email,string $password): array
{
    $email=mb_strtolower(trim($email),'UTF-8');
    $q=$pdo->prepare("SELECT u.*,om.id membership_id FROM users u JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active' JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL JOIN roles r ON r.id=ur.role_id AND r.slug='customer' WHERE u.email=? AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$organizationId,$email]);
    $user=$q->fetch()?:null;
    $locked=$user&&!empty($user['locked_until'])&&strtotime((string)$user['locked_until'])>time();
    $valid=$user&&!$locked&&(string)$user['status']==='active'&&password_verify($password,(string)$user['password_hash']);
    if(!$valid){
        if($user&&!$locked){
            $failed=(int)$user['failed_login_count']+1;
            $lockUntil=$failed>=5?date('Y-m-d H:i:s.u',time()+900):null;
            $pdo->prepare('UPDATE users SET failed_login_count=?,locked_until=? WHERE id=?')->execute([$failed>=5?0:$failed,$lockUntil,(int)$user['id']]);
        }
        usleep(random_int(180000,420000));
        throw new InvalidArgumentException($locked?'This account is temporarily locked. Try again later.':'The email or password is incorrect.');
    }
    if(password_needs_rehash((string)$user['password_hash'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT)){
        $rehash=password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);
        if(is_string($rehash))$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$rehash,(int)$user['id']]);
    }
    customer_account_start_session($pdo,$organizationId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'customer.authentication.login','user',(string)$user['id']);
    return ['userId'=>(int)$user['id'],'membershipId'=>(int)$user['membership_id']];
}

function customer_account_current(PDO $pdo,int $organizationId): ?array
{
    $user=app_current_user();
    if(!$user||(int)$user['organization_id']!==$organizationId||!app_has_permission('customer.portal',$user)) return null;
    $q=$pdo->prepare("SELECT c.id customer_id,c.public_id customer_public_id,c.display_name customer_display_name FROM organization_memberships om JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL JOIN roles r ON r.id=ur.role_id AND r.slug='customer' JOIN crm_customers c ON c.organization_id=om.organization_id AND c.user_id=om.user_id AND c.status='active' WHERE om.organization_id=? AND om.user_id=? AND om.status='active' LIMIT 1");
    $q->execute([$organizationId,(int)$user['id']]);
    $customer=$q->fetch();
    return $customer?array_merge($user,$customer):null;
}

function customer_account_require(PDO $pdo,int $organizationId): array
{
    $account=customer_account_current($pdo,$organizationId);
    if(!$account){
        $return=customer_account_safe_return(basename((string)($_SERVER['REQUEST_URI']??'customer-account.php')));
        app_redirect('customer-login.php?return='.rawurlencode($return));
    }
    return $account;
}

function customer_account_profile(PDO $pdo,int $organizationId,int $userId): array
{
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
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT public_id checkPublicId,check_number checkNumber,business_date businessDate,service_mode orderType,table_name label,status,total_amount totalAmount,opened_at openedAt,closed_at closedAt FROM pos_checks WHERE organization_id=? AND customer_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$organizationId,$customerId]);
    return $q->fetchAll();
}
