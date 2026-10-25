<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-room-presence.php';
require_once __DIR__.'/../includes/agent-eyes-enrollment.php';
$user=app_current_user();
if(!$user||!fm9a_is_super_admin_session($user))
    app_json_response(['ok'=>false,'message'=>'Super Admin session required for room presence.'],403);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'status')==='enroll_candidates')
            app_json_response(['ok'=>true,'people'=>fmeyes_candidates(app_pdo(),$user)]);
        if(($_GET['action']??'status')!=='status')throw new InvalidArgumentException('Invalid camera action.');
        app_json_response(['ok'=>true,'room'=>fm9c_room_context($user),
            'deviceProfilesPossible'=>fmpin_ready(app_pdo())&&(bool)fmpin_device(app_pdo())]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();app_verify_request_csrf($in);
    $action=(string)($in['action']??'');
    if($action==='enroll_commit'){
        $person=fmeyes_commit(app_pdo(),$user,(int)($in['personId']??0),($in['consent']??false)===true);
        app_json_response(['ok'=>true,'person'=>$person]);
    }
    if($action==='enroll_revoke'){
        $result=fmeyes_revoke(app_pdo(),$user,(int)($in['personId']??0));
        app_json_response(['ok'=>true]+$result);
    }
    if($action==='stop'){
        fm9c_room_stop($user);
        app_json_response(['ok'=>true,'room'=>fm9c_empty_room()]);
    }
    if($action==='observe'){
        $tracks=$in['tracks']??null;
        if(!is_array($tracks))throw new InvalidArgumentException('Camera tracks required.');
        $room=fm9c_room_update(app_pdo(),$user,$tracks);
        app_json_response(['ok'=>true,'room'=>$room]);
    }
    throw new InvalidArgumentException('Unsupported room presence action.');
}catch(InvalidArgumentException $error){
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);
}catch(DomainException $error){
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],403);
}catch(Throwable $error){
    error_log('FM-G9C room presence: '.get_class($error));
    app_json_response(['ok'=>false,'message'=>'Room presence temporarily unavailable.'],503);
}
