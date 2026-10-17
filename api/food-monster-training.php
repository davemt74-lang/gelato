<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/agent-voice-conversation.php';
require_once __DIR__.'/../includes/food-monster-g13c-training.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();app_verify_request_csrf($input);
try{
    if(($input['action']??'')!=='ask')throw new InvalidArgumentException('Training supports read-only chat commands only.');
    $message=trim((string)($input['message']??''));
    $room=trim((string)($input['threadId']??($_GET['threadId']??'')));
    if($message===''||mb_strlen($message,'UTF-8')>500
       ||!preg_match('/^agent-[a-f0-9]{20}$/D',$room))
        throw new InvalidArgumentException('A valid saved chat question is required.');
    $command=fm13c_command($message);
    if(!$command)throw new InvalidArgumentException('Say start training, next step, repeat step, previous step, pause training, or resume training.');
    $latest=gaw_messages($pdo,$org,$uid,$room,1)[0]??[];
    if(($latest['role']??'')!=='user'||trim((string)($latest['content']??''))!==$message
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($latest['public_id']??'')))
        throw new DomainException('Only the latest canonical Agent Chat turn may advance training.');
    $turnId=(string)$latest['public_id'];
    $voice=($latest['channel']??'')==='voice';
    $personId=$uid;$ownerVoiceVerified=false;
    if($voice){
        $voiceEvent=(string)($latest['voice_event_public_id']??'');
        $ownerSpeaker=$voiceEvent!==''?avc_verified_speaker($pdo,$org,$user,$voiceEvent):null;
        if($ownerSpeaker&&(int)$ownerSpeaker['userId']===$uid){
            $ownerVoiceVerified=true;
        }else{
            // The owner is only a room operator: do not treat their login as
            // the participant's identity. A fresh server-mirrored voice event
            // and current active membership are required for progress access.
            if(!fm9a_is_super_admin_session($user)){
                app_json_response(['ok'=>true,'skill'=>'food_monster.training_identity',
                    'answer'=>'I cannot attribute this training turn to an enrolled participant. Please use an authenticated account or verify the speaker before saving progress.',
                    'sources'=>[],'data'=>['training'=>false]]);
            }
            $key='fm9d_pending_'.hash('sha256',$org.'|'.$uid.'|'.$room);
            $pending=(array)($_SESSION[$key]??[]);
            $personId=(int)($pending['personId']??0);
            $personThread=(string)($pending['threadId']??'');
            $valid=$personId>0&&$personId!==$uid&&($pending['operatorThreadId']??'')===$room
                &&(string)($pending['voiceEventId']??'')===$voiceEvent&&$voiceEvent!==''
                &&time()-(int)($pending['createdAt']??0)<=120
                &&time()>=(int)($pending['createdAt']??0)
                &&preg_match('/^agent-[a-f0-9]{20}$/D',$personThread)===1;
            if($valid){
                fm9d_person_collections($pdo,$user,$personId);
                $mirrored=gaw_messages($pdo,$org,$personId,$personThread,1);
                $mirror=$mirrored[0]??[];
                $valid=($mirror['role']??'')==='user'
                    &&($mirror['structured']['collection']??'')==='fm9d_person_turn'
                    &&($mirror['structured']['operatorThreadId']??'')===$room
                    &&(string)($mirror['voice_event_public_id']??'')===$voiceEvent
                    &&(string)($mirror['public_id']??'')===(string)($pending['personMessageId']??'')
                    &&trim((string)($mirror['content']??''))===$message;
            }
            if(!$valid){
                app_json_response(['ok'=>true,'skill'=>'food_monster.training_identity',
                    'answer'=>'I need a fresh verified speaker event before opening or changing an employee training session.',
                    'sources'=>[],'data'=>['training'=>false]]);
            }
        }
    }
    // Read-only menu guidance may be offered to staff. Private recipes never
    // inherit the room operator's credentials for an employee voice.
    $isRecipe=$command['action']==='start'&&preg_match('/\brecipe\b/iu',$command['subject']??'')===1;
    $intent=$isRecipe?'recipe':'training';
    if(!fm13b_allowed($user,$intent,$ownerVoiceVerified,$voice))
        throw new DomainException('You do not have permission to access those training records.');

    $material=null;
    if($command['action']==='start'){
        $subject=fm13b_subject((string)$command['subject']);
        if($subject!==''){
            if($isRecipe){
                if(restaurant_brain_table_ready($pdo,'recipes')){
                    $recipes=array_values(array_filter(restaurant_brain_recipe_search($pdo,$org,$subject,5),
                        static fn($r)=>($r['status']??'')==='active'));
                    if(count($recipes)===1)$material=fm13c_material($recipes[0],'recipe');
                }
            }else{
                $items=menu_training_search_items($pdo,$org,$subject,5);
                if(count($items)===1)$material=fm13c_material($items[0],'menu');
            }
        }
    }
    // Lock the active member first to serialize thread creation across tabs.
    $pdo->beginTransaction();
    try{
        $member=$pdo->prepare("SELECT u.id FROM users u
            INNER JOIN organization_memberships om ON om.user_id=u.id
                AND om.organization_id=? AND om.status='active'
            WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL
            FOR UPDATE");
        $member->execute([$org,$personId]);
        if(!$member->fetchColumn())throw new DomainException('An active organization member is required for training.');
        $trainingThread=fm13c_training_thread($pdo,$org,$personId,$command['action']==='start');
        $prior=$trainingThread?fm13c_read_state($pdo,$org,$personId,$trainingThread):null;
        if($prior&&$command['action']!=='start'){
            // Progress on private recipe content cannot be continued by another
            // employee in the Super Admin's shared room.
            $kind=(string)($prior['kind']??'');
            if(!fm13b_allowed($user,$kind==='recipe'?'recipe':'training',$ownerVoiceVerified,$voice))
                throw new DomainException('You cannot access this training procedure.');
            $material=fm13c_fetch_material($pdo,$org,$prior);
        }
        $result=fm13c_transition($prior,$command,$material,$turnId,time());
        if($result['changed']&&$trainingThread){
            gaw_append($pdo,$org,$personId,$trainingThread,'system','Training progress updated',[
                'channel'=>'text',
                'structured'=>['collection'=>'fm13c_guided_training','state'=>$result['state'],
                    'source'=>'gelato_training','operatorUserId'=>$uid,'operatorRoomThread'=>$room]]);
        }
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    app_audit($pdo,$org,$uid,'food_monster.guided_training','agent_conversation',$room,null,
        ['action'=>$command['action'],'updated'=>$result['changed'],
            'sourceType'=>$result['state']['kind']??null,'subjectPersonId'=>$personId]);
    $source=$material?['Gelato '.($material['kind']==='menu'?'active menu':'active recipe').
        ' procedure: '.$material['name']]:[];
    app_json_response(['ok'=>true,'skill'=>'food_monster.guided_training',
        'answer'=>$result['answer'],'sources'=>$source,
        'data'=>['training'=>true,'status'=>$result['state']['status']??'unavailable',
            'stepIndex'=>$result['state']['index']??null,
            'stepCount'=>$result['state']['stepCount']??null,
            'changed'=>$result['changed']]]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G13C training error: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Training could not be loaded or saved. No completion was recorded.'],503);
}
