<?php
declare(strict_types=1);

/** B8D18J7A: tenant-scoped catering lookup and exact event reads without writes. */

function fm8d18j7a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('catering.view',$actor)
        &&app_has_permission('catering.agent',$actor);
}
function fm8d18j7a_query_fields(array $args):array
{
    $query=$args['query']??null;
    if(!is_string($query)||mb_strlen(trim($query),'UTF-8')<2
       ||mb_strlen(trim($query),'UTF-8')>120
       ||preg_match('/[%_;\x00-\x1F]/u',$query))return ['query'];
    return [];
}
function fm8d18j7a_profile_fields(array $args):array
{
    $missing=[];$id=$args['operation_public_id']??null;
    if(!is_string($id)||strlen($id)<2||strlen($id)>120
       ||!preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='operation_public_id';
    if(!in_array($args['section']??null,['readiness','tasks','ingredients','staff','menu'],true))$missing[]='section';
    return $missing;
}
function fm8d18j7a_search(array $args,array $actor):array
{
    if(!fm8d18j7a_can($actor))throw new DomainException('Catering Agent view permission is required.');
    if($missing=fm8d18j7a_query_fields($args))throw new InvalidArgumentException('Specify a specific saved catering operation or event name.');
    require_once __DIR__.'/catering-operations.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$query=trim((string)$args['query']);
    if(!catering_operations_ready($pdo))throw new RuntimeException('Catering operations are unavailable. Complete upgrade.php first.');
    $q=$pdo->prepare("SELECT public_id,title,status,service_start_at,guest_count,venue_name
        FROM restaurant_operations WHERE organization_id=? AND archived_at IS NULL
          AND (LOCATE(LOWER(?),LOWER(title))>0 OR public_id=?)
        ORDER BY service_start_at IS NULL,service_start_at,id LIMIT 9");
    $q->execute([$org,$query,$query]);$rows=$q->fetchAll();$truncated=count($rows)>8;
    $matches=array_map(static fn(array $r):array=>[
        'publicId'=>(string)$r['public_id'],'title'=>(string)$r['title'],
        'status'=>(string)$r['status'],'serviceStartAt'=>$r['service_start_at'],
        'guestCount'=>$r['guest_count']!==null?(int)$r['guest_count']:null,
        'venueName'=>$r['venue_name'],
    ],array_slice($rows,0,8));
    $lines=[];
    foreach($matches as $row)$lines[]=$row['title'].' ['.$row['publicId'].'] · '.$row['status'].
        ($row['serviceStartAt']?' · '.$row['serviceStartAt']:' · date not set');
    $answer=$lines?('Saved catering operations: '.implode('; ',$lines).'.'):
        'No saved catering operation matched this query.';
    if($truncated)$answer.=' More matches exist; narrow the query.';
    if(count($matches)>1)$answer.=' Select one exact public ID before an event-specific action.';
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1500,'UTF-8'),
        'source'=>'Gelato Catering Operations (authenticated event lookup)',
        'readOnly'=>true,'matches'=>$matches,'truncated'=>$truncated,
    ]];
}
function fm8d18j7a_read(array $args,array $actor):array
{
    if(!fm8d18j7a_can($actor))throw new DomainException('Catering Agent view permission is required.');
    if($missing=fm8d18j7a_profile_fields($args))
        throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/catering-operations.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$public=(string)$args['operation_public_id'];
    if(!catering_operations_ready($pdo))throw new RuntimeException('Catering operations are unavailable. Complete upgrade.php first.');
    $operation=catering_operation_row($pdo,$org,$public);
    if(!$operation)throw new InvalidArgumentException('The exact catering operation was not found.');
    $id=(int)$operation['id'];$section=(string)$args['section'];$rows=[];$lines=[];
    if($section==='readiness'){
        // Readiness normally persists and changes updated_at; this read MUST not write.
        $r=catering_operations_readiness($pdo,$org,$id,false);
        $rows=['percent'=>$r['percent'],'issues'=>array_slice((array)$r['issues'],0,10),
            'taskDone'=>$r['taskDone'],'taskTotal'=>$r['taskTotal'],
            'requirementsReady'=>$r['requirementsReady'],'requirementsTotal'=>$r['requirementsTotal'],
            'staffCount'=>$r['staffCount']];
        $lines[]=$r['percent'].'% ready; '.$r['taskDone'].'/'.$r['taskTotal'].' tasks done; '.
            $r['requirementsReady'].'/'.$r['requirementsTotal'].' ingredient requirements ready; '.
            $r['staffCount'].' assigned staff.';
        foreach($rows['issues'] as $issue)$lines[]=mb_substr((string)$issue,0,160,'UTF-8');
    }elseif($section==='tasks'){
        $all=catering_operation_tasks($pdo,$org,$id);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['taskId'=>(int)$r['id'],'title'=>$r['title'],'status'=>$r['status'],
                'priority'=>$r['priority'],'dueAt'=>$r['due_at'],'assignedName'=>$r['assigned_name']];
            $lines[]=mb_substr((string)$r['title'],0,100,'UTF-8').' (#'.$r['id'].') · '.$r['status'].
                ($r['due_at']?' · due '.$r['due_at']:'');
        }
    }elseif($section==='ingredients'){
        $all=catering_operation_requirements($pdo,$org,$id);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['requirementId'=>(int)$r['id'],'name'=>$r['ingredient_name'],
                'status'=>$r['status'],'requiredQuantity'=>$r['required_quantity'],'unit'=>$r['unit']];
            $lines[]=mb_substr((string)$r['ingredient_name'],0,100,'UTF-8').' (#'.$r['id'].') · '.$r['status'].
                ($r['required_quantity']!==null?' · '.$r['required_quantity'].' '.($r['unit']??''):' · quantity TBD');
        }
    }elseif($section==='staff'){
        $all=catering_operation_staff($pdo,$org,$id);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['name'=>$r['user_name'],'role'=>$r['role_name'],
                'status'=>$r['status'],'shiftStartAt'=>$r['shift_start_at']];
            $lines[]=mb_substr((string)$r['user_name'],0,100,'UTF-8').' · '.$r['role_name'].' · '.$r['status'];
        }
    }else{
        $all=catering_operation_menu($pdo,$org,$id);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['itemName'=>$r['item_name'],'recipeName'=>$r['recipe_name'],
                'targetServings'=>$r['target_servings'],'batches'=>$r['batches']];
            $lines[]=mb_substr((string)$r['item_name'],0,100,'UTF-8').
                ($r['target_servings']!==null?' · '.$r['target_servings'].' servings':'');
        }
    }
    $more=isset($all)&&count($all)>count($rows);
    $answer=(string)$operation['title'].' ('.$public.') · '.$section.': '.
        ($lines?implode('; ',$lines):'No recorded entries.').
        ($more?' More records exist; only the first ten are shown.':'');
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1700,'UTF-8'),
        'source'=>'Gelato Catering Operations (authenticated exact event)',
        'readOnly'=>true,'operationPublicId'=>$public,'title'=>(string)$operation['title'],
        'status'=>(string)$operation['status'],'section'=>$section,'records'=>$rows,
        'truncated'=>$more||mb_strlen($answer,'UTF-8')>1700,
    ]];
}
function fm8d18j7a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j7a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'search_catering_operations'=>[
            'description'=>'Find one or more saved catering operations by specific event name or exact public ID. Tenant-scoped, read-only, up to eight minimal event identities. Do not infer or guess an event; ask the user to select the exact public ID before reading or changing one.',
            'parameters'=>['type'=>'object','properties'=>[
                'query'=>['type'=>'string','description'=>'Specific catering event name or saved public ID'],
            ],'required'=>['query'],'additionalProperties'=>false],
            'allowedArguments'=>['query'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j7a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j7a_query_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j7a_search($args,$user),
        ],
        'read_catering_operation'=>[
            'description'=>'Read ONE section of an already selected exact saved catering operation: current readiness, tasks, ingredient requirements, staffing, or menu. Tenant-scoped, no customer contact fields, no writes; readiness is recalculated with persist=false. Never guess a public ID or treat a read as a permission to modify the event.',
            'parameters'=>['type'=>'object','properties'=>[
                'operation_public_id'=>['type'=>'string','description'=>'Exact saved restaurant operation public ID'],
                'section'=>['type'=>'string','enum'=>['readiness','tasks','ingredients','staff','menu']],
            ],'required'=>['operation_public_id','section'],'additionalProperties'=>false],
            'allowedArguments'=>['operation_public_id','section'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j7a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j7a_profile_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j7a_read($args,$user),
        ],
    ];
}
