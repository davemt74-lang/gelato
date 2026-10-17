<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13b-knowledge.php';
require_once __DIR__.'/food-monster-person-conversations.php';
require_once __DIR__.'/food-monster-g13d-dialogue.php';

/** Deliberate training utterances only; never hijack KDS next-ticket controls. */
function fm13c_command(string $message):?array
{
    $s=trim(mb_strtolower(preg_replace('/\s+/u',' ',$message)??$message,'UTF-8'));
    $s=rtrim($s," .!?");
    $actions=[
        'next'=>'^(?:next step|training next step|continue to (?:the )?next step)$',
        'repeat'=>'^(?:repeat (?:that |the |current )?step|say (?:that |the |current )?step again)$',
        'previous'=>'^(?:previous step|go back (?:one |a )?step)$',
        'pause'=>'^(?:pause training|stop training|end training)$',
        'resume'=>'^(?:resume training|continue training|where were we in training)$',
        'restart'=>'^(?:restart training|start training over)$',
        'question'=>'^(?:why (?:this|that) step|explain (?:this|that|the current) step|what does (?:this|that) step mean|what temperature for this step)
    ];
    foreach($actions as $action=>$regex)
        if(preg_match('/'.$regex.'/u',$s))return ['action'=>$action,'subject'=>''];
    if(preg_match('/^(?:training answer|answer|my answer)(?: is)? ([1-3])$/u',$s,$m))
        return ['action'=>'answer','choice'=>(int)$m[1],'subject'=>''];
    // Require a deliberate training request; do not hijack "show me sales".
    $starts=[
        '/^(?:start|begin)\s+(?:a\s+|the\s+)?(?:training|practice)\s+(?:on|for|about)\s+(.{2,160})$/u',
        '/^train me\s+(?:on|about)\s+(.{2,160})$/u',
        '/^(?:teach me|show me|walk me through|guide me through)\s+how to\s+(?:make|prepare|cook|bake|assemble)\s+(.{2,160})$/u',
        '/^(?:walk me through|guide me through)\s+(?:the\s+)?(?:training|preparation|recipe)\s+(?:for|on|of)\s+(.{2,160})$/u',
    ];
    foreach($starts as $expression){
        if(!preg_match($expression,$s,$m))continue;
        $subject=trim(preg_replace('/^(?:(?:the|a|how to|make|prepare|recipe for)\s+)+/u','',$m[1])??$m[1]);
        if($subject!=='')return ['action'=>'start','subject'=>$subject];
    }
    return null;
}

