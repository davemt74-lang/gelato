<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pickup-fulfillment-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];

if(!pickup_fulfillment_can_view($user)) app_json_response(['ok'=>false,'message'=>'Online order operations permission required.'],403);
if(!pickup_fulfillment_ready($pdo)) app_json_response(['ok'=>false,'message'=>'Pickup fulfillment migration is not installed. Run upgrade.php.'],503);
