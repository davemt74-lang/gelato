<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function accounts_id(mixed $value, string $prefix = 'db-user-'): int
{
    if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('The account or account type is invalid.');
    $raw = (string)$value;
    if (str_starts_with($raw, $prefix)) $raw = substr($raw, strlen($prefix));
    if (!ctype_digit($raw) || (int)$raw < 1) throw new InvalidArgumentException('The account or account type is invalid.');
    return (int)$raw;
}

function accounts_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT user_id,delivery_status FROM account_invitations LIMIT 0');
        return $ready = true;
    } catch (PDOException $e) {
        if (in_array((string)$e->getCode(), ['42S22','42S02'], true)) return $ready = false;
        throw $e;
    }
}

/** Authorization uses complete rows; GROUP_CONCAT can truncate large permission sets. */
function accounts_roles(PDO $pdo, int $org, ?int $membership = null): array
{
    $where='r.organization_id=?';$args=[$org];
    if($membership!==null){
        $where.=' AND EXISTS(SELECT 1 FROM user_roles ur WHERE ur.role_id=r.id AND ur.membership_id=? AND ur.revoked_at IS NULL)';
        $args[]=$membership;
    }
    $q=$pdo->prepare('SELECT r.* FROM roles r WHERE '.$where.' ORDER BY r.is_owner_role DESC,r.id');
    $q->execute($args);$roles=$q->fetchAll();
    $q=$pdo->prepare('SELECT rp.role_id,p.permission_key FROM roles r
        JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id
        WHERE '.$where.' ORDER BY rp.role_id,p.permission_key');
    $q->execute($args);$permissions=[];
    foreach($q->fetchAll() as $permission)$permissions[(int)$permission['role_id']][]=(string)$permission['permission_key'];
    return array_map(static function(array $role)use($permissions):array{
        $role['owner']=app_role_has_owner_authority($role);
        $role['permissions']=$role['owner']?['*']:($permissions[(int)$role['id']]??[]);
        return $role;
    },$roles);
}

function accounts_actor(PDO $pdo, int $org, int $uid): array
{
    $q=$pdo->prepare("SELECT u.id,u.status,u.archived_at,om.id AS membership_id,om.organization_id
        FROM users u JOIN organization_memberships om ON om.user_id=u.id
        WHERE u.id=? AND om.organization_id=? AND u.status='active' AND u.archived_at IS NULL AND om.status='active'");
    $q->execute([$uid,$org]); $actor=$q->fetch();
    if (!$actor) throw new DomainException('Your account no longer has access to this restaurant.');
    $actor['permissions']=[];
    foreach(accounts_roles($pdo,$org,(int)$actor['membership_id']) as $role) {
        $actor['permissions']=array_values(array_unique(array_merge($actor['permissions'],$role['permissions'])));
    }
    return $actor;
}

function accounts_require(array $actor, string $permission): void
{
    if (!app_has_permission($permission,$actor)) throw new DomainException('You do not have permission to perform this account action.');
}

function accounts_lock_org(PDO $pdo, int $org): void
{
    $q=$pdo->prepare("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE");
    $q->execute([$org]);
    if (!$q->fetchColumn()) throw new DomainException('This restaurant is unavailable.');
}

function accounts_lock_users(PDO $pdo, array $ids): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); sort($ids,SORT_NUMERIC);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    foreach($ids as $id) {$q->execute([$id]);$q->fetch();}
}

