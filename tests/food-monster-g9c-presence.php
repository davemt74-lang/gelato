<?php
declare(strict_types=1);
$database=in_array('--db',$argv??[],true);
if($database)require __DIR__.'/../includes/bootstrap.php';
else{
    function app_role_has_owner_authority(array $role):bool{
        return (int)($role['is_owner_role']??0)===1
            || ((string)($role['role_slug']??'')==='super_admin'&&(int)($role['is_system_role']??0)===1);
    }
}
require __DIR__.'/../includes/food-monster-room-presence.php';
$n=0;
function fm9c_ok(bool $x,string $label):void{
    global $n;if(!$x){fwrite(STDERR,"FM-G9C FAIL: ".$label."\n");exit(1);}++$n;
}
$owner=['id'=>1,'organization_id'=>10,'display_name'=>'Owner','is_owner_role'=>1,'role_slug'=>'super_admin','is_system_role'=>1,'permissions'=>['*']];
$staff=['id'=>2,'organization_id'=>10,'role_slug'=>'staff','is_owner_role'=>0,'is_system_role'=>1,'permissions'=>[]];
$track='r-1234567890abcdef';
$tracks=fm9c_normalize_tracks([['trackId'=>$track,'box'=>[.1,.2,.3,.4],'localProfileEmail'=>'COOK@EXAMPLE.TEST']]);
fm9c_ok(count($tracks)===1&&$tracks[0]['localProfileEmail']==='cook@example.test','observation normalization');
fm9c_ok(fm9c_empty_room()['status']==='not_connected','no phantom person');
fm9c_ok(fm9c_room_context($owner)['status']==='not_connected','default room absent');
fm9c_ok(fm9c_room_context($staff,['org'=>10,'operator'=>2,'seen'=>time(),'people'=>[]])['status']==='not_connected','employee cannot view room context');
foreach([
 [['trackId'=>'fake','box'=>[.1,.1,.2,.2]]],
 [['trackId'=>$track,'box'=>[-.1,0,.2,.2]]],
 [['trackId'=>$track,'box'=>[.9,0,.3,.2]]],
 [['trackId'=>$track,'box'=>[.1,.1,0,.2]]],
 [['trackId'=>$track,'box'=>[.1,.1,.2,.2],'localProfileEmail'=>'bad identifier']],
 [['trackId'=>$track,'box'=>[.1,.1,.2,.2]],['trackId'=>$track,'box'=>[.3,.3,.2,.2]]],
] as $case){try{fm9c_normalize_tracks($case);fm9c_ok(false,'invalid tracks rejected');}
catch(InvalidArgumentException){fm9c_ok(true,'invalid tracks rejected');}}
$tooMany=[];for($i=0;$i<9;$i++)$tooMany[]=['trackId'=>'r-'.sprintf('%016x',$i),'box'=>[0,0,.1,.1]];
try{fm9c_normalize_tracks($tooMany);fm9c_ok(false,'track volume capped');}
catch(InvalidArgumentException){fm9c_ok(true,'track volume capped');}
$valid=['org'=>10,'operator'=>1,'seen'=>1000,'people'=>[['trackId'=>$track,'personId'=>null,'identitySource'=>'unknown']]];
fm9c_ok(fm9c_room_context($owner,$valid,1001)['status']==='active','current observation visible');
fm9c_ok(fm9c_room_context($owner,$valid,1017)['status']==='not_connected','stale observation expires');
fm9c_ok(fm9c_room_context($owner,$valid,997)['status']==='not_connected','future timestamps rejected');
$another=$owner;$another['organization_id']=11;
fm9c_ok(fm9c_room_context($another,$valid,1001)['status']==='not_connected','organization isolation');
$another=$owner;$another['id']=3;
fm9c_ok(fm9c_room_context($another,$valid,1001)['status']==='not_connected','operator session isolation');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-presence.php');
$space=(string)file_get_contents(__DIR__.'/../includes/food-monster-identity-authority.php');
$core=(string)file_get_contents(__DIR__.'/../includes/food-monster-room-presence.php');
$js=(string)file_get_contents(__DIR__.'/../js/food-monster-room.js');
fm9c_ok(str_contains($api,'app_verify_request_csrf($in)')&&str_contains($api,'fm9a_is_super_admin_session($user)'),'authenticated CSRF protected endpoint');
fm9c_ok(str_contains($space,"$"."context['room']=fm9c_room_context($"."operator)"),'room context attached to Agent Brain');
fm9c_ok(str_contains($core,"'identityAssurance'=>'none'")||str_contains($core,"'identityAssurance'=>$"."id!==null?'provisional_visual_match':'none'"),'client face evidence never elevated');
fm9c_ok(str_contains($core,"'websiteAuthenticated'=>false")&&str_contains($core,"'agentAdministration'=>false"),'camera never grants login or administrator');
fm9c_ok(!str_contains($js,'fmpin_signin')&&!str_contains($js,'pin_login'),'camera cannot enter website login pathway');
fm9c_ok(!str_contains($js,'localStorage.setItem')&&!str_contains($js,'sendBeacon'),'no new biometric persistence or beacon');
fm9c_ok(!str_contains($api,'session_regenerate_id')&&!str_contains($core,'session_regenerate_id'),'room observation never changes login');
fm9c_ok(str_contains($js,'visibilitychange')&&str_contains($js,'pagehide'),'camera closes on tab hide/exit');
if($database){
    $pdo=app_pdo();app_boot_session();$oldSession=$_SESSION;
    fm9c_ok(fmpin_ready($pdo),'existing Face/PIN tables available');
    $pdo->beginTransaction();
    try{
        $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9C CI','America/Phoenix')");
        $org=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);
        $location=(int)$pdo->lastInsertId();
        $user=static function(string $name)use($pdo,$org,$location):array{
            $email=strtolower(str_replace(' ','',$name)).'-'.bin2hex(random_bytes(4)).'@example.test';
            $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$email,'x',$name,'Test',$name]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Employee','active')")->execute([$org,$id,$location]);
            return ['id'=>$id,'email'=>$email,'membership'=>(int)$pdo->lastInsertId()];
        };
        $ownerRow=$user('Owner');$cook=$user('Cook');$manager=$user('Manager');
        $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Super Admin','super_admin',1,1)")->execute([$org]);$ownerRole=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Staff','staff',1,0)")->execute([$org]);$staffRole=(int)$pdo->lastInsertId();
        foreach([[$ownerRow['membership'],$ownerRole],[$cook['membership'],$staffRole],[$manager['membership'],$staffRole]] as $p)
            $pdo->prepare('INSERT INTO user_roles (membership_id,role_id) VALUES (?,?)')->execute($p);
        $o=$owner;$o['id']=$ownerRow['id'];$o['organization_id']=$org;
        $secret=bin2hex(random_bytes(32));
        $_COOKIE[fmpin_cookie()]=$secret;
        $pdo->prepare("INSERT INTO fm_pin_devices (organization_id,secret_hash,label,created_by,expires_at) VALUES (?,?,?, ?,DATE_ADD(NOW(6),INTERVAL 30 DAY))")
            ->execute([$org,hash('sha256',$secret),'CI Terminal',$ownerRow['id']]);
        $device=(int)$pdo->lastInsertId();
        fm9c_ok(fm9c_local_staff($pdo,$org,$cook['email'])===null,'unconsented profile never recognized');
        $pdo->prepare('INSERT INTO fm_pin_device_accounts (device_id,organization_id,user_id) VALUES (?,?,?)')->execute([$device,$org,$cook['id']]);
        fm9c_ok(fm9c_local_staff($pdo,$org,$cook['email'])!==null,'existing consenting device profile resolved');
        fm9c_ok(fm9c_local_staff($pdo,$org,$ownerRow['email'])===null,'Super Admin cannot be spoofed by local employee face');
        fm9c_ok(fm9c_local_staff($pdo,$org,$manager['email'])===null,'unenrolled staff stays unknown');
        fm9c_ok(fm9c_local_staff($pdo,$org+1,$cook['email'])===null,'cross-org face claim rejected');
        $obs=[['trackId'=>$track,'box'=>[.1,.1,.2,.3],'localProfileEmail'=>$cook['email']],['trackId'=>'r-1234567890abcdee','box'=>[.5,.1,.2,.3]]];
        $result=fm9c_room_update($pdo,$o,$obs);
        fm9c_ok($result['status']==='active'&&count($result['people'])===2,'two simultaneous room tracks');
        fm9c_ok($result['people'][0]['personId']===$cook['id'],'recognized existing employee id');
        fm9c_ok($result['people'][1]['personId']===null,'unknown person remains unknown');
        fm9c_ok($result['currentSpeaker']===null,'camera never fabricates speaker');
        fm9c_ok($result['people'][0]['agentAdministration']===false,'face match has no delegated permissions');
        fm9c_ok(!isset($result['people'][0]['email']),'email never echoed to UI/Agent context');
        $duplicate=[['trackId'=>$track,'box'=>[.1,.1,.2,.2],'localProfileEmail'=>$cook['email']],['trackId'=>'r-1234567890abcddd','box'=>[.5,.1,.2,.2],'localProfileEmail'=>$cook['email']]];
        $ambiguous=fm9c_room_update($pdo,$o,$duplicate);
        fm9c_ok($ambiguous['people'][0]['personId']===null&&$ambiguous['people'][1]['personId']===null,'two claimed matches for same face become ambiguous');
        $s=$staff;$s['organization_id']=$org;
        try{fm9c_room_update($pdo,$s,[]);fm9c_ok(false,'non-owner posted presence');}
        catch(DomainException){fm9c_ok(true,'non-owner cannot record presence');}
        $pdo->prepare('UPDATE fm_pin_devices SET revoked_at=NOW(6) WHERE id=?')->execute([$device]);
        fm9c_ok(fm9c_local_staff($pdo,$org,$cook['email'])===null,'revoke trusted terminal invalidates face association');
        fm9c_room_stop($o);
        fm9c_ok(fm9c_room_context($o)['status']==='not_connected','camera stop clears active room');
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        $_SESSION=$oldSession;
        unset($_COOKIE[fmpin_cookie()]);
    }
}
echo "FM-G9C room identity contract PASS ($n assertions".($database?', database-backed':'').")\n";
