<?php
declare(strict_types=1);

function vision_lab_test_grant(PDO $pdo,int $org,int $user,array $permissions,?int $location=null): array
{
    $q=$pdo->prepare('SELECT id FROM organization_memberships WHERE organization_id=? AND user_id=?');
    $q->execute([$org,$user]);$membership=(int)$q->fetchColumn();
    if($membership<1)throw new RuntimeException('Vision Lab fixture membership is required.');
    $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Vision test operator',?,0,0)")
        ->execute([$org,'vision-test-'.bin2hex(random_bytes(5))]);
    $role=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');
    foreach($permissions as $permission){
        $q->execute([$role,$permission]);
        if($q->rowCount()!==1)throw new RuntimeException('Missing Vision Lab fixture permission.');
    }
    $pdo->prepare('INSERT INTO user_roles (membership_id,role_id,location_id) VALUES (?,?,?)')->execute([$membership,$role,$location]);
    return ['id'=>$user,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>$permissions];
}
