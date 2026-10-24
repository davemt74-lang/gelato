<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function b8d9_check(bool $ok,string $desc):void {
    global $checks;
    if(!$ok)throw new RuntimeException('B8D9 FAIL: '.$desc);
    $checks++;
}
$pdo=app_pdo();$slug='b8d9-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Vision Review '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','x','Review','Inspector','Review Inspector']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);
$actor=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Review Inspector','permissions'=>['glasses.view']];
b8d9_check(glasses_vision_lab_ready($pdo),'Vision Lab installed');
b8d9_check(glasses_vision_annotation_qa_ready($pdo),'Vision QA schema installed');
b8d9_check(glasses_v11_model_rc_ready($pdo),'model release governance schema installed');
$names=['get_vision_sample_review_queue','get_vision_sample_review_details',
    'get_vision_annotation_quality'];
$tools=fm8d9_catalogue($actor);
b8d9_check(count($tools)===3&&!array_diff($names,array_keys($tools)),
    'view-only user discovers all three review skills but not model governance');
b8d9_check(fm8d9_catalogue(array_replace($actor,['permissions'=>[]]))===[],
    'unprivileged session does not discover private sample review tools');
foreach($names as $name){
    b8d9_check($tools[$name]['impact']==='read'&&$tools[$name]['authorize']($actor,[])===true,
        $name.' is read-only and authorized');
    b8d9_check($tools[$name]['authorize'](array_replace($actor,['permissions'=>[]]),[])===false,
        $name.' reevaluates revocation');
}
$empty=$tools['get_vision_sample_review_queue']['execute']([], $actor);
b8d9_check(str_contains($empty['data']['answer'],'No unreviewed samples'),
    'empty review queue never claims unrecorded sample');
$quality=$tools['get_vision_annotation_quality']['execute']([], $actor);
b8d9_check(str_contains($quality['data']['answer'],'No training samples'),
    'QA never treats an empty dataset as validated model quality');
