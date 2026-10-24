<?php
declare(strict_types=1);

/**
 * FM-B8D6 — model-selected guided training and training insights.
 * Existing G13C/G13E are the only persistent sources of truth.
 */
require_once __DIR__.'/food-monster-g13c-training.php';
require_once __DIR__.'/food-monster-g13e-intelligence.php';
require_once __DIR__.'/agent-voice-conversation.php';

function fm8d6_observe(string $answer,string $source='Gelato Guided Training'):array
{
    return ['ok'=>true,'data'=>['answer'=>mb_strcut($answer,0,2600,'UTF-8'),'source'=>$source]];
}
function fm8d6_my_progress(PDO $pdo,array $actor):array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $thread=fm13c_training_thread($pdo,$org,$uid,false);
    if(!$thread)return fm8d6_observe('You have no saved guided-training session yet. I can start one using an active menu procedure or an authorized recipe.');
    $state=fm13c_read_state($pdo,$org,$uid,$thread);
    if(!$state)return fm8d6_observe('There is no verified guided-training progress in your saved training thread.');
    if(($state['kind']??'')==='recipe'&&!fm13b_allowed($actor,'recipe',false,false))
        return fm8d6_observe('Your saved training includes a restricted procedure. You do not currently have permission to view it.');
    $material=fm13c_fetch_material($pdo,$org,$state);
    $safe=$material&&($material['digest']??'')===($state['digest']??'');
    $status=(string)($state['status']??'unknown');
    $step=max(1,(int)($state['index']??0)+1);$count=max(1,(int)($state['stepCount']??1));
    if(!$safe)return fm8d6_observe('Your training state is '.$status.' at step '.$step.' of '.$count.', but the saved procedure has changed or is unavailable. Please review the updated source before continuing.');
    return fm8d6_observe('Your training on '.$material['name'].' is '.$status.', step '.$step.' of '.$count.'. Recorded practice answers: '.
        max(0,(int)($state['practiceCorrect']??0)).' correct of '.max(0,(int)($state['practiceAttempts']??0)).
        ' attempts. This is guided practice, not a competency certification.');
}
function fm8d6_team_insights(PDO $pdo,array $actor):array
{
    $org=(int)$actor['organization_id'];
    $q=$pdo->prepare("SELECT u.id,u.display_name FROM users u
        JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
            AND om.status='active'
        WHERE u.status='active' AND u.archived_at IS NULL ORDER BY u.id ASC LIMIT 31");
    $q->execute([$org]);$people=$q->fetchAll(PDO::FETCH_ASSOC);
    $capped=count($people)>30;$people=array_slice($people,0,30);
    $ids=array_map('intval',array_column($people,'id'));
    $data=fm13e_recent_records($pdo,$org,$ids);
    if(!fm13b_allowed($actor,'recipe',false,false))
        $data['rows']=array_values(array_filter($data['rows'],
            static fn(array $r):bool=>(($r['structured']['state']['kind']??'')!=='recipe')));
    $summary=fm13e_aggregate($data['rows'],$ids,30);
    $summary['capped']=$capped||$data['capped'];
    foreach($summary['participants'] as &$entry){
        $current=$entry['current'];$material=null;
        try{$material=fm13c_fetch_material($pdo,$org,$current);}catch(Throwable){}
        $entry['sourceAvailable']=$material!==null&&($material['digest']??'')===($current['digest']??'');
        $entry['sourceTitle']=$entry['sourceAvailable']?$material['name']:null;
        unset($entry['current']['digest']);
    }
    unset($entry);
    $result=fm13e_answer($summary,'team',$people);
    return fm8d6_observe($result['answer'],'Gelato Training Intelligence');
}
function fm8d6_canonical_turn(PDO $pdo,array $actor,array $context):?string
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $room=(string)($context['threadId']??'');$turn=(string)($context['messageId']??'');
    if(!preg_match('/^agent-[a-f0-9]{20}$/D',$room)
      ||!preg_match('/^msg-[a-f0-9]{20}$/D',$turn))return null;
    $latest=gaw_messages($pdo,$org,$uid,$room,1)[0]??[];
    if(($latest['role']??'')!=='user'||(string)($latest['public_id']??'')!==$turn)return null;
    if(($latest['channel']??'')==='voice'){
        $event=(string)($latest['voice_event_public_id']??'');
        if($event===''||$event!==(string)($context['voiceEventId']??''))return null;
        $verified=avc_verified_speaker($pdo,$org,$actor,$event);
        if(!is_array($verified)||(int)($verified['userId']??0)!==$uid)return null;
    }elseif(($latest['channel']??'text')!=='text')return null;
    return $turn;
}
function fm8d6_selected_material(PDO $pdo,int $org,array $args):?array
{
    $name=trim((string)($args['subject']??''));
    if($name===''||mb_strlen($name,'UTF-8')>160)return null;
    $kind=(string)($args['kind']??'menu');
    if($kind==='recipe'){
        $rows=array_values(array_filter(restaurant_brain_recipe_search($pdo,$org,$name,5),
            static fn(array $r):bool=>($r['status']??'')==='active'));
        $matches=array_values(array_filter($rows,static fn(array $r):bool=>
            mb_strtolower((string)($r['name']??''),'UTF-8')===mb_strtolower($name,'UTF-8')));
        if(count($matches)!==1)return null;
        return fm13c_material($matches[0],'recipe');
    }
    $rows=menu_training_search_items($pdo,$org,$name,5);
    $matches=array_values(array_filter($rows,static fn(array $r):bool=>
        mb_strtolower((string)($r['name']??''),'UTF-8')===mb_strtolower($name,'UTF-8')));
    if(count($matches)!==1)return null;
    return fm13c_material($matches[0],'menu');
}
function fm8d6_step(PDO $pdo,array $actor,array $context,array $args):array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $action=(string)($args['action']??'');
    $kind=(string)($args['kind']??'menu');
    $intent=$action==='start'&&$kind==='recipe'?'recipe':'training';
    $voice=($context['channel']??'')==='voice';
    if(!fm13b_allowed($actor,$intent,!$voice||($context['verifiedSameUser']??false)===true,$voice))
        return fm8d6_observe('Your account is not authorized for this training procedure.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT u.id FROM users u
            JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
                AND om.status='active'
            WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL FOR UPDATE");
        $q->execute([$org,$uid]);
        if(!$q->fetchColumn())throw new DomainException('Current employee membership is inactive.');
        $turn=fm8d6_canonical_turn($pdo,$actor,$context);
        if($turn===null)throw new DomainException('This is no longer the latest verified Agent Chat message.');
        $thread=fm13c_training_thread($pdo,$org,$uid,false);
        $prior=$thread?fm13c_read_state($pdo,$org,$uid,$thread):null;
        $material=null;
        if($action==='start'){
            if($kind==='recipe'&&!fm13b_allowed($actor,'recipe',!$voice||($context['verifiedSameUser']??false)===true,$voice))
                throw new DomainException('Recipe training access is not authorized.');
            $material=fm8d6_selected_material($pdo,$org,$args);
        }elseif($prior){
            if(($prior['kind']??'')==='recipe'&&!fm13b_allowed($actor,'recipe',!$voice||($context['verifiedSameUser']??false)===true,$voice))
                throw new DomainException('Your current training procedure is restricted.');
            $material=fm13c_fetch_material($pdo,$org,$prior);
        }
        $command=['action'=>$action,'subject'=>(string)($args['subject']??'')];
        if($action==='answer')$command['choice']=(int)($args['choice']??0);
        $result=fm13c_transition($prior,$command,$material,$turn,time());
        if($result['changed']){
            if(!$thread)$thread=fm13c_training_thread($pdo,$org,$uid,true);
            gaw_append($pdo,$org,$uid,$thread,'system','Training progress updated',[
                'channel'=>'text',
                'structured'=>['collection'=>'fm13c_guided_training','action'=>$action,
                    'state'=>$result['state'],'source'=>'gelato_training',
                    'operatorUserId'=>$uid,'operatorRoomThread'=>$context['threadId']]]);
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return fm8d6_observe((string)$result['answer']);
}
function fm8d6_training_catalogue(array $principal,array $context=[]):array
{
    $allowed=fm13b_allowed($principal,'training',false,false);
    $tools=[];
    if($allowed){
        $tools['get_my_training_progress']=[
            'description'=>'Read the signed-in employee’s private, saved Gelato guided training progress, current step, status, and recorded practice attempts. Use when asked what training have I done, where did I leave off, or my training progress. Never accept an employee id.',
            'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
            'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm13b_allowed($actor,'training',false,false),
            'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d6_my_progress(app_pdo(),$actor),
        ];
    }
    $owner=in_array('*',(array)($principal['permissions']??[]),true)
       ||(int)($principal['is_owner_role']??0)===1
       ||(($principal['role_slug']??'')==='super_admin'&&(int)($principal['is_system_role']??0)===1);
    if($owner&&($context['channel']??'')==='text'){
        $tools['get_team_training_insights']=[
            'description'=>'Owner-only, typed-chat summary of the team’s actual recorded guided-training practice, progress and optional coaching opportunities. Never assess competency or certify employees.',
            'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
            'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                in_array('*',(array)($actor['permissions']??[]),true)
                ||(int)($actor['is_owner_role']??0)===1
                ||(($actor['role_slug']??'')==='super_admin'&&(int)($actor['is_system_role']??0)===1),
            'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static fn(array $args,array $actor):array=>fm8d6_team_insights(app_pdo(),$actor),
        ];
    }
    if($allowed&&preg_match('/^agent-[a-f0-9]{20}$/D',(string)($context['threadId']??''))
       &&preg_match('/^msg-[a-f0-9]{20}$/D',(string)($context['messageId']??''))){
        $tools['run_training_step']=[
            'description'=>'Use the employee’s canonical saved Agent Chat message to start or continue guided on-the-job training. Can start a named, recorded menu procedure or an authorized recipe, explain/repeat/advance steps, pause/resume, quiz/answer, and review updated procedures. Must choose action by meaning rather than matching magic phrases. For start use exact procedure subject and kind (menu or recipe). If missing subject ask the person. Training progress is attributed only to the current signed-in, verified employee.',
            'parameters'=>['type'=>'object','properties'=>[
                'action'=>['type'=>'string','enum'=>['start','next','repeat','previous','pause','resume','restart','question','quiz','answer','hint','progress','review_update','acknowledge_update','start_refresh']],
                'subject'=>['type'=>'string','description'=>'Exact menu item or recipe name when starting'],
                'kind'=>['type'=>'string','enum'=>['menu','recipe']],
                'choice'=>['type'=>'integer','enum'=>[1,2,3]],
            ],'required'=>['action'],'additionalProperties'=>false],
            'allowedArguments'=>['action','subject','kind','choice'],'impact'=>'routine',
            'authorize'=>static fn(array $actor,array $args):bool=>fm13b_allowed($actor,'training',false,false),
            'validate'=>static function(array $args,array $actor):array{
                $allow=['start','next','repeat','previous','pause','resume','restart','question','quiz','answer','hint','progress','review_update','acknowledge_update','start_refresh'];
                if(!in_array($args['action']??null,$allow,true))return ['action'];
                if(($args['action']??'')==='start'){
                    if(trim((string)($args['subject']??''))==='')return ['subject'];
                    if(!in_array($args['kind']??'menu',['menu','recipe'],true))return ['kind'];
                }
                return ($args['action']??'')==='answer'&&!in_array($args['choice']??null,[1,2,3],true)?['choice']:[];
            },
            'execute'=>static fn(array $args,array $actor):array=>fm8d6_step(app_pdo(),$actor,$context,$args),
        ];
    }
    return $tools;
}
