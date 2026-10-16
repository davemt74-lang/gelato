<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-person-conversations.php';
require_once __DIR__.'/../includes/food-monster-operational-followup.php';
function fm9f_db_assert(bool $condition,string $description):void {
    if(!$condition){fwrite(STDERR,"FM-G9F database FAIL: ".$description."\n");exit(1);}
    echo "PASS ".$description.PHP_EOL;
}
$pdo=app_pdo();
fm9f_db_assert(gaw_ready($pdo),'Agent Brain schema available');
fm9f_db_assert(operations_core_ready($pdo),'Restaurant tasks schema available');
// The CI database is disposable; fixtures are committed because the action
// service owns its transaction and must exercise real commit/retry behavior.
$pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9F Action CI','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);
$loc=(int)$pdo->lastInsertId();
$make=static function(string $name)use($pdo,$org,$loc):int {
    $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,'A','Test',?,'active')")
        ->execute(['fm9f-'.bin2hex(random_bytes(8)).'@example.test','hash',$name]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Cook','active')")
        ->execute([$org,$id,$loc]);
    return $id;
};
$admin=$make('Owner');$staff=$make('Cook');
$owner=['id'=>$admin,'organization_id'=>$org,'is_owner_role'=>1,'permissions'=>['*']];
$employee=['id'=>$staff,'organization_id'=>$org,'is_owner_role'=>0,'permissions'=>[]];
$shared=gaw_create_thread($pdo,$org,$admin,'voice','FM G9F Test');
$voice='voice-'.bin2hex(random_bytes(9));
$report=fm9d_mirror_user_turn($pdo,$owner,['personId'=>$staff,'voiceEventId'=>$voice],
    'Report: freezer is not working',$shared['public_id']);
$id=(string)$report['reportMessageId'];
fm9f_db_assert((bool)preg_match('/^msg-[a-f0-9]{20}$/D',$id),'real report ID propagated');
try{fm9f_create_report_task($pdo,$employee,$staff,$id);fm9f_db_assert(false,'employee rejected');}
catch(DomainException){fm9f_db_assert(true,'employee rejected');}
try{fm9f_create_report_task($pdo,$owner,$staff,'msg-aaaaaaaaaaaaaaaaaaaa');fm9f_db_assert(false,'forged report rejected');}
catch(InvalidArgumentException){fm9f_db_assert(true,'forged report rejected');}
$otherOrg=$owner;$otherOrg['organization_id']=$org+123;
try{fm9f_create_report_task($pdo,$otherOrg,$staff,$id);fm9f_db_assert(false,'other tenant rejected');}
catch(InvalidArgumentException){fm9f_db_assert(true,'other tenant rejected');}
$created=fm9f_create_report_task($pdo,$owner,$staff,$id);
fm9f_db_assert($created['alreadyCreated']===false&&preg_match('/^task-[a-f0-9]{20}$/D',$created['taskId'])===1,'authorized task created');
$retry=fm9f_create_report_task($pdo,$owner,$staff,$id);
fm9f_db_assert($retry['alreadyCreated']===true&&$retry['taskId']===$created['taskId'],'retries return same task');
$tasks=$pdo->prepare("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND source_type='food_monster_report' AND source_public_id=?");
$tasks->execute([$org,$id]);
fm9f_db_assert((int)$tasks->fetchColumn()===1,'one actual database task linked to report');
$events=$pdo->prepare("SELECT COUNT(*) FROM agent_messages WHERE organization_id=? AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9f_report_task' AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.reportMessageId'))=?");
$events->execute([$org,$id]);
fm9f_db_assert((int)$events->fetchColumn()===1,'one durable Agent Brain execution receipt');
$resolved=fm9d_mirror_user_turn($pdo,$owner,['personId'=>$staff,'voiceEventId'=>'voice-'.bin2hex(random_bytes(9))],
    'Report: stove is broken',$shared['public_id']);
fm9e_resolve_report($pdo,$owner,$staff,$resolved['reportMessageId']);
try{fm9f_create_report_task($pdo,$owner,$staff,$resolved['reportMessageId']);fm9f_db_assert(false,'resolved report denied');}
catch(DomainException){fm9f_db_assert(true,'resolved report denied');}
$training=fm9d_mirror_user_turn($pdo,$owner,['personId'=>$staff,'voiceEventId'=>'voice-'.bin2hex(random_bytes(9))],
    'How do I stretch dough?',$shared['public_id']);
fm9f_db_assert($training['reportMessageId']===null,'ordinary question cannot auto-create a task');
echo "FM-G9F database operational follow-up PASS\n";
