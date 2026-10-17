<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,(array)($user['permissions']??[]),true);
}
function app_role_has_owner_authority(array $user):bool {
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g13g-procedure-history.php';
$n=0;
function check13g(bool $ok,string $label):void{
    global $n;
    if(!$ok)throw new RuntimeException('FM-G13G FAIL: '.$label);
    $n++;
}
$owner=['id'=>1,'organization_id'=>7,'role_slug'=>'super_admin',
    'permissions'=>['menu.view','menu.manage']];
$staff=['id'=>2,'organization_id'=>7,'role_slug'=>'employee',
    'permissions'=>['menu.view','menu.manage']];
check13g(fm13f_authorized($owner,'text'),'typed Super Admin with menu permissions can view and draft');
check13g(!fm13f_authorized($owner,'voice'),'room voice cannot initiate rollback');
check13g(!fm13f_authorized($staff,'text'),'employee cannot draft using owner room');
$cases=[
    'procedure history 52'=>['history',52],
    'compare procedure ver-'.str_repeat('a',20)=>['compare',null],
    'draft rollback procedure ver-'.str_repeat('a',20)=>['rollback_draft',null]
];
foreach($cases as $cmd=>$expect){
    $parsed=fm13g_intent($cmd);
    check13g(($parsed['action']??null)===$expect[0],'command recognized: '.$cmd);
    if($expect[1]!==null)check13g($parsed['itemId']===$expect[1],'menu item ID recognized');
}
foreach(['rollback procedure 52','rollback all items','approve procedure ver-'.str_repeat('a',20),
    'publish version ver-'.str_repeat('a',20),'compare procedure 52',
    'procedure history 0','draft rollback procedure ver-bad','undo everything',
    'delete revision','training next step','show my schedule'] as $cmd)
    check13g(fm13g_intent($cmd)===null,'unrelated commands not intercepted: '.$cmd);

$before='Stretch pizza dough, apply sauce, then add cheese.';
$after='Stretch pizza dough evenly, apply recorded sauce and cheese, then bake according to approved station guidance.';
$proposal=['id'=>'prp-'.str_repeat('a',20),'orgId'=>7,'ownerId'=>1,'itemId'=>52,
    'itemName'=>'House Pizza','createdAt'=>1800,'publishedAt'=>2000,'status'=>'draft',
    'sourceVersion'=>4,'sourceHash'=>fm13f_notes_digest($before),
    'original'=>$before,'replacement'=>$after];
function revisionEvent(array $p,string $role='system',string $source='gelato_server'):array{
    return ['role'=>$role,'structured'=>['collection'=>'fm13f_procedure_review',
        'source'=>$source,'proposalId'=>$p['id'],'proposal'=>$p,
        'sourceTurnId'=>'msg-'.str_repeat('e',20)]];
}
$approved=$proposal;$approved['status']='approved';
$published=$proposal;$published['status']='published';
$events=[revisionEvent($proposal),revisionEvent($approved),revisionEvent($published)];
$v=fm13g_versions($events,7,1);
check13g(count($v)===1,'draft and approval history does not create duplicate published versions');
check13g($v[0]['itemId']===52&&$v[0]['before']===$before&&$v[0]['after']===$after,
    'published revision preserves both exact procedure snapshots');
check13g($v[0]['sourceVersion']===4,'native menu version retained when available');
check13g(preg_match('/^ver-[a-f0-9]{20}$/D',$v[0]['versionId'])===1,'opaque deterministic revision identifier');
check13g($v[0]['versionId']===fm13g_version_id($published),'stable revision ID from canonical publication');
check13g($v[0]['beforeHash']===fm13f_notes_digest($before)
    &&$v[0]['afterHash']===fm13f_notes_digest($after),'both source hashes independently reproduced');
check13g(fm13g_find_version($v,$v[0]['versionId'])===$v[0],'exact revision lookup');
check13g(fm13g_find_version($v,'ver-'.str_repeat('0',20))===null,'unknown revision never resolved');
check13g(fm13g_versions($events,8,1)===[],'organization isolation');
check13g(fm13g_versions($events,7,9)===[],'owner account isolation');
check13g(fm13g_versions($events,7,1,99)===[],'menu item isolation');
check13g(count(fm13g_versions($events,7,1,52))===1,'menu filter includes authorized matching revision');

$neverPublished=[revisionEvent($proposal),revisionEvent($approved)];
check13g(fm13g_versions($neverPublished,7,1)===[],'approved but not published changes do not appear');
$rejected=$proposal;$rejected['status']='rejected';
check13g(fm13g_versions([revisionEvent($proposal),revisionEvent($rejected)],7,1)===[],
    'rejected proposal is not published version');
$wrongSource=revisionEvent($published,'system','browser');
$wrongRole=revisionEvent($published,'user');
check13g(fm13g_versions([$wrongSource,$wrongRole],7,1)===[],
    'browser and user messages cannot forge historical publications');
$invalid=$published;$invalid['sourceHash']=str_repeat('0',64);
check13g(fm13g_versions([revisionEvent($invalid)],7,1)===[],'tampered preimage is rejected');
$invalid=$published;$invalid['replacement']=$before;
check13g(fm13g_versions([revisionEvent($invalid)],7,1)===[],'unchanged replacement excluded');
$invalid=$published;$invalid['publishedAt']=0;
check13g(fm13g_versions([revisionEvent($invalid)],7,1)===[],'unpublished timestamp excluded');

$oldProposal=$published;$oldProposal['id']='prp-'.str_repeat('b',20);
$oldProposal['publishedAt']=1500;
$oldProposal['sourceVersion']=null;
$oldProposal['original']='Make the old sauce mixture from approved station notes.';
$oldProposal['replacement']=$before;
$oldProposal['sourceHash']=fm13f_notes_digest($oldProposal['original']);
$withLegacy=fm13g_versions([revisionEvent($oldProposal),...$events],7,1);
check13g(count($withLegacy)===2,'older G13F published proposals remain discoverable');
check13g($withLegacy[0]['publishedAt']===2000&&$withLegacy[1]['publishedAt']===1500,
    'published revisions sorted newest first');
check13g($withLegacy[1]['sourceVersion']===null,'legacy revision does not invent native menu version');

$diff=fm13g_diff_summary($v[0]);
check13g($diff['before']===$before&&$diff['after']===$after,'comparison returns full exact prior and new instructions');
check13g($diff['beforeHash']!==$diff['afterHash'],'comparison exposes distinct content digests');
check13g($diff['beforeLines']===1&&$diff['afterLines']===1,'line counts are from stored text');

$draftId='prp-'.str_repeat('c',20);
$item=['id'=>52,'name'=>'House Pizza','version'=>5,'preparation_notes'=>$after];
$rollback=fm13g_rollback_proposal($v[0],$item,7,1,2200,$draftId);
check13g($rollback!==null&&$rollback['status']==='draft','rollback creates a new unpublished draft');
check13g($rollback['original']===$after&&$rollback['replacement']===$before,
    'rollback restores exact BEFORE instruction');
check13g($rollback['sourceHash']===fm13f_notes_digest($after)&&$rollback['sourceVersion']===5,
    'rollback pinned to currently published menu content and version');
check13g($rollback['rollbackOf']===$v[0]['versionId'],'rollback provenance points to canonical revision');
check13g($rollback['rollbackSourceHash']===$v[0]['beforeHash'],'rollback source content verified');
check13g(fm13f_decide($rollback,'approve',$after,2201)['allowed'],'rollback goes through existing G13F approval');
check13g(!fm13f_decide($rollback,'publish',$after,2201)['allowed'],'unapproved rollback cannot publish');
$ok=$rollback;$ok['status']='approved';
check13g(fm13f_decide($ok,'publish',$after,2201)['allowed'],'separately approved rollback qualifies for publish');
check13g(!fm13f_decide($ok,'publish',$after.' changed elsewhere',2201)['allowed'],
    'intervening edit rejects even approved rollback');
$item['preparation_notes']=$before;
check13g(fm13g_rollback_proposal($v[0],$item,7,1,2202,$draftId)===null,
    'already restored prior procedure cannot be rolled back twice');
$item['preparation_notes']=$after;
check13g(fm13g_rollback_proposal($v[0],$item,8,1,2200,$draftId)===null,'cross-tenant rollback impossible');
check13g(fm13g_rollback_proposal($v[0],$item,7,2,2200,$draftId)===null,'cross-owner rollback impossible');
check13g(fm13g_rollback_proposal($v[0],$item,7,1,2200,'bad')===null,'invalid proposal ID blocked');
$item['id']=99;
check13g(fm13g_rollback_proposal($v[0],$item,7,1,2200,$draftId)===null,'wrong menu item blocked');

$turn='msg-'.str_repeat('e',20);
check13g(fm13g_turn_seen($events,$turn),'previous system action from same canonical message prevents duplicate drafts');
check13g(!fm13g_turn_seen($events,'msg-'.str_repeat('d',20)),'new message may create a draft');
check13g(fm13g_turn_seen([],'invalid'),'noncanonical turn fails closed');
$foreign=revisionEvent($published,'system','browser');
check13g(!fm13g_turn_seen([$foreign],$turn),'forged browser source cannot claim server idempotency');

$api=(string)file_get_contents(__DIR__.'/../api/food-monster-procedure-history.php');
$review=(string)file_get_contents(__DIR__.'/../api/food-monster-procedure-review.php');
$route=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$card=(string)file_get_contents(__DIR__.'/../js/agent-canvas.js');
check13g(str_contains($api,'app_verify_request_csrf')&&str_contains($api,'gaw_messages($pdo,$org,$uid,$room,1)'),
    'history and rollback require authenticated canonical typed chat and CSRF');
check13g(str_contains($api,"fm13f_authorized(")&&str_contains($api,"'text'"),
    'history uses same typed Super Admin and menu permission boundary');
check13g(str_contains($api,'$pdo->beginTransaction()')&&str_contains($api,'fm13f_source_snapshot($pdo,$org,$itemId)'),
    'rollback draft snapshots menu item under transaction lock');
check13g(str_contains($api,'fm13g_turn_seen($events,$turnId)'),
    'rollback refuses duplicate canonical command');
check13g(str_contains($api,'fm13f_record($pdo,$org,$uid,$ledger,$proposal,$turnId)'),
    'rollback draft uses existing G13F append-only review ledger');
check13g(!str_contains($api,'UPDATE menu_items'), 'history and rollback draft endpoint does not publish');
check13g(str_contains($review,'version=version+1,updated_at=NOW(6)'),
    'approved publish increments native menu version');
check13g(str_contains($review,"$"."sql.=' AND version=?'"),
    'approved publish CAS guards native source version for new drafts');
check13g(str_contains($review,'sourceVersion')&&str_contains($review,"COALESCE(preparation_notes,'')=?"),
    'original and version must still match for approval and publish');
check13g(str_contains($route,'fm13g_intent($message)')&&str_contains($route,'fm13f_authorized($user,'),
    'owner-only text routing and separate G13F approval preserved');
check13g(str_contains($card,'food_monster.procedure_version_history')&&str_contains($card,'esc(rev.before'),
    'before-after comparison escaped in existing Agent Brain sidebar');
echo 'FM-G13G procedure versions and governed rollback PASS ('.$n.' assertions)'."\n";
