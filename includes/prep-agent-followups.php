<?php
declare(strict_types=1);

/**
 * Conversational prep context is scoped to the authenticated PHP session,
 * organization and user. The dated plan and its tasks remain in the database.
 */
function pac_context_key(int $org,int $uid): string
{
    return $org.':'.$uid;
}

function pac_context_get(int $org,int $uid): ?array
{
    $key=pac_context_key($org,$uid);
    $row=$_SESSION['prep_agent_context'][$key]??null;
    if(!is_array($row)||(int)($row['expires']??0)<time()){
        unset($_SESSION['prep_agent_context'][$key]);
        return null;
    }
    return $row;
}

function pac_context_store(int $org,int $uid,array $plan,?string $taskPublicId=null): void
{
    $_SESSION['prep_agent_context'][pac_context_key($org,$uid)]=[
        'planId'=>(string)$plan['public_id'],
        'date'=>(string)$plan['plan_date'],
        'service'=>(string)$plan['service_period'],
        'taskId'=>$taskPublicId,
        'expires'=>time()+1800,
    ];
}

function pac_has_explicit_date(string $message): bool
{
    return preg_match('/\b(?:today|tomorrow|yesterday|20\d{2}-\d{2}-\d{2})\b/iu',$message)===1;
}

function pac_context_plan(PDO $pdo,int $org,int $uid,string $message): ?array
{
    if(pac_has_explicit_date($message)||preg_match('/\b(?:morning|lunch|dinner|closing)\b/iu',$message))
        return pac_plan_from_message($pdo,$org,$message);
    $context=pac_context_get($org,$uid);
    if($context){
        $plan=prep_plan($pdo,$org,(string)$context['planId']);
        if($plan)return $plan;
    }
    return pac_plan_from_message($pdo,$org,$message);
}

function pac_followup_intent(string $text): ?array
{
    $clean=trim((string)(preg_replace('/^(?:hey\s+gelato|food\s+monster)[,\s]*/iu','',$text)??$text));
    $clean=rtrim($clean," \t\r\n.!?");
    if(preg_match('/^(?:please\s+)?(?:mark|set)\s+(?:the\s+)?(.+?)\s+(?:as\s+)?(?:complete|completed|done)$/iu',$clean,$m))
        return ['type'=>'complete','target'=>trim($m[1])];
    if(preg_match('/^(?:please\s+)?(?:assign|give)\s+(?:the\s+)?(.+?)\s+to\s+(.+)$/iu',$clean,$m))
        return ['type'=>'assign','target'=>trim($m[1]),'assignee'=>trim($m[2])];
    if(preg_match('/^(?:please\s+)?(?:mark|set)\s+(?:the\s+)?(.+?)\s+(?:as\s+)?(in\s+progress|started|blocked|accepted)$/iu',$clean,$m)){
        $state=mb_strtolower(preg_replace('/\s+/u',' ',trim($m[2]))??trim($m[2]),'UTF-8');
        return ['type'=>'progress','target'=>trim($m[1]),'status'=>$state==='started'?'in_progress':str_replace(' ','_',$state)];
    }
    if(preg_match('/^(?:please\s+)?(start|resume|block|accept)\s+(?:the\s+)?(.+)$/iu',$clean,$m)){
        $verb=mb_strtolower($m[1],'UTF-8');
        return ['type'=>'progress','target'=>trim($m[2]),'status'=>match($verb){'block'=>'blocked','accept'=>'accepted',default=>'in_progress'}];
    }
    if(preg_match('/^(?:please\s+)?(?:who(?:\x27s| is)|which (?:person|employee) is)\s+(?:doing|assigned to|working on)\s+(?:the\s+)?(.+)$/iu',$clean,$m))
        return ['type'=>'assignees','target'=>trim($m[1])];
    $numbers='one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty';
    $units='pan|pans|tray|trays|batch|batches|container|containers|case|cases|bag|bags|lb|lbs|pound|pounds|oz|ounce|ounces|qt|quart|quarts|ea|each';
    if(preg_match('/^(?:please\s+)?(?:add|increase(?:\s+by)?)\s+(\d+(?:\.\d+)?|'.$numbers.')\s+(?:more\s+)?('.$units.')\b(?:\s+of\s+(.+?))?(?:\s+(?:to|on)\s+(?:(?:today|tomorrow)(?:\x27s)?\s+)?(?:the\s+)?prep\s+list)?$/iu',$clean,$m)){
        $number=is_numeric($m[1])?(float)$m[1]:pac_word_number($m[1]);
        if($number===null||$number<=0||$number>10000)throw new InvalidArgumentException('Specify a positive prep quantity no greater than 10,000.');
        return ['type'=>'increase','quantity'=>$number,'unit'=>prep_normalized_unit($m[2]),
            'target'=>trim((string)($m[3]??''))];
    }
    return null;
}

