<?php
declare(strict_types=1);

require __DIR__.'/glasses-v11-model-release-candidate-contract.php';
require_once __DIR__.'/../includes/glasses-v11-shadow-validation.php';

function v119_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v119_count(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

v119_assert(glasses_v11_shadow_validation_ready($pdo),'V11 shadow validation migration must be installed.');
$policy=glasses_v11_shadow_policy([]);
v119_assert($policy['minimumFrames']>=100&&$policy['minimumCompletedRuns']>=3,'Default V11 shadow evidence must exceed the legacy single-run minimum.');
v119_assert($policy['minimumDevices']>=2&&$policy['minimumOperators']>=1,'V11 shadow validation must require production coverage.');

$rollout=glasses_vision_model_rollout_create($pdo,$org,[
  'targetPackagePublicId'=>$challengerPublic,
  'baselinePackagePublicId'=>$championPublic,
  'canaryPercent'=>5,
  'notes'=>'V11 Section 9 shadow-only validation fixture.',
],$actor);
v119_assert($rollout['status']==='draft','V11 shadow candidate rollout must remain draft.');

$empty=glasses_v11_shadow_validate($pdo,$org,$rollout['publicId'],[],$actor);
v119_assert($empty['status']==='failed'&&empty($empty['result']['canaryEligible']),'No production shadow evidence must fail closed.');
v119_assert(($empty['metrics']['frames']??-1)===0&&($empty['coverage']['devices']??-1)===0,'Failed validation must report exact evidence deficit.');
v119_assert(strlen((string)$empty['validationHash'])===64,'Shadow validation must be immutable and SHA-256 addressed.');

$again=glasses_v11_shadow_validate($pdo,$org,$rollout['publicId'],[],$actor);
v119_assert($again['publicId']===$empty['publicId'],'Identical shadow evidence/policy must deduplicate.');

v119_assert(v119_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=? AND status='draft'",[$org,$rollout['publicId']])===1,'Section 9 evaluation must not activate a rollout.');
v119_assert(v119_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_release_candidate' AND from_public_id=? AND relation='shadow_validated_as'",[$org,$approved['publicId']])===1,'Shadow validation must retain RC lineage.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-shadow-validation.php');
$models=file_get_contents(__DIR__.'/../includes/glasses-vision-models.php');
$deviceApi=file_get_contents(__DIR__.'/../api/glasses-device.php');
$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$migration=file_get_contents(__DIR__.'/../database/20261205_v11_shadow_production_validation.sql');

v119_assert(str_contains($migration,'ALTER TABLE glasses_vision_shadow_runs')&&str_contains($migration,'ALTER TABLE glasses_vision_shadow_events'),'Section 9 must extend the canonical shadow runtime.');
v119_assert(str_contains($migration,'CREATE TABLE glasses_vision_shadow_validations'),'Section 9 must persist immutable aggregate validation.');
foreach(['champion_latency_ms','challenger_latency_ms','challenger_timeout','challenger_runtime_error'] as $column)v119_assert(str_contains($migration,$column),'Missing V11 shadow telemetry column '.$column);
foreach(['vision.shadow_assignment','vision.shadow_report','vision.shadow_complete'] as $action)v119_assert(str_contains($deviceApi,$action),'Device API missing '.$action);
v119_assert(str_contains($labApi,'shadow_validation.evaluate'),'Vision Lab API must expose shadow validation evaluation.');
v119_assert(str_contains($page,'V11 Shadow Deployment &amp; Production Validation'),'Vision Lab must expose Section 9.');
v119_assert(str_contains($models,'V11 rollout requires a passing Section 9 shadow validation before canary activation.'),'Consequential activation must be gated on passing V11 validation.');
foreach(['glasses_vision_model_rollout_activate(','glasses_vision_model_rollout_advance(','kds_transition(','glasses_handoff_to_expo('] as $forbidden)v119_assert(!str_contains($source,$forbidden),'Section 9 shadow runtime must remain non-consequential: '.$forbidden);

echo "glasses-v11-shadow-production-validation-ok\n";
