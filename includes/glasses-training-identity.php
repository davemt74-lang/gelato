<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/employee-home-core.php';

const GLASSES_TRAINING_ASSIGNMENT_SCHEMA='gelato.glasses_training_assignment.v1';

function glasses_training_ready(PDO $pdo): bool {
  foreach(['glasses_training_programs','glasses_training_assignments','glasses_training_sessions'] as $t){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);if((int)$q->fetchColumn()!==1)return false;
  } return true;
}
function glasses_training_program(PDO $pdo,int $org,string $public): array {
  $q=$pdo->prepare("SELECT p.*,l.name location_name,s.public_id station_public_id,s.name station_name FROM glasses_training_programs p LEFT JOIN locations l ON l.id=p.location_id LEFT JOIN kds_stations s ON s.id=p.station_id WHERE p.organization_id=? AND p.public_id=? LIMIT 1");
  $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Training program not found.');return $r;
}
function glasses_training_program_create(PDO $pdo,int $org,array $in,int $actor): array {
  $name=mb_substr(trim((string)($in['name']??'')),0,180,'UTF-8');if($name==='')throw new InvalidArgumentException('Program name is required.');
  $mode=(string)($in['mode']??'training');if(!in_array($mode,['training','shadow','production_preflight'],true))throw new InvalidArgumentException('Training mode is invalid.');
  $location=(int)($in['locationId']??0)?:null;$stationId=null;if($location)glasses_location($pdo,$org,$location);
  if(trim((string)($in['stationPublicId']??''))!==''){if(!$location)throw new InvalidArgumentException('Station requires location.');$s=glasses_station($pdo,$org,$location,(string)$in['stationPublicId']);$stationId=(int)$s['id'];}
  $public=glasses_public_id('glasses-training-program');
  $pdo->prepare("INSERT INTO glasses_training_programs (organization_id,public_id,name,description,mode,location_id,station_id,status,requirements_json,created_by) VALUES (?,?,?,?,?,?,?,'active',?,?)")
    ->execute([$org,$public,$name,mb_substr(trim((string)($in['description']??'')),0,1200,'UTF-8')?:null,$mode,$location,$stationId,json_encode(is_array($in['requirements']??null)?$in['requirements']:[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
  return glasses_training_program($pdo,$org,$public);
}
function glasses_training_require_employee(PDO $pdo,int $org,int $userId): void {
  if(!employee_home_staff_exists($pdo,$org,$userId))throw new InvalidArgumentException('Active employee is required.');
}
function glasses_training_assignment(PDO $pdo,int $org,string $public): array {
  $q=$pdo->prepare("SELECT a.*,p.public_id program_public_id,p.name program_name,d.public_id device_public_id,d.display_name device_name,u.display_name user_name,su.display_name supervisor_name,ks.public_id station_public_id,ks.name station_name,l.name location_name
    FROM glasses_training_assignments a JOIN glasses_training_programs p ON p.id=a.program_id
    JOIN users u ON u.id=a.user_id LEFT JOIN users su ON su.id=a.supervisor_user_id LEFT JOIN glasses_devices d ON d.id=a.device_id
    LEFT JOIN locations l ON l.id=a.location_id LEFT JOIN kds_stations ks ON ks.id=a.station_id
    WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
  $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Glasses training assignment not found.');return $r;
}
function glasses_training_assignment_create(PDO $pdo,int $org,array $in,int $actor): array {
  if(!glasses_training_ready($pdo))throw new RuntimeException('V11 training migration is not installed.');
  $program=glasses_training_program($pdo,$org,(string)($in['programPublicId']??''));$userId=(int)($in['userId']??0);glasses_training_require_employee($pdo,$org,$userId);
  $supervisor=(int)($in['supervisorUserId']??0)?:null;if($supervisor)glasses_training_require_employee($pdo,$org,$supervisor);
  $deviceId=null;if(trim((string)($in['devicePublicId']??''))!==''){$d=glasses_device_row($pdo,$org,(string)$in['devicePublicId'],false);if($d['status']!=='active'||$d['revoked_at']!==null)throw new InvalidArgumentException('Assigned glasses must be active.');$deviceId=(int)$d['id'];}
  $location=(int)($in['locationId']??($program['location_id']??0))?:null;$stationId=$program['station_id']!==null?(int)$program['station_id']:null;
  if(trim((string)($in['stationPublicId']??''))!==''){if(!$location)throw new InvalidArgumentException('Station requires location.');$s=glasses_station($pdo,$org,$location,(string)$in['stationPublicId']);$stationId=(int)$s['id'];}
  $mode=(string)($in['mode']??$program['mode']);if(!in_array($mode,['training','shadow','production_preflight'],true))throw new InvalidArgumentException('Assignment mode is invalid.');
  return glasses_transaction($pdo,function()use($pdo,$org,$program,$userId,$deviceId,$location,$stationId,$supervisor,$mode,$in,$actor):array{
    $due=trim((string)($in['dueAt']??''))?:null;
    $pdo->prepare("INSERT INTO training_assignments (organization_id,user_id,assignment_type,assignment_reference_id,assigned_by,due_at,status) VALUES (?,?, 'glasses_vision_program',?,?,?,'assigned')")
      ->execute([$org,$userId,$program['public_id'],$actor,$due]);$generic=(int)$pdo->lastInsertId();
    $public=glasses_public_id('glasses-training-assignment');
    $pdo->prepare("INSERT INTO glasses_training_assignments (organization_id,public_id,program_id,training_assignment_id,user_id,device_id,location_id,station_id,supervisor_user_id,mode,status,assigned_by,due_at,notes)
      VALUES (?,?,?,?,?,?,?,?,?,?,'assigned',?,?,?)")->execute([$org,$public,(int)$program['id'],$generic,$userId,$deviceId,$location,$stationId,$supervisor,$mode,$actor,$due,mb_substr(trim((string)($in['notes']??'')),0,1200,'UTF-8')?:null]);
    return glasses_training_assignment($pdo,$org,$public);
  });
}
function glasses_training_session_start(PDO $pdo,int $org,string $assignmentPublic,array $device,int $actor,array $context=[]): array {
  return glasses_transaction($pdo,function()use($pdo,$org,$assignmentPublic,$device,$actor,$context):array{
    $a=glasses_training_assignment($pdo,$org,$assignmentPublic);
    if(!in_array($a['status'],['assigned','active'],true))throw new InvalidArgumentException('Training assignment is not active.');
    if($a['device_id']!==null&&(int)$a['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Training assignment is bound to a different glasses device.');
    if((int)$device['organization_id']!==$org||$device['status']!=='active'||$device['revoked_at']!==null)throw new InvalidArgumentException('Active paired glasses are required.');
    $q=$pdo->prepare("SELECT public_id FROM glasses_training_sessions WHERE organization_id=? AND device_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE");$q->execute([$org,(int)$device['id']]);$existing=$q->fetchColumn();
    if($existing){$row=glasses_training_session($pdo,$org,(string)$existing);if((int)$row['assignment_id']===(int)$a['id'])return $row;throw new InvalidArgumentException('Glasses device is already in another active training session.');}
    $public=glasses_public_id('glasses-training-session');
    $pdo->prepare("INSERT INTO glasses_training_sessions (organization_id,public_id,assignment_id,user_id,device_id,location_id,station_id,supervisor_user_id,mode,status,context_json,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,'active',?,?)")->execute([$org,$public,(int)$a['id'],(int)$a['user_id'],(int)$device['id'],$a['location_id'],$a['station_id'],$a['supervisor_user_id'],$a['mode'],json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
    $pdo->prepare("UPDATE glasses_training_assignments SET status='active',device_id=COALESCE(device_id,?),updated_at=NOW(6) WHERE id=?")->execute([(int)$device['id'],(int)$a['id']]);
    return glasses_training_session($pdo,$org,$public);
  });
}
function glasses_training_session(PDO $pdo,int $org,string $public): array {
  $q=$pdo->prepare("SELECT s.*,a.public_id assignment_public_id,p.public_id program_public_id,p.name program_name,u.display_name user_name,d.public_id device_public_id,d.display_name device_name,su.display_name supervisor_name,ks.public_id station_public_id,ks.name station_name
    FROM glasses_training_sessions s JOIN glasses_training_assignments a ON a.id=s.assignment_id JOIN glasses_training_programs p ON p.id=a.program_id JOIN users u ON u.id=s.user_id JOIN glasses_devices d ON d.id=s.device_id LEFT JOIN users su ON su.id=s.supervisor_user_id LEFT JOIN kds_stations ks ON ks.id=s.station_id
    WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
  $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Training session not found.');$r['context']=json_decode((string)($r['context_json']??'{}'),true)?:[];unset($r['context_json']);return $r;
}
function glasses_training_session_end(PDO $pdo,int $org,string $public,string $status,string $reason,int $actor): array {
  if(!in_array($status,['completed','cancelled','interrupted'],true))throw new InvalidArgumentException('Training session end status is invalid.');
  return glasses_transaction($pdo,function()use($pdo,$org,$public,$status,$reason,$actor):array{
    $s=glasses_training_session($pdo,$org,$public);if($s['status']!=='active')return $s;
    $pdo->prepare("UPDATE glasses_training_sessions SET status=?,ended_at=NOW(6),end_reason=? WHERE organization_id=? AND id=?")->execute([$status,mb_substr(trim($reason),0,500,'UTF-8')?:null,$org,(int)$s['id']]);
    if($status==='completed'){
      $pdo->prepare("UPDATE glasses_training_assignments SET status='completed',completed_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([(int)$s['assignment_id']]);
      $pdo->prepare("UPDATE training_assignments SET status='completed',completed_at=NOW(6) WHERE id=(SELECT training_assignment_id FROM glasses_training_assignments WHERE id=?)")->execute([(int)$s['assignment_id']]);
    } else {
      $pdo->prepare("UPDATE glasses_training_assignments SET status='assigned',updated_at=NOW(6) WHERE id=?")->execute([(int)$s['assignment_id']]);
    }
    return glasses_training_session($pdo,$org,$public);
  });
}
function glasses_training_catalog(PDO $pdo,int $org): array {
  if(!glasses_training_ready($pdo))return ['programs'=>[],'assignments'=>[],'sessions'=>[]];
  $programs=$pdo->prepare("SELECT public_id FROM glasses_training_programs WHERE organization_id=? AND status<>'archived' ORDER BY updated_at DESC");$programs->execute([$org]);
  $assign=$pdo->prepare("SELECT public_id FROM glasses_training_assignments WHERE organization_id=? AND status<>'cancelled' ORDER BY updated_at DESC");$assign->execute([$org]);
  $sessions=$pdo->prepare("SELECT public_id FROM glasses_training_sessions WHERE organization_id=? ORDER BY id DESC LIMIT 100");$sessions->execute([$org]);
  return [
    'programs'=>array_map(fn($id)=>glasses_training_program($pdo,$org,(string)$id),$programs->fetchAll(PDO::FETCH_COLUMN)),
    'assignments'=>array_map(fn($id)=>glasses_training_assignment($pdo,$org,(string)$id),$assign->fetchAll(PDO::FETCH_COLUMN)),
    'sessions'=>array_map(fn($id)=>glasses_training_session($pdo,$org,(string)$id),$sessions->fetchAll(PDO::FETCH_COLUMN)),
  ];
}
