<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!ob_get_level())ob_start();
require_once __DIR__.'/../includes/agent-confirmation-core.php';

/** Canonical chat fixtures for existing domain/model tests. */
function hac_test_context(PDO $pdo,array $actor,array $input=[]): array
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INT, user_id INT,
        public_id TEXT UNIQUE, archived_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INT, user_id INT,
        conversation_id INT, public_id TEXT UNIQUE, role TEXT, content TEXT,
        structured_json TEXT, channel TEXT, voice_event_public_id TEXT)');
    }
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $thread=(string)($input['threadId']??('agent-'.bin2hex(random_bytes(10))));
    $q=$pdo->prepare('SELECT id FROM agent_conversations WHERE organization_id=? AND user_id=? AND public_id=?');
    $q->execute([$org,$uid,$thread]);$conversation=$q->fetchColumn();
    if(!$conversation){
        $pdo->prepare('INSERT INTO agent_conversations(organization_id,user_id,public_id) VALUES (?,?,?)')->execute([$org,$uid,$thread]);
        $conversation=(int)$pdo->lastInsertId();
    }
    $source=(string)($input['sourceMessageId']??('msg-'.bin2hex(random_bytes(10))));
    $pdo->prepare('INSERT INTO agent_messages(organization_id,user_id,conversation_id,public_id,role,content,channel) VALUES (?,?,?,?,?,?,?)')
        ->execute([$org,$uid,$conversation,$source,'user',(string)($input['message']??'Explicit unit-test proposal request'),'text']);
    $bound=$input+['threadId'=>$thread,'sourceMessageId'=>$source];
    gac_request_context($bound,$pdo,$actor);
    return $bound;
}

/** Save the actual canonical proposal text and bind the exact pending ID. */
function hac_test_bind(PDO $pdo,array $actor,array $pending): array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $thread=(string)$pending['threadId'];$node=(string)$pending['node'];
    $q=$pdo->prepare('SELECT id FROM agent_conversations WHERE organization_id=? AND user_id=? AND public_id=?');
    $q->execute([$org,$uid,$thread]);$conversation=$q->fetchColumn();
    if(!$conversation)throw new RuntimeException('Missing canonical test conversation.');
    $message='msg-'.bin2hex(random_bytes(10));
    $pdo->prepare('INSERT INTO agent_messages(organization_id,user_id,conversation_id,public_id,role,content,structured_json,channel) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$org,$uid,$conversation,$message,'agent',gac_proposal_answer($pending),
            json_encode(['proposal'=>gac_public_proposal($pending)],JSON_THROW_ON_ERROR),'text']);
    $public=gac_pending_bind_message($pdo,$node,$org,$uid,(string)$pending['id'],$thread,$message);
    $pdo->prepare('UPDATE agent_messages SET structured_json=? WHERE public_id=?')
        ->execute([json_encode(['proposal'=>$public],JSON_THROW_ON_ERROR),$message]);
    return $public;
}

function hac_test_evidence(array $proposal): array
{
    return ['threadId'=>$proposal['threadId'],'proposalId'=>$proposal['id'],
        'proposalHash'=>$proposal['hash'],'proposalMessageId'=>$proposal['messageId']];
}

/** Existing domain lifecycle tests use this explicit review step before confirming. */
function hac_test_review(PDO $pdo,array $actor,string $node): array
{
    $row=gac_pending_get($node,(int)$actor['organization_id'],(int)$actor['id']);
    if(!$row)throw new RuntimeException('No test proposal to review.');
    $public=($row['proposalMessageId']??'')!==''?gac_public_proposal($row):hac_test_bind($pdo,$actor,$row);
    return hac_test_evidence($public);
}

/** Construct a real request + review fixture for existing specialist lifecycle tests. */
function hac_test_input(PDO $pdo,array $actor,string $node,array $input): array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];$key=$org.':'.$uid;
    $pending=gac_pending_get($node,$org,$uid);
    $decision=gac_is_confirm((string)($input['message']??''))||gac_is_cancel((string)($input['message']??''));
    $thread=$input['threadId']??($decision&&$pending?$pending['threadId']:($GLOBALS['hac_test_threads'][$key]??null));
    if($thread)$input['threadId']=$thread;
    $request=hac_test_context($pdo,$actor,$input);
    $GLOBALS['hac_test_threads'][$key]=$request['threadId'];
    if($decision&&$pending)$request=array_replace($request,hac_test_review($pdo,$actor,$node));
    gac_request_context($request,$pdo,$actor);
    return $request;
}

/** Seed an authenticated user turn for a direct model adapter invocation. */
function hac_test_model_context(PDO $pdo,array $actor,array $context): array
{
    $request=hac_test_context($pdo,$actor,['threadId'=>$context['threadId']??('agent-'.bin2hex(random_bytes(10))),
        'message'=>'Explicit model proposal fixture']);
    return array_replace($context,['threadId'=>$request['threadId'],'messageId'=>$request['sourceMessageId'],
        'sourceMessageId'=>$request['sourceMessageId']]);
}
