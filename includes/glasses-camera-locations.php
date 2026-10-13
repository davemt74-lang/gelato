<?php
declare(strict_types=1);
require_once __DIR__.'/operational-access.php';

const GLASSES_CAMERA_LOCATION_ROLES=[1=>'prep',2=>'pre_oven',3=>'post_oven',4=>'cut_box',5=>'expo'];
const GLASSES_CAMERA_ROLE_LABELS=['prep'=>'Prep / Glasses','pre_oven'=>'Pre-Oven','post_oven'=>'Post-Oven','cut_box'=>'Cut & Box','expo'=>'Expo','custom'=>'Custom'];

function glasses_camera_location_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_camera_locations'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
}
function glasses_camera_location_authorize(PDO $pdo,array $user,int $locationId,bool $write): void
{
    if($locationId<1||!app_has_permission('glasses.view',$user)||
       !operational_location_allowed($pdo,$user,'glasses.view',$locationId)||
       ($write&&!app_has_permission('glasses.manage',$user)))
        throw new DomainException('Not authorized to configure cameras at this location.');
    $q=$pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
    $q->execute([(int)$user['organization_id'],$locationId]);
    if(!$q->fetchColumn())throw new DomainException('Restaurant location is unavailable.');
}
function glasses_camera_location_default(int $locationId,int $slot):array
{
    $role=GLASSES_CAMERA_LOCATION_ROLES[$slot]??'custom';
    return ['locationId'=>$locationId,'slot'=>$slot,'displayName'=>GLASSES_CAMERA_ROLE_LABELS[$role],
        'role'=>$role,'enabled'=>false,'stationPublicId'=>null,'zone'=>['x'=>.1,'y'=>.1,'width'=>.8,'height'=>.8],
        'rotation'=>0,'revision'=>0,'configured'=>false];
}
function glasses_camera_location_list(PDO $pdo,array $user,int $locationId):array
{
    glasses_camera_location_authorize($pdo,$user,$locationId,false);
    if(!glasses_camera_location_ready($pdo))
        throw new RuntimeException('Camera Setup requires the pending database upgrade.');
    $q=$pdo->prepare("SELECT c.*,s.public_id station_public_id FROM glasses_camera_locations c
        LEFT JOIN kds_stations s ON s.organization_id=c.organization_id AND s.id=c.station_id
        WHERE c.organization_id=? AND c.location_id=? ORDER BY c.slot");
    $q->execute([(int)$user['organization_id'],$locationId]);
    $items=[];
    for($slot=1;$slot<=5;$slot++)$items[$slot]=glasses_camera_location_default($locationId,$slot);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $slot=(int)$row['slot'];if($slot<1||$slot>5)continue;
        $items[$slot]=['locationId'=>$locationId,'slot'=>$slot,'displayName'=>(string)$row['display_name'],
            'role'=>(string)$row['role'],'enabled'=>(bool)$row['enabled'],
            'stationPublicId'=>$row['station_public_id']!==null?(string)$row['station_public_id']:null,
            'zone'=>['x'=>(float)$row['zone_x'],'y'=>(float)$row['zone_y'],
                'width'=>(float)$row['zone_width'],'height'=>(float)$row['zone_height']],
            'rotation'=>(int)$row['rotation'],'revision'=>(int)$row['revision'],'configured'=>true];
    }
    $stations=$pdo->prepare("SELECT public_id,name FROM kds_stations WHERE organization_id=? AND location_id=? AND status='active' ORDER BY name");
    $stations->execute([(int)$user['organization_id'],$locationId]);
    return ['locationId'=>$locationId,'cameras'=>array_values($items),
        'stations'=>array_map(static fn(array $r):array=>['publicId'=>(string)$r['public_id'],'name'=>(string)$r['name']],$stations->fetchAll(PDO::FETCH_ASSOC)),
        'canManage'=>app_has_permission('glasses.manage',$user)];
}
function glasses_camera_location_validate(int $slot,array $input):array
{
    if($slot<1||$slot>5)throw new InvalidArgumentException('Camera slot must be between 1 and 5.');
    $name=trim((string)($input['displayName']??''));
    if($name===''||mb_strlen($name,'UTF-8')>100)
        throw new InvalidArgumentException('Camera name is required (maximum 100 characters).');
    $role=(string)($input['role']??'');
    if(!array_key_exists($role,GLASSES_CAMERA_ROLE_LABELS))throw new InvalidArgumentException('Invalid production-stage role.');
    if(!is_bool($input['enabled']??null))throw new InvalidArgumentException('Camera enabled must be true or false.');
    $rotation=$input['rotation']??null;
    if(!is_int($rotation)||!in_array($rotation,[0,90,180,270],true))
        throw new InvalidArgumentException('Camera rotation must be 0, 90, 180 or 270.');
    $zone=$input['zone']??null;
    if(!is_array($zone))throw new InvalidArgumentException('A normalized capture zone is required.');
    $values=[];
    foreach(['x','y','width','height'] as $key){
        $raw=$zone[$key]??null;
        if(!is_int($raw)&&!is_float($raw))throw new InvalidArgumentException('Invalid capture zone '.$key.'.');
        $values[$key]=(float)$raw;
        if(!is_finite($values[$key])||$values[$key]<0||$values[$key]>1)
            throw new InvalidArgumentException('Capture zone values must be between 0 and 1.');
    }
    if($values['width']<.05||$values['height']<.05||
       $values['x']+$values['width']>1.00001||$values['y']+$values['height']>1.00001)
        throw new InvalidArgumentException('Capture zone must fit within the camera frame.');
    $station=trim((string)($input['stationPublicId']??''));
    if(mb_strlen($station,'UTF-8')>80)throw new InvalidArgumentException('Invalid station identifier.');
    $revision=$input['expectedRevision']??null;
    if(!is_int($revision)||$revision<0)
        throw new InvalidArgumentException('The current camera configuration revision is required.');
    return ['slot'=>$slot,'displayName'=>$name,'role'=>$role,'enabled'=>$input['enabled'],
        'rotation'=>$rotation,'zone'=>$values,'stationPublicId'=>$station,'expectedRevision'=>$revision];
}
function glasses_camera_location_save(PDO $pdo,array $user,int $locationId,int $slot,array $input):array
{
    glasses_camera_location_authorize($pdo,$user,$locationId,true);
    if(!glasses_camera_location_ready($pdo))throw new RuntimeException('Camera Setup database upgrade is pending.');
    $v=glasses_camera_location_validate($slot,$input);
    $org=(int)$user['organization_id'];
    return operational_db_wrap($pdo,function() use($pdo,$user,$locationId,$slot,$v,$org):array{
        $stationId=null;
        if($v['stationPublicId']!==''){
            $st=$pdo->prepare("SELECT id FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");
            $st->execute([$org,$locationId,$v['stationPublicId']]);
            $stationId=$st->fetchColumn();
            if(!$stationId)throw new InvalidArgumentException('Selected station is not active at this location.');
        }
        $q=$pdo->prepare("SELECT revision FROM glasses_camera_locations WHERE organization_id=? AND location_id=? AND slot=? FOR UPDATE");
        $q->execute([$org,$locationId,$slot]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if((int)($current['revision']??0)!==$v['expectedRevision'])
            throw new InvalidArgumentException('Camera settings changed. Reload this location before saving.');
        $args=[$v['displayName'],$v['role'],(int)$v['enabled'],$stationId,
            $v['zone']['x'],$v['zone']['y'],$v['zone']['width'],$v['zone']['height'],$v['rotation'],(int)$user['id']];
        if($current){
            $q=$pdo->prepare("UPDATE glasses_camera_locations SET display_name=?,role=?,enabled=?,station_id=?,
              zone_x=?,zone_y=?,zone_width=?,zone_height=?,rotation=?,updated_by=?,revision=revision+1
              WHERE organization_id=? AND location_id=? AND slot=? AND revision=?");
            $q->execute(array_merge($args,[$org,$locationId,$slot,$v['expectedRevision']]));
        }else{
            $q=$pdo->prepare("INSERT INTO glasses_camera_locations
              (display_name,role,enabled,station_id,zone_x,zone_y,zone_width,zone_height,rotation,updated_by,
               organization_id,location_id,slot,revision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
            $q->execute(array_merge($args,[$org,$locationId,$slot]));
        }
        app_audit($pdo,$org,(int)$user['id'],'glasses.camera_configuration','location',(string)$locationId,null,
            ['slot'=>$slot,'enabled'=>$v['enabled'],'role'=>$v['role'],'revision'=>$v['expectedRevision']+1]);
        return ['locationId'=>$locationId,'slot'=>$slot,'revision'=>$v['expectedRevision']+1,'enabled'=>$v['enabled']];
    });
}
