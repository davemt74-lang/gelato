<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'Glasses permission required.'],403);
$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
header('Cache-Control: private, no-store, max-age=0');
try{
    $check=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name='glasses_user_display_preferences'");
    if((int)$check->fetchColumn()!==1)
        app_json_response(['ok'=>false,'message'=>'User display preferences require upgrade.php.'],503);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $q=$pdo->prepare('SELECT settings_json,revision FROM glasses_user_display_preferences
            WHERE organization_id=? AND user_id=? LIMIT 1');
        $q->execute([$org,$uid]);$data=$q->fetch(PDO::FETCH_ASSOC);
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),
            'revision'=>$data?(int)$data['revision']:0,
            'settings'=>$data?(json_decode((string)$data['settings_json'],true)?:[]):[]]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $input=app_json_input();
    app_verify_request_csrf($input);
    $settings=$input['settings']??null;
    if(!is_array($settings)||count($settings)>12)throw new InvalidArgumentException('Invalid simulator settings.');
    $keys=['mode','devicePublicId','frameMode','opticalMask','cameraResolution','cameraFit',
        'cameraMirror','calibration','activeTab','activeCameraTab','voiceReplies'];
    foreach($settings as $key=>$value)
        if(!in_array($key,$keys,true))throw new InvalidArgumentException('Unexpected setting: '.substr((string)$key,0,25));
    $enums=[
        'mode'=>['live','mock'],
        'frameMode'=>['svg','image','none'],
        'cameraResolution'=>['640x480','1280x720','1920x1080'],
        'cameraFit'=>['cover','contain'],
        'activeTab'=>['overview','voice','camera','runtime','build'],
        'activeCameraTab'=>['camera1','camera2','camera3','camera4','camera5','training']
    ];
    foreach($enums as $key=>$values)
        if(isset($settings[$key])&&(!is_string($settings[$key])||!in_array($settings[$key],$values,true)))
            throw new InvalidArgumentException('Invalid option for '.$key);
    foreach(['opticalMask','cameraMirror','voiceReplies'] as $key)
        if(isset($settings[$key])&&!is_bool($settings[$key]))
            throw new InvalidArgumentException('Invalid toggle for '.$key);
    if(isset($settings['devicePublicId'])&&
        (!is_string($settings['devicePublicId'])||strlen($settings['devicePublicId'])>180))
        throw new InvalidArgumentException('Invalid display selection.');
    if(isset($settings['calibration'])){
        if(!is_array($settings['calibration'])||strlen((string)json_encode($settings['calibration']))>2200)
            throw new InvalidArgumentException('Calibration exceeds supported limits.');
        // Always constrain calibration to known numerical keys; never store arbitrary scripts/data.
        $known=['opacity','brightness','scale','safeWidth','safeHeight','leftEyeX','leftEyeY','rightEyeX','rightEyeY'];
        $bounds=['opacity'=>[20,100],'brightness'=>[50,180],'scale'=>[60,140],
            'safeWidth'=>[45,95],'safeHeight'=>[35,90],'leftEyeX'=>[-20,20],
            'leftEyeY'=>[-20,20],'rightEyeX'=>[-20,20],'rightEyeY'=>[-20,20]];
        $cal=$settings['calibration'];
        foreach($cal as $key=>$value){
            if($key==='regions')continue;
            if(!in_array($key,$known,true)||!is_numeric($value)
                ||(float)$value<$bounds[$key][0]||(float)$value>$bounds[$key][1])
                throw new InvalidArgumentException('Invalid calibration value.');
        }
        if(isset($cal['regions'])){
            if(!is_array($cal['regions']))throw new InvalidArgumentException('Invalid calibration regions.');
            foreach($cal['regions'] as $name=>$coords){
                if(!in_array($name,['hudRight','hudStatus','hudOrdersRegion'],true)||!is_array($coords))
                    throw new InvalidArgumentException('Invalid projection region.');
                foreach($coords as $axis=>$number){
                    if(!in_array($axis,['x','y','w','h'],true)||!is_numeric($number)
                        ||(float)$number<0||(float)$number>100)
                        throw new InvalidArgumentException('Invalid projection geometry.');
                }
            }
        }
    }
    $raw=json_encode($settings,JSON_THROW_ON_ERROR);
    if(strlen($raw)>4096)throw new InvalidArgumentException('User preferences are too large.');
    $q=$pdo->prepare("INSERT INTO glasses_user_display_preferences(organization_id,user_id,settings_json)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json),revision=revision+1,updated_at=NOW(6)");
    $q->execute([$org,$uid,$raw]);
    app_json_response(['ok'=>true,'saved'=>true]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[glasses-user-preferences] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Your UI preferences could not be saved.'],503);
}
