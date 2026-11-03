<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-training-identity.php';

function hgt_fixture_seed(PDO $pdo): array {
    $suffix='hgt-'.bin2hex(random_bytes(6));$password='Glasses-Training-HTTP!42';$organizations=[];$locations=[];
    foreach(['home','foreign'] as $key){
        $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix.'-'.$key]);
        $organizations[$key]=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations(organization_id,name,status) VALUES (?,?,'active')")->execute([$organizations[$key],$suffix.' kitchen '.$key]);
        $locations[$key]=(int)$pdo->lastInsertId();
    }
    $users=[];
    foreach(['pairA','pairB','operatorA','operatorB','manager','viewer','foreign'] as $kind){
        $org=$organizations[$kind==='foreign'?'foreign':'home'];$email=$suffix.'-'.$kind.'@example.test';
        $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,email_verified_at,password_changed_at) VALUES (?,?,?,'Training',?,'active',NOW(6),NOW(6))")
            ->execute([$email,app_password_hash($password),$kind,$kind.' Training']);$uid=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);$membership=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,0,0)')->execute([$org,$kind,$suffix.'-'.$kind]);$role=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
        $keys=in_array($kind,['manager','foreign'],true)?['glasses.view','glasses.manage']:['glasses.view'];
        foreach($keys as $permission){
            $q=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');
            $q->execute([$role,$permission]);if($q->rowCount()!==1)throw new RuntimeException('Missing glasses fixture permission: '.$permission);
        }
        $users[$kind]=['id'=>$uid,'organization_id'=>$org,'email'=>$email,'membership_id'=>$membership,'role_id'=>$role,'permissions'=>$keys];
    }
    // Another active restaurant membership must never excuse a local suspension.
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")
        ->execute([$organizations['foreign'],$users['pairA']['id']]);
    $devices=[];
    foreach(['A'=>'pairA','B'=>'pairB','foreign'=>'foreign'] as $key=>$account){
        $scope=$key==='foreign'?'foreign':'home';$org=$organizations[$scope];
        $grant=glasses_create_pairing_grant($pdo,$org,$locations[$scope],null,$users[$account]['id']);
        $paired=glasses_pair_device($pdo,$grant['pairingCode'],['hardwareIdentifier'=>$suffix.'-'.$key,'displayName'=>'Training '.$key,'platform'=>'browser_simulator']);
        $devices[$key]=['token'=>$paired['deviceToken'],'row'=>glasses_authenticate_token($pdo,$paired['deviceToken'])];
    }
    $org=$organizations['home'];$manager=$users['manager']['id'];
    $program=glasses_training_program_create($pdo,$org,['name'=>$suffix.' training','locationId'=>$locations['home']],$manager);
    $fixture=['organizationId'=>$org,'foreignOrganizationId'=>$organizations['foreign'],'locations'=>$locations,'users'=>$users,'devices'=>$devices,'program'=>$program,'password'=>$password];
    foreach(['A'=>'operatorA','B'=>'operatorB'] as $key=>$employee)$fixture['assignments'][$key]=hgt_fixture_assignment($pdo,$fixture,$key,$employee);
    return $fixture;
}

function hgt_fixture_assignment(PDO $pdo,array $fixture,string $device='A',string $employee='operatorA',bool $bound=true): array {
    $input=['programPublicId'=>$fixture['program']['public_id'],'userId'=>$fixture['users'][$employee]['id']];
    if($bound)$input['devicePublicId']=$fixture['devices'][$device]['row']['public_id'];
    return glasses_training_assignment_create($pdo,$fixture['organizationId'],$input,$fixture['users']['manager']['id']);
}

function hgt_fixture_snapshot(PDO $pdo,int $org): array {
    $result=[];
    foreach(['glasses_training_sessions','glasses_training_assignments','training_assignments','glasses_device_events','audit_log'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');$q->execute([$org]);$result[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $pdo=app_pdo();$operation=$argv[1]??'seed';$input=json_decode($argv[2]??'{}',true,512,JSON_THROW_ON_ERROR);
    if($operation==='seed')$result=hgt_fixture_seed($pdo);
    elseif($operation==='snapshot')$result=hgt_fixture_snapshot($pdo,(int)$input['org']);
    elseif($operation==='membership'){
        $status=(string)$input['status'];if(!in_array($status,['active','suspended'],true))throw new RuntimeException('Invalid test membership status.');
        $pdo->prepare('UPDATE organization_memberships SET status=? WHERE organization_id=? AND user_id=?')->execute([$status,(int)$input['org'],(int)$input['user']]);$result=['ok'=>true];
    }elseif($operation==='account'){
        $status=(string)$input['status'];if(!in_array($status,['active','suspended'],true))throw new RuntimeException('Invalid test account status.');
        $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status,(int)$input['user']]);$result=['ok'=>true];
    }elseif($operation==='permission'){
        if(!empty($input['enabled']))$pdo->prepare("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key='glasses.manage' WHERE r.organization_id=? AND r.id=?")
            ->execute([(int)$input['org'],(int)$input['role']]);
        else $pdo->prepare("DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.organization_id=? AND r.id=? AND p.permission_key='glasses.manage'")
            ->execute([(int)$input['org'],(int)$input['role']]);
        $result=['ok'=>true];
    }else throw new RuntimeException('Unsupported training fixture operation.');
    echo json_encode($result,JSON_THROW_ON_ERROR);
}