/** Use only actual recorded preparation/instruction text. */
function fm13c_steps(array $source,string $kind):array
{
    $parts=[];
    if($kind==='menu'){
        $raw=trim((string)($source['preparationNotes']??''));
        if($raw==='')return [];
        $parts=preg_split('/\r?\n+|(?<=[.!?])\s+(?=[\p{Lu}\d])/u',$raw)?:[];
    }elseif($kind==='recipe'){
        $raw=json_decode((string)($source['instructions_json']??'[]'),true);
        if(!is_array($raw))return [];
        foreach($raw as $step)
            $parts[]=is_array($step)?(string)($step['text']??''):(is_string($step)?$step:'');
    }
    $out=[];
    foreach($parts as $part){
        $part=trim(preg_replace('/^\s*(?:step\s*)?\d{1,2}[.):\-]\s*/iu','',(string)$part)??(string)$part);
        $part=trim(preg_replace('/\s+/u',' ',$part)??$part);
        if($part!=='')$out[]=mb_substr($part,0,450,'UTF-8');
        if(count($out)>=16)break;
    }
    return $out;
}
function fm13c_material(array $source,string $kind):?array
{
    if(!in_array($kind,['menu','recipe'],true))return null;
    if($kind==='recipe'&&($source['status']??'')!=='active')return null;
    $id=$kind==='menu'?(string)($source['id']??''):(string)($source['public_id']??'');
    $name=trim((string)($source['name']??''));
    $steps=fm13c_steps($source,$kind);
    if($id===''||$name===''||!$steps)return null;
    return ['kind'=>$kind,'id'=>$id,'name'=>mb_substr($name,0,120,'UTF-8'),
        'steps'=>$steps,
        'notes'=>mb_substr(trim((string)($kind==='menu'
            ?($source['sectionNote']??''):($source['notes']??''))),0,1200,'UTF-8'),
        'digest'=>hash('sha256',json_encode([$kind,$id,$steps],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}
function fm13c_answer(array $state,array $material,string $note=''):string
{
    $count=count($material['steps']);
    if($count===0)return 'The recorded procedure has no training steps. Ask a manager to update it.';
    $index=max(0,min($count-1,(int)($state['index']??0)));
    return ($note!==''?$note.' ':'').$material['name'].', step '.($index+1).' of '.$count.
        ': '.$material['steps'][$index].' Say "next step", "repeat step", "previous step", or "pause training".';
}

/**
 * Pure state transition, independently testable. Every caller owns its own
 * canonical person thread. A repeated saved message ID NEVER advances twice.
 */
function fm13c_transition(?array $prior,array $command,?array $material,string $turnId,int $now):array
{
    $action=(string)($command['action']??'');
    $no=['changed'=>false,'state'=>$prior,'answer'=>'No active guided training is available. Say "start training on" followed by the menu item.'];
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId)||$now<=0)return $no;
    if($prior&&($prior['lastTurnId']??'')===$turnId)
        return ['changed'=>false,'state'=>$prior,'answer'=>(string)($prior['lastAnswer']??'That step has already been handled.')];
    if($action==='start'){
        if(!$material)return ['changed'=>false,'state'=>$prior,'answer'=>'I could not find a single active procedure with recorded steps. Please name the exact item or ask a manager to record its preparation steps.'];
        $state=['status'=>'active','kind'=>$material['kind'],'id'=>$material['id'],
            'digest'=>$material['digest'],'index'=>0,'stepCount'=>count($material['steps']),
            'practiceAttempts'=>0,'practiceCorrect'=>0,'quiz'=>null];
        $answer=fm13c_answer($state,$material,'Training started.');
    }else{
        if(!$prior||!in_array($action,['next','repeat','previous','pause','resume','restart','question',
            'quiz','answer','hint','progress'],true))
            return $no;
        $state=$prior;
        if(!$material||$material['kind']!==($prior['kind']??'')||$material['id']!==($prior['id']??'')
          ||$material['digest']!==($prior['digest']??'')){
            $state['status']='paused';
            $answer='The recorded procedure was changed or is no longer available. I paused training. Ask a manager to verify it, then start training again.';
        }elseif($action==='pause'){
            $state['quiz']=null;
            $state['status']='paused';
            $answer='Training paused for this participant. Say "resume training" to pick up at the same step.';
        }elseif($action==='restart'){
            $state['quiz']=null;
            $state['status']='active';$state['index']=0;
            $answer=fm13c_answer($state,$material,'Training restarted.');
        }elseif($action==='resume'){
            if(($state['status']??'')==='completed'){
                return ['changed'=>false,'state'=>$prior,'answer'=>'This procedure is completed. Say "restart training" to practice it again.'];
            }
            $state['status']='active';$state['quiz']=null;
            $answer=fm13c_answer($state,$material,'Welcome back.');
        }elseif($action==='progress'){
            $answer=fm13d_progress($state,$material);
        }elseif(($state['status']??'')!=='active'){
            return ['changed'=>false,'state'=>$prior,'answer'=>
                'This training is paused or completed. Say "resume training" or "restart training".'];
        }elseif($action==='quiz'){
            $prompt=fm13d_quiz_question($state,$material);
            if(!$prompt){
                $answer='This procedure needs at least two distinct recorded steps for a multiple-choice practice check. You can repeat the current step.';
                $state['quiz']=null;
            }else{
                $state['quiz']=['correct'=>$prompt['correct'],'stepIndex'=>(int)$state['index'],
                    'digest'=>$material['digest'],'optionCount'=>$prompt['optionCount'],'tries'=>0];
                $answer=$prompt['answer'];
            }
        }elseif($action==='answer'){
            $quiz=is_array($state['quiz']??null)?$state['quiz']:null;
            if(!$quiz||($quiz['digest']??'')!==$material['digest']
                ||(int)($quiz['stepIndex']??-1)!==(int)$state['index']){
                $state['quiz']=null;
                $answer='No current practice question is open. Say "quiz me" to start one.';
            }else{
                $choice=(int)($command['choice']??0);
                $max=(int)($quiz['optionCount']??0);
                if($choice<1||$choice>$max){
                    $answer='Please select one of the displayed practice options.';
                }else{
                    $state['practiceAttempts']=min(100000,(int)($state['practiceAttempts']??0)+1);
                    $quiz['tries']=(int)($quiz['tries']??0)+1;
                    if($choice===(int)$quiz['correct']){
                        $state['practiceCorrect']=min(100000,(int)($state['practiceCorrect']??0)+1);
                        $state['quiz']=null;
                        $answer='That matches the recorded instruction for this step. Good practice—not an assessment or certification. '.fm13c_answer($state,$material);
                    }elseif($quiz['tries']>=2){
                        $state['quiz']=null;
                        $answer='Not quite. The recorded instruction is: '.$material['steps'][(int)$state['index']].
                            '. This question is closed. Say "quiz me" to practice again.';
                    }else{
                        $state['quiz']=$quiz;
                        $answer='Not quite. Review the current recorded step, then try another displayed option or say "training hint".';
                    }
                }
            }
        }elseif($action==='hint'){
            $answer='Practice hint: '.fm13d_explanation($state,$material);
        }elseif($action==='next'){
            $state['quiz']=null;
            if((int)$state['index']+1>=count($material['steps'])){
                $state['status']='completed';
                $answer='You have reached the end of the recorded '.$material['name'].' procedure. This is not a skills assessment or certification. Say "restart training" to review it.';
            }else{
                $state['index']++;
                $answer=fm13c_answer($state,$material);
            }
        }elseif($action==='previous'){
            $state['quiz']=null;
            $state['index']=max(0,(int)$state['index']-1);
            $answer=fm13c_answer($state,$material);
        }elseif($action==='repeat'){
            $answer=fm13c_answer($state,$material);
        }else{
            $answer=fm13d_explanation($state,$material);
        }
    }
    $state['lastTurnId']=$turnId;$state['updatedAt']=$now;
    $state['lastAnswer']=mb_substr($answer,0,1100,'UTF-8');
    return ['changed'=>true,'state'=>$state,'answer'=>$answer];
}

/** Tenant+person-owned Agent workspace training ledger, no parallel database. */
function fm13c_training_thread(PDO $pdo,int $org,int $personId,bool $create):?string
{
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=?
        AND title='Food Monster Guided Training' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId]);$found=(string)($q->fetchColumn()?:'');
    if($found!=='')return $found;
    if(!$create)return null;
    return (string)gaw_create_thread($pdo,$org,$personId,'text','Food Monster Guided Training')['public_id'];
}
function fm13c_read_state(PDO $pdo,int $org,int $personId,string $thread):?array
{
    $latest=gaw_messages($pdo,$org,$personId,$thread,1);
    $row=$latest[0]??[];
    $metadata=(array)($row['structured']??[]);
    if(($row['role']??'')!=='system'||($metadata['collection']??'')!=='fm13c_guided_training')return null;
    $state=$metadata['state']??null;
    return is_array($state)?$state:null;
}
/** Source freshness is revalidated on every step and every resume. */
function fm13c_fetch_material(PDO $pdo,int $org,array $state):?array
{
    $kind=(string)($state['kind']??'');
    if($kind==='menu'){
        $id=filter_var($state['id']??'',FILTER_VALIDATE_INT);
        return $id?fm13c_material(menu_training_item($pdo,$org,$id)??[],'menu'):null;
    }
    if($kind==='recipe'){
        $id=(string)($state['id']??'');
        if(!preg_match('/^[a-zA-Z0-9_.:-]{3,100}$/D',$id))return null;
        return fm13c_material(restaurant_brain_recipe_by_public_id($pdo,$org,$id)??[],'recipe');
    }
    return null;
}
,
        'quiz'=>'^(?:quiz me|check my knowledge|training quiz|practice question)
    ];
    foreach($actions as $action=>$regex)
        if(preg_match('/'.$regex.'/u',$s))return ['action'=>$action,'subject'=>''];
    // Require a deliberate training request; do not hijack "show me sales".
    $starts=[
        '/^(?:start|begin)\s+(?:a\s+|the\s+)?(?:training|practice)\s+(?:on|for|about)\s+(.{2,160})$/u',
        '/^train me\s+(?:on|about)\s+(.{2,160})$/u',
        '/^(?:teach me|show me|walk me through|guide me through)\s+how to\s+(?:make|prepare|cook|bake|assemble)\s+(.{2,160})$/u',
        '/^(?:walk me through|guide me through)\s+(?:the\s+)?(?:training|preparation|recipe)\s+(?:for|on|of)\s+(.{2,160})$/u',
    ];
    foreach($starts as $expression){
        if(!preg_match($expression,$s,$m))continue;
        $subject=trim(preg_replace('/^(?:(?:the|a|how to|make|prepare|recipe for)\s+)+/u','',$m[1])??$m[1]);
        if($subject!=='')return ['action'=>'start','subject'=>$subject];
    }
    return null;
}

