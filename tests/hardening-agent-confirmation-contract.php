<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/hardening-agent-confirmation-test-support.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/food-monster-b8d11-domain-routing.php';
require_once __DIR__.'/../includes/food-monster-b8d12-pending-actions.php';
require_once __DIR__.'/../includes/food-monster-b8d18-purchasing-proposals.php';
require_once __DIR__.'/../includes/scheduling-agent-core.php';
function app_has_permission(string $permission,array $actor):bool{return in_array($permission,$actor['permissions']??[],true);}
$n=0;
function hac_check(bool $yes,string $message):void{global $n;if(!$yes)throw new RuntimeException('Confirmation binding: '.$message);++$n;}
function hac_conflict(callable $run,string $message):void{
    $before=$_SESSION;
    try{$run();}catch(GacConfirmationConflict){hac_check($_SESSION===$before,$message.' preserves pending state');return;}
    throw new RuntimeException('Confirmation binding allowed: '.$message);
}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$actor=['organization_id'=>31,'id'=>19,'permissions'=>['tasks.agent','tasks.view','tasks.manage']];
$other=['organization_id'=>31,'id'=>20,'permissions'=>$actor['permissions']];
$foreign=['organization_id'=>32,'id'=>19,'permissions'=>$actor['permissions']];
if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION=[];
$contextA=hac_test_context($pdo,$actor);
$a=gac_pending_store('operations',31,19,'task_create',['items'=>['Item A']],'Create Item A');
hac_check($a['version']===2&&$a['threadId']===$contextA['threadId']&&$a['sourceMessageId']===$contextA['sourceMessageId'],'source owner and canonical origin are stored');
hac_check(gac_public_proposal($a)['hash']===''&&gac_public_proposal($a)['messageId']==='','unrecorded proposal has no executable evidence');
hac_conflict(static fn()=>gac_pending_claim('operations',31,19,['threadId'=>$contextA['threadId'],'proposalId'=>$a['id']]),'unbound confirmation');
$publicA=hac_test_bind($pdo,$actor,$a);$evidenceA=hac_test_evidence($publicA);
hac_check(gac_pending_reviewed('operations',31,19,$evidenceA)['payload']['items']===['Item A'],'exact canonical binding returns reviewed action');
$contextB=hac_test_context($pdo,$actor);
hac_conflict(static fn()=>gac_pending_store('operations',31,19,'task_create',['items'=>['Item B']],'Create Item B'),'Tab B overwrite');
foreach(['proposalId'=>'gap-'.str_repeat('0',16),'proposalHash'=>str_repeat('0',64),
    'proposalMessageId'=>'msg-'.str_repeat('0',20),'threadId'=>$contextB['threadId']] as $key=>$value){
    hac_conflict(static fn()=>gac_pending_claim('operations',31,19,array_replace($evidenceA,[$key=>$value])),'wrong '.$key);
}
foreach(array_keys($evidenceA) as $key){$bad=$evidenceA;unset($bad[$key]);hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$bad),'missing '.$key);}
hac_conflict(static fn()=>gac_pending_claim('operations',31,20,$evidenceA),'cross user');
hac_conflict(static fn()=>gac_pending_claim('operations',32,19,$evidenceA),'cross tenant');
hac_conflict(static fn()=>gac_pending_claim('marketing',31,19,$evidenceA),'cross node');
hac_check(gaw_pending_action_node(31,19,[])===null,'empty routing evidence has no fallback');
hac_check(gaw_pending_action_node(31,19,$evidenceA)==='operations','routing uses exact reviewed identity');
$live=gac_pending_get('operations',31,19);
foreach(['payload'=>['items'=>['Forged Item B']],'summary'=>'Different review','sourceMessageId'=>'msg-'.str_repeat('a',20),
    'organizationId'=>32,'userId'=>20,'node'=>'marketing','expires'=>time()-1] as $field=>$value){
    $_SESSION['agent_node_pending']['operations']['31:19'][$field]=$value;
    hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$evidenceA),'changed immutable '.$field);
    $_SESSION['agent_node_pending']['operations']['31:19']=$live;
}
$pdo->prepare('UPDATE agent_messages SET content=? WHERE public_id=?')->execute(['Forged canonical display',$publicA['messageId']]);
hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$evidenceA),'changed canonical message');
$pdo->prepare('UPDATE agent_messages SET content=? WHERE public_id=?')->execute([gac_proposal_answer($live),$publicA['messageId']]);
hac_conflict(static fn()=>gac_pending_bind_message($pdo,'operations',31,19,$a['id'],$contextA['threadId'],'msg-'.str_repeat('f',20)),'second canonical message');
// An explicitly reviewed cancellation removes A, and stale A cannot approve later B.
$claimed=gac_pending_claim('operations',31,19,$evidenceA);
hac_check($claimed['id']===$a['id']&&gac_pending_get('operations',31,19)===null,'claim is persisted before execution');
hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$evidenceA),'simulated replay after claim');
hac_conflict(static fn()=>gac_pending_store('operations',31,19,'task_create',['items'=>['Item B']],'Create Item B'),'replacement while claim in flight');
gac_pending_clear('operations',31,19,'gap-'.str_repeat('0',16));hac_check(gac_pending_raw('operations',31,19)!==null,'wrong completion ID cannot clear a claim');
gac_pending_clear('operations',31,19,$a['id']);
$b=gac_pending_store('operations',31,19,'task_create',['items'=>['Item B']],'Create Item B');
$publicB=hac_test_bind($pdo,$actor,$b);$evidenceB=hac_test_evidence($publicB);
hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$evidenceA),'stale prior Tab A');
$mutations=[];$claimed=gac_pending_claim('operations',31,19,$evidenceB);$mutations[]=$claimed['payload']['items'];
gac_pending_clear('operations',31,19,$claimed['id']);
hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$evidenceB),'completed replay');
hac_check($mutations===[['Item B']],'only the exact reviewed payload was executed once');
// Legacy rows are neither upgraded implicitly nor silently replaced.
$_SESSION['agent_node_pending']['operations']['31:19']=['id'=>'gap-'.str_repeat('c',16),'organizationId'=>31,'userId'=>19,'expires'=>time()+600,'type'=>'task_create','payload'=>['items'=>['Legacy']]];
hac_conflict(static fn()=>gac_pending_store('operations',31,19,'task_create',['items'=>['New']],'New'),'unresolved legacy overwrite');
hac_conflict(static fn()=>gac_pending_claim('operations',31,19,$evidenceB),'legacy wildcard confirmation');
$_SESSION['agent_node_pending']['operations']['31:19']['expires']=time()-1;
$c=gac_pending_store('operations',31,19,'task_create',['items'=>['Item C']],'Create Item C');
$publicC=hac_test_bind($pdo,$actor,$c);$evidenceC=hac_test_evidence($publicC);
$context=$evidenceC+['messageId'=>'msg-'.str_repeat('d',20)];
$catalogue=fm8d12_pending_catalogue($actor,$context);
hac_check(isset($catalogue['continue_pending_restaurant_action']),'model continuation requires full exact reviewed binding');
hac_check(fm8d12_pending_catalogue($actor,['threadId'=>$c['threadId'],'messageId'=>$context['messageId']])===[],'natural language cannot discover current slot without evidence');
hac_check(fm8d12_pending_catalogue($actor,array_replace($context,['threadId'=>$contextA['threadId']]))===[],'natural language cannot cross threads');
$handoff=$catalogue['continue_pending_restaurant_action']['execute'](['decision'=>'confirm'],$actor)['data']['delegation'];
hac_check($handoff['proposalId']===$publicC['id']&&$handoff['proposalHash']===$publicC['hash']&&$handoff['proposalMessageId']===$publicC['messageId'],'continuation forwards reviewed identity unchanged');
hac_check(!($catalogue['continue_pending_restaurant_action']['authorize'])(array_replace($actor,['permissions'=>[]]),['decision'=>'confirm']),'fresh permission revocation removes continuation');
gac_pending_clear('operations',31,19,$c['id']);
// Separate domain queues obey the same no-overwrite, canonical review, and replay rules.
foreach(['purchasing','scheduling'] as $node){
    $proposal=$node==='purchasing'?fm8d18p_pending_store(31,19,'draft_po',['vendorId'=>'v-1'],'Draft PO'):sac_pending_store(31,19,'shift_create',['userId'=>19],'Create shift');
    hac_check($proposal['node']===$node,'shared '.$node.' binding');
    $public=hac_test_bind($pdo,$actor,$proposal);$evidence=hac_test_evidence($public);
    hac_conflict(static fn()=>gac_pending_store($node,31,19,'different',[],'Different action'),$node.' overwrite');
    gac_pending_claim($node,31,19,$evidence);gac_pending_clear($node,31,19,$proposal['id']);
    hac_conflict(static fn()=>gac_pending_claim($node,31,19,$evidence),$node.' replay');
}
// A different principal's canonical source message cannot create an owner-bound proposal.
$otherContext=hac_test_context($pdo,$other);gac_request_context($otherContext,$pdo,$actor);
hac_conflict(static fn()=>gac_pending_store('operations',31,19,'task_create',[],'Forged source'),'foreign source owner');
gac_request_context([],$pdo,$actor);
hac_conflict(static fn()=>gac_pending_store('operations',31,19,'task_create',[],'Threadless'),'threadless creation');
foreach(['operations','timeclock','prep-intelligence','customer-crm','marketing','catering','wholesale','purchasing','scheduling','front-of-house','recipe','equipment','online-order'] as $route){
    $source=(string)file_get_contents(__DIR__.'/../api/'.$route.'-agent.php');
    hac_check(str_contains($source,'catch(GacConfirmationConflict'),'HTTP '.$route.' maps conflicts to 409');
}
session_write_close();
echo "Agent confirmation binding: PASS ($n assertions)\n";