$mission=glasses_vision_lab_create_mission($pdo,$org,['title'=>'B8D9 Pizza Presentation'], $uid);
$q=$pdo->prepare("SELECT id FROM glasses_vision_training_missions WHERE organization_id=? AND public_id=?");
$q->execute([$org,$mission['publicId']]);$missionId=(int)$q->fetchColumn();
b8d9_check($missionId>0,'real Vision Lab mission persisted');
$prefix='vision-sample-';
$sampleA=$prefix.bin2hex(random_bytes(7));$sampleB=$prefix.bin2hex(random_bytes(7));
$pdo->prepare("INSERT INTO glasses_vision_training_samples
    (organization_id,public_id,mission_id,source_type,source_reference,canonical_label,
     review_status,operator_user_id,created_by)
    VALUES (?, ?, ?, 'manual', ?, 'pizza_appearance','pending', ?, ?)")
    ->execute([$org,$sampleA,$missionId,'b8d9 fixture '.$slug,$uid,$uid]);
$pdo->prepare("INSERT INTO glasses_vision_training_samples
    (organization_id,public_id,mission_id,source_type,source_reference,canonical_label,
     review_status,operator_user_id,created_by)
    VALUES (?, ?, ?, 'manual', ?, 'pizza_surface','needs_adjudication', ?, ?)")
    ->execute([$org,$sampleB,$missionId,'b8d9 disputed '.$slug,$uid,$uid]);
$queue=$tools['get_vision_sample_review_queue']['execute']([], $actor);
b8d9_check(str_contains($queue['data']['answer'],'1 need adjudication, 1 pending review')
    &&str_contains($queue['data']['answer'],$sampleA)
    &&str_contains($queue['data']['answer'],$sampleB),
    'actual persisted pending and disputed sample IDs appear in priority review queue');
$detail=$tools['get_vision_sample_review_details']['execute'](['sample_public_id'=>$sampleB],$actor);
b8d9_check(str_contains($detail['data']['answer'],'needs_adjudication')
    &&str_contains($detail['data']['answer'],'B8D9 Pizza Presentation')
    &&!str_contains($detail['data']['answer'],'http'),
    'real sample detail names mission and recorded status without media URL');
$unknown=$tools['get_vision_sample_review_details']['execute']([
    'sample_public_id'=>'vision-sample-'.str_repeat('0',14)],$actor);
b8d9_check(str_contains($unknown['data']['answer'],'No matching sample'),
    'unknown sample ID does not reveal another restaurant');
$quality=$tools['get_vision_annotation_quality']['execute']([], $actor);
b8d9_check(str_contains($quality['data']['answer'],'2 samples')
    &&str_contains($quality['data']['answer'],'annotation')
    &&str_contains($quality['data']['answer'],'QA score'),
    'real QA report summarizes recorded sample annotation completeness');
$manager=array_replace($actor,['permissions'=>['glasses.view','glasses.manage']]);
$managerTools=fm8d9_catalogue($manager);
b8d9_check(isset($managerTools['get_vision_release_candidate_queue'])
    &&count($managerTools)===4,
    'authorized manager discovers verified model release queue');
b8d9_check($managerTools['get_vision_release_candidate_queue']['authorize']($actor,[])===false,
    'view-only staff cannot access model approval queue');
$candidate=$managerTools['get_vision_release_candidate_queue']['execute']([], $manager);
b8d9_check(str_contains($candidate['data']['answer'],'No model release candidates'),
    'no release candidates are invented');
$payload=[];
$openai=static function($url,$headers,$request)use(&$payload):array {
    $payload=$request;
    return ['output'=>[['type'=>'function_call','name'=>'get_vision_sample_review_queue',
        'call_id'=>'b8d9-openai','arguments'=>'{}']]];
};
$reply=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Which camera training samples still need review?']],'',
    $openai,$tools,static fn():array=>$actor,static function(array $event):void{});
b8d9_check(($reply['data']['tools'][0]??'')==='get_vision_sample_review_queue'
    &&str_contains($reply['answer'],$sampleA),
    'OpenAI selects actual Vision Lab sample-review skill');
b8d9_check(!str_contains(json_encode($payload),$sampleA)
    &&!str_contains(json_encode($payload),$sampleB),
    'private review identifiers stay on Gelato server, not sent back to provider');
$claude=static fn():array=>['content'=>[['type'=>'tool_use',
    'id'=>'b8d9-claude','name'=>'get_vision_annotation_quality','input'=>[]]]];
$qualityReply=fm8d3_turn($pdo,$actor,'anthropic','fake',
    [['role'=>'user','content'=>'How good are our recorded vision annotations?']],'',
    $claude,$tools,static fn():array=>$actor,static function(array $event):void{});
b8d9_check(str_contains($qualityReply['answer'],'2 samples'),
    'Claude independently selects recorded annotation-quality tool');
$blocked=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'What still needs a human review?']],'',
    $openai,$tools,
    static fn():array=>array_replace($actor,['permissions'=>[]]),
    static function(array $event):void{});
b8d9_check(($blocked['data']['status']??'')==='denied',
    'fresh authorization blocks review after permission revoked');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Other Vision Review '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$foreign=fm8d9_review_queue($pdo,array_replace($actor,['organization_id'=>$otherOrg]));
b8d9_check(!str_contains($foreign['data']['answer'],$sampleA)
    &&str_contains($foreign['data']['answer'],'No unreviewed samples'),
    'tenant isolation keeps review sample metadata private');
$q=$pdo->prepare("SELECT COUNT(*) FROM glasses_vision_training_samples WHERE organization_id=?
    AND review_status IN ('pending','needs_adjudication')");$q->execute([$org]);
b8d9_check((int)$q->fetchColumn()===2,
    'all review skills are non-mutating and leave human judgments pending');
echo 'food-monster-b8d9-review-governance-ok assertions='.$checks."\n";
