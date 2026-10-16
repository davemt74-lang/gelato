<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $operator):bool{return ($operator['role_slug']??'')==='super_admin';}
require_once __DIR__.'/../includes/food-monster-operational-followup.php';
function fm9f_assert(bool $condition,string $label):void {
    if(!$condition)throw new RuntimeException($label);
    echo "PASS ".$label.PHP_EOL;
}
$owner=['id'=>1,'organization_id'=>2,'role_slug'=>'super_admin'];
$employee=['id'=>3,'organization_id'=>2,'role_slug'=>'employee'];
fm9f_assert(fm9f_report_action_policy($owner,'routine')==='delegated','routine owner delegation');
fm9f_assert(fm9f_report_action_policy($owner,'high_impact')==='approval_required','high impact requires approval');
try {fm9f_report_action_policy($employee,'routine');fm9f_assert(false,'employee blocked');}
catch(DomainException){fm9f_assert(true,'employee blocked');}
try {fm9f_report_action_policy($owner,'unknown');fm9f_assert(false,'unknown impact blocked');}
catch(DomainException){fm9f_assert(true,'unknown impact blocked');}
$source=file_get_contents(__DIR__.'/../includes/food-monster-operational-followup.php');
$api=file_get_contents(__DIR__.'/../api/food-monster-followup.php');
fm9f_assert(str_contains($source,'FOR UPDATE')&&str_contains($source,"'fm9f_report_task'"),'durable serialized duplicate protection');
fm9f_assert(str_contains($source,"'sourcePublicId'=>$reportId"),'source report is linked to task');
fm9f_assert(str_contains($source,"'fm9e_report_resolution'"),'resolved reports denied');
fm9f_assert(str_contains($source,"'tasks.manage'"),'task permission enforced');
fm9f_assert(str_contains($api,'app_verify_request_csrf')&&str_contains($api,'app_require_auth'),'authenticated CSRF route');
