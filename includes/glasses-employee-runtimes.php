<?php
declare(strict_types=1);

/**
 * One active runtime per physical device and per active employee.
 * A runtime is an assignment/lease, NOT an authorization to impersonate
 * its employee. Every kitchen write still uses the authenticated actor.
 */
require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/employee-home-core.php';
require_once __DIR__.'/operational-access.php';

function glasses_employee_runtimes_ready(PDO $pdo): bool
{
    $q=$pdo->query("SHOW TABLES LIKE 'glasses_employee_runtimes'");
    return (bool)$q->fetchColumn();
}

function glasses_employee_runtime_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'devicePublicId'=>(string)$row['device_public_id'],
        'deviceName'=>(string)$row['device_display_name'],
        'employeeUserId'=>(int)$row['employee_user_id'],
        'employeeName'=>(string)$row['employee_display_name'],
        'locationId'=>(int)$row['location_id'],
        'locationName'=>(string)$row['location_name'],
        'stationName'=>$row['station_name']??null,
        'status'=>(string)$row['status'],
        'lastSeenAt'=>$row['last_seen_at']??null,
        'startedAt'=>$row['started_at']??null
    ];
}

function glasses_employee_runtime_row(PDO $pdo,array $user,string $publicId,bool $forUpdate=false): array
{
    if(!glasses_employee_runtimes_ready($pdo))
        throw new DomainException('Glasses runtimes are not installed; run upgrade.php.');
    $id=trim($publicId);
    if($id===''||strlen($id)>80)throw new InvalidArgumentException('Choose a glasses runtime.');
    $org=(int)$user['organization_id'];
    $sql="SELECT r.*,d.public_id device_public_id,d.display_name device_display_name,
        d.location_id,d.station_id,d.status device_status,d.revoked_at device_revoked_at,
        l.name location_name,s.name station_name,u.display_name employee_display_name,
        u.status employee_status,u.archived_at employee_archived_at,om.status membership_status
      FROM glasses_employee_runtimes r
      JOIN glasses_devices d ON d.id=r.device_id AND d.organization_id=r.organization_id
      JOIN locations l ON l.id=d.location_id AND l.organization_id=r.organization_id
      LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=r.organization_id
      JOIN users u ON u.id=r.employee_user_id
      LEFT JOIN organization_memberships om ON om.organization_id=r.organization_id AND om.user_id=r.employee_user_id
      WHERE r.organization_id=? AND r.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new DomainException('Runtime is not available to this organization.');
    if(!app_has_permission('glasses.view',$user)||
       !operational_location_allowed($pdo,$user,'glasses.view',(int)$row['location_id']))
        throw new DomainException('You cannot access this runtime location.');
    return $row;
}

function glasses_employee_runtime_assert_binding(PDO $pdo,array $user,string $publicId,array $device): array
{
    $row=glasses_employee_runtime_row($pdo,$user,$publicId);
    if($row['status']!=='active'||(int)($row['active_slot']??0)!==1||
       $row['device_status']!=='active'||!empty($row['device_revoked_at'])||
       $row['employee_status']!=='active'||!empty($row['employee_archived_at'])||
       $row['membership_status']!=='active')
        throw new DomainException('Runtime or employee assignment is inactive. Select an active runtime.');
    if((int)$row['device_id']!==(int)$device['id']||
       (string)$row['device_public_id']!==(string)$device['public_id'])
        throw new DomainException('Runtime is assigned to a different glasses device.');
    return glasses_employee_runtime_public($row);
}

function glasses_employee_runtime_catalog(PDO $pdo,array $user): array
{
    if(!glasses_employee_runtimes_ready($pdo))
        return ['schemaReady'=>false,'runtimes'=>[],'employees'=>[]];
    $org=(int)$user['organization_id'];
    $q=$pdo->prepare("SELECT r.public_id,r.employee_user_id,r.status,r.started_at,r.last_seen_at,
      d.public_id device_public_id,d.display_name device_display_name,d.location_id,
      l.name location_name,s.name station_name,u.display_name employee_display_name
      FROM glasses_employee_runtimes r
      JOIN glasses_devices d ON d.id=r.device_id AND d.organization_id=r.organization_id
      JOIN locations l ON l.id=d.location_id AND l.organization_id=r.organization_id
      LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=r.organization_id
      JOIN users u ON u.id=r.employee_user_id
      WHERE r.organization_id=? AND r.status='active' AND d.status='active'
      ORDER BY r.started_at ASC,r.id ASC");
    $q->execute([$org]);
    $runtimes=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!operational_location_allowed($pdo,$user,'glasses.view',(int)$row['location_id']))continue;
        $runtimes[]=glasses_employee_runtime_public($row);
    }
    $employees=[];
    if(app_has_permission('glasses.manage',$user)){
        $q=$pdo->prepare("SELECT u.id,u.display_name,om.employee_number,om.primary_location_id
          FROM organization_memberships om
          JOIN users u ON u.id=om.user_id
          WHERE om.organization_id=? AND om.status='active'
            AND u.status='active' AND u.archived_at IS NULL
          ORDER BY u.display_name,u.id");
        $q->execute([$org]);
        $employees=array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'displayName'=>(string)$r['display_name'],
            'employeeNumber'=>$r['employee_number'],
            'primaryLocationId'=>$r['primary_location_id']!==null?(int)$r['primary_location_id']:null
        ],$q->fetchAll(PDO::FETCH_ASSOC));
    }
    return ['schemaReady'=>true,'runtimes'=>$runtimes,'employees'=>$employees];
}

