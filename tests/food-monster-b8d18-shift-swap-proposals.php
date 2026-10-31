<?php
declare(strict_types=1);
function app_has_permission(string $key,array $actor):bool
{
    return in_array($key,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-shift-swap-proposals.php';
$checks=0;
function h_check(bool $condition,string $label):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D18H FAIL: $label\n");exit(1);}
    ++$checks;
}
function h_reject(callable $operation,string $label):void
{
    try{$operation();h_check(false,$label);}catch(DomainException|InvalidArgumentException){h_check(true,$label);}
}
$actor=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.self','schedule.agent']];
h_check(fm8d18h_can($actor),'authorized signed-in employee');
h_check(!fm8d18h_can(['id'=>9,'organization_id'=>17,'permissions'=>['schedule.manage']]),'manager permission alone not sufficient');
h_check(!fm8d18h_can(['id'=>9,'organization_id'=>17,'permissions'=>['schedule.self']]),'agent permission required');
h_check(fm8d18h_catalogue($actor,['channel'=>'voice','privateVoiceApproved'=>false])===[],'room voice not offered');
h_check(isset(fm8d18h_catalogue($actor,['channel'=>'voice','privateVoiceApproved'=>true])['propose_my_shift_swap']),'approved private voice offered');
$tool=fm8d18h_catalogue($actor)['propose_my_shift_swap'];
h_check($tool['impact']==='read','model cannot write directly');
h_check(($tool['validate'])([], $actor)===['shift_id','target_user_id'],'exact identifiers required');
h_check(($tool['validate'])(['shift_id'=>'s-1','target_user_id'=>'12'], $actor)===['target_user_id'],'string ID not silently converted');
h_check(($tool['validate'])(['shift_id'=>'s-1','target_user_id'=>12],$actor)===[],'valid identifiers accepted');
h_check(!in_array('userId',$tool['allowedArguments'],true),'requester bound to session');
h_check(!in_array('organizationId',$tool['allowedArguments'],true),'tenant bound to session');
h_reject(static fn()=>fm8d18h_payload(['shift_id'=>'s/1','target_user_id'=>12]),'unsafe shift ID rejected');
h_reject(static fn()=>fm8d18h_payload(['shift_id'=>'s-1','target_user_id'=>12,'note'=>str_repeat('x',1001)]),'oversized note rejected');
$payload=fm8d18h_payload(['shift_id'=>'shift-1','target_user_id'=>12,'proposed_shift_id'=>'shift-2','note'=>'Trade please']);
h_check($payload['shiftId']==='shift-1'&&$payload['targetUserId']===12,'exact scoped payload');
h_check($payload['proposedShiftId']==='shift-2','proposed exchange shift retained');
$specialist=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-shift-swap-proposals.php');
h_check(str_contains($runtime,'fm8d18h_catalogue($principal,$context)'),'native model registration');
h_check(str_contains($runtime,"'propose_my_shift_swap'"),'cannot batch swap alongside other tools');
h_check(str_contains($specialist,"if(\$type==='shift_swap_request')"),'existing specialist confirms swap');
h_check(str_contains($specialist,'scheduling_request_create('),'existing shift request core used');
h_check(str_contains($specialist,'sac_swap_preflight('),'fresh checks at confirmation');
h_check(str_contains($specialist,"['published','locked']"),'published schedule requirement');
h_check(str_contains($specialist,'expectedUpdatedAt'),'shift version captured and rechecked');
h_check(str_contains($adapter,'tv_voice_event_valid('),'private voice event verified');
h_check(str_contains($specialist,"'status'=>'pending'"),'manager review remains pending');
echo "Food Monster B8D18H: $checks checks passed.\n";
