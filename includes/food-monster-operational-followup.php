<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-conversation-memory.php';
require_once __DIR__.'/operations-core.php';

/**
 * FM-G9F operational handoff: owner-delegated, routine task creation from an
 * existing unresolved, voice-verified report. This is not a model tool and
 * never accepts an arbitrary employee identity, action, or task payload.
 */
function fm9f_report_action_policy(array $operator,string $kind):string {
    $decision=fm9a_agent_action_decision($operator,$kind);
    if($decision==='denied')throw new DomainException('Super Admin delegation required.');
    return $decision;
}
function fm9f_create_report_task(PDO $pdo,array $operator,int $personId,string $reportId):array {
    if(fm9f_report_action_policy($operator,'routine')!=='delegated')
        throw new DomainException('Standing authorization not available.');
    if(!function_exists('app_has_permission')||!app_has_permission('tasks.manage',$operator))
        throw new DomainException('Task management permission required.');
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$reportId)||$personId<1)
        throw new InvalidArgumentException('Invalid report reference.');
    if(!operations_core_ready($pdo))throw new RuntimeException('Restaurant tasks unavailable.');
    fm9d_person_collections($pdo,$operator,$personId);
    $org=(int)$operator['organization_id'];$actor=(int)$operator['id'];
    if($pdo->inTransaction())throw new LogicException('Action needs its own transaction boundary.');
    $pdo->beginTransaction();
    try {
        // Serializing on the original row makes retries and concurrent requests idempotent.
        $q=$pdo->prepare("SELECT m.id,m.content,m.structured_json,c.public_id thread
            FROM agent_messages m INNER JOIN agent_conversations c ON c.id=m.conversation_id
            WHERE m.organization_id=? AND c.organization_id=? AND c.user_id=?
              AND c.title LIKE 'Food Monster · %' AND m.public_id=? AND m.role='user'
            FOR UPDATE");
        $q->execute([$org,$org,$personId,$reportId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $meta=$row?json_decode((string)$row['structured_json'],true):null;
        if(!$row||!is_array($meta)||($meta['collection']??'')!=='fm9d_person_turn'
            ||($meta['identityAssurance']??'')!=='provisional_voice_match'
            ||(int)($meta['speakerPersonId']??0)!==$personId
            ||($meta['memoryCategory']??fm9e_classify((string)$row['content']))!=='operational_report')
            throw new InvalidArgumentException('Verified operational report not found.');
        $find=$pdo->prepare("SELECT structured_json FROM agent_messages
            WHERE organization_id=? AND conversation_id=(SELECT id FROM agent_conversations
                WHERE organization_id=? AND user_id=? AND public_id=? LIMIT 1)
              AND role='system'
              AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9f_report_task'
              AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.reportMessageId'))=?
            ORDER BY id DESC LIMIT 1");
        $find->execute([$org,$org,$personId,(string)$row['thread'],$reportId]);
        $prior=$find->fetchColumn();
        if($prior!==false){
            $existing=json_decode((string)$prior,true)?:[];
            $pdo->commit();
            return ['alreadyCreated'=>true,'taskId'=>(string)($existing['taskId']??''),'reportMessageId'=>$reportId];
        }
        $resolved=$pdo->prepare("SELECT COUNT(*) FROM agent_messages
            WHERE organization_id=? AND conversation_id=(SELECT id FROM agent_conversations
                WHERE organization_id=? AND user_id=? AND public_id=? LIMIT 1)
              AND role='system'
              AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9e_report_resolution'
              AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.reportMessageId'))=?");
        $resolved->execute([$org,$org,$personId,(string)$row['thread'],$reportId]);
        if((int)$resolved->fetchColumn()>0)throw new DomainException('Report already resolved.');
        $title='Investigate reported issue: '.fm9e_trim((string)$row['content'],150);
        $task=operations_create_task($pdo,$org,[
            'title'=>$title,'category'=>'general','sourceType'=>'food_monster_report',
            'sourcePublicId'=>$reportId,'quantity'=>null,'unit'=>null,
            'transcript'=>(string)$row['content'],'aiConfidence'=>1.0,'assigneeIds'=>[]
        ],$actor);
        $taskId=(string)($task['public_id']??'');
        if($taskId==='')throw new RuntimeException('Task creation returned no reference.');
        gaw_append($pdo,$org,$personId,(string)$row['thread'],'system',
            'Food Monster created an operational follow-up task.',[
            'channel'=>'proactive','structured'=>[
                'collection'=>'fm9f_report_task','reportMessageId'=>$reportId,
                'taskId'=>$taskId,'actorUserId'=>$actor,'source'=>'super_admin_routine_delegation'
            ]]);
        $pdo->commit();
        return ['alreadyCreated'=>false,'taskId'=>$taskId,'reportMessageId'=>$reportId];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
