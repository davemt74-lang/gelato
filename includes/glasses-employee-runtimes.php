<?php
declare(strict_types=1);

/**
 * One active runtime per physical device and per active employee.
 * A runtime is an assignment/lease, NOT an authorization to impersonate
 * its employee. Every kitchen write still uses the authenticated actor.
 */
require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/employee-home-core.php';
require_once __DIR__.'/glasses-vision-lab.php';
require_once __DIR__.'/glasses-training-identity.php';
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
        'startedAt'=>$row['started_at']??null,
        'wearerAssignmentPublicId'=>$row['wearer_assignment_public_id']??null
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
        a.public_id wearer_assignment_public_id,a.released_at wearer_released_at,
        u.status employee_status,u.archived_at employee_archived_at,om.status membership_status
      FROM glasses_employee_runtimes r
      JOIN glasses_devices d ON d.id=r.device_id AND d.organization_id=r.organization_id
      JOIN locations l ON l.id=d.location_id AND l.organization_id=r.organization_id
      LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=r.organization_id
      JOIN users u ON u.id=r.employee_user_id
      JOIN glasses_user_device_assignments a ON a.id=r.wearer_assignment_id AND a.organization_id=r.organization_id
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
       $row['membership_status']!=='active'||$row['wearer_released_at']!==null)
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
      l.name location_name,s.name station_name,u.display_name employee_display_name,
      a.public_id wearer_assignment_public_id
      FROM glasses_employee_runtimes r
      JOIN glasses_devices d ON d.id=r.device_id AND d.organization_id=r.organization_id
      JOIN locations l ON l.id=d.location_id AND l.organization_id=r.organization_id
      LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=r.organization_id
      JOIN users u ON u.id=r.employee_user_id
      JOIN glasses_user_device_assignments a ON a.id=r.wearer_assignment_id AND a.organization_id=r.organization_id
      WHERE r.organization_id=? AND r.status='active' AND d.status='active'
      ORDER BY r.started_at ASC,r.id ASC");
    $q->execute([$org]);
    $runtimes=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!operational_location_allowed($pdo,$user,'glasses.view',(int)$row['location_id']))continue;
        $runtimes[]=glasses_employee_runtime_public($row);
    }
    // Reuse the canonical V11 training/employee directory; no duplicate roster.
    $employees=app_has_permission('glasses.manage',$user) && glasses_training_ready($pdo)
        ?glasses_training_employee_catalog($pdo,$org):[];
    $trainingAssignments=[];
    $activeSessions=[];
    if(glasses_training_ready($pdo)){
        $q=$pdo->prepare("SELECT a.public_id,a.user_id,a.device_id,a.mode,a.status,
            p.name program_name,p.public_id program_public_id
          FROM glasses_training_assignments a
          JOIN glasses_training_programs p ON p.id=a.program_id
          WHERE a.organization_id=? AND a.status IN ('assigned','active')
            AND p.status='active' ORDER BY p.name,a.id");
        $q->execute([$org]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $trainingAssignments[]=[
                'publicId'=>(string)$row['public_id'],
                'employeeUserId'=>(int)$row['user_id'],
                'deviceId'=>$row['device_id']!==null?(int)$row['device_id']:null,
                'programName'=>(string)$row['program_name'],
                'programPublicId'=>(string)$row['program_public_id'],
                'mode'=>(string)$row['mode'],'status'=>(string)$row['status']
            ];
        }
        $q=$pdo->prepare("SELECT s.public_id,s.user_id,s.device_id,s.status,a.public_id assignment_public_id
          FROM glasses_training_sessions s
          JOIN glasses_training_assignments a ON a.id=s.assignment_id
          WHERE s.organization_id=? AND s.status='active'");
        $q->execute([$org]);
        $activeSessions=array_map(static fn(array $r):array=>[
            'publicId'=>(string)$r['public_id'],
            'employeeUserId'=>(int)$r['user_id'],
            'deviceId'=>(int)$r['device_id'],
            'assignmentPublicId'=>(string)$r['assignment_public_id']
        ],$q->fetchAll(PDO::FETCH_ASSOC));
    }
    return ['schemaReady'=>true,'runtimes'=>$runtimes,'employees'=>$employees,
        'trainingAssignments'=>$trainingAssignments,'activeTrainingSessions'=>$activeSessions];
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
        // The existing Vision Lab wearer ledger remains the identity source of truth.
        // Reject silently stealing an assigned device from another employee.
        if(!glasses_vision_lab_ready($pdo))
            throw new DomainException('Existing Vision Lab wearer assignments are not installed.');
        $q=$pdo->prepare("SELECT id,public_id,user_id FROM glasses_user_device_assignments
          WHERE organization_id=? AND device_id=? AND released_at IS NULL LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$device['id']]);$wearer=$q->fetch(PDO::FETCH_ASSOC);
        if($wearer&&(int)$wearer['user_id']!==$employeeId)
            throw new DomainException('This device is already assigned to another wearer in Vision Lab. Release that assignment first.');
        if(!$wearer){
            $identity=glasses_vision_lab_assign_device($pdo,$org,$devicePublicId,$employeeId,'operator','manual',(int)$user['id']);
            $q=$pdo->prepare("SELECT id,public_id,user_id FROM glasses_user_device_assignments WHERE organization_id=? AND public_id=?");
            $q->execute([$org,(string)$identity['publicId']]);$wearer=$q->fetch(PDO::FETCH_ASSOC);
        }
        $publicId=glasses_public_id('runtime');
        try{
            $pdo->prepare("INSERT INTO glasses_employee_runtimes
              (organization_id,public_id,device_id,employee_user_id,wearer_assignment_id,status,active_slot,started_by,last_seen_at)
              VALUES (?,?,?,?,?,'active',1,?,NOW(6))")
              ->execute([$org,$publicId,(int)$device['id'],$employeeId,(int)$wearer['id'],(int)$user['id']]);
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
            // Release only the exact wearer record bound to this runtime.
            $pdo->prepare("UPDATE glasses_user_device_assignments SET released_at=COALESCE(released_at,NOW(6)),
              release_reason=COALESCE(release_reason,'runtime_ended') WHERE organization_id=? AND id=? AND released_at IS NULL")
              ->execute([(int)$user['organization_id'],(int)$row['wearer_assignment_id']]);
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

/** Start an EXISTING assigned training program; never create a parallel program. */
function glasses_employee_runtime_training_start(PDO $pdo,array $user,string $runtimePublicId,string $assignmentPublicId): array
{
    if(!app_has_permission('glasses.manage',$user))
        throw new DomainException('Training session management requires glasses.manage.');
    $row=glasses_employee_runtime_row($pdo,$user,$runtimePublicId);
    $device=glasses_device_row($pdo,(int)$user['organization_id'],(string)$row['device_public_id']);
    glasses_employee_runtime_assert_binding($pdo,$user,$runtimePublicId,$device);
    if(!glasses_training_ready($pdo))throw new DomainException('Existing V11 training programs are unavailable.');
    $org=(int)$user['organization_id'];
    $assignment=glasses_training_assignment($pdo,$org,$assignmentPublicId);
    if((int)$assignment['user_id']!==(int)$row['employee_user_id']||
        ($assignment['device_id']!==null&&(int)$assignment['device_id']!==(int)$row['device_id']))
        throw new DomainException('Training assignment does not belong to this employee and device.');
    if($assignment['location_id']!==null&&(int)$assignment['location_id']!==(int)$row['location_id'])
        throw new DomainException('Training is assigned to a different location.');
    if($assignment['station_id']!==null&&(int)$assignment['station_id']!==(int)$row['station_id'])
        throw new DomainException('Training is assigned to a different kitchen station.');
    return glasses_training_session_start($pdo,$org,$assignmentPublicId,$device,(int)$user['id'],
        ['runtimePublicId'=>$runtimePublicId,'source'=>'glasses_employee_runtime']);
}

function glasses_employee_runtime_training_end(PDO $pdo,array $user,string $runtimePublicId,string $sessionPublicId,string $status): array
{
    if(!app_has_permission('glasses.manage',$user))
        throw new DomainException('Training session management requires glasses.manage.');
    if(!in_array($status,['completed','interrupted'],true))
        throw new InvalidArgumentException('Training session status must be completed or interrupted.');
    $row=glasses_employee_runtime_row($pdo,$user,$runtimePublicId);
    $device=glasses_device_row($pdo,(int)$user['organization_id'],(string)$row['device_public_id']);
    glasses_employee_runtime_assert_binding($pdo,$user,$runtimePublicId,$device);
    $org=(int)$user['organization_id'];
    $session=glasses_training_session($pdo,$org,$sessionPublicId);
    $context=is_array($session['context']??null)?$session['context']:[];
    if((int)$session['user_id']!==(int)$row['employee_user_id']||
        (int)$session['device_id']!==(int)$row['device_id']||
        (string)($context['runtimePublicId']??'')!==$runtimePublicId)
        throw new DomainException('Training session does not belong to this runtime.');
    return glasses_training_session_end($pdo,$org,$sessionPublicId,$status,'ended_from_glasses_runtime',(int)$user['id']);
}
