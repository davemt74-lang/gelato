<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
$mode=$argv[1]??'seed';
if($mode==='expire'){
    session_id((string)$argv[2]); app_boot_session();
    $kind=$argv[3]??'idle';
    if($kind==='legacy'){
        unset($_SESSION['credential_version'],$_SESSION['last_activity_at']);
    }else{
        $_SESSION['authenticated_at']=time()-30000;
        $_SESSION['last_activity_at']=$kind==='absolute'?time():time()-30000;
    }
    session_write_close(); exit;
}
$pdo=app_pdo();
$suffix=bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Auth HTTP '.$suffix]);
$org=(int)$pdo->lastInsertId();
$password='Http-Hardening-Test!42';
$identities=[];
foreach(['owner','employee','coworker'] as $kind){
    $email=$kind.'-'.$suffix.'@example.test';
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,email_verified_at,password_changed_at)
        VALUES (?,?,?,'HTTP',?,'active',NOW(6),NOW(6))")
        ->execute([$email,app_password_hash($password),$kind,$kind.' HTTP']);
    $uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
    $membership=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,?,?)')
        ->execute([$org,'HTTP '.$kind,'http-'.$kind,$kind==='owner'?1:0,$kind==='owner'?1:0]);
    $role=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
    $identities[$kind]=['id'=>$uid,'email'=>$email];
}
$draft='Private-hiring-'.$suffix;
$pdo->prepare("INSERT INTO jobs(organization_id,public_id,slug,title,status) VALUES (?,?,?,?,'draft')")
    ->execute([$org,'private-'.$suffix,'private-'.$suffix,$draft]);
$token=bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(6),INTERVAL 1 HOUR))')
    ->execute([$identities['owner']['id'],hash('sha256',$token)]);
echo json_encode(['organizationId'=>$org,'users'=>$identities,'password'=>$password,'resetToken'=>$token,
    'draft'=>$draft,'sessionName'=>app_config()['security']['session_name']??'restaurant_workspace_session'],JSON_THROW_ON_ERROR);
