<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-speaker.php';
$actor=app_require_auth();header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);
if(($input['action']??'')!=='identify')app_json_response(['ok'=>false,'message'=>'Unsupported action.'],422);
if(!fmspeaker_can_roster($actor))app_json_response(['ok'=>false,'message'=>'Voice roster permission required.'],403);
if(($input['consent']??null)!==true)app_json_response(['ok'=>false,'message'=>'The speaker must agree to this identification sample.'],422);
$now=time();$key='fm_speaker_'.(int)$actor['organization_id'].'_'.(int)$actor['id'];
if($now-(int)($_SESSION[$key]??0)<10)app_json_response(['ok'=>false,'message'=>'Wait before checking another voice sample.'],429);
$_SESSION[$key]=$now;
try{
 $features=$input['features']??null;
 if(!is_array($features)||count($features)<12||count($features)>64)throw new InvalidArgumentException('Voice sample was invalid.');
 app_json_response(['ok'=>true,'match'=>fmspeaker_identify(app_pdo(),$actor,$features),
  'notice'=>'Voice is a provisional speaker cue; not login, attendance or a visual-track identity.']);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('FM speaker identification: '.get_class($e));app_json_response(['ok'=>false,'message'=>'Speaker matching unavailable.'],503);}
