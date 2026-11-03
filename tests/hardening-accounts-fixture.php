<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')==='mail') {
    $capture=(string)getenv('GELATO_ACCOUNTS_MAIL_CAPTURE');
    $body=stream_get_contents(STDIN);
    if($capture==='' || is_file($capture.'.fail'))exit(1);
    exit(file_put_contents($capture,$body,LOCK_EX)===strlen($body)?0:1);
}
require_once __DIR__.'/../includes/user-accounts.php';

function accounts_fixture_seed(PDO $pdo):array
{
    $suffix=bin2hex(random_bytes(6));$organizations=[];$roles=[];$users=[];
    foreach(['home','other'] as $name){$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Accounts '.$name.' '.$suffix]);$organizations[$name]=(int)$pdo->lastInsertId();}
    $grants=[
        'owner'=>[],
        'manager'=>['dashboard.view','users.view','users.create','users.edit','users.suspend','users.assign_roles','resumes.convert_to_employee','settings.self_edit'],
        'employee'=>['dashboard.view','settings.self_edit'],
        'broader'=>['dashboard.view','settings.self_edit','settings.organization_edit'],
        'otherEmployee'=>['dashboard.view','settings.self_edit'],
        'otherOwner'=>[],
    ];
    foreach($grants as $kind=>$permissions){
        $org=str_starts_with($kind,'other')?$organizations['other']:$organizations['home'];
        $owner=in_array($kind,['owner','otherOwner'],true)?1:0;
        $slug=$kind==='owner'||$kind==='otherOwner'?'super_admin':($kind==='otherEmployee'?'employee':$kind);
        $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role,is_assignable) VALUES (?,?,?,?,?,1)')->execute([$org,'Account '.$kind,$slug,$owner,$owner]);
        $role=(int)$pdo->lastInsertId();$roles[$kind]=$role;
        foreach($permissions as $permission){$statement=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');$statement->execute([$role,$permission]);if($statement->rowCount()!==1)throw new RuntimeException('Missing fixture permission: '.$permission);}
    }
    $password='Accounts-Fixture!42';$hash=app_password_hash($password);
    foreach(['owner','manager','employee','otherOwner','shared','foreign'] as $kind){
        $org=in_array($kind,['otherOwner','foreign'],true)?$organizations['other']:$organizations['home'];
        $email='accounts-'.$kind.'-'.$suffix.'@example.test';
        $first=$kind==='foreign'?'ForeignSecret':ucfirst($kind);$last=$kind==='foreign'?'PRIVATE-'.$suffix:'Account';$name=$first.' '.$last;$phone=$kind==='foreign'?'6025559937':'6025550117';
        $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,phone,status,email_verified_at,password_changed_at) VALUES (?,?,?,?,?,?,'active',NOW(6),NOW(6))")->execute([$email,$hash,$first,$last,$name,$phone]);
        $uid=(int)$pdo->lastInsertId();$users[$kind]=['id'=>$uid,'email'=>$email,'organizationId'=>$org];
        $role=$roles[match($kind){'otherOwner'=>'otherOwner','foreign'=>'otherEmployee','shared'=>'employee',default=>$kind}];
        foreach($kind==='shared'?[$organizations['home'],$organizations['other']]:[$org] as $membershipOrg){
            $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,job_title,status) VALUES (?,?,?,'active')")->execute([$membershipOrg,$uid,$membershipOrg===$organizations['home']?'Home kitchen':'Other kitchen']);
            $membership=(int)$pdo->lastInsertId();$membershipRole=$kind==='shared'&&$membershipOrg===$organizations['other']?$roles['otherEmployee']:$role;
            $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$membershipRole,$uid]);
        }
    }
    return ['password'=>$password,'organizations'=>$organizations,'users'=>$users,'roles'=>$roles,
        'inviteEmail'=>'accounts-invite-'.$suffix.'@example.test','suffix'=>$suffix,
        'foreignPrivate'=>['firstName'=>'ForeignSecret','lastName'=>'PRIVATE-'.$suffix,'displayName'=>'ForeignSecret PRIVATE-'.$suffix,'phone'=>'6025559937']];
}

function accounts_fixture_inspect(PDO $pdo,string $email,?int $organization=null):array
{
    $q=$pdo->prepare('SELECT id,email,first_name,last_name,display_name,phone,status,email_verified_at,password_changed_at,failed_login_count,locked_until FROM users WHERE email=?');$q->execute([$email]);$user=$q->fetch()?:null;
    $memberships=[];$invitations=[];$audits=[];
    if($user){
        $args=[(int)$user['id']];if($organization)$args[]=$organization;
        $q=$pdo->prepare('SELECT id,organization_id,status,job_title,updated_at FROM organization_memberships WHERE user_id=?'.($organization?' AND organization_id=?':'').' ORDER BY organization_id');$q->execute($args);
        foreach($q->fetchAll() as $row){$r=$pdo->prepare('SELECT role_id FROM user_roles WHERE membership_id=? AND revoked_at IS NULL ORDER BY role_id');$r->execute([(int)$row['id']]);$memberships[]=['id'=>(int)$row['id'],'organizationId'=>(int)$row['organization_id'],'status'=>$row['status'],'jobTitle'=>$row['job_title'],'updatedAt'=>$row['updated_at'],'roleIds'=>array_map('intval',$r->fetchAll(PDO::FETCH_COLUMN))];}
        $q=$pdo->prepare('SELECT id,organization_id,user_id,email,role_id,first_name,last_name,display_name,phone,job_title,delivery_status,expires_at,accepted_at,revoked_at,created_at FROM account_invitations WHERE user_id=?'.($organization?' AND organization_id=?':'').' ORDER BY id');$q->execute($args);$invitations=$q->fetchAll();
        $args=[(string)$user['id']];if($organization)$args[]=$organization;
        $q=$pdo->prepare("SELECT action,organization_id,entity_id,created_at FROM audit_log WHERE entity_type='user' AND entity_id=?".($organization?' AND organization_id=?':'').' ORDER BY id');$q->execute($args);$audits=$q->fetchAll();
    }
    return ['user'=>$user,'memberships'=>$memberships,'invitations'=>$invitations,'audits'=>$audits];
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $pdo=app_pdo();$mode=$argv[1]??'seed';
    if($mode==='seed'){echo json_encode(accounts_fixture_seed($pdo),JSON_THROW_ON_ERROR);exit;}
    if($mode==='inspect'){echo json_encode(accounts_fixture_inspect($pdo,(string)($argv[2]??''),isset($argv[3])?(int)$argv[3]:null),JSON_THROW_ON_ERROR);exit;}
    if($mode==='session'){
        $uid=(int)($argv[2]??0);$org=(int)($argv[3]??0);
        app_session_start_user($pdo,$org,$uid);
        $result=['cookie'=>session_name().'='.session_id(),'csrfToken'=>app_csrf_token()];session_write_close();echo json_encode($result,JSON_THROW_ON_ERROR);exit;
    }
    if($mode==='expire'){
        $args=[(string)($argv[2]??'')];if(isset($argv[3]))$args[]=(int)$argv[3];
        $q=$pdo->prepare('UPDATE account_invitations SET expires_at=DATE_SUB(NOW(6),INTERVAL 1 MINUTE) WHERE email=?'.(isset($argv[3])?' AND organization_id=?':''));$q->execute($args);echo json_encode(['updated'=>$q->rowCount()]);exit;
    }
    throw new InvalidArgumentException('Unknown accounts fixture mode.');
}