/** Use only actual recorded preparation/instruction text. */
function fm13c_steps(array $source,string $kind):array
{
    $parts=[];
    if($kind==='menu'){
        $raw=trim((string)($source['preparationNotes']??''));
        if($raw==='')return [];
        $parts=preg_split('/\r?\n+|(?<=[.!?])\s+(?=[\p{Lu}\d])/u',$raw)?:[];
    }elseif($kind==='recipe'){
        $raw=json_decode((string)($source['instructions_json']??'[]'),true);
        if(!is_array($raw))return [];
        foreach($raw as $step)
            $parts[]=is_array($step)?(string)($step['text']??''):(is_string($step)?$step:'');
    }
    $out=[];
    foreach($parts as $part){
        $part=trim(preg_replace('/^\s*(?:step\s*)?\d{1,2}[.):\-]\s*/iu','',(string)$part)??(string)$part);
        $part=trim(preg_replace('/\s+/u',' ',$part)??$part);
        if($part!=='')$out[]=mb_substr($part,0,450,'UTF-8');
        if(count($out)>=16)break;
    }
    return $out;
}
function fm13c_material(array $source,string $kind):?array
{
    if(!in_array($kind,['menu','recipe'],true))return null;
    if($kind==='recipe'&&($source['status']??'')!=='active')return null;
    $id=$kind==='menu'?(string)($source['id']??''):(string)($source['public_id']??'');
    $name=trim((string)($source['name']??''));
    $steps=fm13c_steps($source,$kind);
    if($id===''||$name===''||!$steps)return null;
    return ['kind'=>$kind,'id'=>$id,'name'=>mb_substr($name,0,120,'UTF-8'),
        'steps'=>$steps,'digest'=>hash('sha256',json_encode([$kind,$id,$steps],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}
function fm13c_answer(array $state,array $material,string $note=''):string
{
    $count=count($material['steps']);
    if($count===0)return 'The recorded procedure has no training steps. Ask a manager to update it.';
    $index=max(0,min($count-1,(int)($state['index']??0)));
    return ($note!==''?$note.' ':'').$material['name'].', step '.($index+1).' of '.$count.
        ': '.$material['steps'][$index].' Say "next step", "repeat step", "previous step", or "pause training".';
}

/**
 * Pure state transition, independently testable. Every caller owns its own
 * canonical person thread. A repeated saved message ID NEVER advances twice.
 */
function fm13c_transition(?array $prior,array $command,?array $material,string $turnId,int $now):array
{
    $action=(string)($command['action']??'');
    $no=['changed'=>false,'state'=>$prior,'answer'=>'No active guided training is available. Say "start training on" followed by the menu item.'];
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId)||$now<=0)return $no;
    if($prior&&($prior['lastTurnId']??'')===$turnId)
        return ['changed'=>false,'state'=>$prior,'answer'=>(string)($prior['lastAnswer']??'That step has already been handled.')];
    if($action==='start'){
        if(!$material)return ['changed'=>false,'state'=>$prior,'answer'=>'I could not find a single active procedure with recorded steps. Please name the exact item or ask a manager to record its preparation steps.'];
        $state=['status'=>'active','kind'=>$material['kind'],'id'=>$material['id'],
            'digest'=>$material['digest'],'index'=>0,'stepCount'=>count($material['steps'])];
        $answer=fm13c_answer($state,$material,'Training started.');
    }else{
        if(!$prior||!in_array($action,['next','repeat','previous','pause','resume','restart','question'],true))
            return $no;
        $state=$prior;
        if(!$material||$material['kind']!==($prior['kind']??'')||$material['id']!==($prior['id']??'')
          ||$material['digest']!==($prior['digest']??'')){
            $state['status']='paused';
            $answer='The recorded procedure was changed or is no longer available. I paused training. Ask a manager to verify it, then start training again.';
        }elseif($action==='pause'){
            $state['status']='paused';
            $answer='Training paused for this participant. Say "resume training" to pick up at the same step.';
        }elseif($action==='restart'){
            $state['status']='active';$state['index']=0;
            $answer=fm13c_answer($state,$material,'Training restarted.');
        }elseif($action==='resume'){
            if(($state['status']??'')==='completed'){
                return ['changed'=>false,'state'=>$prior,'answer'=>'This procedure is completed. Say "restart training" to practice it again.'];
            }
            $state['status']='active';
            $answer=fm13c_answer($state,$material,'Welcome back.');
        }elseif(($state['status']??'')!=='active'){
            return ['changed'=>false,'state'=>$prior,'answer'=>
                'This training is paused or completed. Say "resume training" or "restart training".'];
        }elseif($action==='next'){
            if((int)$state['index']+1>=count($material['steps'])){
                $state['status']='completed';
                $answer='You have reached the end of the recorded '.$material['name'].' procedure. This is not a skills assessment or certification. Say "restart training" to review it.';
            }else{
                $state['index']++;
                $answer=fm13c_answer($state,$material);
            }
        }elseif($action==='previous'){
            $state['index']=max(0,(int)$state['index']-1);
            $answer=fm13c_answer($state,$material);
        }elseif($action==='repeat'){
            $answer=fm13c_answer($state,$material);
        }else{
            $answer=fm13c_answer($state,$material,
                'The saved instruction does not establish additional reasons or safety details. Ask the kitchen manager if you need an explanation.');
        }
    }
    $state['lastTurnId']=$turnId;$state['updatedAt']=$now;
    $state['lastAnswer']=mb_substr($answer,0,1100,'UTF-8');
    return ['changed'=>true,'state'=>$state,'answer'=>$answer];
}

/** Tenant+person-owned Agent workspace training ledger, no parallel database. */
function fm13c_training_thread(PDO $pdo,int $org,int $personId,bool $create):?string
{
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=?
        AND title='Food Monster Guided Training' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId]);$found=(string)($q->fetchColumn()?:'');
    if($found!=='')return $found;
    if(!$create)return null;
    return (string)gaw_create_thread($pdo,$org,$personId,'text','Food Monster Guided Training')['public_id'];
}
function fm13c_read_state(PDO $pdo,int $org,int $personId,string $thread):?array
{
    $latest=gaw_messages($pdo,$org,$personId,$thread,1);
    $row=$latest[0]??[];
    $metadata=(array)($row['structured']??[]);
    if(($row['role']??'')!=='system'||($metadata['collection']??'')!=='fm13c_guided_training')return null;
    $state=$metadata['state']??null;
    return is_array($state)?$state:null;
}
/** Source freshness is revalidated on every step and every resume. */
function fm13c_fetch_material(PDO $pdo,int $org,array $state):?array
{
    $kind=(string)($state['kind']??'');
    if($kind==='menu'){
        $id=filter_var($state['id']??'',FILTER_VALIDATE_INT);
        return $id?fm13c_material(menu_training_item($pdo,$org,$id)??[],'menu'):null;
    }
    if($kind==='recipe'){
        $id=(string)($state['id']??'');
        if(!preg_match('/^[a-zA-Z0-9_.:-]{3,100}$/D',$id))return null;
        return fm13c_material(restaurant_brain_recipe_by_public_id($pdo,$org,$id)??[],'recipe');
    }
    return null;
}
,
        'hint'=>'^(?:training hint|hint for (?:this|the current) step|give me a training hint)
    ];
    foreach($actions as $action=>$regex)
        if(preg_match('/'.$regex.'/u',$s))return ['action'=>$action,'subject'=>''];
    // Require a deliberate training request; do not hijack "show me sales".
    $starts=[
        '/^(?:start|begin)\s+(?:a\s+|the\s+)?(?:training|practice)\s+(?:on|for|about)\s+(.{2,160})$/u',
        '/^train me\s+(?:on|about)\s+(.{2,160})$/u',
        '/^(?:teach me|show me|walk me through|guide me through)\s+how to\s+(?:make|prepare|cook|bake|assemble)\s+(.{2,160})$/u',
        '/^(?:walk me through|guide me through)\s+(?:the\s+)?(?:training|preparation|recipe)\s+(?:for|on|of)\s+(.{2,160})$/u',
    ];
    foreach($starts as $expression){
        if(!preg_match($expression,$s,$m))continue;
        $subject=trim(preg_replace('/^(?:(?:the|a|how to|make|prepare|recipe for)\s+)+/u','',$m[1])??$m[1]);
        if($subject!=='')return ['action'=>'start','subject'=>$subject];
    }
    return null;
}

