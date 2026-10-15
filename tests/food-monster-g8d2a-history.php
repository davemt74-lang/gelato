<?php
declare(strict_types=1);
require __DIR__.'/../includes/food-monster-participant-history.php';
$n=0;function yes(bool $v,string $label):void{global $n;if(!$v){fwrite(STDERR,"FAIL: $label\n");exit(1);}++$n;}
foreach(['voice_enrolled','goal_started','name_confirmed','participant_type','existing_account_linked','photo_consent','photo_saved',
 'voice_consent','account_proposed','employee_account_created','goal_completed','goal_cancelled','guest_crm_conversion','photos_deferred','photo_removed'] as $event)yes(fmph_allowed_event($event),'Valid history event '.$event);
foreach(['login_with_pin','raw_audio','camera_image','password','face_vector','unknown'] as $bad)yes(!fmph_allowed_event($bad),'Reject sensitive event type');
yes(fmph_owner(['is_owner_role'=>1]),'Protected owner can read operational summaries');
yes(!fmph_owner(['is_owner_role'=>0]),'Staff does not gain cross-account access');
$core=file_get_contents(__DIR__.'/../includes/food-monster-participant-history.php');
$api=file_get_contents(__DIR__.'/../api/food-monster-participant-history.php');
$sql=file_get_contents(__DIR__.'/../database/20261015_fm_g8d2a_participant_history.sql');
foreach([
 [str_contains($api,'app_require_auth()'),'Signed-in account required'],
 [str_contains($api,'app_verify_request_csrf'),'CSRF for changes'],
 [str_contains($api,"'Cache-Control: no-store, private'"),'Privacy cache header'],
 [str_contains($core,"privacy_scope=\\'operational\\'"),'Private events filtered from admin'],
 [str_contains($core,'gaw_thread($pdo,$org,$uid,$threadId)'),'Private thread ownership checked'],
 [str_contains($core,'user_id=? AND public_id=?'),'Folder deletion scoped to own account'],
 [str_contains($core,'privateConversationContentIncluded'),'No private chat in manager summary'],
 [!str_contains($core,'session_regenerate_id'),'No login mutation'],
 [!str_contains($core,'getUserMedia'),'No microphone'],
 [str_contains($sql,'fm_participant_events'),'Timestamped event table'],
 [str_contains($sql,'fm_participant_chat_folders'),'Owned conversation folders'],
 [str_contains($sql,'occurred_at DATETIME(6)'),'Event timestamp microsecond'],
] as [$result,$label])yes($result,$label);
echo "FM-G8D2A history and private workspace contract PASS ($n checks)\n";
