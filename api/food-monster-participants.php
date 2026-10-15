<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-participants.php';
$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
header('Cache-Control: no-store, private');
try{app_json_response(['ok'=>true]+fmpt_roster(app_pdo(),$user));}
catch(Throwable $e){error_log('Food Monster participant roster read: '.get_class($e));app_json_response(['ok'=>false,'message'=>'Participant roster is temporarily unavailable.'],503);}