function pac_task_match(array $tasks,string $target,?string $focusedId=null): array
{
    $target=trim((string)(preg_replace('/^(?:the\s+)?(?:prep\s+)?(?:task\s+)?/iu','',$target)??$target));
    if($target===''){
        if($focusedId!==null){
            foreach($tasks as $task)if((string)$task['public_id']===$focusedId)return $task;
        }
        if(count($tasks)===1)return $tasks[0];
        throw new InvalidArgumentException('Which prep item do you mean? Say its name, such as “add two more pans of dough”.');
    }
    $needle=prep_normalized_key($target);
    $matches=[];
    foreach($tasks as $task){
        $name=prep_normalized_key((string)$task['title']);
        if($name===$needle||str_contains(' '.$name.' ',' '.$needle.' '))$matches[]=$task;
    }
    if(count($matches)===1)return $matches[0];
    if(!$matches)throw new InvalidArgumentException('I could not find “'.$target.'” on this prep plan.');
    throw new InvalidArgumentException('More than one prep item matches “'.$target.'”. Please use the full item name.');
}

function pac_task_description(array $task): string
{
    $prefix='';
    if(($task['quantity']??null)!==null){
        $quantity=(float)$task['quantity'];
        $unit=mb_strtolower(trim((string)($task['unit']??'')),'UTF-8');
        $singular=['pans'=>'pan','trays'=>'tray','batches'=>'batch','containers'=>'container','bags'=>'bag',
            'cases'=>'case','boxes'=>'box','bottles'=>'bottle','pounds'=>'lb','lbs'=>'lb'][$unit]??$unit;
        $plurals=['pan'=>'pans','tray'=>'trays','batch'=>'batches','container'=>'containers',
            'bag'=>'bags','case'=>'cases','box'=>'boxes','bottle'=>'bottles'];
        $spokenUnit=abs($quantity-1.0)<0.00001?$singular:($plurals[$singular]??$singular);
        $prefix=pac_quantity($quantity).' '.($spokenUnit==='ea'?'each ':($spokenUnit!==''?$spokenUnit.' of ':' '));
    }
    $status=str_replace('_',' ',(string)$task['status']);
    $assignees=trim((string)($task['assigned_names']??''));
    return $prefix.$task['title'].' ('.$status.($assignees!==''?', assigned to '.$assignees:', unassigned').')';
}

/** Resolve an active restaurant employee by exact display name or unambiguous first name. */
function pac_staff_match(PDO $pdo,int $org,string $requested): array
{
    $needle=prep_normalized_key($requested);
    if($needle==='')throw new InvalidArgumentException('Name the employee who should receive this prep task.');
    $q=$pdo->prepare("SELECT DISTINCT u.id,u.display_name FROM users u
        INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
        WHERE u.status='active' AND u.archived_at IS NULL
          AND COALESCE(om.job_title,'')<>'Wholesale Customer'
          AND NOT EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id
            WHERE ur.membership_id=om.id AND r.slug='wholesale_customer')");
    $q->execute([$org]);$exact=[];$first=[];
    foreach($q->fetchAll() as $row){
        $name=prep_normalized_key((string)$row['display_name']);
        if($name===$needle)$exact[]=$row;
        if(explode(' ',$name,2)[0]===$needle)$first[]=$row;
    }
    $found=$exact?:$first;
    if(count($found)===1)return $found[0];
    if(!$found)throw new InvalidArgumentException('I could not find an active restaurant employee named “'.$requested.'”.');
    throw new InvalidArgumentException('More than one employee matches “'.$requested.'”. Use the full display name.');
}

function pac_assignment_signature(PDO $pdo,int $org,int $taskId): string
{
    $q=$pdo->prepare("SELECT CONCAT(user_id,':',assignment_role) AS assignee_entry FROM restaurant_task_assignments WHERE organization_id=? AND task_id=? ORDER BY user_id,assignment_role");
    $q->execute([$org,$taskId]);
    return implode('|',$q->fetchAll(PDO::FETCH_COLUMN));
}

