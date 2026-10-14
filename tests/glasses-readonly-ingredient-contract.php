<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$server=(string)file_get_contents($root.'/includes/glasses-vision-models.php');
$dispatcher=(string)file_get_contents($root.'/includes/glasses-simulator.php');
$api=(string)file_get_contents($root.'/api/glasses-simulator.php');
$page=(string)file_get_contents($root.'/glasses-vision-models.php');
$assert=static function(bool $ok,string $reason):void{
    if(!$ok){fwrite(STDERR,"readonly-ingredient-model: $reason\n");exit(1);}
};
$start=strpos($server,'function glasses_vision_readonly_ingredient_assignment(');
$assert($start!==false,'read-only assignment resolver exists');
$body=substr($server,(int)$start);
$assert(str_contains($body,"app_has_permission('glasses.view'")&&
    str_contains($body,"app_has_permission('kds.view'"),'requires both permissions');
$assert(str_contains($body,"operational_location_allowed(\$pdo,\$user,'glasses.view'")&&
    str_contains($body,"operational_location_allowed(\$pdo,\$user,'kds.view'"),'scope checked');
$assert(str_contains($body,"tp.platform='browser_preview'"),'browser rollout separate from AIR3');
$assert(str_contains($body,"r.status IN ('active','paused','rolled_back')"),'governed rollout required');
$assert(str_contains($body,"if(\$status==='paused')"),'paused rollout held');
$assert(str_contains($body,"package['status']!=='ready'"),'ready package required');
$assert(str_contains($body,"runtime_type']!=='onnx'"),'ONNX required');
$assert(str_contains($body,"browser_inference_metadata_required"),'YOLO metadata required');
$assert(str_contains($body,"minimum_sdk_version"),'no unknown native version floors');
$assert(str_contains($body,"mode'=>'read_only'"),'explicitly read-only');
$assert(!str_contains($body,'glasses_vision_model_assignment_record('),'no build ledger writes');
$assert(!str_contains($body,'buildSessionPublicId'),'read-only not tied to build');
$start=strpos($dispatcher,"if(\$action==='vision.ingredient_readonly_assignment')");
$build=strpos($dispatcher,"\$session=trim((string)(\$in['buildSessionPublicId']");
$assert($start!==false&&$build!==false&&$start<$build,'dispatch before build-session requirement');
$assert(str_contains($dispatcher,'glasses_vision_readonly_ingredient_assignment('),'approved resolver called');
$assert(str_contains($api,'app_verify_request_csrf($in)'),'CSRF before POST');
$assert(str_contains($page,'browser_preview'),'registration guidance for browser platform');
echo "glasses-readonly-ingredient-contract=ok\n";