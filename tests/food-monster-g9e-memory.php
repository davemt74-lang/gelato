<?php
declare(strict_types=1);
$db=in_array('--db',$argv??[],true);
if($db)require __DIR__.'/../includes/bootstrap.php';
else{
    function app_role_has_owner_authority(array $u):bool{return (int)($u['is_owner_role']??0)===1;}
    function app_has_permission(string $permission,array $u):bool{return in_array('*',$u['permissions']??[],true)||in_array($permission,$u['permissions']??[],true);}
}
require __DIR__.'/../includes/food-monster-person-conversations.php';
require_once __DIR__.'/../includes/food-monster-conversation-memory.php';
require __DIR__.'/../includes/food-monster-llm.php';
$n=0;
function fm9e_ok(bool $v,string $name):void{global $n;if(!$v){fwrite(STDERR,"FM-G9E FAIL: ".$name."\n");exit(1);}++$n;}
$cases=[
['Report: the pizza oven is broken','operational_report'],
['We ran out of mozzarella','operational_report'],
['The freezer is not working','operational_report'],
['What if the oven is broken?','general'],
['What ingredients are in our pizza?','ingredient_question'],
['Does this contain dairy?','ingredient_question'],
['How do I stretch pizza dough?','training_question'],
['Show me how to prepare the dough','training_question'],
['I clocked in','general'],
['Hello monster','general'],
];
foreach($cases as [$txt,$category])fm9e_ok(fm9e_classify($txt)===$category,'conservative classification: '.$txt);
fm9e_ok(fm9e_trim("  two \n sentences  ",160)==='two sentences','normalize whitespace');
fm9e_ok(mb_strlen(fm9e_trim(str_repeat('a',250),160),'UTF-8')===160,'excerpt bounded');
fm9e_ok(str_contains(fmllm_payload('openai',[['role'=>'user','content'=>'Hi']],'memory testing')['instructions'],'memory testing'),'OpenAI prompt contains bounded memory');
fm9e_ok(str_contains(fmllm_payload('anthropic',[['role'=>'user','content'=>'Hi']],'memory testing')['system'],'memory testing'),'Anthropic prompt contains bounded memory');
fm9e_ok(!str_contains(fmllm_payload('anthropic',[['role'=>'user','content'=>'Hi']])['system'],'memory testing'),'no memory injected by default');
fm9e_ok(mb_strlen(fmllm_payload('openai',[['role'=>'user','content'=>'Hi']],str_repeat('a',5000))['instructions'],'UTF-8')<2800,'provider memory payload size bounded');
$api=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$llmapi=(string)file_get_contents(__DIR__.'/../api/food-monster-llm.php');
$core=(string)file_get_contents(__DIR__.'/../includes/food-monster-conversation-memory.php');
$person=(string)file_get_contents(__DIR__.'/../includes/food-monster-person-conversations.php');
fm9e_ok(str_contains($api,"'person_memory'")&&str_contains($api,"'resolve_person_report'"),'scoped memory and resolution actions exposed');
fm9e_ok(str_contains($api,'app_require_auth()')&&str_contains($api,'app_verify_request_csrf($in)'),'owner session and CSRF retained');
fm9e_ok(str_contains($llmapi,'fm9e_llm_memory($pdo,$user,$threadId,$last)'),'LLM receives server-verified person memory');
fm9e_ok(str_contains($person,"'memoryCategory'=>fm9e_classify("),'person turn carries classifier metadata');
fm9e_ok(str_contains($core,'fm9a_is_super_admin_session')&&str_contains($core,'fm9d_person_collections'),'only owner and tenant-member memories accessed');
fm9e_ok(str_contains($core,'$last[\'channel\']')&&str_contains($core,'$pending[\'voiceEventId\']'),'model memory only for matched voice turn');
fm9e_ok(!str_contains($core,'CREATE TABLE')&&!str_contains($core,'$_GET'),'no alternate employee database or client-provided speaker identities');
if($db){
 $pdo=app_pdo();fm9e_ok(gaw_ready($pdo),'canonical GAW database installed');
 if(session_status()!==PHP_SESSION_ACTIVE)app_boot_session();
 $originalSession=$_SESSION;
 $pdo->beginTransaction();
 try{
  $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9E CI','America/Phoenix')");$org=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);$location=(int)$pdo->lastInsertId();
  $make=function($name)use($pdo,$org,$location){
    $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,'A','Person',?,'active')")
      ->execute(['fm9e-'.bin2hex(random_bytes(6)).'@example.test','hash',$name]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Cook','active')")->execute([$org,$id,$location]);
    return $id;
  };
  $admin=$make('Owner');$cook=$make('Cook');$other=$make('Other');
  $owner=['id'=>$admin,'organization_id'=>$org,'is_owner_role'=>1,'permissions'=>['*']];
  $unprivileged=['id'=>$cook,'organization_id'=>$org,'is_owner_role'=>0,'permissions'=>[]];
  $shared=gaw_create_thread($pdo,$org,$admin,'voice','Room conversation');
  $sharedId=(string)$shared['public_id'];
  $voice='voice-'.bin2hex(random_bytes(10));
  $speaker=['personId'=>$cook,'voiceEventId'=>$voice];
  // FM-G9D verified person turn is the canonical source for FM-G9E, not any browser-supplied person ID.
  $report=fm9d_mirror_user_turn($pdo,$owner,$speaker,'Report: the pizza oven is broken',$sharedId);
  $question=fm9d_mirror_user_turn($pdo,$owner,['personId'=>$cook,'voiceEventId'=>'voice-'.bin2hex(random_bytes(9))],
      'What ingredients are in the dough?',$sharedId);
  $training=fm9d_mirror_user_turn($pdo,$owner,['personId'=>$cook,'voiceEventId'=>'voice-'.bin2hex(random_bytes(9))],
      'Show me how to stretch dough',$sharedId);
  $memory=fm9e_person_memory($pdo,$owner,$cook);
  fm9e_ok(count($memory['openReports'])===1,'operational report is open');
  fm9e_ok(count($memory['recentIngredientQuestions'])===1,'ingredient question preserved');
  fm9e_ok(count($memory['recentTrainingQuestions'])===1,'training question preserved');
  fm9e_ok($memory['reportCounts']['open']===1,'open report count accurate');
  fm9e_ok($memory['personId']===$cook,'memory is attached to permanent employee identity');
  fm9e_ok(fm9e_person_memory($pdo,$owner,$other)['openReports']===[],'second employee sees no first employee reports');
  try{fm9e_person_memory($pdo,$unprivileged,$cook);fm9e_ok(false,'deny employee reading coworkers');}
  catch(DomainException){fm9e_ok(true,'deny employee reading coworkers');}
  $otherOrg=$owner;$otherOrg['organization_id']=$org+999;
  try{fm9e_person_memory($pdo,$otherOrg,$cook);fm9e_ok(false,'deny cross organization memory');}
  catch(InvalidArgumentException){fm9e_ok(true,'deny cross organization memory');}
  $list=gaw_history_page($pdo,$org,$cook,$report['threadId'],0,40);
  $id=null;
  foreach($list['messages'] as $msg)if(($msg['structured']['memoryCategory']??'')==='operational_report')$id=$msg['public_id'];
  fm9e_ok(is_string($id)&&str_starts_with($id,'msg-'),'canonical report message ID found');
  try{fm9e_resolve_report($pdo,$unprivileged,$cook,$id);fm9e_ok(false,'deny employee resolution');}
  catch(DomainException){fm9e_ok(true,'deny employee resolution');}
  try{fm9e_resolve_report($pdo,$owner,$cook,'msg-aaaaaaaaaaaaaaaaaaaa');fm9e_ok(false,'unknown report denied');}
  catch(InvalidArgumentException){fm9e_ok(true,'unknown report denied');}
  $done=fm9e_resolve_report($pdo,$owner,$cook,$id);
  fm9e_ok($done['state']==='resolved'&&$done['alreadyResolved']===false,'owner resolves actual report');
  fm9e_ok(fm9e_person_memory($pdo,$owner,$cook)['openReports']===[],'resolved report no longer appears as open');
  fm9e_ok(fm9e_person_memory($pdo,$owner,$cook)['reportCounts']['resolved']===1,'resolution event is durable');
  $twice=fm9e_resolve_report($pdo,$owner,$cook,$id);
  fm9e_ok($twice['alreadyResolved']===true,'duplicate resolution idempotent');
  $count=$pdo->prepare("SELECT COUNT(*) FROM agent_messages WHERE organization_id=? AND user_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9e_report_resolution'");
  $count->execute([$org]);fm9e_ok((int)$count->fetchColumn()===1,'one resolution event and no second table');
  $pkey='fm9d_pending_'.hash('sha256',$org.'|'.$admin.'|'.$sharedId);
  $last=['role'=>'user','channel'=>'voice','voice_event_public_id'=>$voice,'content'=>'Remember?'];
  fm9e_ok(fm9e_llm_memory($pdo,$owner,$sharedId,$last)==='','no person memory without server pending speaker');
  $_SESSION[$pkey]=['personId'=>$cook,'operatorThreadId'=>$sharedId,'voiceEventId'=>$voice,'createdAt'=>time()];
  $prompt=fm9e_llm_memory($pdo,$owner,$sharedId,$last);
  fm9e_ok(str_contains($prompt,'Prior training questions')&&str_contains($prompt,'Prior recipe/ingredient questions'),'matched speaker retrieves useful history');
  fm9e_ok(!str_contains($prompt,'oven is broken'),'resolved reports excluded from LLM context');
  fm9e_ok(mb_strlen($prompt,'UTF-8')<=1100,'bounded LLM personalization');
  fm9e_ok(fm9e_llm_memory($pdo,$owner,$sharedId,['role'=>'user','channel'=>'text','voice_event_public_id'=>$voice])==='','typed unknown speaker never gets employee memory');
  fm9e_ok(fm9e_llm_memory($pdo,$owner,$sharedId,['role'=>'user','channel'=>'voice','voice_event_public_id'=>'voice-incorrect'])==='','stale/other speaker event denied');
  $otherThread=gaw_create_thread($pdo,$org,$admin,'voice','Other room');
  fm9e_ok(fm9e_llm_memory($pdo,$owner,$otherThread['public_id'],$last)==='','room thread session mismatch denied');
  $_SESSION[$pkey]['createdAt']=time()-300;
  fm9e_ok(fm9e_llm_memory($pdo,$owner,$sharedId,$last)==='','expired recognition never accesses memory');
 }finally{
  if($pdo->inTransaction())$pdo->rollBack();
  $_SESSION=$originalSession;
 }
}
echo "FM-G9E structured conversational memory PASS ($n assertions".($db?', MariaDB':'').")\n";