/** Use only actual recorded preparation/instruction text. */
function fm13c_steps(array $source,string $kind):array
{
    $parts=[];
    if($kind==='menu'){
        $raw=trim((string)($source['preparationNotes']??''));
        if($raw==='')return [];
        $parts=preg_split('/\r?\n+|(?<=[.!?])\s+(?=[\p{Lu}\d])/u',$raw)?:[];
    }elseif($kind==='recipe'){
        $raw=json_decode((string)($source['instructions_json']??'[]'),true);
        if(!is_array($raw))return [];
        foreach($raw as $step)
            $parts[]=is_array($step)?(string)($step['text']??''):(is_string($step)?$step:'');
    }
    $out=[];
    foreach($parts as $part){
        $part=trim(preg_replace('/^\s*(?:step\s*)?\d{1,2}[.):\-]\s*/iu','',(string)$part)??(string)$part);
        $part=trim(preg_replace('/\s+/u',' ',$part)??$part);
        if($part!=='')$out[]=mb_substr($part,0,450,'UTF-8');
        if(count($out)>=16)break;
    }
    return $out;
}
function fm13c_material(array $source,string $kind):?array
{
    if(!in_array($kind,['menu','recipe'],true))return null;
    if($kind==='recipe'&&($source['status']??'')!=='active')return null;
    $id=$kind==='menu'?(string)($source['id']??''):(string)($source['public_id']??'');
    $name=trim((string)($source['name']??''));
    $steps=fm13c_steps($source,$kind);
    if($id===''||$name===''||!$steps)return null;
    return ['kind'=>$kind,'id'=>$id,'name'=>mb_substr($name,0,120,'UTF-8'),
        'steps'=>$steps,'digest'=>hash('sha256',json_encode([$kind,$id,$steps],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}
function fm13c_answer(array $state,array $material,string $note=''):string
{
    $count=count($material['steps']);
    if($count===0)return 'The recorded procedure has no training steps. Ask a manager to update it.';
    $index=max(0,min($count-1,(int)($state['index']??0)));
    return ($note!==''?$note.' ':'').$material['name'].', step '.($index+1).' of '.$count.
        ': '.$material['steps'][$index].' Say "next step", "repeat step", "previous step", or "pause training".';
}

/**
 * Pure state transition, independently testable. Every caller owns its own
 * canonical person thread. A repeated saved message ID NEVER advances twice.
 */
function fm13c_transition(?array $prior,array $command,?array $material,string $turnId,int $now):array
{
    $action=(string)($command['action']??'');
    $no=['changed'=>false,'state'=>$prior,'answer'=>'No active guided training is available. Say "start training on" followed by the menu item.'];
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId)||$now<=0)return $no;
    if($prior&&($prior['lastTurnId']??'')===$turnId)
        return ['changed'=>false,'state'=>$prior,'answer'=>(string)($prior['lastAnswer']??'That step has already been handled.')];
    if($action==='start'){
        if(!$material)return ['changed'=>false,'state'=>$prior,'answer'=>'I could not find a single active procedure with recorded steps. Please name the exact item or ask a manager to record its preparation steps.'];
        $state=['status'=>'active','kind'=>$material['kind'],'id'=>$material['id'],
            'digest'=>$material['digest'],'index'=>0,'stepCount'=>count($material['steps'])];
        $answer=fm13c_answer($state,$material,'Training started.');
    }else{
        if(!$prior||!in_array($action,['next','repeat','previous','pause','resume','restart','question'],true))
            return $no;
        $state=$prior;
        if(!$material||$material['kind']!==($prior['kind']??'')||$material['id']!==($prior['id']??'')
          ||$material['digest']!==($prior['digest']??'')){
            $state['status']='paused';
            $answer='The recorded procedure was changed or is no longer available. I paused training. Ask a manager to verify it, then start training again.';
        }elseif($action==='pause'){
            $state['status']='paused';
            $answer='Training paused for this participant. Say "resume training" to pick up at the same step.';
        }elseif($action==='restart'){
            $state['status']='active';$state['index']=0;
            $answer=fm13c_answer($state,$material,'Training restarted.');
        }elseif($action==='resume'){
            if(($state['status']??'')==='completed'){
                return ['changed'=>false,'state'=>$prior,'answer'=>'This procedure is completed. Say "restart training" to practice it again.'];
            }
            $state['status']='active';
            $answer=fm13c_answer($state,$material,'Welcome back.');
        }elseif(($state['status']??'')!=='active'){
            return ['changed'=>false,'state'=>$prior,'answer'=>
                'This training is paused or completed. Say "resume training" or "restart training".'];
        }elseif($action==='next'){
            if((int)$state['index']+1>=count($material['steps'])){
                $state['status']='completed';
                $answer='You have reached the end of the recorded '.$material['name'].' procedure. This is not a skills assessment or certification. Say "restart training" to review it.';
            }else{
                $state['index']++;
                $answer=fm13c_answer($state,$material);
            }
        }elseif($action==='previous'){
            $state['index']=max(0,(int)$state['index']-1);
            $answer=fm13c_answer($state,$material);
        }elseif($action==='repeat'){
            $answer=fm13c_answer($state,$material);
        }else{
            $answer=fm13c_answer($state,$material,
                'The saved instruction does not establish additional reasons or safety details. Ask the kitchen manager if you need an explanation.');
        }
    }
    $state['lastTurnId']=$turnId;$state['updatedAt']=$now;
    $state['lastAnswer']=mb_substr($answer,0,1100,'UTF-8');
    return ['changed'=>true,'state'=>$state,'answer'=>$answer];
}

/** Tenant+person-owned Agent workspace training ledger, no parallel database. */
function fm13c_training_thread(PDO $pdo,int $org,int $personId,bool $create):?string
{
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=?
        AND title='Food Monster Guided Training' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId]);$found=(string)($q->fetchColumn()?:'');
    if($found!=='')return $found;
    if(!$create)return null;
    return (string)gaw_create_thread($pdo,$org,$personId,'text','Food Monster Guided Training')['public_id'];
}
function fm13c_read_state(PDO $pdo,int $org,int $personId,string $thread):?array
{
    $latest=gaw_messages($pdo,$org,$personId,$thread,1);
    $row=$latest[0]??[];
    $metadata=(array)($row['structured']??[]);
    if(($row['role']??'')!=='system'||($metadata['collection']??'')!=='fm13c_guided_training')return null;
    $state=$metadata['state']??null;
    return is_array($state)?$state:null;
}
/** Source freshness is revalidated on every step and every resume. */
function fm13c_fetch_material(PDO $pdo,int $org,array $state):?array
{
    $kind=(string)($state['kind']??'');
    if($kind==='menu'){
        $id=filter_var($state['id']??'',FILTER_VALIDATE_INT);
        return $id?fm13c_material(menu_training_item($pdo,$org,$id)??[],'menu'):null;
    }
    if($kind==='recipe'){
        $id=(string)($state['id']??'');
        if(!preg_match('/^[a-zA-Z0-9_.:-]{3,100}$/D',$id))return null;
        return fm13c_material(restaurant_brain_recipe_by_public_id($pdo,$org,$id)??[],'recipe');
    }
    return null;
}
,
        'progress'=>'^(?:training progress|my training progress|training summary|summarize my training)
    ];
    foreach($actions as $action=>$regex)
        if(preg_match('/'.$regex.'/u',$s))return ['action'=>$action,'subject'=>''];
    // Require a deliberate training request; do not hijack "show me sales".
    $starts=[
        '/^(?:start|begin)\s+(?:a\s+|the\s+)?(?:training|practice)\s+(?:on|for|about)\s+(.{2,160})$/u',
        '/^train me\s+(?:on|about)\s+(.{2,160})$/u',
        '/^(?:teach me|show me|walk me through|guide me through)\s+how to\s+(?:make|prepare|cook|bake|assemble)\s+(.{2,160})$/u',
        '/^(?:walk me through|guide me through)\s+(?:the\s+)?(?:training|preparation|recipe)\s+(?:for|on|of)\s+(.{2,160})$/u',
    ];
    foreach($starts as $expression){
        if(!preg_match($expression,$s,$m))continue;
        $subject=trim(preg_replace('/^(?:(?:the|a|how to|make|prepare|recipe for)\s+)+/u','',$m[1])??$m[1]);
        if($subject!=='')return ['action'=>'start','subject'=>$subject];
    }
    return null;
}

