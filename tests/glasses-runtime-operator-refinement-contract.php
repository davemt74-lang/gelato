<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$p=$read('api/glasses-user-preferences.php');
$r=$read('includes/glasses-employee-runtimes.php');
$api=$read('api/glasses-simulator.php');
$sim=$read('glasses-simulator.php');
$check=static function(bool $okay,string $why):void{
    if(!$okay){fwrite(STDERR,"FAIL glasses-runtime-operator: ".$why."\n");exit(1);}
};
$check(str_contains($sim,'Assigned Employee')&&str_contains($sim,'Kitchen Station'),'single operator and station controls');
$check(str_contains($p,"'previewEmployeeUserId'"),'reuse existing display preference schema');
$check(str_contains($p,"!app_has_permission('glasses.manage'"),'non-manager cannot select another employee');
$check(str_contains($p,'u.archived_at IS NULL'),'preview employee must be active');
$check(str_contains($r,'function glasses_employee_runtime_employee_update('),'physical assignment handler');
$check(str_contains($r,'glasses_employee_runtime_assert_binding('),'active binding required');
$check(str_contains($r,'employee_home_staff_exists('),'employee membership required');
$check(str_contains($r,'wearer_assignment_created'),'preserve external Vision Lab assignment');
$check(str_contains($r,"'glasses_training_sessions','glasses_build_sessions'"),'no change during active sessions');
$check(str_contains($r,'profile_confirmed_at=NULL'),'new wearer must reconfirm');
$check(str_contains($r,'runtime_employee_changed'),'auditable change');
$check(str_contains($api,"action==='runtime.employee_update'")&&str_contains($api,'app_verify_request_csrf($in)'),'CSRF required');
echo "glasses-runtime-operator-refinement-contract=ok\n";
