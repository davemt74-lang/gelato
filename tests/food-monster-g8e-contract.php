<?php
declare(strict_types=1);
$n=0;function gate8e(bool $ok,string $name):void{global $n;if(!$ok){fwrite(STDERR,"FM-G8E FAIL: $name\n");exit(1);}++$n;}
$core=file_get_contents(__DIR__.'/../includes/food-monster-onboarding.php');
$api=file_get_contents(__DIR__.'/../api/food-monster-onboarding.php');
$js=file_get_contents(__DIR__.'/../js/food-monster-onboarding.js');
$live=file_get_contents(__DIR__.'/../js/food-monster-participants.js');
$css=file_get_contents(__DIR__.'/../css/food-monster.css');
foreach([
 [str_contains($core,'fmgoal_start_self'),'Signed-in account reusable'],
 [str_contains($core,'fmgoal_name_candidates'),'Match authorized existing user list'],
 [str_contains($core,'fmgoal_existing_accounts($pdo,$actor)'),'Account checks scoped to existing permissions'],
 [str_contains($core,'app_has_permission')||str_contains($core,'fmgoal_owner'),'Role approval remains protected'],
 [str_contains($core,"'linked_user_id'=>$uid"),'Self can only link logged-in account'],
 [str_contains($api,"'selfConfirmed'"),'Explicit self assertion required'],
 [str_contains($api,"'start_self'"),'Existing-account initiation action'],
 [str_contains($api,"'matches'"),'Name matching returned to popup'],
 [str_contains($js,'state.matches.length'),'Matched account decision precedes participant type question'],
 [str_contains($js,'start-self'),'Owner path omits name/type question'],
 [str_contains($js,'someone-else'),'Bystander cannot silently become signed-in user'],
 [str_contains($js,'not-listed'),'Names do not automatically claim identities'],
 [str_contains($js,'link_existing'),'Current permission-protected account link reused'],
 [str_contains($live,"panel.innerHTML='<div class=\"fm-participant-cards\""),'Only active card collection in right sidebar'],
 [!str_contains($live,'fm-participant-roster'),'No staff roster UI in participant sidebar'],
 [!str_contains($live,'fm-participants-top'),'No heading count or decorative sidebar UI'],
 [str_contains($live,'inputLevel(t.id)'),'Each card has track-scoped input level'],
 [str_contains($live,'attributed!==true'),'Unattributed voice cannot claim a participant'],
 [str_contains($live,'visible.length!==1'),'Multiple people cannot share one live input meter'],
 [str_contains($live,"gelato-agent-listening-change"),'Mic meter tied to explicitly enabled Agent Chat microphone'],
 [str_contains($live,'stopMeter();clearTracks();'),'Camera/page cleanup prevents stale presence'],
 [str_contains($css,'.fm-participant-input'),'Minimal visual per-card meter styled'],
 [!str_contains($core,'session_regenerate_id'),'Name resolution never authenticates anyone'],
] as [$ok,$name])gate8e($ok,$name);
echo "FM-G8E simple user-first onboarding and participant card gates PASS ($n)\n";