/** Use only actual recorded preparation/instruction text. */
function fm13c_steps(array $source,string $kind):array
{
    $parts=[];
    if($kind==='menu'){
        $raw=trim((string)($source['preparationNotes']??''));
        if($raw==='')return [];
        $parts=preg_split('/\r?\n+|(?<=[.!?])\s+(?=[\p{Lu}\d])/u',$raw)?:[];
    }elseif($kind==='recipe'){
        $raw=json_decode((string)($source['instructions_json']??'[]'),true);
        if(!is_array($raw))return [];
        foreach($raw as $step)
            $parts[]=is_array($step)?(string)($step['text']??''):(is_string($step)?$step:'');
    }
    $out=[];
    foreach($parts as $part){
        $part=trim(preg_replace('/^\s*(?:step\s*)?\d{1,2}[.):\-]\s*/iu','',(string)$part)??(string)$part);
        $part=trim(preg_replace('/\s+/u',' ',$part)??$part);
        if($part!=='')$out[]=mb_substr($part,0,450,'UTF-8');
        if(count($out)>=16)break;
    }
    return $out;
}
function fm13c_material(array $source,string $kind):?array
{
    if(!in_array($kind,['menu','recipe'],true))return null;
    if($kind==='recipe'&&($source['status']??'')!=='active')return null;
    $id=$kind==='menu'?(string)($source['id']??''):(string)($source['public_id']??'');
    $name=trim((string)($source['name']??''));
    $steps=fm13c_steps($source,$kind);
    if($id===''||$name===''||!$steps)return null;
    return ['kind'=>$kind,'id'=>$id,'name'=>mb_substr($name,0,120,'UTF-8'),
        'steps'=>$steps,'digest'=>hash('sha256',json_encode([$kind,$id,$steps],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}
function fm13c_answer(array $state,array $material,string $note=''):string
{
    $count=count($material['steps']);
    if($count===0)return 'The recorded procedure has no training steps. Ask a manager to update it.';
    $index=max(0,min($count-1,(int)($state['index']??0)));
    return ($note!==''?$note.' ':'').$material['name'].', step '.($index+1).' of '.$count.
        ': '.$material['steps'][$index].' Say "next step", "repeat step", "previous step", or "pause training".';
}

/**
 * Pure state transition, independently testable. Every caller owns its own
 * canonical person thread. A repeated saved message ID NEVER advances twice.
 */
function fm13c_transition(?array $prior,array $command,?array $material,string $turnId,int $now):array
{
    $action=(string)($command['action']??'');
    $no=['changed'=>false,'state'=>$prior,'answer'=>'No active guided training is available. Say "start training on" followed by the menu item.'];
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId)||$now<=0)return $no;
    if($prior&&($prior['lastTurnId']??'')===$turnId)
        return ['changed'=>false,'state'=>$prior,'answer'=>(string)($prior['lastAnswer']??'That step has already been handled.')];
    if($action==='start'){
        if(!$material)return ['changed'=>false,'state'=>$prior,'answer'=>'I could not find a single active procedure with recorded steps. Please name the exact item or ask a manager to record its preparation steps.'];
        $state=['status'=>'active','kind'=>$material['kind'],'id'=>$material['id'],
            'digest'=>$material['digest'],'index'=>0,'stepCount'=>count($material['steps'])];
        $answer=fm13c_answer($state,$material,'Training started.');
    }else{
        if(!$prior||!in_array($action,['next','repeat','previous','pause','resume','restart','question'],true))
            return $no;
        $state=$prior;
        if(!$material||$material['kind']!==($prior['kind']??'')||$material['id']!==($prior['id']??'')
          ||$material['digest']!==($prior['digest']??'')){
            $state['status']='paused';
            $answer='The recorded procedure was changed or is no longer available. I paused training. Ask a manager to verify it, then start training again.';
        }elseif($action==='pause'){
            $state['status']='paused';
            $answer='Training paused for this participant. Say "resume training" to pick up at the same step.';
        }elseif($action==='restart'){
            $state['status']='active';$state['index']=0;
            $answer=fm13c_answer($state,$material,'Training restarted.');
        }elseif($action==='resume'){
            if(($state['status']??'')==='completed'){
                return ['changed'=>false,'state'=>$prior,'answer'=>'This procedure is completed. Say "restart training" to practice it again.'];
            }
            $state['status']='active';
            $answer=fm13c_answer($state,$material,'Welcome back.');
        }elseif(($state['status']??'')!=='active'){
            return ['changed'=>false,'state'=>$prior,'answer'=>
                'This training is paused or completed. Say "resume training" or "restart training".'];
        }elseif($action==='next'){
            if((int)$state['index']+1>=count($material['steps'])){
                $state['status']='completed';
                $answer='You have reached the end of the recorded '.$material['name'].' procedure. This is not a skills assessment or certification. Say "restart training" to review it.';
            }else{
                $state['index']++;
                $answer=fm13c_answer($state,$material);
            }
        }elseif($action==='previous'){
            $state['index']=max(0,(int)$state['index']-1);
            $answer=fm13c_answer($state,$material);
        }elseif($action==='repeat'){
            $answer=fm13c_answer($state,$material);
        }else{
            $answer=fm13c_answer($state,$material,
                'The saved instruction does not establish additional reasons or safety details. Ask the kitchen manager if you need an explanation.');
        }
    }
    $state['lastTurnId']=$turnId;$state['updatedAt']=$now;
    $state['lastAnswer']=mb_substr($answer,0,1100,'UTF-8');
    return ['changed'=>true,'state'=>$state,'answer'=>$answer];
}

/** Tenant+person-owned Agent workspace training ledger, no parallel database. */
function fm13c_training_thread(PDO $pdo,int $org,int $personId,bool $create):?string
{
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=?
        AND title='Food Monster Guided Training' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId]);$found=(string)($q->fetchColumn()?:'');
    if($found!=='')return $found;
    if(!$create)return null;
    return (string)gaw_create_thread($pdo,$org,$personId,'text','Food Monster Guided Training')['public_id'];
}
function fm13c_read_state(PDO $pdo,int $org,int $personId,string $thread):?array
{
    $latest=gaw_messages($pdo,$org,$personId,$thread,1);
    $row=$latest[0]??[];
    $metadata=(array)($row['structured']??[]);
    if(($row['role']??'')!=='system'||($metadata['collection']??'')!=='fm13c_guided_training')return null;
    $state=$metadata['state']??null;
    return is_array($state)?$state:null;
}
/** Source freshness is revalidated on every step and every resume. */
function fm13c_fetch_material(PDO $pdo,int $org,array $state):?array
{
    $kind=(string)($state['kind']??'');
    if($kind==='menu'){
        $id=filter_var($state['id']??'',FILTER_VALIDATE_INT);
        return $id?fm13c_material(menu_training_item($pdo,$org,$id)??[],'menu'):null;
    }
    if($kind==='recipe'){
        $id=(string)($state['id']??'');
        if(!preg_match('/^[a-zA-Z0-9_.:-]{3,100}$/D',$id))return null;
        return fm13c_material(restaurant_brain_recipe_by_public_id($pdo,$org,$id)??[],'recipe');
    }
    return null;
}
,
    ];
    foreach($actions as $action=>$regex)
        if(preg_match('/'.$regex.'/u',$s))return ['action'=>$action,'subject'=>''];
    // Require a deliberate training request; do not hijack "show me sales".
    $starts=[
        '/^(?:start|begin)\s+(?:a\s+|the\s+)?(?:training|practice)\s+(?:on|for|about)\s+(.{2,160})$/u',
        '/^train me\s+(?:on|about)\s+(.{2,160})$/u',
        '/^(?:teach me|show me|walk me through|guide me through)\s+how to\s+(?:make|prepare|cook|bake|assemble)\s+(.{2,160})$/u',
        '/^(?:walk me through|guide me through)\s+(?:the\s+)?(?:training|preparation|recipe)\s+(?:for|on|of)\s+(.{2,160})$/u',
    ];
    foreach($starts as $expression){
        if(!preg_match($expression,$s,$m))continue;
        $subject=trim(preg_replace('/^(?:(?:the|a|how to|make|prepare|recipe for)\s+)+/u','',$m[1])??$m[1]);
        if($subject!=='')return ['action'=>'start','subject'=>$subject];
    }
    return null;
}

