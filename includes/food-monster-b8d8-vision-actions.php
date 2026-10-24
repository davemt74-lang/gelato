<?php
declare(strict_types=1);

/**
 * FM-B8D8 — governed routine Vision Lab actions.
 * Only existing Gelato service adapters are callable. Every write is
 * serialized on an active organization membership and deduplicated against
 * the latest canonical authenticated Agent Chat turn.
 */
require_once __DIR__.'/food-monster-b8d6-training.php';
require_once __DIR__.'/glasses-vision-lab.php';

function fm8d8_observe(string $answer):array
{
    return ['ok'=>true,'data'=>['answer'=>mb_strcut($answer,0,2600,'UTF-8'),
        'source'=>'Gelato Vision Lab mission/dataset ledger']];
}
function fm8d8_receipts_ready(PDO $pdo):bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='food_monster_agent_action_receipts'");
    return (int)$q->fetchColumn()===1;
}
function fm8d8_can_manage(array $actor):bool
{
    return app_has_permission('glasses.view',$actor)&&app_has_permission('glasses.manage',$actor);
}
function fm8d8_lock_actor(PDO $pdo,int $org,int $uid):void
{
    $q=$pdo->prepare("SELECT u.id FROM users u
        JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
             AND om.status='active'
        WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL FOR UPDATE");
    $q->execute([$org,$uid]);
    if(!$q->fetchColumn())throw new DomainException('No active verified staff membership.');
}
function fm8d8_create_mission(PDO $pdo,array $actor,array $context,array $args):array
{
    if(!fm8d8_can_manage($actor))throw new DomainException('Vision Lab management is not permitted.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $title=trim((string)($args['title']??''));
    $desc=trim((string)($args['description']??''));
    $target=max(0,min(500,(int)($args['target_samples']??0)));
    if($title===''||mb_strlen($title,'UTF-8')>190
       ||mb_strlen($desc,'UTF-8')>1000)throw new InvalidArgumentException('Invalid mission details.');
    if(!glasses_vision_lab_ready($pdo))return fm8d8_observe('Vision Lab is not installed. No mission was created.');
    $pdo->beginTransaction();
    try{
        fm8d8_lock_actor($pdo,$org,$uid);
        try{$turn=fm8d6_canonical_turn($pdo,$actor,$context);}
        catch(InvalidArgumentException){$turn=null;}
        if($turn===null)throw new DomainException('The latest verified chat turn changed. No mission was created.');
        $source='agentchat:'.$turn;
        $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_missions
            WHERE organization_id=? AND source_type='manual' AND source_reference=? LIMIT 1");
        $q->execute([$org,$source]);
        $existing=(string)($q->fetchColumn()?:'');
        if($existing!==''){$m=glasses_vision_lab_mission($pdo,$org,$existing);$reused=true;}
        else{
            $m=glasses_vision_lab_create_mission($pdo,$org,[
                'title'=>$title,'description'=>$desc,'targetSamples'=>$target,
                'sourceType'=>'manual','sourceReference'=>$source],$uid);
            $reused=false;
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return fm8d8_observe(($reused?'That request was already completed. ':'Created Vision Lab collection mission. ').
        (string)$m['title'].'; status '.(string)$m['status'].
        '; target samples '.(int)$m['targetSamples'].'; reference '.(string)$m['publicId'].'.');
}
function fm8d8_create_dataset(PDO $pdo,array $actor,array $context,array $args):array
{
    if(!fm8d8_can_manage($actor))throw new DomainException('Vision Lab management is not permitted.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $name=trim((string)($args['name']??''));
    $version=trim((string)($args['version_label']??''));
    if($name===''||$version===''||mb_strlen($name,'UTF-8')>190
       ||mb_strlen($version,'UTF-8')>80)throw new InvalidArgumentException('Invalid dataset version.');
    if(!glasses_vision_lab_ready($pdo)||!fm8d8_receipts_ready($pdo))
        return fm8d8_observe('Vision Lab agent receipt migration is pending. No dataset was created.');
    $pdo->beginTransaction();
    try{
        fm8d8_lock_actor($pdo,$org,$uid);
        try{$turn=fm8d6_canonical_turn($pdo,$actor,$context);}
        catch(InvalidArgumentException){$turn=null;}
        if($turn===null)
            throw new DomainException('The latest verified chat turn changed. No dataset was created.');
        $q=$pdo->prepare("SELECT resource_public_id FROM food_monster_agent_action_receipts
            WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?
                AND action='create_vision_dataset_draft' LIMIT 1");
        $q->execute([$org,$uid,$turn]);
        $receipt=(string)($q->fetchColumn()?:'');
        if($receipt!==''){$d=glasses_vision_lab_dataset($pdo,$org,$receipt);$reused=true;}
        else{
            $q=$pdo->prepare("SELECT public_id FROM glasses_vision_dataset_versions
                WHERE organization_id=? AND name=? AND version_label=? LIMIT 1");
            $q->execute([$org,$name,$version]);
            $existing=(string)($q->fetchColumn()?:'');
            if($existing!==''){$d=glasses_vision_lab_dataset($pdo,$org,$existing);$reused=true;}
            else{$d=glasses_vision_lab_create_dataset($pdo,$org,[
                'name'=>$name,'versionLabel'=>$version],$uid);$reused=false;}
            $pdo->prepare("INSERT INTO food_monster_agent_action_receipts
                (organization_id,actor_user_id,turn_public_id,action,resource_public_id)
                VALUES (?,?,?,'create_vision_dataset_draft',?)")
                ->execute([$org,$uid,$turn,(string)$d['publicId']]);
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return fm8d8_observe(($reused?'This dataset version already exists. ':'Created new draft Vision Lab dataset. ').
        (string)$d['name'].' version '.(string)$d['versionLabel'].
        '; status '.(string)$d['status'].'; reference '.(string)$d['publicId'].'.');
}
function fm8d8_list_missions(PDO $pdo,array $actor):array
{
    if(!app_has_permission('glasses.view',$actor))throw new DomainException('Vision Lab read is not permitted.');
    if(!glasses_vision_lab_ready($pdo))
        return fm8d8_observe('Vision Lab is not installed or upgraded.');
    $rows=glasses_vision_lab_missions($pdo,(int)$actor['organization_id']);
    if(!$rows)return fm8d8_observe('No Vision Lab collection missions are recorded for this restaurant.');
    $lines=['Recorded Vision Lab collection missions:'];
    foreach(array_slice($rows,0,12) as $m)
        $lines[]=(string)($m['title']??'Untitled').': '.(string)($m['status']??'unknown').
            ', '.(int)($m['sampleCount']??0).' samples, reference '.
            (string)($m['publicId']??'unknown').'.';
    if(count($rows)>12)$lines[]='Additional missions exist; only 12 are displayed.';
    return fm8d8_observe(implode("\n",$lines));
}
function fm8d8_list_datasets(PDO $pdo,array $actor):array
{
    if(!app_has_permission('glasses.view',$actor))throw new DomainException('Vision Lab read is not permitted.');
    if(!glasses_vision_lab_ready($pdo))
        return fm8d8_observe('Vision Lab is not installed or upgraded.');
    $rows=glasses_vision_lab_datasets($pdo,(int)$actor['organization_id']);
    if(!$rows)return fm8d8_observe('No Vision Lab dataset versions are recorded for this restaurant.');
    $lines=['Recorded Vision Lab datasets:'];
    foreach(array_slice($rows,0,12) as $d)
        $lines[]=(string)$d['name'].' version '.(string)$d['versionLabel'].': '.
            (string)$d['status'].', '.(int)$d['itemCount'].' sample items.';
    if(count($rows)>12)$lines[]='Additional datasets exist; only 12 are displayed.';
    return fm8d8_observe(implode("\n",$lines));
}
function fm8d8_vision_catalogue(array $principal,array $context=[]):array
{
    if(!app_has_permission('glasses.view',$principal))return [];
    $view=static fn(array $actor,array $args):bool=>app_has_permission('glasses.view',$actor);
    $manage=static fn(array $actor,array $args):bool=>fm8d8_can_manage($actor);
    $none=['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false];
    $list=[
        'list_vision_training_missions'=>[
            'description'=>'List actual Vision Lab collection missions, current statuses and sample counts in this restaurant. Use to avoid duplicate missions and answer what training missions are active.',
            'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>$view,'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d8_list_missions(app_pdo(),$actor),
        ],
        'list_vision_dataset_versions'=>[
            'description'=>'List actual recorded Vision Lab dataset versions and their draft/frozen states. Does not change training data.',
            'parameters'=>$none,'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>$view,'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d8_list_datasets(app_pdo(),$actor),
        ],
    ];
    if(!fm8d8_can_manage($principal)||!preg_match('/^agent-[a-f0-9]{20}$/D',(string)($context['threadId']??''))
       ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($context['messageId']??'')))return $list;
    $list['create_vision_collection_mission']=[
        'description'=>'Create an actual Vision Lab training/collection mission with a user-selected title, optional instructions, and optional sample target. A routine authorized manager action; use only if the person clearly asks to create a mission. The authenticated saved chat message supplies a durable duplicate key. No camera, device assignment, or model rollout changes.',
        'parameters'=>['type'=>'object','properties'=>[
            'title'=>['type'=>'string','description'=>'Mission title, 1-190 characters'],
            'description'=>['type'=>'string','description'=>'Optional mission instructions'],
            'target_samples'=>['type'=>'integer','minimum'=>0,'maximum'=>500],
        ],'required'=>['title'],'additionalProperties'=>false],
        'allowedArguments'=>['title','description','target_samples'],'impact'=>'routine',
        'authorize'=>$manage,
        'validate'=>static fn(array $args,array $actor):array=>
            trim((string)($args['title']??''))===''?['title']:[],
        'execute'=>static fn(array $args,array $actor):array=>fm8d8_create_mission(app_pdo(),$actor,$context,$args),
    ];
    $list['create_vision_dataset_draft']=[
        'description'=>'Create a blank DRAFT Vision Lab dataset version using an exact name and version label, or return the existing one if already recorded. Does not freeze a dataset, approve samples or activate models. Requires an authenticated saved chat request.',
        'parameters'=>['type'=>'object','properties'=>[
            'name'=>['type'=>'string','description'=>'Dataset name'],
            'version_label'=>['type'=>'string','description'=>'Version label, e.g. v1'],
        ],'required'=>['name','version_label'],'additionalProperties'=>false],
        'allowedArguments'=>['name','version_label'],'impact'=>'routine',
        'authorize'=>$manage,
        'validate'=>static function(array $args,array $actor):array{
            $missing=[];foreach(['name','version_label'] as $k)
                if(trim((string)($args[$k]??''))==='')$missing[]=$k;
            return $missing;
        },
        'execute'=>static fn(array $args,array $actor):array=>fm8d8_create_dataset(app_pdo(),$actor,$context,$args),
    ];
    return $list;
}
