<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/hardening-agent-confirmation-test-support.php';
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/timeclock-agent-core.php';
require_once __DIR__.'/../includes/agent-voice-conversation.php';
require_once __DIR__.'/../includes/agent-private-voice.php';
$pdo=app_pdo();
function hlv_vector():array{return tv_voice_vector(array_map(static fn(int $n):float=>$n/100,range(1,24)));}
function hlv_profile(PDO $pdo,int $org,int $uid):void{
    $pdo->prepare("INSERT INTO voice_identity_profiles(organization_id,user_id,status,consent_version,consented_at,enrolled_at,voiceprint_json,sample_count) VALUES (?,?,'active','test-consent',NOW(6),NOW(6),?,1) ON DUPLICATE KEY UPDATE status='active',voiceprint_json=VALUES(voiceprint_json)")
        ->execute([$org,$uid,json_encode(hlv_vector(),JSON_THROW_ON_ERROR)]);
}
function hlv_event(PDO $pdo,int $org,int $uid):string{
    $match=tv_voice_match($pdo,$org,$uid,hlv_vector(),false);
    if(!$match['matched']||!$match['sessionUserMatch'])throw new RuntimeException('Fixture voice adapter did not match.');
    return (string)$match['eventId'];
}
function hlv_snapshot(PDO $pdo,int $org):array{
    $out=[];
    foreach(['time_clock_entries','time_clock_breaks','attendance_events'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');$q->execute([$org]);$out[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}
// CLI-only fixture operations drive real HTTP routes without an AI-provider
// dependency. "approve" consumes the real token issued after HTTP UI consent;
// it stands in only for the model gateway's existing one-use token consumption.
if(($argv[1]??'')==='fixture'){
    $mode=$argv[2]??'';
    if($mode==='profile'){hlv_profile($pdo,(int)$argv[3],(int)$argv[4]);$out=['ok'=>true,'features'=>hlv_vector()];}
    elseif($mode==='snapshot')$out=hlv_snapshot($pdo,(int)$argv[3]);
    elseif($mode==='event')$out=['eventId'=>hlv_event($pdo,(int)$argv[3],(int)$argv[4])];
    elseif($mode==='age'){$pdo->prepare('UPDATE voice_identity_events SET created_at=DATE_SUB(NOW(6),INTERVAL 31 SECOND) WHERE organization_id=? AND public_id=?')->execute([(int)$argv[3],$argv[4]]);$out=['ok'=>true];}
    elseif($mode==='approve'){
        session_id((string)$argv[3]);app_boot_session();$actor=app_current_user(true);
        if(!$actor)throw new RuntimeException('Authenticated fixture session required.');
        $out=['approved'=>gavp_consume((int)$actor['organization_id'],(int)$actor['id'],(string)$argv[4],(string)$argv[5],(string)$argv[6])];session_write_close();
    }else throw new InvalidArgumentException('Unknown legacy voice fixture.');
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
app_boot_session();$_SESSION['agent_node_pending']=[];
$checks=0;
function hlv_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('Legacy voice: '.$label);$checks++;}
function hlv_blocked(callable $call,string $label):void{
    global $pdo,$org,$uid;
    $before=hlv_snapshot($pdo,$org);$pending=gac_pending_raw('timeclock',$org,$uid);
    try{$call();}catch(GacConfirmationConflict|TimeclockAgentPermissionException $e){
        hlv_check(hlv_snapshot($pdo,$org)===$before,$label.' leaves clock/break/attendance unchanged');
        hlv_check(gac_pending_raw('timeclock',$org,$uid)===$pending,$label.' preserves the exact pending action');return;
    }
    throw new RuntimeException('Legacy voice accepted '.$label);
}
function hlv_turn(PDO $pdo,array $actor,string $thread,string $command,string $channel='text',string $event='',bool $approved=true):array{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    gavp_clear_approved();$pdo->beginTransaction();
    try{
        if($channel==='voice'&&!avc_claim_unused_event($pdo,$org,$actor,$event))$event='';
        $saved=gaw_append($pdo,$org,$uid,$thread,'user',$command,['channel'=>$channel,'voiceEventId'=>$event]);$pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    if($channel==='voice'&&$approved){$token=gavp_issue($org,$uid,$thread,$saved['id']);hlv_check(gavp_consume($org,$uid,$thread,$saved['id'],$token),'exact private-turn approval is consumed');}
    return ['message'=>$command,'threadId'=>$thread,'sourceMessageId'=>$saved['id'],
        'voice'=>$channel==='voice','voiceEventId'=>$event];
}
$suffix=bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Hardening E Voice '.$suffix]);$org=(int)$pdo->lastInsertId();
$makeUser=static function(string $name)use($pdo,$org,$suffix):int{
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'x',?,'Voice',?,'active')")->execute([$name.'-'.$suffix.'@example.test',$name,$name.' Voice']);$id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$id]);hlv_profile($pdo,$org,$id);return $id;
};
$uid=$makeUser('Owner');$other=$makeUser('Other');
$user=['organization_id'=>$org,'id'=>$uid,'display_name'=>'Owner Voice','permissions'=>['timeclock.agent','timeclock.self','voice.agent','voice.self','voice.manage']];
$thread=gaw_create_thread($pdo,$org,$uid)['public_id'];$second=gaw_create_thread($pdo,$org,$uid)['public_id'];
$typed=hlv_turn($pdo,$user,$thread,'clock me in');$proposal=timeclock_agent_handle($pdo,$user,$typed);$review=hac_test_review($pdo,$user,'timeclock');
hlv_check(!tv_open_clock($pdo,$org,$uid),'typed proposal does not execute without confirmation');
$unknown=hlv_turn($pdo,$user,$thread,'Confirm','voice');$unknown['voice']=false;
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$unknown+$review),'unknown spoken Confirm with voice:false');
$typedConfirm=hlv_turn($pdo,$user,$thread,'Confirm');timeclock_agent_handle($pdo,$user,$typedConfirm+$review);
hlv_check((tv_open_clock($pdo,$org,$uid)['clock_in_source']??'')==='agent','typed confirmation remains permitted');
tv_clock_out($pdo,$org,$uid,$uid,'manual');
$unknown=hlv_turn($pdo,$user,$thread,'clock me in','voice');$unknown['voice']=false;
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$unknown),'unknown voice action cannot be labeled typed');
$event=hlv_event($pdo,$org,$uid);$request=hlv_turn($pdo,$user,$thread,'clock me in','voice',$event,false);
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$request),'speaker match without authenticated operator consent');
$token=gavp_issue($org,$uid,$thread,$request['sourceMessageId']);gavp_consume($org,$uid,$thread,$request['sourceMessageId'],$token);
$request['voice']=false;timeclock_agent_handle($pdo,$user,$request);$pending=gac_pending_get('timeclock',$org,$uid);$review=hac_test_review($pdo,$user,'timeclock');
hlv_check($pending['payload']['source']==='voice'&&$pending['payload']['voiceEventId']===$event,'canonical source overrides a false caller flag');
$again=hlv_turn($pdo,$user,$thread,'Confirm','voice',$event);
hlv_check($again['voiceEventId']==='','canonical append strips a replayed event');
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$again+$review),'proposal event replay as confirmation');
$staleEvent=hlv_event($pdo,$org,$uid);$stale=hlv_turn($pdo,$user,$thread,'Confirm','voice',$staleEvent);
$pdo->prepare('UPDATE voice_identity_events SET created_at=DATE_SUB(NOW(6),INTERVAL 31 SECOND) WHERE organization_id=? AND public_id=?')->execute([$org,$staleEvent]);
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$stale+$review),'expired confirmation event');
$foreignEvent=hlv_event($pdo,$org,$uid);$pdo->prepare('UPDATE voice_identity_events SET matched_user_id=? WHERE organization_id=? AND public_id=?')->execute([$other,$org,$foreignEvent]);
$wrongSpeaker=hlv_turn($pdo,$user,$thread,'Confirm','voice',$foreignEvent);
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$wrongSpeaker+$review),'another enrolled speaker on a manager session');
$wrongThread=hlv_turn($pdo,$user,$second,'Confirm','voice',hlv_event($pdo,$org,$uid));
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$wrongThread+$review),'different conversation confirmation');
$mismatch=hlv_turn($pdo,$user,$thread,'hello','voice',hlv_event($pdo,$org,$uid));$mismatch['message']='Confirm';
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$mismatch+$review),'receipt for a different spoken command');
$oldTyped=$typedConfirm;$newUnknown=hlv_turn($pdo,$user,$thread,'Confirm','voice');
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$oldTyped+$review),'old typed source cannot conceal the latest spoken turn');
$disabled=hlv_turn($pdo,$user,$thread,'Confirm','voice',hlv_event($pdo,$org,$uid));
$pdo->prepare("UPDATE voice_identity_profiles SET status='disabled' WHERE organization_id=? AND user_id=?")->execute([$org,$uid]);
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$disabled+$review),'profile disabled before confirmation');hlv_profile($pdo,$org,$uid);
$confirmEvent=hlv_event($pdo,$org,$uid);$confirm=hlv_turn($pdo,$user,$thread,'Confirm','voice',$confirmEvent);
$revoked=$user;$revoked['permissions']=['timeclock.agent','voice.agent'];
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$revoked,$confirm+$review),'revoked personal clock authorization');
// A rejected authorization must not spend the otherwise valid spoken receipt.
$confirm=hlv_turn($pdo,$user,$thread,'Confirm','voice',hlv_event($pdo,$org,$uid));$confirmEvent=$confirm['voiceEventId'];
timeclock_agent_handle($pdo,$user,$confirm+$review);$clock=tv_open_clock($pdo,$org,$uid);
hlv_check(($clock['clock_in_source']??'')==='voice','fresh verified spoken confirmation executes as voice');
$q=$pdo->prepare("SELECT new_values_json FROM audit_log WHERE organization_id=? AND action='timeclock.agent_action_confirmed' ORDER BY id DESC LIMIT 1");$q->execute([$org]);$audit=json_decode((string)$q->fetchColumn(),true);
hlv_check(($audit['voiceEventId']??'')===$confirmEvent&&($audit['decisionMessageId']??'')===$confirm['sourceMessageId'],'audit uses fresh confirming receipt and canonical decision message');
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$confirm+$review),'replayed completed confirmation');
$out=hlv_turn($pdo,$user,$thread,'clock me out');timeclock_agent_handle($pdo,$user,$out);$review=hac_test_review($pdo,$user,'timeclock');
$done=hlv_turn($pdo,$user,$thread,'Confirm');timeclock_agent_handle($pdo,$user,$done+$review);hlv_check(!tv_open_clock($pdo,$org,$uid),'typed action still completes after spoken workflow');
$once=hlv_turn($pdo,$user,$thread,'clock me in','voice',hlv_event($pdo,$org,$uid));timeclock_agent_handle($pdo,$user,$once);gac_pending_clear('timeclock',$org,$uid);
hlv_blocked(static fn()=>timeclock_agent_handle($pdo,$user,$once),'one spoken command cannot stage another action after removal');
echo 'Legacy voice MariaDB: PASS ('.$checks." assertions; canonical actions/decisions, principal, conversation, command, fresh receipts, replay, consent, permissions, audit, typed controls).\n";
