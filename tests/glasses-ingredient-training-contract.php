<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$backend=(string)file_get_contents($root.'/includes/glasses-ingredient-training.php');
$api=(string)file_get_contents($root.'/api/glasses-ingredient-training.php');
$layout=(string)file_get_contents($root.'/glasses-simulator.php');
function ingredientGate(bool $ok,string $why):void{
 if(!$ok){fwrite(STDERR,"FAIL section267: ".$why."\n");exit(1);}
}
foreach([
 'glasses_employee_runtime_assert_binding','glasses_training_session(',
 'glasses_vision_training_media_store(','glasses_work_menu_source(',
 'glasses_vision_training_samples','review_status','pending',
 'reviewRequired','training_media_opt_in','operator_user_id','wearer_assignment_id',
 'ingredientKey','canonicalIngredient','missionPublicId','captureGroup',
 'glasses_camera_locations','imageSource','file_upload','runtime_camera',
 'autoTrain','false','NOW(6)'
] as $str)ingredientGate(str_contains($backend,$str),'missing governed training integration '.$str);
ingredientGate(!str_contains($backend,'CREATE TABLE'),'must reuse existing Vision Lab schema');
ingredientGate(str_contains($backend,"in_array(\$source,['runtime_camera','file_upload']"),'capture source must use explicit allowlist');
ingredientGate(str_contains($api,'app_require_auth()'),'authentication required');
ingredientGate(str_contains($api,'app_verify_request_csrf($in)'),'CSRF required');
ingredientGate(str_contains($api,"glasses_ingredient_training_capture("),'API must use validated runtime bridge');
ingredientGate(str_contains($layout,'assets/js/glasses-ingredient-training-studio.js'),'studio must load in simulator');
ingredientGate(str_contains($layout,'assets/js/glasses-ingredient-training-plan.js'),'guidance policy must be loaded');
foreach(['simViewHudOnly','simViewGlasses','simNavPrevious','simNavNext','simNavPreviousItem','simNavNextItem','simNavItemAdded','simNavRepeat'] as $id)
 ingredientGate(!str_contains($layout,'id="'.$id.'"'),'redundant simulator button still rendered '.$id);
echo "section267-ingredient-training-php-contract=ok\n";
