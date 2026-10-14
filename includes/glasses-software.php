<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-employee-runtimes.php';
require_once __DIR__.'/operational-access.php';

function glasses_software_ready(PDO $pdo): bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema=DATABASE() AND table_name='glasses_software_policies'");
    return (int)$q->fetchColumn()===1;
}

/** Advisory numeric release comparison; unknown formats never become "current". */
function glasses_software_version_valid(?string $value): bool
{
    return $value!==null &&
      (bool)preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\.(?:0|[1-9][0-9]{0,4})){1,3}$/D',$value);
}

function glasses_software_assess(?string $installed,?array $policy): array
{
    if($policy===null || (empty($policy['minimumVersion'])&&empty($policy['targetVersion'])))
        return ['state'=>'unmanaged','label'=>'No version policy'];
    if($installed===null||trim($installed)==='')
        return ['state'=>'unreported','label'=>'Software version not reported'];
    if(!glasses_software_version_valid(trim($installed)))
        return ['state'=>'unknown','label'=>'Unrecognized software version'];
    $minimum=$policy['minimumVersion']??null;
    $target=$policy['targetVersion']??null;
    if($minimum!==null&&version_compare(trim($installed),$minimum,'<'))
        return ['state'=>'below_minimum','label'=>'Below minimum version'];
    if($target!==null&&version_compare(trim($installed),$target,'<'))
        return ['state'=>'update_recommended','label'=>'Update recommended'];
    return ['state'=>'current','label'=>'Version policy met'];
}

