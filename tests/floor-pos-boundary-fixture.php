<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||getenv('GELATO_FLOOR_BOUNDARY_FIXTURE')!=='1'){
    http_response_code(404);
    exit;
}

if(($argv[1]??'')==='state'){
    require __DIR__.'/../includes/bootstrap.php';
    $pdo=app_pdo();
    $org=(int)($argv[2]??0);
    $public=(string)($argv[3]??'');
    $q=$pdo->prepare("SELECT t.status,c.public_id active_check,t.floor_plan_public_id,t.floor_plan_component_id
        FROM service_tables t LEFT JOIN pos_checks c ON c.id=t.active_check_id AND c.organization_id=t.organization_id
        WHERE t.organization_id=? AND t.public_id=?");
    $q->execute([$org,$public]);
    echo json_encode($q->fetch(),JSON_THROW_ON_ERROR);
    exit;
}
if(($argv[1]??'')==='add-location'){
    require __DIR__.'/../includes/bootstrap.php';
    $pdo=app_pdo();
    $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,'Fixture fallback location','active')")
        ->execute([(int)($argv[2]??0)]);
    echo json_encode((int)$pdo->lastInsertId(),JSON_THROW_ON_ERROR);
    exit;
}
if(($argv[1]??'')!=='seed')throw new InvalidArgumentException('Choose seed or state.');

ob_start();
require __DIR__.'/hardening-native-pdo-fixture.php';
$fixture=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$org=$fixture['organizations']['home'];
$employee=$fixture['users']['employee'];
$locations=$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status)
    VALUES (?,?,'Phoenix','AZ','active')");
$locations->execute([$org,'Fixture East']);
$east=(int)$pdo->lastInsertId();
$locations->execute([$org,'Fixture West']);
$west=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE user_roles SET location_id=? WHERE membership_id=? AND role_id=?')
    ->execute([$east,$employee['membershipId'],$employee['roleId']]);
$pdo->prepare('UPDATE organization_memberships SET primary_location_id=? WHERE id=?')
    ->execute([$east,$employee['membershipId']]);
$grant=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');
foreach(['floorplans.view','floorplans.edit','host.view','host.manage','pos.use','pos.manage','table_service.view','table_service.manage','table_service.use'] as $permission){
    $grant->execute([$employee['roleId'],$permission]);
    if($grant->rowCount()!==1)throw new RuntimeException('Missing fixture permission '.$permission);
}
echo json_encode($fixture+compact('east','west'),JSON_THROW_ON_ERROR);
