<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-participant-history.php';
$actor=app_require_auth();$pdo=app_pdo();header('Cache-Control: no-store, private');
if(!fmph_ready($pdo))app_json_response(['ok'=>false,'message'=>'Run the protected Gelato database upgrade first.'],503);
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $action=(string)($_GET['action']??'workspace');$target=(int)($_GET['userId']??$actor['id']);
  if($action==='workspace'){
   $mine=$target===(int)$actor['id'];
   app_json_response(['ok'=>true,'userId'=>$target,'ownWorkspace'=>$mine,
    'events'=>fmph_read($pdo,$actor,$target,75),'goals'=>fmph_goals($pdo,$actor,$target),
    'folders'=>$mine?fmph_folders($pdo,$actor):[],
    'summary'=>fmph_agent_summary($pdo,$actor,$target)]);
  }
  if($action==='summary')app_json_response(['ok'=>true,'summary'=>fmph_agent_summary($pdo,$actor,$target)]);
  throw new InvalidArgumentException('Unknown workspace request.');
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Unsupported method.'],405);}
 $input=app_json_input();app_verify_request_csrf($input);
 if(($input['action']??'')==='link_thread')$folders=fmph_add_folder($pdo,$actor,(string)($input['threadId']??''),(string)($input['title']??''));
 elseif(($input['action']??'')==='remove_thread')$folders=fmph_remove_folder($pdo,$actor,(string)($input['folderId']??''));
 else throw new InvalidArgumentException('Unsupported participant action.');
 app_json_response(['ok'=>true,'folders'=>$folders]);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(Throwable $e){error_log('Participant workspace unavailable: '.get_class($e));app_json_response(['ok'=>false,'message'=>'Participant workspace unavailable.'],503);}
