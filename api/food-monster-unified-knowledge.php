<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/agent-private-voice.php';
require_once __DIR__.'/../includes/food-monster-unified-knowledge.php';

$user=app_require_auth();$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();app_verify_request_csrf($input);
try{
    if(($input['action']??'')!=='ask')throw new InvalidArgumentException('Read-only Agent questions only.');
    $question=trim((string)($input['message']??''));
    $thread=trim((string)($input['threadId']??($_GET['threadId']??'')));
    if($question===''||mb_strlen($question,'UTF-8')>2000
        ||preg_match('/^agent-[a-f0-9]{20}$/D',$thread)!==1)
        throw new InvalidArgumentException('Enter a valid restaurant question and conversation.');
    if(!fmuk_topics($question)&&!fmuk_capability_question($question))
        throw new InvalidArgumentException('Ask a read-only restaurant question.');
    // The caller cannot supply a role, speaker or arbitrary conversation.
    // The current turn must already be committed by Agent Chat.
    if(!gaw_ready($pdo)||!gaw_thread($pdo,$org,$uid,$thread))
        app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
    $last=gaw_messages($pdo,$org,$uid,$thread,1)[0]??null;
    if(!$last||($last['role']??'')!=='user'
        ||trim((string)($last['content']??''))!==$question)
        app_json_response(['ok'=>false,'message'=>'This must be the latest saved Agent Chat user turn.'],409);
    $voice=($last['channel']??'')==='voice';$ownerVoiceVerified=false;
    if($voice){
        $ownerVoiceVerified=gavp_was_approved($org,$uid,$thread,(string)$last['public_id']);
    }
    $result=fmuk_answer($pdo,$user,$question,$voice,$ownerVoiceVerified);
    app_audit($pdo,$org,$uid,'food_monster.unified_knowledge_read','agent_conversation',$thread,null,
        ['topics'=>$result['data']['evidenceTopics'],'sourceCount'=>count($result['sources']),'voice'=>$voice]);
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $error){app_json_response(['ok'=>false,'message'=>$error->getMessage()],403);}
catch(InvalidArgumentException $error){app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);}
catch(Throwable $error){
    error_log('Food Monster unified knowledge: '.get_class($error));
    app_json_response(['ok'=>false,'message'=>'Gelato could not read that information safely. No result was invented.'],503);
}
