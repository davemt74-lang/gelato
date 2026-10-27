<?php
declare(strict_types=1);

/**
 * B8D17F: exact-target, confirmation-only task status commands.
 * No fuzzy task selection, silent bulk edits, or implicit verification.
 */
function ops_agent_task_status_intent(string $message): ?array
{
    $command=trim((string)(preg_replace('/^\\s*(?:hey\\s+gelato[,\\s]*)?(?:please\\s+)?/iu','',$message)??$message));
    $command=rtrim($command," \t\r\n.");
    $status=null;$name='';
    if(preg_match('/^(?:mark|set)\\s+(?:the\\s+)?(?:task\\s+)?(.+?)\\s+(?:as|to)\\s+(done|completed|in progress|blocked|accepted)$/iu',$command,$m)){
        $name=$m[1];$status=strtolower($m[2]);
    }elseif(preg_match('/^(?:complete|finish|start|block|accept)\\s+(?:the\\s+)?(?:task\\s+)?(.+)$/iu',$command,$m)){
        $name=$m[1];$verb=strtolower($m[0]);
        $status=preg_match('/^(complete|finish)\\b/',$verb)?'completed'
            :(preg_match('/^start\\b/',$verb)?'in_progress'
            :(preg_match('/^block\\b/',$verb)?'blocked':'accepted'));
    }else{return null;}
    $status=['done'=>'completed','in progress'=>'in_progress'][$status]??$status;
    $name=trim($name," \t\r\n.");
    if($name===''||strlen($name)>240||preg_match('/[;\\n]|\\band\\s+(?:complete|finish|start|block|accept|mark|set)\\b/iu',$name)){
        throw new InvalidArgumentException('Name exactly one restaurant task for a status change.');
    }
    return ['name'=>$name,'status'=>$status];
}

function ops_agent_task_status_prepare(PDO $pdo,int $organizationId,array $intent):array
{
    $stmt=$pdo->prepare("SELECT public_id,title,status,requires_photo,requires_verification FROM restaurant_tasks WHERE organization_id=? AND archived_at IS NULL AND LOWER(TRIM(title))=LOWER(TRIM(?)) LIMIT 3");
    $stmt->execute([$organizationId,$intent['name']]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new InvalidArgumentException(count($rows)>1
        ?'Several tasks have that exact title. Use a unique task title in Restaurant Tasks.'
        :'No exact task found. Check the task title in Restaurant Tasks.');
    $row=$rows[0];$old=(string)$row['status'];$new=(string)$intent['status'];
    if(in_array($old,['completed','verified','cancelled'],true))
        throw new InvalidArgumentException('This task is already closed. Review it in Restaurant Tasks.');
    if($old===$new)throw new InvalidArgumentException('The task already has that status.');
    if($new==='completed'&&((int)$row['requires_photo']===1||(int)$row['requires_verification']===1))
        throw new InvalidArgumentException('This task requires proof or verification. Complete it through Restaurant Tasks.');
    return ['publicId'=>(string)$row['public_id'],'title'=>(string)$row['title'],
        'expectedStatus'=>$old,'status'=>$new];
}

function ops_agent_task_status_confirm(PDO $pdo,int $organizationId,array $payload,int $userId):array
{
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT status,requires_photo,requires_verification FROM restaurant_tasks WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');
        $stmt->execute([$organizationId,(string)$payload['publicId']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Task no longer exists.');
        if((string)$row['status']!==(string)$payload['expectedStatus'])
            throw new RuntimeException('Task changed since confirmation was requested. Ask for a fresh proposal.');
        if($payload['status']==='completed'&&((int)$row['requires_photo']===1||(int)$row['requires_verification']===1))
            throw new RuntimeException('Task proof requirements changed. Complete it through Restaurant Tasks.');
        $result=operations_task_set_status($pdo,$organizationId,(string)$payload['publicId'],(string)$payload['status'],$userId);
        $pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