function accounts_target(PDO $pdo, int $org, int $uid, bool $lock=false): array
{
    $q=$pdo->prepare("SELECT u.*,om.id AS membership_id,om.status AS membership_status,om.job_title,
        om.updated_at AS membership_updated_at FROM users u
        JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
        WHERE u.id=? AND u.archived_at IS NULL".($lock?' FOR UPDATE':''));
    $q->execute([$org,$uid]); $row=$q->fetch();
    if (!$row) throw new InvalidArgumentException('Account not found in this restaurant.');
    return $row;
}

function accounts_role_allowed(array $actor, array $role): bool
{
    if (!(bool)$role['is_assignable']) return false;
    if (in_array('*',$actor['permissions'],true)) return true;
    return !$role['owner'] && !array_diff($role['permissions'],$actor['permissions']);
}

function accounts_assignable_roles(PDO $pdo, int $org, array $actor): array
{
    if (!app_has_permission('users.assign_roles',$actor)) return [];
    return array_values(array_map(static fn(array $r):array=>[
        'id'=>'db-role-'.$r['id'],'name'=>$r['name'],'slug'=>$r['slug'],'owner'=>$r['owner'],
    ],array_filter(accounts_roles($pdo,$org),static fn(array $r):bool=>accounts_role_allowed($actor,$r))));
}

/** Two set-based queries load account types without duplicating multi-role users. */
function accounts_list(PDO $pdo, int $org, int $actorId): array
{
    $q=$pdo->prepare("SELECT u.id,u.email,u.first_name,u.last_name,u.display_name,u.phone,u.status AS global_status,
        u.last_login_at,u.created_at,om.created_at AS membership_created_at,om.id AS membership_id,om.status AS membership_status,om.job_title,
        EXISTS(SELECT 1 FROM organization_memberships other WHERE other.user_id=u.id AND other.organization_id<>om.organization_id) AS shared_identity
        FROM users u JOIN organization_memberships om ON om.user_id=u.id
        WHERE om.organization_id=? AND u.archived_at IS NULL ORDER BY u.display_name,u.id");
    $q->execute([$org]); $rows=$q->fetchAll();
    $q=$pdo->prepare("SELECT om.user_id,r.id,r.name,r.slug,r.is_owner_role,r.is_system_role
        FROM organization_memberships om JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
        JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
        WHERE om.organization_id=? ORDER BY r.is_owner_role DESC,r.id");
    $q->execute([$org]); $byUser=[];
    foreach($q->fetchAll() as $role) $byUser[(int)$role['user_id']][]=$role;
    $invites=[];
    if(accounts_schema_ready($pdo)) {
        $q=$pdo->prepare("SELECT i.* FROM account_invitations i
            WHERE i.organization_id=? AND i.user_id IS NOT NULL AND i.accepted_at IS NULL
              AND NOT EXISTS(SELECT 1 FROM account_invitations newer WHERE newer.organization_id=i.organization_id
                  AND newer.user_id=i.user_id AND newer.id>i.id) ORDER BY i.id DESC");
        $q->execute([$org]);
        foreach($q->fetchAll() as $inv) $invites[(int)$inv['user_id']]??=$inv;
    }
    $seen=array_fill_keys(array_map(static fn(array $r):int=>(int)$r['id'],$rows),true);
    foreach($invites as $uid=>$inv) {
        if(isset($seen[$uid]))continue;
        // An invitation is not a restaurant membership. Only submitted local details
        // may be returned until the recipient accepts; never read the global profile.
        $rows[]=['id'=>$uid,'email'=>$inv['email'],'first_name'=>$inv['first_name']??'Invited','last_name'=>$inv['last_name']??'account',
            'display_name'=>$inv['display_name']??'Invitation pending','phone'=>$inv['phone']??'','global_status'=>'invited',
            'membership_status'=>$inv['revoked_at']?'suspended':'invited','job_title'=>$inv['job_title'],
            'last_login_at'=>null,'created_at'=>$inv['created_at'],'membership_created_at'=>$inv['created_at'],
            'shared_identity'=>1,'membership_id'=>null];
    }
    return array_map(static function(array $row) use($actorId,$byUser,$invites):array {
        $uid=(int)$row['id']; $roles=$byUser[$uid]??[]; $inv=$invites[$uid]??null;
        $pending=empty($row['membership_id'])||$row['membership_status']==='invited';
        if($pending) {
            $row['first_name']=$inv['first_name']??'Invited';$row['last_name']=$inv['last_name']??'account';
            $row['display_name']=$inv['display_name']??'Invitation pending';$row['phone']=$inv['phone']??'';
            $row['last_login_at']=null;$row['created_at']=$row['membership_created_at'];
        }
        $status=in_array($row['global_status'],['active','invited'],true)?$row['membership_status']:'suspended';
        if($row['global_status']==='invited' && $status==='active') $status='invited';
        return ['id'=>'db-user-'.$uid,'email'=>$row['email'],'firstName'=>$row['first_name'],'lastName'=>$row['last_name'],
            'displayName'=>$row['display_name'],'phone'=>$row['phone']??'',
            'roleId'=>$roles?'db-role-'.$roles[0]['id']:($inv?'db-role-'.$inv['role_id']:''),
            'roleIds'=>array_map(static fn(array $r):string=>'db-role-'.$r['id'],$roles),
            'roleNames'=>array_column($roles,'name'),'protected'=>(bool)array_filter($roles,'app_role_has_owner_authority'),
            'position'=>$row['job_title']?:'Employee','status'=>$status,
            'profileEditable'=>!$pending&&(!(bool)$row['shared_identity']||$uid===$actorId),'pendingMembership'=>$pending,
            'initials'=>mb_strtoupper(mb_substr($row['first_name'],0,1).mb_substr($row['last_name'],0,1)),
            'lastLogin'=>$row['last_login_at'],'createdAt'=>$row['created_at'],
            'invitation'=>$inv?['delivery'=>$inv['revoked_at']?'revoked':$inv['delivery_status'],'expiresAt'=>$inv['expires_at']]:null,
            'progress'=>['quiz'=>0,'flashcards'=>0,'daily'=>0,'certifications'=>0,'overdue'=>0]];
    },$rows);
}

function accounts_payload(PDO $pdo, int $org, int $uid, int $actorId): array
{
    foreach(accounts_list($pdo,$org,$actorId) as $row) if($row['id']==='db-user-'.$uid) return $row;
    throw new InvalidArgumentException('Account not found in this restaurant.');
}

function accounts_valid_profile(array $input): array
{
    $out=[];
    foreach(['firstName'=>100,'lastName'=>100,'displayName'=>160,'phone'=>40,'position'=>160] as $key=>$max) {
        if(isset($input[$key])&&!is_scalar($input[$key]))throw new InvalidArgumentException('Account fields must contain text.');
        $out[$key]=trim((string)($input[$key]??''));
        if(mb_strlen($out[$key])>$max) throw new InvalidArgumentException('An account field is too long.');
    }
    if($out['firstName']===''||$out['lastName']==='') throw new InvalidArgumentException('First and last name are required.');
    $out['displayName']=$out['displayName']?:trim($out['firstName'].' '.$out['lastName']);
    if(mb_strlen($out['displayName'])>160) throw new InvalidArgumentException('Display name is too long.');
    $out['position']=$out['position']?:'Employee';
    return $out;
}

function accounts_bump_membership(PDO $pdo, int $membership): void
{
    $pdo->prepare('UPDATE organization_memberships SET updated_at=GREATEST(NOW(6),DATE_ADD(updated_at,INTERVAL 1 MICROSECOND)) WHERE id=?')
        ->execute([$membership]);
}

function accounts_queue_invitation(PDO $pdo, int $org, int $uid, int $role, int $actorId, array $profile): array
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM account_invitations WHERE organization_id=? AND user_id=?
        AND created_at>DATE_SUB(NOW(6),INTERVAL 1 HOUR)");
    $q->execute([$org,$uid]);
    if((int)$q->fetchColumn()>=5) throw new InvalidArgumentException('Too many invitations. Try again after an hour.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM account_invitations WHERE organization_id=? AND user_id=?
        AND delivery_status='sent' AND revoked_at IS NULL AND accepted_at IS NULL
        AND created_at>DATE_SUB(NOW(6),INTERVAL 2 MINUTE)");
    $q->execute([$org,$uid]);
    if((int)$q->fetchColumn()>0) throw new InvalidArgumentException('An invitation was just sent. Wait two minutes before resending.');
    $q=$pdo->prepare("SELECT id,email FROM users WHERE id=? AND archived_at IS NULL AND status IN ('active','invited') FOR UPDATE");$q->execute([$uid]);$user=$q->fetch();
    if(!$user)throw new InvalidArgumentException('The invited identity is unavailable.');
    if(!hash_equals(mb_strtolower(trim($user['email'])),mb_strtolower(trim((string)($profile['email']??'')))))
        throw new InvalidArgumentException('The invitation no longer matches this identity. Create a new invitation using its verified email.');
    $pdo->prepare('UPDATE account_invitations SET revoked_at=NOW(6) WHERE organization_id=? AND user_id=? AND accepted_at IS NULL AND revoked_at IS NULL')->execute([$org,$uid]);
    $token=bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO account_invitations(organization_id,user_id,first_name,last_name,display_name,phone,job_title,email,role_id,token_hash,invited_by,expires_at,delivery_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(6),INTERVAL 24 HOUR),'pending')")
        ->execute([$org,$uid,$profile['firstName'],$profile['lastName'],$profile['displayName'],$profile['phone']?:null,$profile['position'],
            $user['email'],$role,hash('sha256',$token),$actorId]);
    $id=(int)$pdo->lastInsertId();
    app_audit($pdo,$org,$actorId,'user.invitation_created','user',(string)$uid,null,['roleId'=>$role]);
    return ['id'=>$id,'token'=>$token,'email'=>$user['email'],'displayName'=>$profile['displayName'],'organizationId'=>$org,'userId'=>$uid];
}

/** All durable writes commit before mail transport; no token is returned to managers. */
function accounts_deliver_invitation(PDO $pdo, array $mail): array
{
    $config=app_config(); $mailConfig=$config['mail']??[];
    $appName=str_replace(["\r","\n"],'',(string)($config['app']['name']??'Gelato'));
    $fromName=str_replace(["\r","\n"],'',(string)($mailConfig['from_name']??$appName));
    $host=(string)(parse_url((string)($config['app']['url']??''),PHP_URL_HOST)?:'localhost');
    $fromEmail=str_replace(["\r","\n"],'',(string)($mailConfig['from_email']??'no-reply@'.$host));
    $url=app_url('account-invitation.php?token='.rawurlencode($mail['token']));
    $body="Hello ".$mail['displayName'].",\n\nYou have been invited to a restaurant workspace in ".$appName.".\n\n"
        ."Open this single-use link to review and accept your invitation:\n".$url."\n\n"
        ."Choose your own password for a new account, or use your existing password. This link expires in 24 hours.\n"
        ."If you were not expecting this invitation, you can ignore it.\n";
    $sent=function_exists('mail')&&@mail($mail['email'],$appName.' account invitation',$body,
        implode("\r\n",['From: '.$fromName.' <'.$fromEmail.'>','Content-Type: text/plain; charset=UTF-8']));
    $status=$sent?'sent':'failed';
    $pdo->prepare('UPDATE account_invitations SET delivery_status=?,delivery_attempted_at=NOW(6)
        WHERE id=? AND token_hash=? AND accepted_at IS NULL AND revoked_at IS NULL')
        ->execute([$status,$mail['id'],hash('sha256',$mail['token'])]);
    $q=$pdo->prepare('SELECT expires_at FROM account_invitations WHERE id=?');$q->execute([$mail['id']]);
    return ['delivery'=>$status,'expiresAt'=>$q->fetchColumn()];
}

function accounts_invite(PDO $pdo, array $requestActor, array $input): array
{
    if(!empty($input['password']))throw new InvalidArgumentException('The recipient chooses their own password when accepting the invitation.');
    $org=(int)$requestActor['organization_id'];$actorId=(int)$requestActor['id'];
    if($pdo->inTransaction()) throw new LogicException('Account invitation must own its transaction.');
    $pdo->beginTransaction();
    try {
        accounts_lock_org($pdo,$org);
        $application=null;
        if(!empty($input['applicationId'])) {
            $q=$pdo->prepare('SELECT * FROM resume_submissions WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');
            $q->execute([$org,(string)$input['applicationId']]);$application=$q->fetch();
            if(!$application) throw new InvalidArgumentException('Application not found in this restaurant.');
            $position=array_key_exists('position',$input)?$input['position']:mb_substr((string)$application['position_interest'],0,160);
            $input=array_merge($input,['firstName'=>$application['first_name'],'lastName'=>$application['last_name'],
                'email'=>$application['email'],'phone'=>$application['phone'],'position'=>$position]);
        }
        $profile=accounts_valid_profile($input);
        $email=mb_strtolower(trim((string)($input['email']??'')));
        if(mb_strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
        $profile['email']=$email;
        $q=$pdo->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);$existing=(int)$q->fetchColumn();
        accounts_lock_users($pdo,[$actorId,$existing]);
        $actor=accounts_actor($pdo,$org,$actorId);accounts_require($actor,'users.create');accounts_require($actor,'users.assign_roles');
        if($application)accounts_require($actor,'resumes.convert_to_employee');
        if($application&&!empty($application['converted_user_id'])) {
            $uid=(int)$application['converted_user_id'];$user=accounts_payload($pdo,$org,$uid,$actorId);
            $pdo->commit();
            return ['user'=>$user,'message'=>'This application is already linked to an account.','invitation'=>$user['invitation'],'applicationId'=>$application['public_id']];
        }
        $role=null;
        foreach(accounts_roles($pdo,$org) as $candidate) {
            if(!empty($input['roleId'])?((int)$candidate['id']===accounts_id($input['roleId'],'db-role-')):($candidate['slug']==='employee'))$role=$candidate;
        }
        if(!$role||!accounts_role_allowed($actor,$role))throw new DomainException('You cannot assign that account type.');
        if(!$existing) {
            // A random undisclosed credential cannot be used before mailbox acceptance.
            $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,phone,status)
                VALUES (?,?,?,?,?,?,'invited') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")
                ->execute([$email,app_password_hash(bin2hex(random_bytes(32))),$profile['firstName'],$profile['lastName'],$profile['displayName'],$profile['phone']?:null]);
            $existing=(int)$pdo->lastInsertId();
        }
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$existing]);$identity=$q->fetch();
        if(!$identity||$identity['archived_at']!==null||!in_array($identity['status'],['active','invited'],true))
            throw new InvalidArgumentException('That account cannot be invited. Contact the account owner.');
        $q=$pdo->prepare('SELECT * FROM organization_memberships WHERE organization_id=? AND user_id=? FOR UPDATE');
        $q->execute([$org,$existing]);$membership=$q->fetch();
        if($membership) {
            if(!$application)throw new InvalidArgumentException('That account already belongs to this restaurant. Use its account controls.');
            if(!in_array($membership['status'],['active','invited'],true))throw new InvalidArgumentException('Reactivate the restaurant account before inviting this applicant.');
        }
        $mail=accounts_queue_invitation($pdo,$org,$existing,(int)$role['id'],$actorId,$profile);
        if($application) {
            $pdo->prepare("UPDATE resume_submissions SET converted_user_id=?,status='hired' WHERE id=? AND organization_id=?")
                ->execute([$existing,$application['id'],$org]);
            $pdo->prepare("INSERT INTO resume_status_history(resume_submission_id,previous_status,new_status,changed_by,note) VALUES (?,?,'hired',?,?)")
                ->execute([$application['id'],$application['status'],$actorId,'Employee invitation created; account activation requires recipient acceptance.']);
            app_audit($pdo,$org,$actorId,'resume.converted_to_employee','resume',(string)$application['public_id'],null,['userId'=>$existing]);
        }
        app_audit($pdo,$org,$actorId,'user.created','user',(string)$existing,null,['membershipStatus'=>$membership['status']??'invited','email'=>$email]);
        $pdo->commit();
        $delivery=accounts_deliver_invitation($pdo,$mail);
        return ['user'=>accounts_payload($pdo,$org,$existing,$actorId),'invitation'=>$delivery,
            'applicationId'=>$application['public_id']??null,
            'message'=>$delivery['delivery']==='sent'?'Account saved and invitation sent.':'Account saved, but the invitation email could not be sent. Check mail settings and resend.'];
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function accounts_update(PDO $pdo, array $requestActor, array $input): array
{
    $org=(int)$requestActor['organization_id'];$actorId=(int)$requestActor['id'];
    $action=(string)($input['action']??'update');$uid=$action==='profile'?$actorId:accounts_id($input['id']??'');
    $pdo->beginTransaction();
    try {
        accounts_lock_org($pdo,$org);accounts_lock_users($pdo,[$actorId,$uid]);
        $actor=accounts_actor($pdo,$org,$actorId);
        accounts_require($actor,match($action){'profile'=>'settings.self_edit','status'=>'users.suspend','resend'=>'users.create',default=>'users.edit'});
        $membershipQuery=$pdo->prepare('SELECT id FROM organization_memberships WHERE organization_id=? AND user_id=? FOR UPDATE');
        $membershipQuery->execute([$org,$uid]);
        if(!$membershipQuery->fetchColumn()) {
            $q=$pdo->prepare('SELECT * FROM account_invitations WHERE organization_id=? AND user_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $q->execute([$org,$uid]);$inv=$q->fetch();
            if(!$inv||$inv['accepted_at']!==null)throw new InvalidArgumentException('Account not found in this restaurant.');
            $mail=null;
            if($action==='status'&&($input['status']??'')==='suspended') {
                $pdo->prepare('UPDATE account_invitations SET revoked_at=NOW(6) WHERE id=?')->execute([$inv['id']]);
                app_audit($pdo,$org,$actorId,'user.invitation_revoked','user',(string)$uid);
            } elseif($action==='resend') {
                accounts_require($actor,'users.assign_roles');$role=null;
                foreach(accounts_roles($pdo,$org) as $candidate)if((int)$candidate['id']===(int)$inv['role_id'])$role=$candidate;
                if(!$role||!accounts_role_allowed($actor,$role))throw new DomainException('A valid assignable invitation is required.');
                $details=['firstName'=>$inv['first_name']??'Invited','lastName'=>$inv['last_name']??'account',
                    'displayName'=>$inv['display_name']??'Invited account','phone'=>$inv['phone']??'','position'=>$inv['job_title']?:'Employee','email'=>$inv['email']];
                $mail=accounts_queue_invitation($pdo,$org,$uid,(int)$role['id'],$actorId,$details);
            } else throw new InvalidArgumentException('This account has not accepted its invitation. You can resend or cancel the invitation.');
            $pdo->commit();$delivery=$mail?accounts_deliver_invitation($pdo,$mail):null;
            return ['user'=>accounts_payload($pdo,$org,$uid,$actorId),'invitation'=>$delivery,
                'message'=>$mail?($delivery['delivery']==='sent'?'Invitation sent.':'The invitation email could not be sent. Check mail settings and resend.'):'Invitation cancelled.'];
        }
        $target=accounts_target($pdo,$org,$uid,true);$oldRoles=accounts_roles($pdo,$org,(int)$target['membership_id']);
        $protected=(bool)array_filter($oldRoles,static fn(array $r):bool=>$r['owner']);
        if($action!=='profile'&&!in_array('*',$actor['permissions'],true)) {
            foreach($oldRoles as $role) if(array_diff($role['permissions'],$actor['permissions']))
                throw new DomainException('An account with broader permissions must be managed by its owner.');
        }
        if(!empty($input['password']))throw new InvalidArgumentException('Passwords are chosen by account holders through invitation or password recovery.');
        if(isset($input['email'])&&mb_strtolower(trim((string)$input['email']))!==mb_strtolower((string)$target['email']))
            throw new InvalidArgumentException('The verified login email cannot be changed by this account editor.');
        $changedMembership=false;$mail=null;
        if($action==='status') {
            if($uid===$actorId||$protected)throw new DomainException('Your own access and protected owner access cannot be suspended here.');
            $status=(string)($input['status']??'');
            if(!in_array($status,['active','suspended'],true))throw new InvalidArgumentException('Choose active or suspended.');
            if($status==='active'&&($target['status']!=='active'||$target['membership_status']==='invited'))
                throw new InvalidArgumentException('An invited account must accept its invitation before activation.');
            if($target['membership_status']!==$status) {
                $pdo->prepare('UPDATE organization_memberships SET status=? WHERE id=?')->execute([$status,$target['membership_id']]);
                accounts_bump_membership($pdo,(int)$target['membership_id']);$changedMembership=true;
                $pdo->prepare('UPDATE account_invitations SET revoked_at=NOW(6) WHERE organization_id=? AND user_id=? AND accepted_at IS NULL AND revoked_at IS NULL')->execute([$org,$uid]);
                app_audit($pdo,$org,$actorId,$status==='suspended'?'user.suspended':'user.reactivated','user',(string)$uid,
                    ['membershipStatus'=>$target['membership_status']],['membershipStatus'=>$status]);
            }
        } elseif($action==='resend') {
            accounts_require($actor,'users.assign_roles');
            if(!in_array($target['membership_status'],['invited','active'],true))throw new InvalidArgumentException('This restaurant account cannot receive an invitation.');
            $q=$pdo->prepare("SELECT * FROM account_invitations WHERE organization_id=? AND user_id=? ORDER BY id DESC LIMIT 1");
            $q->execute([$org,$uid]);$inv=$q->fetch();$roleId=(int)($inv['role_id']??0);$role=null;
            if(!$inv||$inv['accepted_at']!==null)throw new InvalidArgumentException('There is no pending invitation to resend.');
            foreach(accounts_roles($pdo,$org) as $candidate)if((int)$candidate['id']===$roleId)$role=$candidate;
            if(!$role||!accounts_role_allowed($actor,$role))throw new DomainException('A valid assignable invitation is required.');
            $details=['firstName'=>$inv['first_name']??'Invited','lastName'=>$inv['last_name']??'account',
                'displayName'=>$inv['display_name']??'Invited account','phone'=>$inv['phone']??'','position'=>$inv['job_title']?:'Employee','email'=>$inv['email']];
            $mail=accounts_queue_invitation($pdo,$org,$uid,$roleId,$actorId,$details);
        } else {
            if($target['membership_status']==='invited')throw new InvalidArgumentException('The recipient must accept the invitation before editing this account.');
            $profile=accounts_valid_profile($input);
            $q=$pdo->prepare('SELECT COUNT(*) FROM organization_memberships WHERE user_id=? AND organization_id<>?');$q->execute([$uid,$org]);
            $shared=(int)$q->fetchColumn()>0;
            $profileChanged=$profile['firstName']!==$target['first_name']||$profile['lastName']!==$target['last_name']
                ||$profile['displayName']!==$target['display_name']||$profile['phone']!==($target['phone']??'');
            if($shared&&$uid!==$actorId&&$profileChanged)throw new DomainException('This shared identity must update its own personal profile. Restaurant position and access can still be managed here.');
            if($profileChanged)$pdo->prepare('UPDATE users SET first_name=?,last_name=?,display_name=?,phone=? WHERE id=?')
                ->execute([$profile['firstName'],$profile['lastName'],$profile['displayName'],$profile['phone']?:null,$uid]);
            if($action!=='profile') {
                if(isset($input['status'])&&$input['status']!==$target['membership_status'])throw new InvalidArgumentException('Use the separate suspend/reactivate action to change access.');
                if($profile['position']!==($target['job_title']?:'Employee')) {
                    $pdo->prepare('UPDATE organization_memberships SET job_title=? WHERE id=?')->execute([$profile['position'],$target['membership_id']]);
                    $changedMembership=true;
                }
                if(array_key_exists('roleIds',$input)) {
                    if(!is_array($input['roleIds'])||count($input['roleIds'])>20)throw new InvalidArgumentException('Choose valid account types.');
                    $ids=array_values(array_unique(array_map(static fn($x):int=>accounts_id($x,'db-role-'),$input['roleIds'])));sort($ids);
                    $oldIds=array_map(static fn(array $r):int=>(int)$r['id'],$oldRoles);sort($oldIds);
                    if($ids!==$oldIds) {
                        accounts_require($actor,'users.assign_roles');
                        if($protected||$uid===$actorId)throw new DomainException('Protected owner and self-assigned account types cannot be changed here.');
                        if($target['membership_status']==='invited')throw new InvalidArgumentException('The recipient must accept the invitation before account types can be edited.');
                        if(!$ids)throw new InvalidArgumentException('Keep at least one account type.');
                        $allowed=[];
                        foreach(accounts_roles($pdo,$org) as $role)if(accounts_role_allowed($actor,$role))$allowed[]=(int)$role['id'];
                        if(array_diff($ids,$allowed))throw new DomainException('You cannot assign one or more selected account types.');
                        $pdo->prepare('UPDATE user_roles SET revoked_at=NOW(6) WHERE membership_id=? AND revoked_at IS NULL')->execute([$target['membership_id']]);
                        foreach($ids as $roleId)$pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$target['membership_id'],$roleId,$actorId]);
                        $changedMembership=true;
                    }
                }
                if($changedMembership)accounts_bump_membership($pdo,(int)$target['membership_id']);
            }
            app_audit($pdo,$org,$actorId,$action==='profile'?'profile.updated':'user.updated','user',(string)$uid,
                ['firstName'=>$target['first_name'],'lastName'=>$target['last_name'],'position'=>$target['job_title']],
                ['firstName'=>$profile['firstName'],'lastName'=>$profile['lastName'],'position'=>$action==='profile'?$target['job_title']:$profile['position']]);
        }
        $pdo->commit();
        $delivery=$mail?accounts_deliver_invitation($pdo,$mail):null;
        return ['user'=>accounts_payload($pdo,$org,$uid,$actorId),'invitation'=>$delivery,
            'reauthenticate'=>$uid===$actorId&&$changedMembership,
            'message'=>$delivery?($delivery['delivery']==='sent'?'Invitation sent.':'The invitation email could not be sent. Check mail settings and resend.')
                :($action==='status'?'Restaurant account access updated.':'Account changes saved.')];
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function accounts_invitation_preview(PDO $pdo, string $token): ?array
{
    if(preg_match('/^[a-f0-9]{64}$/D',$token)!==1)return null;
    $q=$pdo->prepare("SELECT i.id,i.organization_id,i.user_id,i.invited_by,i.role_id,i.email,i.expires_at,o.name AS organization_name,
        u.status AS user_status,r.name AS role_name FROM account_invitations i
        JOIN users u ON u.id=i.user_id JOIN organizations o ON o.id=i.organization_id
        JOIN roles r ON r.id=i.role_id AND r.organization_id=i.organization_id
        WHERE i.token_hash=? AND i.delivery_status='sent' AND i.accepted_at IS NULL AND i.revoked_at IS NULL
          AND i.expires_at>NOW(6) AND u.email=i.email AND u.archived_at IS NULL AND u.status IN ('active','invited') AND o.status='active'");
    $q->execute([hash('sha256',$token)]);return $q->fetch()?:null;
}

function accounts_accept_invitation(PDO $pdo, string $token, string $password): array
{
    $unavailable='This invitation is invalid, expired, or already used.';
    $preview=accounts_invitation_preview($pdo,$token);
    if(!$preview)throw new InvalidArgumentException($unavailable);
    $org=(int)$preview['organization_id'];$uid=(int)$preview['user_id'];
    $pdo->beginTransaction();
    try {
        accounts_lock_org($pdo,$org);accounts_lock_users($pdo,[$uid,(int)$preview['invited_by']]);
        $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND archived_at IS NULL FOR UPDATE');$q->execute([$uid]);$target=$q->fetch();
        $q=$pdo->prepare("SELECT * FROM account_invitations WHERE id=? AND token_hash=? AND delivery_status='sent'
            AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>NOW(6) FOR UPDATE");
        $q->execute([$preview['id'],hash('sha256',$token)]);$invite=$q->fetch();
        if(!$invite||!$target||!in_array($target['status'],['active','invited'],true)
            ||!hash_equals(mb_strtolower(trim($invite['email'])),mb_strtolower(trim($target['email']))))
            throw new InvalidArgumentException($unavailable);
        $q=$pdo->prepare('SELECT * FROM organization_memberships WHERE organization_id=? AND user_id=? FOR UPDATE');
        $q->execute([$org,$uid]);$membership=$q->fetch();
        if($membership&&!in_array($membership['status'],['active','invited'],true))throw new InvalidArgumentException($unavailable);
        $q=$pdo->prepare('SELECT locked_until>NOW(6) FROM users WHERE id=?');$q->execute([$uid]);
        if((bool)$q->fetchColumn())throw new InvalidArgumentException('This account is temporarily locked. Try again later.');
        if($target['status']==='active') {
            if(!password_verify($password,(string)$target['password_hash'])) {
                app_auth_record_failure($pdo,$uid);$pdo->commit();
                throw new InvalidArgumentException('The existing account password is incorrect.');
            }
            $passwordHash=(string)$target['password_hash'];
        } else {
            if(!app_password_is_valid($password))throw new InvalidArgumentException('Use at least 12 characters with uppercase, lowercase, and a number.');
            $passwordHash=app_password_hash($password);
            $pdo->prepare("UPDATE users SET password_hash=?,status='active',password_changed_at=NOW(6) WHERE id=?")->execute([$passwordHash,$uid]);
        }
        $actor=accounts_actor($pdo,$org,(int)$invite['invited_by']);
        accounts_require($actor,'users.create');accounts_require($actor,'users.assign_roles');
        $role=null;foreach(accounts_roles($pdo,$org) as $candidate)if((int)$candidate['id']===(int)$invite['role_id'])$role=$candidate;
        if(!$role||!accounts_role_allowed($actor,$role))throw new InvalidArgumentException('The invitation account type is no longer available. Request a new invitation.');
        $pdo->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW(6)),failed_login_count=0,locked_until=NULL WHERE id=?')->execute([$uid]);
        if(!$membership) {
            $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,job_title,status) VALUES (?,?,?,'active')")
                ->execute([$org,$uid,$invite['job_title']?:'Employee']);
            $membership=['id'=>(int)$pdo->lastInsertId()];
        } else $pdo->prepare("UPDATE organization_memberships SET status='active' WHERE id=?")->execute([$membership['id']]);
        $q=$pdo->prepare('SELECT id FROM user_roles WHERE membership_id=? AND role_id=? AND revoked_at IS NULL');
        $q->execute([$membership['id'],$role['id']]);
        if(!$q->fetchColumn())$pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership['id'],$role['id'],$invite['invited_by']]);
        accounts_bump_membership($pdo,(int)$membership['id']);
        $pdo->prepare('UPDATE account_invitations SET accepted_at=NOW(6) WHERE id=?')->execute([$invite['id']]);
        $pdo->prepare('UPDATE account_invitations SET revoked_at=NOW(6) WHERE organization_id=? AND user_id=? AND id<>? AND accepted_at IS NULL AND revoked_at IS NULL')
            ->execute([$org,$uid,$invite['id']]);
        app_audit($pdo,$org,$uid,'user.invitation_accepted','user',(string)$uid,null,['roleId'=>$role['id']]);
        $pdo->commit();
        return ['userId'=>$uid,'organizationId'=>$org,'passwordHash'=>$passwordHash];
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
