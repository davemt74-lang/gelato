<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-training-identity.php';

function v111_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v111_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v111_assert(glasses_training_ready($pdo),'V11 training identity migration must be installed.');

$slug='v111-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Vision Training Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();

function v111_user(PDO $pdo,int $org,int $location,string $email,string $name):int{
  $parts=preg_split('/\s+/',trim($name),2);$first=$parts[0]?:'Vision';$last=$parts[1]??'User';
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash',?,?,?,'active')")->execute([$email,$first,$last,$name]);$id=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$id,$location]);return $id;
}
$actor=v111_user($pdo,$org,$location,$slug.'-admin@example.test','Vision Admin');
$operator=v111_user($pdo,$org,$location,$slug.'-cook@example.test','Vision Cook');
$operator2=v111_user($pdo,$org,$location,$slug.'-cook2@example.test','Vision Cook Two');
$supervisor=v111_user($pdo,$org,$location,$slug.'-sup@example.test','Vision Supervisor');

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Training Line','slug'=>'pizza-'.$slug,'targetSeconds'=>300],$actor);

function v111_device(PDO $pdo,int $org,int $location,string $stationPublic,int $actor,string $hardware):array{
  $grant=glasses_create_pairing_grant($pdo,$org,$location,$stationPublic,$actor,10);
  $paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>$hardware,'displayName'=>$hardware,'platform'=>'browser_simulator','sdkVersion'=>'sim','appVersion'=>'v11','capabilities'=>['hardwareRuntimeAdapterId'=>'simulator.v1']]);
  return glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
}
$device1=v111_device($pdo,$org,$location,(string)$station['public_id'],$actor,'V11-GLASSES-A-'.$slug);
$device2=v111_device($pdo,$org,$location,(string)$station['public_id'],$actor,'V11-GLASSES-B-'.$slug);

$program=glasses_training_program_create($pdo,$org,[
 'name'=>'Pizza Line Vision Training','description'=>'Operator identity acceptance','mode'=>'training',
 'locationId'=>$location,'stationPublicId'=>$station['public_id'],'requirements'=>['recipe'=>'pizza']
],$actor);
v111_assert($program['status']==='active'&&$program['mode']==='training','Program must be active training mode.');

$assignment=glasses_training_assignment_create($pdo,$org,[
 'programPublicId'=>$program['public_id'],'userId'=>$operator,'devicePublicId'=>$device1['public_id'],
 'supervisorUserId'=>$supervisor,'mode'=>'training','notes'=>'Section 1 acceptance'
],$actor);
v111_assert((int)$assignment['user_id']===$operator,'Assignment must bind canonical employee.');
v111_assert($assignment['device_public_id']===$device1['public_id'],'Assignment must bind paired glasses.');
v111_assert((int)$assignment['supervisor_user_id']===$supervisor,'Assignment must bind supervisor.');
v111_assert((string)v111_one($pdo,"SELECT assignment_type FROM training_assignments WHERE id=?",[(int)$assignment['training_assignment_id']])==='glasses_vision_program','V11 assignment must also exist in canonical employee training ledger.');

$session=glasses_training_session_start($pdo,$org,$assignment['public_id'],$device1,$actor,['source'=>'contract']);
v111_assert($session['status']==='active'&&(int)$session['user_id']===$operator,'Session must snapshot operator identity.');
v111_assert($session['device_public_id']===$device1['public_id'],'Session must snapshot glasses identity.');
v111_assert($session['program_public_id']===$program['public_id'],'Session must snapshot program identity.');
v111_assert($session['station_public_id']===$station['public_id'],'Session must retain station identity.');
v111_assert((int)$session['supervisor_user_id']===$supervisor,'Session must retain supervisor identity.');

$idempotent=glasses_training_session_start($pdo,$org,$assignment['public_id'],$device1,$actor,['source'=>'repeat']);
v111_assert($idempotent['public_id']===$session['public_id'],'Starting the same assignment/device twice must be idempotent.');

$otherAssignment=glasses_training_assignment_create($pdo,$org,[
 'programPublicId'=>$program['public_id'],'userId'=>$operator2,'devicePublicId'=>$device1['public_id'],'supervisorUserId'=>$supervisor
],$actor);
$deviceConflict=false;try{glasses_training_session_start($pdo,$org,$otherAssignment['public_id'],$device1,$actor);}catch(Throwable){$deviceConflict=true;}
v111_assert($deviceConflict,'One glasses device cannot represent two active operators.');

$userAssignment=glasses_training_assignment_create($pdo,$org,[
 'programPublicId'=>$program['public_id'],'userId'=>$operator,'devicePublicId'=>$device2['public_id'],'supervisorUserId'=>$supervisor
],$actor);
$userConflict=false;try{glasses_training_session_start($pdo,$org,$userAssignment['public_id'],$device2,$actor);}catch(Throwable){$userConflict=true;}
v111_assert($userConflict,'One employee cannot have two simultaneous active glasses training sessions.');

$ended=glasses_training_session_end($pdo,$org,$session['public_id'],'completed','Training complete',$actor);
v111_assert($ended['status']==='completed','Session must complete.');
v111_assert((string)v111_one($pdo,"SELECT status FROM glasses_training_assignments WHERE id=?",[(int)$assignment['id']])==='completed','V11 assignment must complete.');
v111_assert((string)v111_one($pdo,"SELECT status FROM training_assignments WHERE id=?",[(int)$assignment['training_assignment_id']])==='completed','Canonical employee training assignment must complete with V11 session.');

$next=glasses_training_session_start($pdo,$org,$userAssignment['public_id'],$device2,$actor);
v111_assert($next['status']==='active','Employee may start a later training session after prior session ends.');
glasses_training_session_end($pdo,$org,$next['public_id'],'interrupted','Acceptance cleanup',$actor);

$catalog=glasses_training_catalog($pdo,$org);
v111_assert(count($catalog['employees'])===4,'Employee catalog must use canonical active memberships.');
v111_assert(count($catalog['programs'])===1&&count($catalog['assignments'])===3,'Training catalog must expose programs and assignments.');
v111_assert(count($catalog['sessions'])>=2,'Training catalog must expose session history.');

$source=file_get_contents(__DIR__.'/../includes/glasses-training-identity.php');
$deviceApi=file_get_contents(__DIR__.'/../api/glasses-device.php');
$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$ui=file_get_contents(__DIR__.'/../assets/js/glasses-training-identity.js');
foreach(['kds_transition(','glasses_handoff_to_expo(','glasses_build_confirm(','glasses_production_pilot_set_device_status('] as $forbidden)
 v111_assert(!str_contains($source,$forbidden),'Training identity must not mutate kitchen/production authority: '.$forbidden);
v111_assert(str_contains($deviceApi,'training.session_start')&&str_contains($deviceApi,'training.session_end'),'Device API must expose V11 session lifecycle.');
v111_assert(str_contains($labApi,'training.program_create')&&str_contains($labApi,'training.assignment_create'),'Vision Lab API must expose V11 assignment management.');
v111_assert(str_contains($page,'V11 Training Assignment &amp; Operator Identity'),'Vision Lab must expose V11 training identity panel.');
v111_assert(str_contains($ui,'New assignment')||str_contains($ui,'training.assignment_create'),'V11 management UI must create assignments.');

echo "glasses-v11-training-identity-ok\n";
