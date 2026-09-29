<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-qualification.php';

function v66_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$pdo=app_pdo();
v66_assert(glasses_vision_training_qualification_ready($pdo),'V6 qualification migration must be installed.');

$floor=glasses_vision_training_qualification_floor('yolo11n_640');
v66_assert($floor['minimumSamplesPerClass']===20&&$floor['maximumLeakageGroups']===0&&$floor['maximumUnresolvedDisagreements']===0,'Qualification floors must enforce corpus minimums and zero-tolerance blockers.');
$policy=glasses_vision_training_qualification_policy('yolo11n_640',['minimumSamplesPerClass'=>1,'minimumHardExamples'=>50]);
v66_assert($policy['minimumSamplesPerClass']===20&&$policy['minimumHardExamples']===50,'Requested qualification policy may tighten but never weaken profile floors.');
v66_assert(glasses_vision_training_qualification_check('a','A',20,20,'>=')['passed'],'Minimum check must pass equality.');
v66_assert(!glasses_vision_training_qualification_check('a','A',1,0,'<=')['passed'],'Zero-tolerance maximum check must reject blockers.');

$source=file_get_contents(__DIR__.'/../includes/glasses-vision-training-qualification.php');
$pipeline=file_get_contents(__DIR__.'/../tools/vision_training/pipeline.py');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');

foreach(['samples_per_class','negative_examples','hard_examples','operator_diversity','location_diversity','station_diversity','device_diversity','pose_diversity','lighting_diversity','split_leakage','annotation_disagreements','annotation_completeness','exact_duplicates','poor_media','capture_group_leakage'] as $key)
    v66_assert(str_contains($source,"'".$key."'"),'Qualification gate missing '.$key.'.');

v66_assert(str_contains($source,"status='qualified'")&&str_contains($source,"status='blocked'")&&str_contains($source,'qualification.json'),'Qualification outcomes must persist and only passing attestations unlock artifacts.');
v66_assert(str_contains($pipeline,'require_qualification=True')&&str_contains($pipeline,'verify_training_qualification'),'Model training must require a verified qualification attestation.');
v66_assert(str_contains($pipeline,'qualificationHash')&&str_contains($pipeline,'releaseHash'),'Trainer must bind qualification to both qualification and release hashes.');
v66_assert(str_contains($api,'training_qualification.run')&&!str_contains($api,'kds_transition'),'Qualification API must not mutate production KDS truth.');
v66_assert(str_contains($page,'Pre-Training Qualification Gate'),'Vision Lab must expose the readiness scorecard workspace.');

echo "vision-lab-v6-pretraining-qualification-ok\n";