function pac_list_result(PDO $pdo,int $org,int $uid,array $plan,bool $canForecast): array
{
    $detail=pac_visible(prep_plan_detail($pdo,$org,(string)$plan['public_id']),$canForecast);
    $tasks=$detail['tasks'];
    $count=count($tasks);
    $descriptions=array_map('pac_task_description',array_slice($tasks,0,16));
    $answer=$plan['plan_date'].' '.ucfirst((string)$plan['service_period']).' prep list: ';
    $answer.=$count?implode('; ',$descriptions).($count>16?'; plus '.($count-16).' more item(s) in Prep Intelligence.':'.'):'No items have been added yet.';
    pac_context_store($org,$uid,$plan,$count===1?(string)$tasks[0]['public_id']:null);
    return ['ok'=>true,'skill'=>'prep.list','answer'=>$answer,
        'data'=>['plan'=>$detail['plan'],'tasks'=>$tasks,'proof'=>['url'=>'prep-intelligence.php','planId'=>$plan['public_id']]],
        'sources'=>array_column($tasks,'public_id')];
}

function pac_execute_followup(PDO $pdo,int $org,int $uid,array $user,array $payload,string $type): array
{
    if(in_array($type,['task_quantity','task_assign'],true)){
        pac_require($user,'prep.intelligence.manage','Prep management permission is required to change prep tasks.');
        pac_require($user,'tasks.manage','Task management permission is required to change prep tasks.');
    }else{
        pac_require($user,'prep.intelligence.view','Prep view permission is required.');
        pac_require($user,'prep.intelligence.agent','Prep Agent permission is required.');
    }
    $pdo->beginTransaction();
    try{
        $plan=pac_resolve_plan($pdo,$org,$payload,false,$uid);
        if((string)$plan['status']==='closed')
            throw new InvalidArgumentException('This prep plan is closed and cannot be modified.');
        $taskId=(string)($payload['taskId']??'');
        $task=operations_task_by_public_id($pdo,$org,$taskId);
        $link=$pdo->prepare('SELECT COUNT(*) FROM prep_plan_tasks WHERE organization_id=? AND plan_id=? AND task_id=?');
        $link->execute([$org,(int)$plan['id'],(int)($task['id']??0)]);
        if(!$task||(int)$link->fetchColumn()!==1)
            throw new InvalidArgumentException('That item is no longer on this prep plan.');
        if((string)$task['updated_at']!==(string)($payload['taskUpdatedAt']??'')
            ||(string)$task['status']!==(string)($payload['taskStatus']??'')
            ||(string)($task['quantity']??'')!==(string)($payload['taskQuantity']??'')
            ||(string)($task['unit']??'')!==(string)($payload['taskUnit']??'')){
            throw new InvalidArgumentException('That prep task changed after the proposal. Review it and ask again.');
        }
        if(array_key_exists('assignmentSignature',$payload)
            &&pac_assignment_signature($pdo,$org,(int)$task['id'])!==(string)$payload['assignmentSignature'])
            throw new InvalidArgumentException('The prep task assignment changed. Review it and ask again.');
        $advance=$pdo->prepare('UPDATE prep_plans SET revision=revision+1,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND revision=?');
        $advance->execute([$uid,(int)$plan['id'],$org,(int)$plan['revision']]);
        if($advance->rowCount()!==1)
            throw new InvalidArgumentException('That prep plan changed. Review it and ask again.');
        if(in_array($type,['task_status','task_progress'],true)){
            $manager=app_has_permission('tasks.manage',$user)&&app_has_permission('prep.intelligence.manage',$user);
            if(!$manager){
                pac_require($user,'tasks.self','Only an assigned employee or a manager may complete this prep task.');
                $assigned=$pdo->prepare('SELECT COUNT(*) FROM restaurant_task_assignments WHERE organization_id=? AND task_id=? AND user_id=?');
                $assigned->execute([$org,(int)$task['id'],$uid]);
                if((int)$assigned->fetchColumn()===0)throw new PrepAgentPermissionException('You may only complete prep tasks assigned to you.');
            }
            $newStatus=$type==='task_status'?'completed':(string)($payload['status']??'');
            if(!in_array($newStatus,['completed','accepted','in_progress','blocked'],true))
                throw new InvalidArgumentException('That prep task status is not supported.');
            if($newStatus==='completed'&&(int)$task['requires_photo']===1){
                $proof=$pdo->prepare('SELECT COUNT(*) FROM restaurant_task_proofs WHERE organization_id=? AND task_id=?');
                $proof->execute([$org,(int)$task['id']]);
                if((int)$proof->fetchColumn()===0)
                    throw new InvalidArgumentException('Photo proof is required before this prep task can be completed.');
            }
            if(in_array((string)$task['status'],['completed','verified','cancelled'],true))
                throw new InvalidArgumentException('That prep task is already finished or cancelled.');
            if((string)$task['status']===$newStatus)throw new InvalidArgumentException('That prep task is already in the requested status.');
            $updated=operations_task_set_status($pdo,$org,$taskId,$newStatus,$uid);
            $answer='Confirmed. Marked '.$task['title'].' '.str_replace('_',' ',$newStatus).'.';
            $after=$newStatus;$before=$task['status'];
        }elseif($type==='task_assign'){
            if(in_array((string)$task['status'],['completed','verified','cancelled'],true))
                throw new InvalidArgumentException('A finished prep task cannot be reassigned.');
            $staff=pac_staff_match($pdo,$org,(string)($payload['assigneeName']??''));
            if((int)$staff['id']!==(int)($payload['assigneeId']??0))
                throw new InvalidArgumentException('That employee identity changed. Review the assignment and ask again.');
            $before=pac_assignment_signature($pdo,$org,(int)$task['id']);
            $pdo->prepare('DELETE FROM restaurant_task_assignments WHERE organization_id=? AND task_id=?')
                ->execute([$org,(int)$task['id']]);
            $pdo->prepare("INSERT INTO restaurant_task_assignments (organization_id,task_id,user_id,assignment_role,assigned_by) VALUES (?,?,?,'assignee',?)")
                ->execute([$org,(int)$task['id'],(int)$staff['id'],$uid]);
            $pdo->prepare("UPDATE restaurant_tasks SET status=IF(status='queued','assigned',status),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$uid,$org,(int)$task['id']]);
            $after=(int)$staff['id'];
            operations_task_event($pdo,$org,(int)$task['id'],'assignment_changed','Prep task assigned to '.$staff['display_name'],$uid,['prior'=>$before,'assigneeId'=>$after,'planId'=>$plan['public_id']]);
            $updated=operations_task_by_public_id($pdo,$org,$taskId);
            $answer='Confirmed. Assigned '.$task['title'].' to '.$staff['display_name'].'.';
        }elseif($type==='task_quantity'){
            if(!empty($task['source_type']))
                throw new InvalidArgumentException('This task is generated from another source. Change its source recommendation in Prep Intelligence.');
            if(in_array((string)$task['status'],['completed','verified','cancelled'],true))
                throw new InvalidArgumentException('A finished prep task cannot have its quantity increased.');
            if(!is_numeric($task['quantity']))
                throw new InvalidArgumentException('This task has no starting quantity. Set its quantity in Prep Intelligence first.');
            $unit=prep_normalized_unit((string)$task['unit']);
            if($unit===''||$unit!==(string)($payload['unit']??''))
                throw new InvalidArgumentException('The requested unit does not match this prep item. No quantity changed.');
            $delta=(float)($payload['delta']??0);
            if(!is_finite($delta)||$delta<=0||$delta>10000)
                throw new InvalidArgumentException('Specify a valid positive quantity increase.');
            $before=(float)$task['quantity'];
            $after=$before+$delta;
            if($after>1000000)throw new InvalidArgumentException('The new prep quantity would be too large.');
            $stmt=$pdo->prepare('UPDATE restaurant_tasks SET quantity=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND updated_at=? AND archived_at IS NULL');
            $stmt->execute([$after,$uid,$org,(int)$task['id'],(string)$task['updated_at']]);
            if($stmt->rowCount()!==1)
                throw new InvalidArgumentException('The quantity changed before confirmation. Review the task and ask again.');
            operations_task_event($pdo,$org,(int)$task['id'],'quantity_adjusted','Prep quantity increased by '.pac_quantity($delta).' '.$unit,$uid,['before'=>$before,'after'=>$after,'planId'=>$plan['public_id']]);
            $updated=operations_task_by_public_id($pdo,$org,$taskId);
            $answer='Confirmed. Increased '.$task['title'].' from '.pac_quantity($before).' to '.pac_quantity($after).' '.$unit.'.';
        }else{
            throw new InvalidArgumentException('Unsupported prep task follow-up.');
        }
        prep_event($pdo,$org,(int)$plan['id'],'task_updated','Agent-confirmed prep task change.',$uid,['taskId'=>$taskId,'action'=>$type,'before'=>$before,'after'=>$after],(int)$task['id']);
        app_audit($pdo,$org,$uid,'prep.agent_task_updated','restaurant_task',$taskId,null,['action'=>$type,'planId'=>$plan['public_id'],'before'=>$before,'after'=>$after]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    pac_context_store($org,$uid,$plan,$taskId);
    return ['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>$answer,
        'data'=>['action'=>$type,'task'=>$updated,'before'=>$before,'after'=>$after,
            'proof'=>['url'=>'prep-intelligence.php','planId'=>$plan['public_id'],'taskId'=>$taskId]],
        'sources'=>[$plan['public_id'],$taskId]];
}
