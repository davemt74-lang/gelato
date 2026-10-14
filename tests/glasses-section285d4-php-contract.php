<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');
$api=file_get_contents(__DIR__.'/../api/glasses-simulator.php');
$checks=[
 'separate action'=>str_contains($source,"'kds.item.ingredient_review'"),
 'authenticated csrf dispatch'=>str_contains($api,'app_verify_request_csrf'),
 'permission check'=>str_contains($source,"app_has_permission('kds.update'"),
 'location scope'=>str_contains($source,"operational_location_allowed(") ,
 'item station scope'=>str_contains($source,"$".'item[\'station_id\']'),
 'in progress only'=>str_contains($source,"$"."expected!=='in_progress'"),
 'item row lock'=>str_contains($source,'kds_item($pdo,$org,$itemPublicId,true)'),
 'unique review token'=>str_contains($source,"preg_match('/^[a-f0-9]{32}$/D'"),
 'event idempotency'=>str_contains($source,'event_type=\'glasses_ingredient_review\' AND note LIKE ?'),
 'original undo verification'=>str_contains($source,'original operator approval was not found'),
 'server menu ingredient gate'=>str_contains($source,"'menu']['ingredients'"),
 'append only event'=>str_contains($source,'kds_event($pdo,$org,(int)$locked[\'id\']'),
 'durable audit'=>str_contains($source,'glasses.operator_ingredient_review'),
 'explicit no camera verification'=>str_contains($source,"'cameraVerified'=>false"),
 'explicit no KDS status updates'=>str_contains($source,"'kdsStatusChanged'=>false"),
];
foreach($checks as $name=>$passed){if(!$passed){fwrite(STDERR,'FAIL '.$name."\n");exit(1);}}
echo '285d4-php-security-contract='.count($checks).'/'.count($checks)."\n";
