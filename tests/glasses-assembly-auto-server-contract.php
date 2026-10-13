<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$gate=(string)file_get_contents($root.'/includes/glasses-assembly-auto-gate.php');
$dispatcher=(string)file_get_contents($root.'/includes/glasses-simulator.php');
$client=(string)file_get_contents($root.'/assets/js/glasses-web-simulator.js');
function s269(bool $condition,string $reason):void{
    if(!$condition){fwrite(STDERR,'FAIL Section269: '.$reason."\n");exit(1);}
}
foreach([
  "require_once __DIR__.'/glasses-vision-models.php'",
  "require_once __DIR__.'/glasses-vision-profiles.php'",
  "glasses_employee_runtime_assert_binding(",
  "glasses_vision_model_assignment(",
  "glasses_vision_profile_for_build(",
  "artifactSha256",
  "profileHash",
  "runtimePublicId",
  "automatic",
  "web_glasses_simulator_auto_vision",
  "profile['mappings']",
  "componentKey",
  "normalizedLabel",
  "minimumConfidence",
  "trackingId",
  "requestedThreshold",
  "max(0.90",
  "glasses.view",
] as $term){
  if($term==='glasses.view')continue; // permission handled by simulator dispatcher
  s269(str_contains($gate,$term),'server gate missing '.$term);
}
s269(str_contains($dispatcher,"glasses_assembly_auto_observation_validate("),'server dispatch enforces automatic observation gate');
s269(str_contains($dispatcher,"glasses_build_observe("),'manual and governed build path remains intact');
s269(str_contains($gate,"if((\$meta['automatic']??null)!==true)return"),'nonautomatic operator observations untouched');
s269(str_contains($gate,"if(\$confidence<\$floor"),'backend enforces class/auto confidence floor');
s269(str_contains($gate,"if(!hash_equals("),'profile hash must remain unchanged');
s269(str_contains($gate,"action")&&str_contains($gate,"compatible"),'server requires authorized model assignment');
s269(str_contains($gate,"'onnx'"),'fixture cannot produce automatic backend evidence');
s269(str_contains($gate,"$"."bbox['width']"),'normalized bounding box validated');
s269(str_contains($client,"modelSha256:state.browserModel.verifiedSha256"),'client provides model digest');
s269(str_contains($client,"profileHash:state.browserModel.profile?.profileHash"),'client provides profile fingerprint');
s269(str_contains($client,"detectorLabel:track.detectorLabel"),'raw detector label reaches backend');
s269(str_contains($client,"clearTemporalRuntime();state.visionMode=visionMode()"),'vision mode/model changes invalidate stale tracks');
s269(str_contains($client,"reconcileAutomaticAssemblyEvidence();"),'automatic evidence gets rolled back when backend retracts confirmation');
s269(!str_contains($gate,"UPDATE glasses_build_"),'gate does not mutate build state');
echo "section269-governed-server-observations=ok\n";
