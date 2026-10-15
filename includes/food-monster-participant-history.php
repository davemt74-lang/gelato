<?php
declare(strict_types=1);
/** FM-G8D2A — scoped participant memory; no raw microphone, PIN or face data.
 * Existing Agent Chat remains the only message store; these are owner-bound folders.
 */
require_once __DIR__.'/agent-workspace-core.php';
function fmph_ready(PDO $pdo):bool {
 try{$q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('fm_participant_events','fm_participant_chat_folders')");
  return (int)$q->fetchColumn()===2;}catch(Throwable){return false;}
}
function fmph_allowed_event(string $event):bool {
 return in_array($event,['goal_started','name_confirmed','participant_type','existing_account_linked','photo_consent',
  'photo_saved','photos_deferred','voice_consent','account_proposed','employee_account_created','voice_enrolled',
  'goal_completed','goal_cancelled','guest_crm_conversion','photo_removed'],true);
}
function fmph_write(PDO $pdo,array $actor,string $event,?array $goal=null):void {
 if(!fmph_allowed_event($event))throw new InvalidArgumentException('Invalid history action.');
 if(!fmph_ready($pdo))return; // Safe upgrade compatibility; app_audit remains the canonical audit log.
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 if($goal&&(int)$goal['organization_id']!==$org)throw new DomainException('Participant organization mismatch.');
 $goalId=$goal?(int)$goal['id']:null;
 $userId=$goal&&!empty($goal['linked_user_id'])?(int)$goal['linked_user_id']:null;
 $labels=[
  'goal_started'=>'Participant setup started','name_confirmed'=>'Introduction recorded',
  'participant_type'=>'Participant type confirmed','existing_account_linked'=>'Existing account linked',
  'photo_consent'=>'Photo storage consented','photo_saved'=>'Enrollment photo saved',
  'photos_deferred'=>'Photos postponed for poor lighting','voice_consent'=>'Voice enrollment consented',
  'account_proposed'=>'Employee account proposed','employee_account_created'=>'Employee account approved and created',
  'voice_enrolled'=>'Enrolled voice profile saved',
  'goal_completed'=>'Participant goal completed','goal_cancelled'=>'Participant goal cancelled',
  'guest_crm_conversion'=>'Guest CRM contact created with consent','photo_removed'=>'Enrollment photo deleted'
 ];
 $q=$pdo->prepare('INSERT INTO fm_participant_events (organization_id,public_id,user_id,goal_id,actor_user_id,event_type,privacy_scope,source,summary) VALUES (?,?,?,?,?,?,?,?,?)');
 $q->execute([$org,'fme-'.bin2hex(random_bytes(12)),$userId,$goalId,$uid,$event,'operational','onboarding',$labels[$event]]);
}
function fmph_voice_enrollment_event(PDO $pdo,array $actor):void {
 if(!fmph_ready($pdo))return;
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 $q=$pdo->prepare("SELECT p.id FROM voice_identity_profiles p WHERE p.organization_id=? AND p.user_id=? AND p.status='active' AND p.enrolled_at>=DATE_SUB(NOW(6),INTERVAL 5 MINUTE)");
 $q->execute([$org,$uid]);if(!$q->fetchColumn())return;
 $q=$pdo->prepare("SELECT * FROM fm_participant_goals WHERE organization_id=? AND linked_user_id=? ORDER BY updated_at DESC LIMIT 1");
 $q->execute([$org,$uid]);$goal=$q->fetch(PDO::FETCH_ASSOC);if(!$goal)return;
 $seen=$pdo->prepare("SELECT COUNT(*) FROM fm_participant_events WHERE organization_id=? AND goal_id=? AND event_type='voice_enrolled' AND occurred_at>=DATE_SUB(NOW(6),INTERVAL 5 MINUTE)");
 $seen->execute([$org,(int)$goal['id']]);if((int)$seen->fetchColumn()>0)return;
 fmph_write($pdo,$actor,'voice_enrolled',$goal);
}
function fmph_owner(array $actor):bool{return (int)($actor['is_owner_role']??0)===1;}
function fmph_read(PDO $pdo,array $actor,int $targetId,int $limit=75):array {
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 if($targetId!==$uid&&!fmph_owner($actor))throw new DomainException('Participant history is private.');
 if($targetId<1)throw new InvalidArgumentException('Invalid user.');
 $limit=max(1,min(100,$limit));
 $where=$targetId===$uid?'':' AND privacy_scope=\'operational\'';
 // Include earlier unlinked goal events after an authorized account is linked.
 $link=" OR goal_id IN (SELECT id FROM fm_participant_goals WHERE organization_id=? AND linked_user_id=?)";
 $q=$pdo->prepare("SELECT public_id,event_type,privacy_scope,source,summary,occurred_at FROM fm_participant_events WHERE organization_id=? AND (user_id=?".$link.")".$where." ORDER BY occurred_at DESC,id DESC LIMIT ".$limit);
 $q->execute([$org,$targetId,$org,$targetId]);
 return array_map(static fn($r)=>['id'=>(string)$r['public_id'],'type'=>(string)$r['event_type'],'scope'=>(string)$r['privacy_scope'],
  'source'=>(string)$r['source'],'summary'=>(string)$r['summary'],'at'=>(string)$r['occurred_at']],$q->fetchAll(PDO::FETCH_ASSOC));
}
function fmph_goals(PDO $pdo,array $actor,int $target):array {
 $org=(int)$actor['organization_id'];$self=(int)$actor['id']===$target;
 if(!$self&&!fmph_owner($actor))throw new DomainException('Participant goal access denied.');
 $q=$pdo->prepare('SELECT public_id,phase,status,updated_at FROM fm_participant_goals WHERE organization_id=? AND linked_user_id=? ORDER BY updated_at DESC LIMIT 30');
 $q->execute([$org,$target]);
 return array_map(static fn($r)=>['id'=>(string)$r['public_id'],'phase'=>(string)$r['phase'],
   'status'=>(string)$r['status'],'updatedAt'=>(string)$r['updated_at']],$q->fetchAll(PDO::FETCH_ASSOC));
}
function fmph_folders(PDO $pdo,array $actor):array {
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 $q=$pdo->prepare('SELECT public_id,thread_public_id,title,created_at FROM fm_participant_chat_folders WHERE organization_id=? AND user_id=? ORDER BY created_at DESC LIMIT 60');
 $q->execute([$org,$uid]);
 return array_map(static fn($r)=>['id'=>(string)$r['public_id'],'threadId'=>(string)$r['thread_public_id'],
  'title'=>(string)$r['title'],'createdAt'=>(string)$r['created_at']],$q->fetchAll(PDO::FETCH_ASSOC));
}
function fmph_add_folder(PDO $pdo,array $actor,string $threadId,string $title):array {
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 // Exact same ownership check as existing private Agent Chat.
 $thread=gaw_thread($pdo,$org,$uid,$threadId);
 if(!$thread)throw new DomainException('Your Agent conversation was not found.');
 $title=trim(preg_replace('/[\x00-\x1f\x7f<>]/u',' ',trim($title))??'');
 if($title==='')$title='Participant conversation';
 if(mb_strlen($title,'UTF-8')>180)throw new InvalidArgumentException('Folder title is too long.');
 $public='fmf-'.bin2hex(random_bytes(12));
 $q=$pdo->prepare('INSERT INTO fm_participant_chat_folders (organization_id,public_id,user_id,thread_public_id,title) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title)');
 $q->execute([$org,$public,$uid,$threadId,$title]);
 app_audit($pdo,$org,$uid,'participant.chat_folder_saved','agent_conversation',$threadId);
 return fmph_folders($pdo,$actor);
}
function fmph_remove_folder(PDO $pdo,array $actor,string $folder):array {
 $q=$pdo->prepare('DELETE FROM fm_participant_chat_folders WHERE organization_id=? AND user_id=? AND public_id=?');
 $q->execute([(int)$actor['organization_id'],(int)$actor['id'],$folder]);
 app_audit($pdo,(int)$actor['organization_id'],(int)$actor['id'],'participant.chat_folder_removed','agent_folder',$folder);
 return fmph_folders($pdo,$actor);
}
function fmph_agent_summary(PDO $pdo,array $actor,int $target):array {
 $events=fmph_read($pdo,$actor,$target,1);$goals=fmph_goals($pdo,$actor,$target);
 return ['userId'=>$target,'lastOperationalEvent'=>$events[0]??null,
  'pendingGoals'=>count(array_filter($goals,static fn($g)=>$g['status']==='in_progress')),
  'nextGoal'=>array_values(array_filter($goals,static fn($g)=>$g['status']==='in_progress'))[0]??null,
  'privateConversationContentIncluded'=>false];
}
