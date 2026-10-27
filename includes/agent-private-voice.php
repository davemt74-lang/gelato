<?php
declare(strict_types=1);

/**
 * An acoustic voiceprint is only provisional speaker context. Private restaurant
 * reads from a spoken utterance require an explicit, authenticated UI approval
 * bound to the specific canonical user message. This is consent, not MFA.
 *
 * The session is PHP-locked until this one-use grant is consumed.
 */
function gavp_issue(int $org,int $uid,string $threadId,string $messageId,int $now=0): string
{
    if(session_status()!==PHP_SESSION_ACTIVE)throw new RuntimeException('An authenticated session is required.');
    $now=$now?:time();
    $token=bin2hex(random_bytes(24));
    $_SESSION['gelato_private_voice_turn']=[
        'org'=>$org,'user'=>$uid,'thread'=>$threadId,'message'=>$messageId,
        'token'=>$token,'issuedAt'=>$now,'expiresAt'=>$now+45,
    ];
    return $token;
}

function gavp_consume(int $org,int $uid,string $threadId,string $messageId,string $token,int $now=0): bool
{
    if(session_status()!==PHP_SESSION_ACTIVE)return false;
    $grant=$_SESSION['gelato_private_voice_turn']??null;
    // Always consume: wrong thread, expired token and failed attempts may not
    // be retried and cannot authenticate a later participant in the room.
    unset($_SESSION['gelato_private_voice_turn']);
    if(!is_array($grant)||$token===''||strlen($token)!==48)return false;
    $now=$now?:time();
    $accepted=(int)($grant['org']??0)===$org
        &&(int)($grant['user']??0)===$uid
        &&(string)($grant['thread']??'')===$threadId
        &&(string)($grant['message']??'')===$messageId
        &&$now>=(int)($grant['issuedAt']??PHP_INT_MAX)
        &&$now<=(int)($grant['expiresAt']??0)
        &&hash_equals((string)($grant['token']??''),$token);
    if($accepted){
        // Delegated private reads remain pinned to this exact approved turn.
        // This is not authentication for anyone heard in the room.
        $_SESSION['gelato_private_voice_approved']=[
            'org'=>$org,'user'=>$uid,'thread'=>$threadId,'message'=>$messageId,
            'issuedAt'=>$now,'expiresAt'=>$now+90,
        ];
    }
    return $accepted;
}

function gavp_was_approved(int $org,int $uid,string $threadId,string $messageId,int $now=0):bool
{
    if(session_status()!==PHP_SESSION_ACTIVE)return false;
    $record=$_SESSION['gelato_private_voice_approved']??null;
    if(!is_array($record)||$messageId===''||$threadId==='')return false;
    $now=$now?:time();
    if($now>(int)($record['expiresAt']??0)){
        unset($_SESSION['gelato_private_voice_approved']);
        return false;
    }
    return (int)($record['org']??0)===$org
        &&(int)($record['user']??0)===$uid
        &&(string)($record['thread']??'')===$threadId
        &&(string)($record['message']??'')===$messageId
        &&$now>=(int)($record['issuedAt']??PHP_INT_MAX);
}

function gavp_clear_approved():void
{
    if(session_status()===PHP_SESSION_ACTIVE)
        unset($_SESSION['gelato_private_voice_approved'],$_SESSION['gelato_private_voice_turn']);
}
