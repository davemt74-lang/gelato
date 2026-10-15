<?php
declare(strict_types=1);
if(!function_exists('app_has_permission')){function app_has_permission(string $p,array $user=[]):bool{return in_array($p,$user['permissions']??[],true);}}
require_once __DIR__.'/../includes/food-monster-speaker.php';
$n=0;function gate_sp(bool $b,string $name):void{global $n;if(!$b){fwrite(STDERR,"FAIL: $name\n");exit(1);}++$n;}
$one=array_fill(0,24,0);$one[1]=1;$two=array_fill(0,24,0);$two[2]=1;
$rows=[['user_id'=>2,'display_name'=>'A','status'=>'active','voiceprint_json'=>json_encode(tv_voice_vector($one)),'match_threshold'=>.86],
 ['user_id'=>3,'display_name'=>'B','status'=>'active','voiceprint_json'=>json_encode(tv_voice_vector($two)),'match_threshold'=>.86]];
gate_sp(fmspeaker_scoring($rows,$one)['speaker']['userId']===2,'First voice matched');
gate_sp(fmspeaker_scoring($rows,$two)['speaker']['userId']===3,'Second voice matched');
gate_sp(!fmspeaker_scoring($rows,array_fill(0,24,1))['matched'],'Unknown rejected');
$rows[]=['user_id'=>4,'display_name'=>'C','status'=>'active','voiceprint_json'=>json_encode(tv_voice_vector($one)),'match_threshold'=>.86];
gate_sp(fmspeaker_scoring($rows,$one)['reason']==='ambiguous','Similar speakers rejected');
$rows[2]['status']='disabled';
gate_sp(fmspeaker_scoring($rows,$one)['speaker']['userId']===2,'Disabled profile excluded');
gate_sp(!fmspeaker_can_roster([]),'Deny missing manage permission');
$api=file_get_contents(__DIR__.'/../api/food-monster-speaker.php');
$core=file_get_contents(__DIR__.'/../includes/food-monster-speaker.php');
foreach([
 [str_contains($api,'app_require_auth()'),'Authentication'],
 [str_contains($api,'app_verify_request_csrf'),'CSRF'],
 [str_contains($api,"'consent'"),'Explicit speaker consent'],
 [str_contains($api,'$now-(int)($_SESSION[$key]??0)<10'),'Rate limit'],
 [str_contains($core,'om.organization_id=vp.organization_id'),'Same org'],
 [str_contains($core,'ur.revoked_at IS NULL'),'Role still active'],
 [str_contains($core,'vp.status='),'Active voice profile only'],
 [str_contains($core,'u.archived_at IS NULL'),'Exclude archived accounts'],
 [str_contains($core,'agent.voice_speaker_checked'),'Audited'],
 [!str_contains($core,'session_regenerate_id'),'No login'],
 [!str_contains($core,'tv_clock_in('),'No clock actions']
] as [$v,$name])gate_sp($v,$name);
echo "FM-G8D1 speaker matching contract PASS ($n gates)\n";
