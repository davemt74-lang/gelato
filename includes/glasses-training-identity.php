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
  return glasses_training_transaction($pdo,$org,function()use($pdo,$org,$assignmentPublic,$device,$actor,$context):array{
    if($actor!==(int)($device['paired_by']??0))glasses_training_require_manager($pdo,$org,$actor);
    $device=glasses_training_lock_device($pdo,$org,$device);
    // Every lifecycle path locks the device before a session and its assignment.
    $q=$pdo->prepare("SELECT * FROM glasses_training_sessions WHERE organization_id=? AND device_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $q->execute([$org,(int)$device['id']]);$existing=$q->fetch();
    $q=$pdo->prepare('SELECT * FROM glasses_training_assignments WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$org,$assignmentPublic]);$a=$q->fetch();
    if(!$a)throw new InvalidArgumentException('Glasses training assignment not found.');
    if(!in_array($a['status'],['assigned','active'],true))throw new InvalidArgumentException('Training assignment is not active.');
    if($a['device_id']!==null&&(int)$a['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Training assignment is bound to a different glasses device.');
    glasses_training_lock_generic_assignment($pdo,$org,$a);
    if($existing){
      if((int)$existing['assignment_id']===(int)$a['id']&&(int)$existing['user_id']===(int)$a['user_id']){
        if($a['status']!=='active'||(int)$a['device_id']!==(int)$device['id'])
          throw new InvalidArgumentException('Training assignment needs manager correction before another session transition.');
        return glasses_training_session_result($pdo,$org,$existing);
      }
      throw new InvalidArgumentException('Glasses device is already in another active training session.');
    }
    glasses_training_require_employee($pdo,$org,(int)$a['user_id']);
    $q=$pdo->prepare("SELECT id FROM glasses_training_sessions WHERE organization_id=? AND user_id=? AND status='active' LIMIT 1 FOR UPDATE");
    $q->execute([$org,(int)$a['user_id']]);
    if($q->fetchColumn())throw new InvalidArgumentException('Employee is already in another active training session.');
    $public=glasses_public_id('glasses-training-session');
    $pdo->prepare("INSERT INTO glasses_training_sessions (organization_id,public_id,assignment_id,user_id,device_id,location_id,station_id,supervisor_user_id,mode,status,context_json,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,'active',?,?)")->execute([$org,$public,(int)$a['id'],(int)$a['user_id'],(int)$device['id'],$a['location_id'],$a['station_id'],$a['supervisor_user_id'],$a['mode'],json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
    $pdo->prepare("UPDATE glasses_training_assignments SET status='active',device_id=COALESCE(device_id,?),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([(int)$device['id'],$org,(int)$a['id']]);
    glasses_device_event($pdo,$org,(int)$device['id'],'training_session_started',$a['location_id']!==null?(int)$a['location_id']:null,$a['station_id']!==null?(int)$a['station_id']:null,['sessionPublicId'=>$public,'assignmentPublicId'=>$assignmentPublic],$actor);
    app_audit($pdo,$org,$actor,'glasses.training_session_started','glasses_training_session',$public,null,['devicePublicId'=>$device['public_id'],'assignmentPublicId'=>$assignmentPublic,'operatorUserId'=>(int)$a['user_id']]);
    return glasses_training_session($pdo,$org,$public);
  });
}
function glasses_training_session(PDO $pdo,int $org,string $public): array {
  $q=$pdo->prepare("SELECT s.*,a.public_id assignment_public_id,p.public_id program_public_id,p.name program_name,u.display_name user_name,d.public_id device_public_id,d.display_name device_name,su.display_name supervisor_name,ks.public_id station_public_id,ks.name station_name
    FROM glasses_training_sessions s JOIN glasses_training_assignments a ON a.id=s.assignment_id AND a.organization_id=s.organization_id JOIN glasses_training_programs p ON p.id=a.program_id AND p.organization_id=s.organization_id JOIN users u ON u.id=s.user_id JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id LEFT JOIN users su ON su.id=s.supervisor_user_id LEFT JOIN kds_stations ks ON ks.id=s.station_id AND ks.organization_id=s.organization_id
    WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
  $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Training session not found.');$r['context']=json_decode((string)($r['context_json']??'{}'),true)?:[];unset($r['context_json']);return $r;
}
/** One restaurant lock orders training transitions and canonical account/role changes. */
function glasses_training_transaction(PDO $pdo,int $org,callable $work): array {
  require_once __DIR__.'/user-accounts.php';
  $own=!$pdo->inTransaction();$savepoint='glasses_training_'.bin2hex(random_bytes(8));
  if($own)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
  try{
    accounts_lock_org($pdo,$org);
    $result=$work();
    if($own)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
    return $result;
  }catch(Throwable $error){
    if($pdo->inTransaction()){
      if($own)$pdo->rollBack();
      else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
    }
    throw $error;
  }
}

/** Revalidate both pairing and its accountable account inside the mutation transaction. */
function glasses_training_lock_device(PDO $pdo,int $org,array $device): array {
  if(!$pdo->inTransaction())throw new LogicException('Training device locks require a transaction.');
  if($org<1||(int)($device['organization_id']??0)!==$org||(int)($device['id']??0)<1)
    throw new InvalidArgumentException('Active paired glasses are required.');
  $actor=glasses_require_accountable_user($pdo,$device,true);
  $q=$pdo->prepare('SELECT * FROM glasses_devices WHERE organization_id=? AND id=? AND public_id=? LIMIT 1 FOR UPDATE');
  $q->execute([$org,(int)$device['id'],(string)($device['public_id']??'')]);$current=$q->fetch();
  if(!$current||$current['status']!=='active'||$current['revoked_at']!==null||(int)$current['paired_by']!==$actor||
      empty($current['token_hash'])||!hash_equals((string)$current['token_hash'],(string)($device['token_hash']??'')))
    throw new InvalidArgumentException('Active paired glasses are required.');
  return $current;
}

/** Canonical permissions, with current locking reads even inside an older outer snapshot. */
function glasses_training_require_manager(PDO $pdo,int $org,int $actor): array {
  require_once __DIR__.'/user-accounts.php';
  if(!$pdo->inTransaction())throw new LogicException('Training management requires a transaction.');
  try{glasses_require_accountable_user($pdo,['organization_id'=>$org,'paired_by'=>$actor],true);}
  catch(InvalidArgumentException){throw new DomainException('Your account no longer has access to this restaurant.');}
  $fresh=accounts_actor($pdo,$org,$actor);
  $q=$pdo->prepare('SELECT id FROM organization_memberships WHERE organization_id=? AND user_id=? FOR UPDATE');
  $q->execute([$org,$actor]);$fresh['membership_id']=(int)$q->fetchColumn();
  $q=$pdo->prepare('SELECT r.*,p.permission_key FROM organization_memberships om
    JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
    JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
    LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id
    WHERE om.organization_id=? AND om.user_id=? AND om.status=\'active\' ORDER BY r.id,p.permission_key FOR UPDATE');
  $q->execute([$org,$actor]);$permissions=[];
  foreach($q->fetchAll() as $role){
    if(app_role_has_owner_authority($role)){$permissions=['*'];break;}
    if($role['permission_key']!==null)$permissions[]=(string)$role['permission_key'];
  }
  $fresh['permissions']=array_values(array_unique($permissions));
  if(!app_has_permission('glasses.view',$fresh)||!app_has_permission('glasses.manage',$fresh))
    throw new DomainException('Training session management requires glasses.view and glasses.manage.');
  return $fresh;
}

/** An assignment must still refer to its own employee's canonical training ledger. */
function glasses_training_lock_generic_assignment(PDO $pdo,int $org,array $assignment): array {
  $q=$pdo->prepare('SELECT t.*,p.public_id program_public_id FROM training_assignments t
    JOIN glasses_training_programs p ON p.organization_id=t.organization_id AND p.id=?
    WHERE t.organization_id=? AND t.id=? LIMIT 1 FOR UPDATE');
  $q->execute([(int)$assignment['program_id'],$org,(int)($assignment['training_assignment_id']??0)]);$generic=$q->fetch();
  if(!$generic||(int)$generic['user_id']!==(int)$assignment['user_id']||$generic['assignment_type']!=='glasses_vision_program'||
      $generic['assignment_reference_id']!==$generic['program_public_id']||$generic['completed_at']!==null||
      !in_array($generic['status'],['assigned','active','in_progress'],true)||$assignment['completed_at']!==null)
    throw new InvalidArgumentException('Training assignment needs manager correction before another session transition.');
  return $generic;
}

/** Return the current locked state rather than an older repeatable-read snapshot. */
function glasses_training_session_result(PDO $pdo,int $org,array $session): array {
  $result=array_replace(glasses_training_session($pdo,$org,(string)$session['public_id']),$session);
  $result['context']=json_decode((string)($session['context_json']??'{}'),true)?:[];
  unset($result['context_json']);return $result;
}

/** Device authority is always the complete authenticated pairing, never an actor ID. */
function glasses_training_session_end(PDO $pdo,int $org,string $public,string $status,string $reason,array $device): array {
  if(!in_array($status,['completed','cancelled','interrupted'],true))throw new InvalidArgumentException('Training session end status is invalid.');
  return glasses_training_transaction($pdo,$org,function()use($pdo,$org,$public,$status,$reason,$device):array{
    $device=glasses_training_lock_device($pdo,$org,$device);
    $q=$pdo->prepare('SELECT * FROM glasses_training_sessions WHERE organization_id=? AND device_id=? AND public_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$org,(int)$device['id'],$public]);$session=$q->fetch();
    if(!$session)throw new InvalidArgumentException('Training session not found.');
    return glasses_training_finish_session($pdo,$org,$session,$status,$reason,(int)$device['paired_by'],'device');
  });
}

/** Explicit management override retains its own current account and permission boundary. */
function glasses_training_session_end_managed(PDO $pdo,array $manager,string $public,string $status,string $reason): array {
  if(!in_array($status,['completed','cancelled','interrupted'],true))throw new InvalidArgumentException('Training session end status is invalid.');
  $org=(int)$manager['organization_id'];$actor=(int)$manager['id'];
  return glasses_training_transaction($pdo,$org,function()use($pdo,$org,$actor,$public,$status,$reason):array{
    glasses_training_require_manager($pdo,$org,$actor);
    // An authorized manager may finish a session whose physical device was revoked.
    $q=$pdo->prepare('SELECT device_id FROM glasses_training_sessions WHERE organization_id=? AND public_id=? LIMIT 1');
    $q->execute([$org,$public]);$deviceId=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT id FROM glasses_devices WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');
    $q->execute([$org,$deviceId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Training session not found.');
    $q=$pdo->prepare('SELECT * FROM glasses_training_sessions WHERE organization_id=? AND device_id=? AND public_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$org,$deviceId,$public]);$session=$q->fetch();
    if(!$session)throw new InvalidArgumentException('Training session not found.');
    return glasses_training_finish_session($pdo,$org,$session,$status,$reason,$actor,'manager');
  });
}

/** Internal transition: both public entrypoints authorize and lock before reaching here. */
function glasses_training_finish_session(PDO $pdo,int $org,array $session,string $status,string $reason,int $actor,string $authority): array {
  if(!$pdo->inTransaction())throw new LogicException('Training session endings require a transaction.');
  if($session['status']!=='active')return glasses_training_session_result($pdo,$org,$session);
  $q=$pdo->prepare('SELECT * FROM glasses_training_assignments WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');
  $q->execute([$org,(int)$session['assignment_id']]);$assignment=$q->fetch();
  if(!$assignment||$assignment['status']!=='active'||(int)$assignment['device_id']!==(int)$session['device_id']||
      (int)$assignment['user_id']!==(int)$session['user_id'])
    throw new InvalidArgumentException('Training assignment needs manager correction before another session transition.');
  $generic=glasses_training_lock_generic_assignment($pdo,$org,$assignment);
  $reason=mb_substr(trim($reason),0,500,'UTF-8')?:null;
  $pdo->prepare("UPDATE glasses_training_sessions SET status=?,ended_at=NOW(6),end_reason=? WHERE organization_id=? AND id=? AND status='active'")
    ->execute([$status,$reason,$org,(int)$session['id']]);
  $completed=$status==='completed';$assignmentStatus=$completed?'completed':'assigned';
  $completedSql=$completed?'NOW(6)':'NULL';
  $pdo->prepare('UPDATE glasses_training_assignments SET status=?,completed_at='.$completedSql.',updated_at=NOW(6) WHERE organization_id=? AND id=?')
    ->execute([$assignmentStatus,$org,(int)$assignment['id']]);
  $pdo->prepare('UPDATE training_assignments SET status=?,completed_at='.$completedSql.' WHERE organization_id=? AND id=? AND user_id=?')
    ->execute([$assignmentStatus,$org,(int)$generic['id'],(int)$session['user_id']]);
  $metadata=['sessionPublicId'=>$session['public_id'],'assignmentPublicId'=>$assignment['public_id'],'deviceId'=>(int)$session['device_id'],'operatorUserId'=>(int)$session['user_id'],'status'=>$status,'reason'=>$reason,'authority'=>$authority];
  glasses_device_event($pdo,$org,(int)$session['device_id'],'training_session_ended',$session['location_id']!==null?(int)$session['location_id']:null,$session['station_id']!==null?(int)$session['station_id']:null,$metadata,$actor);
  app_audit($pdo,$org,$actor,'glasses.training_session_ended','glasses_training_session',(string)$session['public_id'],['status'=>'active'],$metadata);
  $q=$pdo->prepare('SELECT * FROM glasses_training_sessions WHERE organization_id=? AND device_id=? AND public_id=? LIMIT 1 FOR UPDATE');
  $q->execute([$org,(int)$session['device_id'],(string)$session['public_id']]);
  return glasses_training_session_result($pdo,$org,$q->fetch());
}
function glasses_training_employee_catalog(PDO $pdo,int $org): array {
  $q=$pdo->prepare("SELECT u.id,u.display_name,u.email,om.employee_number,om.job_title,om.primary_location_id,l.name location_name
    FROM organization_memberships om JOIN users u ON u.id=om.user_id LEFT JOIN locations l ON l.id=om.primary_location_id
    WHERE om.organization_id=? AND om.status='active' AND u.status='active' AND u.archived_at IS NULL ORDER BY u.display_name,u.id");
  $q->execute([$org]);return array_map(static fn(array $r):array=>[
    'id'=>(int)$r['id'],'displayName'=>(string)$r['display_name'],'email'=>(string)$r['email'],
    'employeeNumber'=>$r['employee_number'],'jobTitle'=>$r['job_title'],'primaryLocationId'=>$r['primary_location_id']!==null?(int)$r['primary_location_id']:null,
    'locationName'=>$r['location_name'],
  ],$q->fetchAll());
}

function glasses_training_catalog(PDO $pdo,int $org): array {
  if(!glasses_training_ready($pdo))return ['programs'=>[],'assignments'=>[],'sessions'=>[]];
  $programs=$pdo->prepare("SELECT public_id FROM glasses_training_programs WHERE organization_id=? AND status<>'archived' ORDER BY updated_at DESC");$programs->execute([$org]);
  $assign=$pdo->prepare("SELECT public_id FROM glasses_training_assignments WHERE organization_id=? AND status<>'cancelled' ORDER BY updated_at DESC");$assign->execute([$org]);
  $sessions=$pdo->prepare("SELECT public_id FROM glasses_training_sessions WHERE organization_id=? ORDER BY id DESC LIMIT 100");$sessions->execute([$org]);
  return [
    'employees'=>glasses_training_employee_catalog($pdo,$org),
    'programs'=>array_map(fn($id)=>glasses_training_program($pdo,$org,(string)$id),$programs->fetchAll(PDO::FETCH_COLUMN)),
    'assignments'=>array_map(fn($id)=>glasses_training_assignment($pdo,$org,(string)$id),$assign->fetchAll(PDO::FETCH_COLUMN)),
    'sessions'=>array_map(fn($id)=>glasses_training_session($pdo,$org,(string)$id),$sessions->fetchAll(PDO::FETCH_COLUMN)),
  ];
}
