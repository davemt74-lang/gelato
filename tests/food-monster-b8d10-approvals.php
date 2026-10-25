<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-workspace-core.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function b8d10_ok(bool $ok,string $description):void {
    global $checks;
    if(!$ok)throw new RuntimeException('B8D10 FAIL: '.$description);
    $checks++;
}
$pdo=app_pdo();$slug='b8d10-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Vision Approvals '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test','hash','Review','Owner','Review Owner']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);$membership=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO roles
    (organization_id,name,slug,is_system_role,is_owner_role,is_assignable)
    VALUES (?,'Vision Test Owner',?,0,1,1)")
    ->execute([$org,'owner_b8d10_'.$slug]);$role=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (membership_id,role_id) VALUES (?,?)")
    ->execute([$membership,$role]);$userRole=(int)$pdo->lastInsertId();
$actor=fm8d2_refresh_operator($pdo,['id'=>$uid,'organization_id'=>$org,'display_name'=>'Review Owner']);
b8d10_ok($actor!==null&&fm8d10_can_review($actor),'real database role grants Vision review manager authority');
b8d10_ok(fm8d10_ready($pdo)&&glasses_vision_lab_ready($pdo),'approval and Vision Lab migrations installed');
$room=(string)gaw_create_thread($pdo,$org,$uid,'text','Human approval test')['public_id'];
$mission=glasses_vision_lab_create_mission($pdo,$org,['title'=>'Visual QC Review'], $uid);
$q=$pdo->prepare("SELECT id FROM glasses_vision_training_missions WHERE organization_id=? AND public_id=?");
$q->execute([$org,$mission['publicId']]);$missionId=(int)$q->fetchColumn();
$refs=[];
foreach(['first','second','third','fourth','fifth','sixth'] as $name){
    $ref='vision-sample-'.bin2hex(random_bytes(9));$refs[$name]=$ref;
    $pdo->prepare("INSERT INTO glasses_vision_training_samples
        (organization_id,public_id,mission_id,source_type,source_reference,
         canonical_label,review_status,created_by)
        VALUES (?,?,?,'manual',?,?,'pending',?)")
        ->execute([$org,$ref,$missionId,'b8d10 '.$name,$name.'_label',$uid]);
}
$send=static function(string $message)use($pdo,$org,$uid,$room):array {
    $row=gaw_append($pdo,$org,$uid,$room,'user',$message);
    return ['threadId'=>$room,'messageId'=>(string)$row['id'],
        'channel'=>'text','voiceEventId'=>'','verifiedSameUser'=>true];
};
$ctx=$send('Please approve the first vision sample '.$refs['first']);
$tools=fm8d10_catalogue($actor,$ctx);
b8d10_ok(isset($tools['request_vision_sample_review'])&&count($tools)===1,
    'LLM-discoverable proposal-only skill registered');
b8d10_ok($tools['request_vision_sample_review']['impact']==='routine',
    'model can only create a low-impact proposal, never execute a review');
b8d10_ok(fm8d10_catalogue(array_replace($actor,['permissions'=>[]]),$ctx)===[],
    'revoked manager cannot discover approval proposals');
b8d10_ok(fm8d10_catalogue($actor,[])===[],
    'no private write skill without saved canonical chat context');
$first=$tools['request_vision_sample_review']['execute']([
    'sample_public_id'=>$refs['first'],'review_decision'=>'approve'],$actor);
$approval=$first['data']['approval'];
b8d10_ok(($approval['state']??'')==='pending'
    &&($approval['type']??'')==='vision_sample_review'
    &&preg_match('/^fmapproval-[a-f0-9]{24}$/',$approval['id'])===1,
    'proposal saved and human-approval card payload returned');
b8d10_ok(glasses_vision_lab_sample($pdo,$org,$refs['first'])['reviewStatus']==='pending',
    'LLM tool did not execute the requested sample decision');
$replay=$tools['request_vision_sample_review']['execute']([
    'sample_public_id'=>$refs['second'],'review_decision'=>'reject'],$actor);
b8d10_ok(($replay['data']['approval']['id']??'')===$approval['id']
    &&($replay['data']['approval']['resource']??'')===$refs['first'],
    'same saved chat turn cannot redirect or duplicate a proposal');
b8d10_ok(fm8d10_pending($pdo,$actor,$room)['id']===$approval['id'],
    'pending human approval survives a request boundary');
$otherRoom=(string)gaw_create_thread($pdo,$org,$uid,'text','Other chat')['public_id'];
try{fm8d10_decide($pdo,$actor,$approval['id'],'approve',$otherRoom);$wrongRoom=false;}
catch(DomainException){$wrongRoom=true;}
b8d10_ok($wrongRoom,'proposal cannot be approved from another chat');
$ctx2=$send('Prepare a review rejection for the second sample');
$second=fm8d10_propose($pdo,$actor,$ctx2,[
    'sample_public_id'=>$refs['second'],'review_decision'=>'reject']);
$dismiss=fm8d10_decide($pdo,$actor,$second['data']['approval']['id'],'dismiss',$room);
b8d10_ok($dismiss['state']==='dismissed'&&!$dismiss['executed']
    &&glasses_vision_lab_sample($pdo,$org,$refs['second'])['reviewStatus']==='pending',
    'human Dismiss preserves original sample');
$ctx3=$send('Relabel the third sample to pizza_ready');
$third=fm8d10_propose($pdo,$actor,$ctx3,[
    'sample_public_id'=>$refs['third'],'review_decision'=>'relabel',
    'canonical_label'=>'pizza_ready']);
$pdo->prepare("UPDATE glasses_vision_training_samples
    SET canonical_label='changed_after_proposal',updated_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
    WHERE organization_id=? AND public_id=?")->execute([$org,$refs['third']]);
$stale=fm8d10_decide($pdo,$actor,$third['data']['approval']['id'],'approve',$room);
b8d10_ok($stale['state']==='stale'&&!$stale['executed']
    &&glasses_vision_lab_sample($pdo,$org,$refs['third'])['canonicalLabel']==='changed_after_proposal',
    'changed sample fingerprint blocks stale human approval');
$ctx4=$send('Approve the fourth sample');
$fourth=fm8d10_propose($pdo,$actor,$ctx4,[
    'sample_public_id'=>$refs['fourth'],'review_decision'=>'approve']);
$pdo->prepare("UPDATE user_roles SET revoked_at=UTC_TIMESTAMP(6) WHERE id=?")
    ->execute([$userRole]);
try{fm8d10_decide($pdo,$actor,$fourth['data']['approval']['id'],'approve',$room);$revoked=false;}
catch(DomainException){$revoked=true;}
b8d10_ok($revoked&&glasses_vision_lab_sample($pdo,$org,$refs['fourth'])['reviewStatus']==='pending',
    'fresh role revocation blocks clicking Approve');
$pdo->prepare("UPDATE user_roles SET revoked_at=NULL WHERE id=?")->execute([$userRole]);
$actor=fm8d2_refresh_operator($pdo,$actor);
$approved=fm8d10_decide($pdo,$actor,$fourth['data']['approval']['id'],'approve',$room);
b8d10_ok($approved['state']==='approved'&&$approved['executed']
    &&glasses_vision_lab_sample($pdo,$org,$refs['fourth'])['reviewStatus']==='approved',
    'authenticated click commits real Vision Lab sample review once');
$repeated=fm8d10_decide($pdo,$actor,$fourth['data']['approval']['id'],'approve',$room);
b8d10_ok($repeated['state']==='approved'&&!$repeated['executed'],
    'approval replay never repeats write');
$q=$pdo->prepare("SELECT COUNT(*) FROM audit_log
    WHERE organization_id=? AND action='food_monster.vision_sample_review_approved'");
try{$q->execute([$org]);$auditCount=(int)$q->fetchColumn();}
catch(Throwable){$auditCount=-1;}
b8d10_ok($auditCount===1,'exactly one governance audit for executed review');
$ctx5=$send('Reject the fifth sample');
$fifth=fm8d10_propose($pdo,$actor,$ctx5,[
    'sample_public_id'=>$refs['fifth'],'review_decision'=>'reject']);
$pdo->prepare("UPDATE food_monster_agent_approval_requests
    SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 MINUTE)
    WHERE organization_id=? AND public_id=?")
    ->execute([$org,$fifth['data']['approval']['id']]);
$expired=fm8d10_decide($pdo,$actor,$fifth['data']['approval']['id'],'approve',$room);
b8d10_ok($expired['state']==='expired'&&!$expired['executed']
    &&glasses_vision_lab_sample($pdo,$org,$refs['fifth'])['reviewStatus']==='pending',
    'expired approval cannot execute');
$ctx6=$send('Prepare an adjudication review request for the sixth sample');
$transportRequest=[];
$openai=static function($url,$headers,$payload)use(&$transportRequest,$refs):array {
    $transportRequest=$payload;
    return ['output'=>[['type'=>'function_call','name'=>'request_vision_sample_review',
        'call_id'=>'b8d10-proposal','arguments'=>json_encode([
            'sample_public_id'=>$refs['sixth'],'review_decision'=>'needs_adjudication'])]]];
};
$result=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Prepare a review request for the sixth sample']],'',
    $openai,fm8d3_catalogue($actor,$ctx6),static fn():array=>$actor,
    static function(array $event):void{},$ctx6);
b8d10_ok(($result['data']['status']??'')==='completed'
    &&($result['data']['approval']['type']??'')==='vision_sample_review'
    &&glasses_vision_lab_sample($pdo,$org,$refs['sixth'])['reviewStatus']==='pending',
    'OpenAI selects governed request and receives a human-click proposal, no write');
b8d10_ok(!str_contains(json_encode($transportRequest),$refs['first'])
    &&!str_contains(json_encode($transportRequest),$refs['second']),
    'private past review metadata never returned to LLM provider');
$blocked=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Prepare that review']],'',
    $openai,fm8d3_catalogue($actor,$ctx6),
    static fn():array=>array_replace($actor,['permissions'=>[]]),
    static function(array $event):void{},$ctx6);
b8d10_ok(($blocked['data']['status']??'')==='denied',
    'tool loop rechecks actor permissions before the proposal');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Other Approvals '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
b8d10_ok(fm8d10_pending($pdo,array_replace($actor,['organization_id'=>$otherOrg]),$room)===null,
    'approval records are isolated by restaurant');
echo 'food-monster-b8d10-approvals-ok assertions='.$checks."\n";
