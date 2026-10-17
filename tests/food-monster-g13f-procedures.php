<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,(array)($user['permissions']??[]),true);
}
function app_role_has_owner_authority(array $user):bool {
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g13f-procedure-review.php';
$n=0;
function check13f(bool $ok,string $label):void {
    global $n;
    if(!$ok)throw new RuntimeException('FM-G13F FAIL: '.$label);
    $n++;
}
$owner=['id'=>1,'organization_id'=>3,'role_slug'=>'super_admin',
  'permissions'=>['menu.view','menu.manage']];
$staff=['id'=>5,'organization_id'=>3,'role_slug'=>'employee',
  'permissions'=>['menu.view','menu.manage']];
$viewer=['id'=>1,'organization_id'=>3,'role_slug'=>'super_admin',
  'permissions'=>['menu.view']];
check13f(fm13f_authorized($owner,'text'),'owner may request a typed review');
check13f(!fm13f_authorized($owner,'voice'),'camera/voice cannot approve a procedure');
check13f(!fm13f_authorized($staff,'text'),'regular employee cannot publish using menu grants');
check13f(!fm13f_authorized($viewer,'text'),'missing menu manage denies authoring');
foreach(['review training procedures','training procedure improvements','review unclear training steps'] as $q)
 check13f((fm13f_intent($q)['action']??'')==='review','explicit improvement report: '.$q);
$draft=fm13f_intent('draft menu procedure 52: Stretch dough, apply ingredients, and bake using the current approved kitchen standard.');
check13f(($draft['action']??'')==='draft'&&$draft['itemId']===52
    &&str_contains($draft['replacement'],'Stretch dough'),'complete owner replacement draft parsed');
foreach(['approve','reject','publish'] as $action)
 check13f((fm13f_intent($action.' procedure prp-'.str_repeat('a',20))['action']??'')===$action,
    'separate explicit command: '.$action);
foreach(['publish procedure prp-not-valid','update menu procedure 5','confirm',
    'auto approve all training','publish recipe', 'next ticket', 'training progress',
    'draft menu procedure 52: short','change ingredient prices'] as $q)
 check13f(fm13f_intent($q)===null,'unrelated and malformed intent cannot hijack routing: '.$q);
$old='Stretch the dough before applying ingredients.';
$new='Stretch dough evenly, add only approved ingredients, and follow the documented oven procedure.';
check13f(fm13f_validate_note($old,$new),'distinct complete correction can be drafted');
check13f(!fm13f_validate_note($old,$old),'identical procedure rejected');
check13f(!fm13f_validate_note($old,'Too short'),'incomplete replacement denied');
check13f(!fm13f_validate_note($old,str_repeat('a',2401)),'oversized replacement denied');
check13f(!fm13f_validate_note($old,"Stretch dough.\x00Apply pesto and bake."),'control characters denied');
$now=2000000;
$proposal=['id'=>'prp-'.str_repeat('a',20),'status'=>'draft','ownerId'=>1,'orgId'=>3,
  'createdAt'=>$now-40,'sourceHash'=>fm13f_notes_digest($old),'replacement'=>$new];
$approve=fm13f_decide($proposal,'approve',$old,$now);
check13f($approve['allowed']&&$approve['status']==='approved','draft can be approved');
check13f(!fm13f_decide($proposal,'publish',$old,$now)['allowed'],
    'draft cannot be published without a distinct approval');
$approved=$proposal;$approved['status']='approved';
check13f(fm13f_decide($approved,'publish',$old,$now)['allowed'],
    'approved exact-source proposal permits explicit publish');
check13f(!fm13f_decide($approved,'approve',$old,$now)['allowed'],
    'approval cannot be redundantly repeated');
check13f(fm13f_decide($proposal,'reject',$old,$now)['status']==='rejected',
    'draft can be rejected');
check13f(fm13f_decide($approved,'reject',$old,$now)['status']==='rejected',
    'approved but unpublished proposal may be rejected');
$published=$proposal;$published['status']='published';
check13f(!fm13f_decide($published,'publish',$old,$now)['allowed'],
    'published proposal never replays');
$rejected=$proposal;$rejected['status']='rejected';
check13f(!fm13f_decide($rejected,'approve',$old,$now)['allowed'],
    'rejection closes proposal permanently');
check13f(!fm13f_decide($approved,'publish',$old.' Updated by kitchen manager.',$now)['allowed'],
    'stale source revision blocks publish');
check13f(!fm13f_decide($proposal,'approve',$old,$now+604801)['allowed'],
    'expired proposal must be redrafted');
check13f(!fm13f_decide($proposal,'approve',$old,$now-45)['allowed'],
    'future-dated proposal denied');
$badId=$proposal;$badId['id']='bad';
check13f(!fm13f_decide($badId,'approve',$old,$now)['allowed'],
    'malformed proposal ID cannot be approved');
$badReplacement=$proposal;$badReplacement['replacement']=$old;
check13f(!fm13f_decide($badReplacement,'approve',$old,$now)['allowed'],
    'proposed text must differ from locked source');
function rec13f(int $id,string $action,int $person=1,string $kind='menu'):array{
 return ['person_id'=>$person,'role'=>'system',
   'structured'=>['collection'=>'fm13c_guided_training','source'=>'gelato_training',
    'action'=>$action,'state'=>['kind'=>$kind,'id'=>(string)$id,'index'=>0,
      'status'=>'active','stepCount'=>3]]];
}
$rows=[rec13f(42,'hint',7),rec13f(42,'question',7),
 rec13f(42,'hint',8),rec13f(42,'repeat',7),rec13f(42,'repeat',8),
 rec13f(90,'hint',7),rec13f(90,'repeat',7),rec13f(90,'progress',7),
 rec13f(42,'hint',99),rec13f(42,'hint',8,'recipe')];
$signals=fm13f_review_signals($rows,[7,8]);
check13f(count($signals)===2,'only authorized menu procedures appear');
check13f($signals[0]['itemId']===42,'most frequently reviewed menu item ranked first');
check13f($signals[0]['helpRequests']===3&&$signals[0]['revisits']===2,
 'actual help and revisit counts attributed to same source');
check13f($signals[0]['peopleRequestingHelp']===2,'unique requesting participants counted once');
check13f($signals[0]['reviewSuggested']===true,'repeated requests trigger optional owner review');
check13f($signals[1]['itemId']===90&&$signals[1]['helpRequests']===1,
 'unrelated procedure activity not misattributed to most visited');
check13f($signals[1]['reviewSuggested']===false,'single request does not automatically flag correction');
$forged=rec13f(42,'hint',7);$forged['role']='user';
$foreign=rec13f(42,'hint',7);$foreign['structured']['source']='untrusted';
$legacy=rec13f(42,'',7);
check13f(fm13f_review_signals([$forged,$foreign,$legacy],[7])===[],
 'forged user records, unrelated sources and old unlabeled events excluded');
$oldEvent=['role'=>'system','structured'=>[
 'collection'=>'fm13f_procedure_review','source'=>'gelato_server',
 'proposalId'=>$proposal['id'],'proposal'=>$proposal]];
$newEvent=$oldEvent;$newEvent['structured']['proposal']['status']='approved';
$foreignEvent=$newEvent;$foreignEvent['structured']['source']='browser';
check13f(fm13f_latest_proposal([$oldEvent,$newEvent],$proposal['id'])['status']==='approved',
    'latest authenticated server proposal event selected');
check13f(fm13f_latest_proposal([$oldEvent,$foreignEvent],$proposal['id'])['status']==='draft',
    'browser-origin record cannot supplant canonical system evidence');
check13f(fm13f_latest_proposal([$oldEvent], 'prp-'.str_repeat('b',20))===null,
    'proposal ID is exact, never partial');
$endpoint=(string)file_get_contents(__DIR__.'/../api/food-monster-procedure-review.php');
$router=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$module=(string)file_get_contents(__DIR__.'/../includes/food-monster-g13f-procedure-review.php');
check13f(str_contains($endpoint,'app_verify_request_csrf')
    &&str_contains($endpoint,'gaw_messages($pdo,$org,$uid,$room,1)'),
    'typed canonical Agent Chat request and CSRF required at write endpoint');
check13f(str_contains($endpoint,'fm13f_authorized($user,')
    &&str_contains($endpoint,"$"."turn['channel']"),'Super Admin/menu permission enforced by endpoint');
check13f(str_contains($endpoint,'beginTransaction()')
    &&str_contains($module,'FOR UPDATE'),'transactional menu row lock protects approval and publication');
check13f(str_contains($endpoint,"COALESCE(preparation_notes,'')=?")
    &&str_contains($endpoint,'$q->rowCount()!==1'),
    'publish uses compare-and-set on prior exact preparation note');
check13f(str_contains($endpoint,"if($action==='approve')")&&str_contains($endpoint,"if($action==='publish')"),
    'approval and publication are separate user operations');
check13f(str_contains($endpoint,'fm13f_record($pdo,')
    &&str_contains($endpoint,'app_audit($pdo,'),'proposal history and audit receipts persisted');
check13f(str_contains($router,'fm13f_intent($message)')
    &&str_contains($router,'fm13f_authorized($user,'),
    'governed Agent Chat route cannot be initiated by employee/voice');
check13f(str_contains($endpoint,'fm13e_recent_records')
    &&str_contains($module,'fm13f_review_signals'),
    'suggestions require canonical training-history evidence');
check13f(!str_contains($endpoint,'UPDATE recipes'),
    'new publication scope limited to menu training note');
echo 'FM-G13F manager procedure review PASS ('.$n.' assertions)'."\n";