function glasses_software_fleet(PDO $pdo,array $user): array
{
    if(!app_has_permission('glasses.view',$user))
        throw new DomainException('Glasses view permission required.');
    if(!glasses_software_ready($pdo))
        throw new DomainException('Software management is not installed. Run upgrade.php.');
    $org=(int)$user['organization_id'];
    $q=$pdo->prepare("SELECT location_id,platform,minimum_version,target_version,notes,revision,updated_at
      FROM glasses_software_policies WHERE organization_id=?");
    $q->execute([$org]);
    $policies=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $p=['minimumVersion'=>$row['minimum_version'],'targetVersion'=>$row['target_version'],
            'notes'=>$row['notes'],'revision'=>(int)$row['revision'],'updatedAt'=>$row['updated_at']];
        $policies[(int)$row['location_id'].'|'.(string)$row['platform']]=$p;
    }
    $sessions=[];
    if(glasses_employee_runtimes_ready($pdo)){
        $q=$pdo->prepare("SELECT r.device_id,r.public_id,u.display_name,r.last_seen_at
          FROM glasses_employee_runtimes r JOIN users u ON u.id=r.employee_user_id
          WHERE r.organization_id=? AND r.status='active' AND r.active_slot=1");
        $q->execute([$org]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)
            $sessions[(int)$r['device_id']]=[
                'publicId'=>(string)$r['public_id'],
                'employeeName'=>(string)$r['display_name'],
                'lastSeenAt'=>$r['last_seen_at']
            ];
    }
    $devices=[];
    foreach(glasses_devices($pdo,$org) as $d){
        $location=(int)$d['locationId'];
        if(!operational_location_allowed($pdo,$user,'glasses.view',$location))continue;
        $policy=$policies[$location.'|'.(string)$d['platform']]??null;
        $devices[]=[
          'publicId'=>(string)$d['publicId'],
          'name'=>(string)$d['displayName'],
          'platform'=>(string)$d['platform'],
          'locationId'=>$location,
          'locationName'=>(string)($d['locationName']??''),
          'stationName'=>$d['stationName']??null,
          'appVersion'=>$d['appVersion']??null,
          'sdkVersion'=>$d['sdkVersion']??null,
          'systemVersion'=>$d['systemVersion']??null,
          'pairedAt'=>$d['pairedAt']??null,
          'lastSeenAt'=>$d['lastSeenAt']??null,
          'heartbeatAgeSeconds'=>$d['heartbeatAgeSeconds']??null,
          'online'=>(bool)($d['deviceOnline']??false),
          'status'=>(string)$d['status'],
          'runtime'=>$sessions[(int)$d['id']]??null,
          'policy'=>$policy,
          'software'=>glasses_software_assess($d['appVersion']??null,$policy),
          'canManage'=>app_has_permission('glasses.manage',$user)
             &&operational_location_allowed($pdo,$user,'glasses.manage',$location),
        ];
    }
    return ['devices'=>$devices,'updateMethod'=>'manual_only','nativeInstallerAvailable'=>false];
}

function glasses_software_policy_save(PDO $pdo,array $user,string $devicePublicId,
    ?string $minimum,?string $target,string $notes,int $expectedRevision): array
{
    if(!glasses_software_ready($pdo))
        throw new DomainException('Software management is not installed. Run upgrade.php.');
    $org=(int)$user['organization_id'];
    $device=glasses_device_row($pdo,$org,trim($devicePublicId));
    $location=(int)$device['location_id'];
    if(!app_has_permission('glasses.manage',$user) ||
       !operational_location_allowed($pdo,$user,'glasses.manage',$location))
        throw new DomainException('Glasses management permission required at this restaurant.');
    $minimum=trim((string)$minimum);$target=trim((string)$target);$notes=trim($notes);
    $minimum=$minimum!==''?$minimum:null;$target=$target!==''?$target:null;
    if($minimum!==null&&!glasses_software_version_valid($minimum))
        throw new InvalidArgumentException('Minimum release must use numeric dotted notation such as 1.2.3.');
    if($target!==null&&!glasses_software_version_valid($target))
        throw new InvalidArgumentException('Target release must use numeric dotted notation such as 1.2.3.');
    if($minimum!==null&&$target!==null&&version_compare($target,$minimum,'<'))
        throw new InvalidArgumentException('Target version cannot be below minimum version.');
    if(mb_strlen($notes,'UTF-8')>240)throw new InvalidArgumentException('Notes must be 240 characters or fewer.');
    if($expectedRevision<0)throw new InvalidArgumentException('Invalid policy revision.');
    $platform=(string)$device['platform'];
    return glasses_transaction($pdo,static function()use(
        $pdo,$org,$location,$platform,$minimum,$target,$notes,$expectedRevision,$user,$device
    ):array{
        $q=$pdo->prepare("SELECT id,revision FROM glasses_software_policies
          WHERE organization_id=? AND location_id=? AND platform=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$location,$platform]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if((int)$existing['revision']!==$expectedRevision)
                throw new DomainException('Software policy changed. Refresh and review before saving.');
            $q=$pdo->prepare("UPDATE glasses_software_policies
              SET minimum_version=?,target_version=?,notes=?,revision=revision+1,updated_by=?,updated_at=NOW(6)
              WHERE id=? AND organization_id=? AND revision=?");
            $q->execute([$minimum,$target,$notes!==''?$notes:null,(int)$user['id'],
                (int)$existing['id'],$org,$expectedRevision]);
            if($q->rowCount()!==1)throw new DomainException('Software policy changed. Refresh and retry.');
            $revision=$expectedRevision+1;
        }else{
            if($expectedRevision!==0)
                throw new DomainException('Software policy was removed or changed. Refresh before saving.');
            try{
                $pdo->prepare("INSERT INTO glasses_software_policies
                  (organization_id,location_id,platform,minimum_version,target_version,notes,updated_by)
                  VALUES (?,?,?,?,?,?,?)")->execute([$org,$location,$platform,$minimum,$target,
                    $notes!==''?$notes:null,(int)$user['id']]);
            }catch(PDOException $e){
                if((string)$e->getCode()==='23000')
                    throw new DomainException('Software policy was created by another administrator. Refresh.');
                throw $e;
            }
            $revision=1;
        }
        glasses_device_event($pdo,$org,(int)$device['id'],'software_policy_updated',
            $location,$device['station_id']!==null?(int)$device['station_id']:null,
            ['platform'=>$platform,'minimumVersion'=>$minimum,'targetVersion'=>$target,
             'revision'=>$revision],(int)$user['id']);
        return ['minimumVersion'=>$minimum,'targetVersion'=>$target,
            'notes'=>$notes!==''?$notes:null,'revision'=>$revision];
    });
}

function glasses_software_diagnostics(PDO $pdo,array $user,string $devicePublicId): array
{
    if(!glasses_software_ready($pdo))
        throw new DomainException('Software management is not installed. Run upgrade.php.');
    $org=(int)$user['organization_id'];
    $device=glasses_device_row($pdo,$org,trim($devicePublicId));
    if(!app_has_permission('glasses.view',$user)||
       !operational_location_allowed($pdo,$user,'glasses.view',(int)$device['location_id']))
        throw new DomainException('You cannot view this glasses device.');
    $q=$pdo->prepare("SELECT event_type,created_at FROM glasses_device_events
      WHERE organization_id=? AND device_id=? AND event_type<>'heartbeat'
      ORDER BY created_at DESC,id DESC LIMIT 20");
    $q->execute([$org,(int)$device['id']]);
    $events=array_map(static fn(array $row):array=>[
        'eventType'=>(string)$row['event_type'],
        'at'=>(string)$row['created_at'],
    ],$q->fetchAll(PDO::FETCH_ASSOC));
    return ['publicId'=>(string)$device['public_id'],'events'=>$events,
        'updateMethod'=>'manual_only',
        'nativeInstallerAvailable'=>false];
}
