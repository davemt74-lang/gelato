<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/agent-voice-conversation.php';
require_once __DIR__.'/../includes/food-monster-person-conversations.php';
require_once __DIR__.'/../includes/food-monster-g13b-knowledge.php';

$user=app_require_auth();$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();app_verify_request_csrf($input);
try{
    if(($input['action']??'')!=='ask')throw new InvalidArgumentException('Read-only knowledge questions only.');
    $question=trim((string)($input['message']??''));
    $thread=trim((string)($input['threadId']??''));
    if($question===''||mb_strlen($question,'UTF-8')>1000
       ||!preg_match('/^agent-[a-f0-9]{20}$/D',$thread))
        throw new InvalidArgumentException('Enter a valid question and conversation.');
    $intent=fm13b_intent($question);
    if($intent===null)throw new InvalidArgumentException('Ask about a menu item, ingredient, allergen, preparation or recipe.');
    // Never read evidence based on browser-supplied identity, role, or history.
    $messages=gaw_messages($pdo,$org,$uid,$thread,1);
    $last=(array)($messages[0]??[]);
    if(($last['role']??'')!=='user'||trim((string)($last['content']??''))!==$question)
        throw new DomainException('The question must be the latest saved Agent Chat user turn.');
    $voice=($last['channel']??'')==='voice';
    $ownerVoiceVerified=false;
    if($voice){
        $voiceId=(string)($last['voice_event_public_id']??'');
        $matched=$voiceId!==''?avc_verified_speaker($pdo,$org,$user,$voiceId):null;
        $ownerVoiceVerified=is_array($matched)&&(int)($matched['userId']??0)===$uid;
    }
    // A recognized employee is allowed only the same staff-facing menu
    // evidence the operator's configured menu-view permission allows.
    // Owner-private recipe records require the operator's own verified
    // voice, or their explicitly typed authorized request.
    if(!fm13b_allowed($user,$intent,$ownerVoiceVerified,$voice))
        throw new DomainException('You do not have permission to read that information.');
    $referent='';
    if($voice&&fm13a_needs_context($question)&&fm9a_is_super_admin_session($user)){
        $key='fm9d_pending_'.hash('sha256',$org.'|'.$uid.'|'.$thread);
        $pending=(array)($_SESSION[$key]??[]);
        $p=(int)($pending['personId']??0);
        $personThread=(string)($pending['threadId']??'');
        $valid=$p>0&&($pending['operatorThreadId']??'')===$thread
            &&($pending['voiceEventId']??'')===(string)($last['voice_event_public_id']??'')
            &&(string)($pending['voiceEventId']??'')!==''
            &&time()-(int)($pending['createdAt']??0)<=120
            &&time()>=(int)($pending['createdAt']??0)
            &&preg_match('/^agent-[a-f0-9]{20}$/D',$personThread);
        $rows=[];
        if($valid){
            fm9d_person_collections($pdo,$user,$p);
            $rows=gaw_messages($pdo,$org,$p,$personThread,12);
        }
        $plan=fm13a_engagement_plan($rows,$last,$thread,$valid,time());
        if($plan['mode']==='clarify')app_json_response(['ok'=>true,
            'skill'=>'food_monster.knowledge_clarification',
            'answer'=>'Which specific menu item or preparation are you asking about?',
            'sources'=>[],'data'=>['verified'=>false]]);
        if($plan['mode']==='continue')$referent=(string)($plan['referent']??'');
    }
    $result=fm13b_knowledge($pdo,$user,$question,$intent,$ownerVoiceVerified,$voice,$referent);
    app_audit($pdo,$org,$uid,'food_monster.knowledge_read','agent_conversation',$thread,null,
        ['skill'=>$result['skill'],'sourceCount'=>count($result['sources']??[]),'voice'=>$voice]);
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G13B knowledge error: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Menu or recipe evidence is unavailable. Ask a manager to verify current records.'],503);
}
