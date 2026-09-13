<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-workspace-core.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!gaw_ready($pdo))app_json_response(['ok'=>false,'message'=>'Agent Workspace migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'bootstrap');
    if($action==='bootstrap'){
        $threads=gaw_threads($pdo,$org,$uid,40);$active=$threads[0]['public_id']??null;
        if(!$active){$thread=gaw_create_thread($pdo,$org,$uid);$threads=gaw_threads($pdo,$org,$uid,40);$active=$thread['public_id']??null;}
        app_json_response(['ok'=>true,'csrf'=>app_csrf_token(),'threads'=>$threads,'activeThread'=>$active,'messages'=>$active?gaw_messages($pdo,$org,$uid,(string)$active,120):[],'user'=>['id'=>$uid,'name'=>$user['display_name'],'firstName'=>$user['first_name'],'role'=>$user['role_slug']],'permissions'=>$user['permissions']]);
    }
    if($action==='thread'){$id=trim((string)($_GET['id']??''));app_json_response(['ok'=>true,'thread'=>gaw_thread($pdo,$org,$uid,$id),'messages'=>gaw_messages($pdo,$org,$uid,$id,220)]);}
    if($action==='threads')app_json_response(['ok'=>true,'threads'=>gaw_threads($pdo,$org,$uid,80)]);
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent Workspace action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);} 
$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
try{
    if($action==='new_thread'){$thread=gaw_create_thread($pdo,$org,$uid,(string)($in['channel']??'text'));app_audit($pdo,$org,$uid,'agent.thread_created','agent_conversation',(string)$thread['public_id']);app_json_response(['ok'=>true,'thread'=>$thread]);}
    if($action==='append'){$thread=trim((string)($in['threadId']??''));$role=(string)($in['role']??'user');$message=gaw_append($pdo,$org,$uid,$thread,$role,(string)($in['content']??''),['channel'=>$in['channel']??'text','skill'=>$in['skill']??null,'tool'=>$in['tool']??null,'voiceEventId'=>$in['voiceEventId']??null,'structured'=>$in['structured']??null,'sources'=>is_array($in['sources']??null)?$in['sources']:[]]);app_json_response(['ok'=>true,'message'=>$message]);}
    if($action==='route'){
        $message=trim((string)($in['message']??''));if($message==='')throw new InvalidArgumentException('Enter an Agent request.');
        $text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');
        $developmentIntent=preg_match('/\b(employee development|development brief|performance brief|coaching|coaching notes?|recognition|training progress|task completion|attendance reliability|development goals?)\b/u',$text)===1;
        if($developmentIntent&&(app_has_permission('employee.performance.view',$user)||app_has_permission('employee.manage',$user)||app_has_permission('staff.manage',$user)))app_json_response(['ok'=>true,'route'=>'api/employee-development-agent.php','domain'=>'employee_development']);
        $handoffIntent=preg_match('/\b(handoff|handoffs|shift note|station note|arrival brief|what happened before i got here|what happened before my shift|anything i should know|tell (?:the )?next shift|leave .*next shift|note .*next shift)\b/u',$text)===1;
        if($handoffIntent&&(app_has_permission('employee.handoffs.view',$user)||app_has_permission('employee.handoffs.create',$user)||app_has_permission('employee.handoffs.manage',$user)||app_has_permission('employee.self',$user)||app_has_permission('agent.employee_view',$user)))app_json_response(['ok'=>true,'route'=>'api/employee-agent.php','domain'=>'employee_handoff']);
        app_json_response(['ok'=>true]+gaw_route($user,$message));
    }
    if($action==='record_action'){$thread=trim((string)($in['threadId']??''));$public=gaw_record_action($pdo,$org,$uid,$thread,['messageDatabaseId'=>isset($in['messageDatabaseId'])?(int)$in['messageDatabaseId']:null,'skill'=>$in['skill']??'unknown','route'=>$in['route']??'unknown','status'=>$in['status']??'completed','request'=>$in['request']??null,'result'=>$in['result']??null]);app_json_response(['ok'=>true,'actionId'=>$public]);}
    if($action==='archive'){$id=trim((string)($in['threadId']??''));$thread=gaw_thread($pdo,$org,$uid,$id);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');$pdo->prepare("UPDATE agent_conversations SET status='archived',archived_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([(int)$thread['id']]);app_audit($pdo,$org,$uid,'agent.thread_archived','agent_conversation',$id);app_json_response(['ok'=>true]);}
    if($action==='rename'){$id=trim((string)($in['threadId']??''));$title=trim((string)($in['title']??''));if($title==='')throw new InvalidArgumentException('Conversation title is required.');$thread=gaw_thread($pdo,$org,$uid,$id);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');$pdo->prepare('UPDATE agent_conversations SET title=?,updated_at=NOW(6) WHERE id=?')->execute([mb_substr($title,0,220,'UTF-8'),(int)$thread['id']]);app_json_response(['ok'=>true]);}
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent Workspace action.'],422);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],$e instanceof InvalidArgumentException?422:500);}