/** Use only actual recorded preparation/instruction text. */
function fm13c_steps(array $source,string $kind):array
{
    $parts=[];
    if($kind==='menu'){
        $raw=trim((string)($source['preparationNotes']??''));
        if($raw==='')return [];
        $parts=preg_split('/\r?\n+|(?<=[.!?])\s+(?=[\p{Lu}\d])/u',$raw)?:[];
    }elseif($kind==='recipe'){
        $raw=json_decode((string)($source['instructions_json']??'[]'),true);
        if(!is_array($raw))return [];
        foreach($raw as $step)
            $parts[]=is_array($step)?(string)($step['text']??''):(is_string($step)?$step:'');
    }
    $out=[];
    foreach($parts as $part){
        $part=trim(preg_replace('/^\s*(?:step\s*)?\d{1,2}[.):\-]\s*/iu','',(string)$part)??(string)$part);
        $part=trim(preg_replace('/\s+/u',' ',$part)??$part);
        if($part!=='')$out[]=mb_substr($part,0,450,'UTF-8');
        if(count($out)>=16)break;
    }
    return $out;
}
function fm13c_material(array $source,string $kind):?array
{
    if(!in_array($kind,['menu','recipe'],true))return null;
    if($kind==='recipe'&&($source['status']??'')!=='active')return null;
    $id=$kind==='menu'?(string)($source['id']??''):(string)($source['public_id']??'');
    $name=trim((string)($source['name']??''));
    $steps=fm13c_steps($source,$kind);
    if($id===''||$name===''||!$steps)return null;
    return ['kind'=>$kind,'id'=>$id,'name'=>mb_substr($name,0,120,'UTF-8'),
        'steps'=>$steps,'digest'=>hash('sha256',json_encode([$kind,$id,$steps],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}
function fm13c_answer(array $state,array $material,string $note=''):string
{
    $count=count($material['steps']);
    if($count===0)return 'The recorded procedure has no training steps. Ask a manager to update it.';
    $index=max(0,min($count-1,(int)($state['index']??0)));
    return ($note!==''?$note.' ':'').$material['name'].', step '.($index+1).' of '.$count.
        ': '.$material['steps'][$index].' Say "next step", "repeat step", "previous step", or "pause training".';
}

/**
 * Pure state transition, independently testable. Every caller owns its own
 * canonical person thread. A repeated saved message ID NEVER advances twice.
 */
function fm13c_transition(?array $prior,array $command,?array $material,string $turnId,int $now):array
{
    $action=(string)($command['action']??'');
    $no=['changed'=>false,'state'=>$prior,'answer'=>'No active guided training is available. Say "start training on" followed by the menu item.'];
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId)||$now<=0)return $no;
    if($prior&&($prior['lastTurnId']??'')===$turnId)
        return ['changed'=>false,'state'=>$prior,'answer'=>(string)($prior['lastAnswer']??'That step has already been handled.')];
    if($action==='start'){
        if(!$material)return ['changed'=>false,'state'=>$prior,'answer'=>'I could not find a single active procedure with recorded steps. Please name the exact item or ask a manager to record its preparation steps.'];
        $state=['status'=>'active','kind'=>$material['kind'],'id'=>$material['id'],
            'digest'=>$material['digest'],'index'=>0,'stepCount'=>count($material['steps'])];
        $answer=fm13c_answer($state,$material,'Training started.');
    }else{
        if(!$prior||!in_array($action,['next','repeat','previous','pause','resume','restart','question'],true))
            return $no;
        $state=$prior;
        if(!$material||$material['kind']!==($prior['kind']??'')||$material['id']!==($prior['id']??'')
          ||$material['digest']!==($prior['digest']??'')){
            $state['status']='paused';
            $answer='The recorded procedure was changed or is no longer available. I paused training. Ask a manager to verify it, then start training again.';
        }elseif($action==='pause'){
            $state['status']='paused';
            $answer='Training paused for this participant. Say "resume training" to pick up at the same step.';
        }elseif($action==='restart'){
            $state['status']='active';$state['index']=0;
            $answer=fm13c_answer($state,$material,'Training restarted.');
        }elseif($action==='resume'){
            if(($state['status']??'')==='completed'){
                return ['changed'=>false,'state'=>$prior,'answer'=>'This procedure is completed. Say "restart training" to practice it again.'];
            }
            $state['status']='active';
            $answer=fm13c_answer($state,$material,'Welcome back.');
        }elseif(($state['status']??'')!=='active'){
            return ['changed'=>false,'state'=>$prior,'answer'=>
                'This training is paused or completed. Say "resume training" or "restart training".'];
        }elseif($action==='next'){
            if((int)$state['index']+1>=count($material['steps'])){
                $state['status']='completed';
                $answer='You have reached the end of the recorded '.$material['name'].' procedure. This is not a skills assessment or certification. Say "restart training" to review it.';
            }else{
                $state['index']++;
                $answer=fm13c_answer($state,$material);
            }
        }elseif($action==='previous'){
            $state['index']=max(0,(int)$state['index']-1);
            $answer=fm13c_answer($state,$material);
        }elseif($action==='repeat'){
            $answer=fm13c_answer($state,$material);
        }else{
            $answer=fm13c_answer($state,$material,
                'The saved instruction does not establish additional reasons or safety details. Ask the kitchen manager if you need an explanation.');
        }
    }
    $state['lastTurnId']=$turnId;$state['updatedAt']=$now;
    $state['lastAnswer']=mb_substr($answer,0,1100,'UTF-8');
    return ['changed'=>true,'state'=>$state,'answer'=>$answer];
}

/** Tenant+person-owned Agent workspace training ledger, no parallel database. */
function fm13c_training_thread(PDO $pdo,int $org,int $personId,bool $create):?string
{
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=?
        AND title='Food Monster Guided Training' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId]);$found=(string)($q->fetchColumn()?:'');
    if($found!=='')return $found;
    if(!$create)return null;
    return (string)gaw_create_thread($pdo,$org,$personId,'text','Food Monster Guided Training')['public_id'];
}
function fm13c_read_state(PDO $pdo,int $org,int $personId,string $thread):?array
{
    $latest=gaw_messages($pdo,$org,$personId,$thread,1);
    $row=$latest[0]??[];
    $metadata=(array)($row['structured']??[]);
    if(($row['role']??'')!=='system'||($metadata['collection']??'')!=='fm13c_guided_training')return null;
    $state=$metadata['state']??null;
    return is_array($state)?$state:null;
}
/** Source freshness is revalidated on every step and every resume. */
function fm13c_fetch_material(PDO $pdo,int $org,array $state):?array
{
    $kind=(string)($state['kind']??'');
    if($kind==='menu'){
        $id=filter_var($state['id']??'',FILTER_VALIDATE_INT);
        return $id?fm13c_material(menu_training_item($pdo,$org,$id)??[],'menu'):null;
    }
    if($kind==='recipe'){
        $id=(string)($state['id']??'');
        if(!preg_match('/^[a-zA-Z0-9_.:-]{3,100}$/D',$id))return null;
        return fm13c_material(restaurant_brain_recipe_by_public_id($pdo,$org,$id)??[],'recipe');
    }
    return null;
}
