<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$release=file_get_contents($root.'/includes/glasses-vision-training-release.php');
$api=file_get_contents($root.'/api/glasses-vision-lab.php');
$page=file_get_contents($root.'/glasses-vision-lab.php');
$migration=file_get_contents($root.'/database/20261031_vision_lab_v6_training_releases.sql');

$checks=[
    'immutable release table'=>str_contains($migration,'CREATE TABLE glasses_vision_training_releases')&&str_contains($migration,'UNIQUE KEY uq_glasses_vision_training_release_hash'),
    'frozen dataset gate'=>str_contains($release,"status']!=='frozen'")&&str_contains($release,'freeze the governed dataset first'),
    'applied split required'=>str_contains($release,"status='applied'")&&str_contains($release,'no applied governed split plan'),
    'no resplit contract'=>str_contains($release,'stored dataset split does not match the applied governed split')&&!str_contains($release,'random_shuffle'),
    'sha verified source bytes'=>str_contains($release,"hash_file('sha256'")&&str_contains($release,'media SHA-256 verification failed'),
    'deterministic package identity'=>str_contains($release,'glasses_vision_training_release_core_hash')&&str_contains($release,'sorted package-relative path + NUL'),
    'yolo package layout'=>str_contains($release,"'images/'.$split")&&str_contains($release,"'labels/'.$split")&&str_contains($release,"data.yaml"),
    'provenance and checksums'=>str_contains($release,'provenance.json')&&str_contains($release,'checksums.sha256')&&str_contains($release,'release-manifest.json'),
    'private paths excluded'=>str_contains($release,"'privatePathsExcluded'=>true")&&!str_contains($release,"'storage_relative_path'=>"),
    'normalized archive metadata'=>str_contains($release,'setMtimeName')&&str_contains($release,'315532800'),
    'profile persisted'=>str_contains($migration,'profile_key')&&str_contains($release,'yolo_detection_v1'),
    'api wired'=>str_contains($api,"training_release.build")&&str_contains($api,'glasses_vision_training_release_build'),
    'ui wired'=>str_contains($page,'Training Release Builder')&&str_contains($page,'glasses-vision-training-release.js'),
];
foreach($checks as $name=>$ok){if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}echo "ok - $name\n";}
echo "Vision Lab V6 training release contract green\n";