function glasses_employee_runtime_create(PDO $pdo,array $user,string $devicePublicId,int $employeeId): array
{
    if(!glasses_employee_runtimes_ready($pdo))
        throw new DomainException('Glasses runtimes are not installed; run upgrade.php.');
    if(!app_has_permission('glasses.manage',$user))
        throw new DomainException('Glasses management permission is required to assign employees.');
    if($employeeId<1)throw new InvalidArgumentException('Select an employee.');
    $org=(int)$user['organization_id'];
    return glasses_transaction($pdo,static function()use($pdo,$user,$org,$devicePublicId,$employeeId):array{
        $device=glasses_device_row($pdo,$org,$devicePublicId,true);
        $locationId=(int)$device['location_id'];
        if($device['status']!=='active'||$device['revoked_at']!==null)
            throw new InvalidArgumentException('Only active paired glasses can run.');
        if(!operational_location_allowed($pdo,$user,'glasses.manage',$locationId)||
           !operational_location_allowed($pdo,$user,'glasses.view',$locationId))
            throw new DomainException('You cannot manage glasses at this location.');
        if(!employee_home_staff_exists($pdo,$org,$employeeId))
            throw new InvalidArgumentException('Employee must have an active account in this organization.');
        $q=$pdo->prepare("SELECT id FROM glasses_employee_runtimes WHERE organization_id=?
          AND status='active' AND (device_id=? OR employee_user_id=?) LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$device['id'],$employeeId]);
        if($q->fetchColumn())
            throw new DomainException('This glasses device or employee already has an active runtime. End it before reassignment.');
        $publicId=glasses_public_id('runtime');
        try{
            $pdo->prepare("INSERT INTO glasses_employee_runtimes
              (organization_id,public_id,device_id,employee_user_id,status,active_slot,started_by,last_seen_at)
              VALUES (?,?,?,?,'active',1,?,NOW(6))")
              ->execute([$org,$publicId,(int)$device['id'],$employeeId,(int)$user['id']]);
        }catch(PDOException $e){
            if((string)$e->getCode()==='23000')
                throw new DomainException('This employee or device has already been assigned to a runtime.');
            throw $e;
        }
        glasses_device_event($pdo,$org,(int)$device['id'],'runtime_employee_assigned',$locationId,
          $device['station_id']!==null?(int)$device['station_id']:null,
          ['runtimePublicId'=>$publicId,'employeeUserId'=>$employeeId],(int)$user['id']);
        return glasses_employee_runtime_public(glasses_employee_runtime_row($pdo,$user,$publicId));
    });
}

function glasses_employee_runtime_end(PDO $pdo,array $user,string $publicId): array
{
    if(!app_has_permission('glasses.manage',$user))
        throw new DomainException('Glasses management permission is required.');
    return glasses_transaction($pdo,static function()use($pdo,$user,$publicId):array{
        $row=glasses_employee_runtime_row($pdo,$user,$publicId,true);
        if(!operational_location_allowed($pdo,$user,'glasses.manage',(int)$row['location_id']))
            throw new DomainException('You cannot manage this runtime location.');
        if($row['status']==='active'){
            $pdo->prepare("UPDATE glasses_employee_runtimes SET status='ended',active_slot=NULL,ended_at=NOW(6),ended_by=?,updated_at=NOW(6)
              WHERE organization_id=? AND id=? AND status='active'")
              ->execute([(int)$user['id'],(int)$user['organization_id'],(int)$row['id']]);
            glasses_device_event($pdo,(int)$user['organization_id'],(int)$row['device_id'],
              'runtime_employee_released',(int)$row['location_id'],
              $row['station_id']!==null?(int)$row['station_id']:null,
              ['runtimePublicId'=>$publicId,'employeeUserId'=>(int)$row['employee_user_id']],(int)$user['id']);
        }
        return ['publicId'=>$publicId,'status'=>'ended'];
    });
}

function glasses_employee_runtime_heartbeat(PDO $pdo,array $user,string $publicId,string $devicePublicId): array
{
    $device=glasses_device_row($pdo,(int)$user['organization_id'],$devicePublicId);
    $runtime=glasses_employee_runtime_assert_binding($pdo,$user,$publicId,$device);
    $pdo->prepare("UPDATE glasses_employee_runtimes SET last_seen_at=NOW(6)
      WHERE organization_id=? AND public_id=? AND status='active'")
      ->execute([(int)$user['organization_id'],$publicId]);
    return $runtime;
}
