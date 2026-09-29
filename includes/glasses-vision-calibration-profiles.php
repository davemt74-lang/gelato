<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-context-drift.php';
require_once __DIR__.'/glasses-calibration.php';

const GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA='gelato.vision_calibration_profile.v1';

function glasses_vision_calibration_profile_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_calibration_profiles','glasses_vision_calibration_selections'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_context_drift_ready($pdo)&&glasses_station_calibration_ready($pdo);
}

function glasses_vision_calibration_profile_context(array $in): array
{
    $range=function(string $key,float $minAllowed,float $maxAllowed)use($in):?array{
        $v=$in[$key]??null;
        if($v===null||$v==='')return null;
        if(!is_array($v))throw new InvalidArgumentException('Calibration profile '.$key.' range is invalid.');
        $min=array_key_exists('min',$v)?(float)$v['min']:$minAllowed;
        $max=array_key_exists('max',$v)?(float)$v['max']:$maxAllowed;
        if(!is_finite($min)||!is_finite($max)||$min<$minAllowed||$max>$maxAllowed||$min>$max)
            throw new InvalidArgumentException('Calibration profile '.$key.' range is invalid.');
        return ['min'=>round($min,6),'max'=>round($max,6)];
    };
    $set=function(string $key)use($in):array{
        $v=$in[$key]??[];
        if(!is_array($v))throw new InvalidArgumentException('Calibration profile '.$key.' must be a list.');
        $out=array_values(array_unique(array_filter(array_map(static fn($x)=>mb_substr(trim((string)$x),0,190,'UTF-8'),$v),static fn($x)=>$x!=='')));
        sort($out,SORT_STRING);return $out;
    };
    return [
      'brightness'=>$range('brightness',0,1),'contrast'=>$range('contrast',0,1),
      'cameraPitch'=>$range('cameraPitch',-180,180),'cameraYaw'=>$range('cameraYaw',-180,180),'cameraRoll'=>$range('cameraRoll',-180,180),
      'menuSignatures'=>$set('menuSignatures'),'ingredientSignatures'=>$set('ingredientSignatures'),
    ];
}

function glasses_vision_calibration_profile_calibration(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT c.*,s.public_id station_public_id FROM glasses_station_calibrations c
      JOIN kds_stations s ON s.id=c.station_id AND s.organization_id=c.organization_id
      WHERE c.organization_id=? AND c.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Station calibration was not found.');
    return $r;
}

function glasses_vision_calibration_profile_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT p.*,c.public_id calibration_public_id,c.source_hash calibration_source_hash,c.status calibration_status,c.platform,c.frame_width,c.frame_height,c.pixel_format,
      s.public_id station_public_id,m.public_id model_package_public_id,m.artifact_sha256 model_package_sha256
      FROM glasses_vision_calibration_profiles p
      JOIN glasses_station_calibrations c ON c.id=p.calibration_id AND c.organization_id=p.organization_id
      JOIN kds_stations s ON s.id=p.station_id AND s.organization_id=p.organization_id
      LEFT JOIN glasses_vision_model_packages m ON m.id=p.package_id AND m.organization_id=p.organization_id
      WHERE p.organization_id=? AND p.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Calibration profile was not found.');
    return [
      'schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'publicId'=>$r['public_id'],'profileKey'=>$r['profile_key'],'status'=>$r['status'],'priority'=>(int)$r['priority'],
      'calibrationPublicId'=>$r['calibration_public_id'],'calibrationSourceHash'=>$r['calibration_source_hash'],'calibrationStatus'=>$r['calibration_status'],
      'modelPackagePublicId'=>$r['model_package_public_id'],'modelPackageSha256'=>$r['model_package_sha256'],
      'locationId'=>(int)$r['location_id'],'stationPublicId'=>$r['station_public_id'],
      'platform'=>$r['platform'],'frame'=>['width'=>(int)$r['frame_width'],'height'=>(int)$r['frame_height'],'pixelFormat'=>$r['pixel_format']],
      'context'=>json_decode((string)$r['context_json'],true)?:[],'guardrails'=>json_decode((string)$r['guardrails_json'],true)?:[],
      'profileHash'=>$r['profile_hash'],'activatedAt'=>$r['activated_at'],'retiredAt'=>$r['retired_at'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_calibration_profile_create(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_calibration_profile_ready($pdo))throw new RuntimeException('Vision Lab V8 calibration-profile migration is not installed.');
    $key=mb_substr(trim((string)($input['profileKey']??'')),0,160,'UTF-8');
    if($key===''||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,159}$/',$key))throw new InvalidArgumentException('Calibration profile key is invalid.');
    $cal=glasses_vision_calibration_profile_calibration($pdo,$org,(string)($input['calibrationPublicId']??''));
    $package=null;
    if(trim((string)($input['modelPackagePublicId']??''))!==''){
        $package=glasses_vision_model_package_row($pdo,$org,(string)$input['modelPackagePublicId'],false);
        if((string)$package['status']!=='ready')throw new InvalidArgumentException('Calibration profile selection requires a ready model package.');
    }
    $context=glasses_vision_calibration_profile_context(is_array($input['context']??null)?$input['context']:[]);
    $priority=max(-1000,min(1000,(int)($input['priority']??0)));
    $guardrails=['explicitActivationRequired'=>true,'fallbackToStationCalibration'=>true,'automaticThresholdChange'=>false,'automaticModelChange'=>false,'automaticCalibrationRewrite'=>false];
    $material=[
      'schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'profileKey'=>$key,
      'calibration'=>['publicId'=>$cal['public_id'],'sourceHash'=>$cal['source_hash'],'platform'=>$cal['platform'],'frameWidth'=>(int)$cal['frame_width'],'frameHeight'=>(int)$cal['frame_height'],'pixelFormat'=>$cal['pixel_format']],
      'model'=> $package?['publicId'=>$package['public_id'],'artifactSha256'=>$package['artifact_sha256']]:null,
      'locationId'=>(int)$cal['location_id'],'stationPublicId'=>$cal['station_public_id'],'priority'=>$priority,'context'=>$context,'guardrails'=>$guardrails,
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($material));
    $q=$pdo->prepare("SELECT public_id,profile_hash FROM glasses_vision_calibration_profiles WHERE organization_id=? AND profile_key=? AND profile_hash=? LIMIT 1");
    $q->execute([$org,$key,$hash]);$existing=$q->fetch();
    if($existing)return glasses_vision_calibration_profile_row($pdo,$org,(string)$existing['public_id']);
    $public=glasses_public_id('vision-cal-profile');
    $pdo->prepare("INSERT INTO glasses_vision_calibration_profiles
      (organization_id,public_id,profile_key,calibration_id,package_id,location_id,station_id,status,priority,context_json,guardrails_json,profile_hash,created_by)
      VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?)")
      ->execute([$org,$public,$key,(int)$cal['id'],$package?(int)$package['id']:null,(int)$cal['location_id'],(int)$cal['station_id'],$priority,
        glasses_vision_training_release_json($context),glasses_vision_training_release_json($guardrails),$hash,$actor]);
    return glasses_vision_calibration_profile_row($pdo,$org,$public);
}

function glasses_vision_calibration_profile_set_status(PDO $pdo,int $org,string $publicId,string $status,int $actor): array
{
    $status=strtolower(trim($status));
    if(!in_array($status,['active','retired'],true))throw new InvalidArgumentException('Calibration profile status is invalid.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$status,$actor):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_calibration_profiles WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Calibration profile was not found.');
        if((string)$r['status']==='retired'&&$status==='active')throw new InvalidArgumentException('Retired calibration profiles cannot be reactivated.');
        if($status==='active'){
            $calStatus=(string)glasses_vision_calibration_profile_scalar($pdo,"SELECT status FROM glasses_station_calibrations WHERE organization_id=? AND id=?",[$org,(int)$r['calibration_id']]);
            if($calStatus!=='active')throw new InvalidArgumentException('Calibration profile cannot activate after its station calibration is superseded.');
            if($r['package_id']!==null){
                $packageStatus=(string)glasses_vision_calibration_profile_scalar($pdo,"SELECT status FROM glasses_vision_model_packages WHERE organization_id=? AND id=?",[$org,(int)$r['package_id']]);
                if($packageStatus!=='ready')throw new InvalidArgumentException('Calibration profile model package must be ready before activation.');
            }
            $pdo->prepare("UPDATE glasses_vision_calibration_profiles SET status='active',activated_by=?,activated_at=COALESCE(activated_at,NOW(6)),retired_at=NULL WHERE organization_id=? AND id=?")
              ->execute([$actor,$org,(int)$r['id']]);
        }else{
            $pdo->prepare("UPDATE glasses_vision_calibration_profiles SET status='retired',retired_at=NOW(6) WHERE organization_id=? AND id=?")
              ->execute([$org,(int)$r['id']]);
        }
        return glasses_vision_calibration_profile_row($pdo,$org,$publicId);
    });
}

function glasses_vision_calibration_profile_device(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_devices WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$d=$q->fetch();
    if(!$d)throw new InvalidArgumentException('Calibration profile device was not found.');
    if($d['location_id']===null||$d['station_id']===null)throw new InvalidArgumentException('Calibration profile selection requires a paired location and station.');
    return $d;
}

function glasses_vision_calibration_profile_context_from_input(array $input): array
{
    $n=function(string $key)use($input):?float{
        if(!array_key_exists($key,$input)||$input[$key]===null||$input[$key]==='')return null;
        $v=(float)$input[$key];if(!is_finite($v))throw new InvalidArgumentException('Live calibration context is invalid.');return round($v,6);
    };
    $str=function(string $key)use($input):?string{$v=trim((string)($input[$key]??''));return $v===''?null:mb_substr($v,0,190,'UTF-8');};
    return [
      'brightnessMean'=>$n('brightnessMean'),'contrastMean'=>$n('contrastMean'),'cameraPitchMean'=>$n('cameraPitchMean'),'cameraYawMean'=>$n('cameraYawMean'),'cameraRollMean'=>$n('cameraRollMean'),
      'menuSignature'=>$str('menuSignature'),'ingredientSignature'=>$str('ingredientSignature'),
    ];
}

function glasses_vision_calibration_profile_runtime(array $device,array $input,?array $analysis): array
{
    $runtime=is_array($input['runtime']??null)?$input['runtime']:[];
    $width=(int)($runtime['frameWidth']??0);$height=(int)($runtime['frameHeight']??0);
    $pixel=mb_strtolower(trim((string)($runtime['pixelFormat']??'')),'UTF-8');
    if(($width<1||$height<1||$pixel==='')&&$analysis){
        $geometries=(array)($analysis['evidence']['currentContext']['geometries']??[]);
        if(count($geometries)===1&&preg_match('/^(\d+)x(\d+)x(.+)$/',(string)$geometries[0],$m)){
            $width=(int)$m[1];$height=(int)$m[2];$pixel=mb_strtolower(trim((string)$m[3]),'UTF-8');
        }
    }
    if($width<16||$width>8192||$height<16||$height>8192||$pixel==='')
        throw new InvalidArgumentException('Calibration profile selection requires the current camera frame width, height, and pixel format.');
    return ['platform'=>(string)$device['platform'],'frameWidth'=>$width,'frameHeight'=>$height,'pixelFormat'=>$pixel];
}

function glasses_vision_calibration_profile_analysis_scope(PDO $pdo,int $org,array $analysis,array $device,?array $package): void
{
    $snapshotPublic=(string)($analysis['healthSnapshotPublicId']??'');
    $snapshot=glasses_vision_context_drift_snapshot_db($pdo,$org,$snapshotPublic);
    if($snapshot['_deviceId']===null||(int)$snapshot['_deviceId']!==(int)$device['id'])
        throw new InvalidArgumentException('Context-drift analysis does not belong to the selected device.');
    if((int)($snapshot['locationId']??0)!==(int)$device['location_id']||(int)($snapshot['_stationId']??0)!==(int)$device['station_id'])
        throw new InvalidArgumentException('Context-drift analysis does not belong to the selected station.');
    if($package!==null&&!hash_equals((string)$snapshot['packagePublicId'],(string)$package['public_id']))
        throw new InvalidArgumentException('Context-drift analysis does not belong to the selected model package.');
}

function glasses_vision_calibration_profile_rule_matches(array $profile,array $context): array
{
    $rules=$profile['context'];$matched=0;$specified=0;$reasons=[];
    foreach([['brightness','brightnessMean'],['contrast','contrastMean'],['cameraPitch','cameraPitchMean'],['cameraYaw','cameraYawMean'],['cameraRoll','cameraRollMean']] as [$rk,$ck]){
        $range=$rules[$rk]??null;if(!$range)continue;$specified++;$v=$context[$ck]??null;
        if($v===null){$reasons[]=$rk.'_missing';continue;}
        if((float)$v<(float)$range['min']||(float)$v>(float)$range['max']){$reasons[]=$rk.'_out_of_range';continue;}
        $matched++;
    }
    foreach([['menuSignatures','menuSignature'],['ingredientSignatures','ingredientSignature']] as [$rk,$ck]){
        $allowed=(array)($rules[$rk]??[]);if(!$allowed)continue;$specified++;$v=$context[$ck]??null;
        if($v===null){$reasons[]=$ck.'_missing';continue;}
        if(!in_array($v,$allowed,true)){$reasons[]=$ck.'_mismatch';continue;}
        $matched++;
    }
    return ['compatible'=>count($reasons)===0,'score'=>$specified>0?round($matched/$specified,6):0.1,'reasons'=>$reasons,'specified'=>$specified];
}

function glasses_vision_calibration_profile_select(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_calibration_profile_ready($pdo))throw new RuntimeException('Vision Lab V8 calibration-profile migration is not installed.');
    $device=glasses_vision_calibration_profile_device($pdo,$org,(string)($input['devicePublicId']??''));
    $package=null;
    if(trim((string)($input['modelPackagePublicId']??''))!=='')$package=glasses_vision_model_package_row($pdo,$org,(string)$input['modelPackagePublicId'],false);

    $runtime=is_array($input['runtime']??null)?$input['runtime']:[];
    $runtimePlatform=trim((string)($runtime['platform']??$device['platform']));
    $runtimeWidth=(int)($runtime['frameWidth']??0);$runtimeHeight=(int)($runtime['frameHeight']??0);
    $runtimePixel=mb_strtolower(trim((string)($runtime['pixelFormat']??'')),'UTF-8');

    $analysis=null;$context=[];
    if(trim((string)($input['contextDriftAnalysisPublicId']??''))!==''){
        $analysis=glasses_vision_context_drift_row($pdo,$org,(string)$input['contextDriftAnalysisPublicId']);
        if(!glasses_vision_context_drift_verify($pdo,$org,$analysis['publicId'])['passed'])throw new InvalidArgumentException('Calibration selection requires an intact context-drift analysis.');
        glasses_vision_calibration_profile_analysis_scope($pdo,$org,$analysis,$device,$package);
        $cc=(array)($analysis['evidence']['currentContext']??[]);
        $context=[
          'brightnessMean'=>$cc['brightnessMean']??null,'contrastMean'=>$cc['contrastMean']??null,'cameraPitchMean'=>$cc['cameraPitchMean']??null,
          'cameraYawMean'=>$cc['cameraYawMean']??null,'cameraRollMean'=>$cc['cameraRollMean']??null,
          'menuSignature'=>count((array)($cc['menuSignatures']??[]))===1?$cc['menuSignatures'][0]:null,
          'ingredientSignature'=>count((array)($cc['ingredientSignatures']??[]))===1?$cc['ingredientSignatures'][0]:null,
        ];
    }else{
        $context=glasses_vision_calibration_profile_context_from_input(is_array($input['context']??null)?$input['context']:[]);
    }
    $contextFingerprint=hash('sha256',glasses_vision_training_release_json($context));
    $runtime=glasses_vision_calibration_profile_runtime($device,$input,$analysis);

    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_calibration_profiles
      WHERE organization_id=? AND location_id=? AND station_id=? AND status='active'
        AND (package_id IS NULL OR package_id=?)
      ORDER BY priority DESC,id ASC");
    $q->execute([$org,(int)$device['location_id'],(int)$device['station_id'],$package?(int)$package['id']:0]);
    $candidates=[];$fallbackRuntime=['platform'=>$runtimePlatform];
    if($runtimeWidth>0)$fallbackRuntime['frameWidth']=$runtimeWidth;
    if($runtimeHeight>0)$fallbackRuntime['frameHeight']=$runtimeHeight;
    if($runtimePixel!=='')$fallbackRuntime['pixelFormat']=$runtimePixel;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){
        $p=glasses_vision_calibration_profile_row($pdo,$org,(string)$public);
        if($package!==null&&$p['modelPackagePublicId']!==null&&!hash_equals((string)$p['modelPackagePublicId'],(string)$package['public_id']))continue;
        if(!hash_equals((string)$p['platform'],$runtimePlatform))continue;
        if($runtimeWidth>0&&$runtimeWidth!==(int)$p['frame']['width'])continue;
        if($runtimeHeight>0&&$runtimeHeight!==(int)$p['frame']['height'])continue;
        if($runtimePixel!==''&&!hash_equals(mb_strtolower((string)$p['frame']['pixelFormat'],'UTF-8'),$runtimePixel))continue;
        $match=glasses_vision_calibration_profile_rule_matches($p,$context);
        if(!$match['compatible'])continue;
        $candidates[]=['profile'=>$p,'match'=>$match];
    }
    usort($candidates,static function(array $a,array $b):int{
        $p=((int)$b['profile']['priority'])<=>((int)$a['profile']['priority']);if($p!==0)return $p;
        $s=((float)$b['match']['score'])<=>((float)$a['match']['score']);if($s!==0)return $s;
        return strcmp((string)$a['profile']['publicId'],(string)$b['profile']['publicId']);
    });

    $selected=$candidates[0]??null;
    $fallback=glasses_station_calibration_active($pdo,$org,(int)$device['location_id'],(int)$device['station_id'],$fallbackRuntime);
    $decision=$selected?'profile':'station_fallback';
    if(!$selected&&!$fallback)$decision='none';
    $selectionMaterial=[
      'schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'devicePublicId'=>$device['public_id'],'runtime'=>$runtime,
      'modelPackagePublicId'=>$package['public_id']??null,'modelArtifactSha256'=>$package['artifact_sha256']??null,
      'contextDriftAnalysisPublicId'=>$analysis['publicId']??null,'contextFingerprint'=>$contextFingerprint,
      'selectedProfilePublicId'=>$selected['profile']['publicId']??null,'selectedProfileHash'=>$selected['profile']['profileHash']??null,
      'fallbackCalibrationPublicId'=>$fallback['publicId']??null,'fallbackCalibrationSourceHash'=>$fallback['sourceHash']??null,
      'decision'=>$decision,'matchScore'=>$selected?(float)$selected['match']['score']:0.0,
    ];
    $selectionHash=hash('sha256',glasses_vision_training_release_json($selectionMaterial));
    $key=hash('sha256',glasses_vision_training_release_json(['device'=>$device['public_id'],'package'=>$package['public_id']??null,'analysis'=>$analysis['publicId']??null,'contextFingerprint'=>$contextFingerprint,'runtime'=>$runtime]));
    $existing=$pdo->prepare("SELECT public_id,selection_hash FROM glasses_vision_calibration_selections WHERE organization_id=? AND selection_key=? LIMIT 1");
    $existing->execute([$org,$key]);$e=$existing->fetch();
    if($e){
        if(!hash_equals((string)$e['selection_hash'],$selectionHash))throw new InvalidArgumentException('Calibration selection identity conflicts with different immutable evidence.');
        return glasses_vision_calibration_selection_row($pdo,$org,(string)$e['public_id']);
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$device,$package,$analysis,$selected,$fallback,$key,$contextFingerprint,$selectionHash,$decision,$selectionMaterial):array{
        $profileId=null;if($selected){$x=$pdo->prepare("SELECT id FROM glasses_vision_calibration_profiles WHERE organization_id=? AND public_id=?");$x->execute([$org,$selected['profile']['publicId']]);$profileId=(int)$x->fetchColumn();}
        $fallbackId=null;if($fallback){$x=$pdo->prepare("SELECT id FROM glasses_station_calibrations WHERE organization_id=? AND public_id=?");$x->execute([$org,$fallback['publicId']]);$fallbackId=(int)$x->fetchColumn();}
        $analysisId=null;if($analysis){$x=$pdo->prepare("SELECT id FROM glasses_vision_context_drift_analyses WHERE organization_id=? AND public_id=?");$x->execute([$org,$analysis['publicId']]);$analysisId=(int)$x->fetchColumn();}
        $public=glasses_public_id('vision-cal-selection');
        $pdo->prepare("INSERT INTO glasses_vision_calibration_selections
          (organization_id,public_id,device_id,package_id,context_drift_analysis_id,selected_profile_id,fallback_calibration_id,selection_key,match_score,decision,reasons_json,context_fingerprint,selection_hash,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$device['id'],$package?(int)$package['id']:null,$analysisId,$profileId,$fallbackId,$key,$selected?(float)$selected['match']['score']:0.0,$decision,
            glasses_vision_training_release_json($selectionMaterial),$contextFingerprint,$selectionHash,$actor]);
        return glasses_vision_calibration_selection_row($pdo,$org,$public);
    });
}

function glasses_vision_calibration_selection_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT s.*,d.public_id device_public_id,p.public_id profile_public_id,c.public_id fallback_public_id,m.public_id package_public_id,a.public_id analysis_public_id
      FROM glasses_vision_calibration_selections s
      JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
      LEFT JOIN glasses_vision_calibration_profiles p ON p.id=s.selected_profile_id AND p.organization_id=s.organization_id
      LEFT JOIN glasses_station_calibrations c ON c.id=s.fallback_calibration_id AND c.organization_id=s.organization_id
      LEFT JOIN glasses_vision_model_packages m ON m.id=s.package_id AND m.organization_id=s.organization_id
      LEFT JOIN glasses_vision_context_drift_analyses a ON a.id=s.context_drift_analysis_id AND a.organization_id=s.organization_id
      WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Calibration selection was not found.');
    return ['schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'publicId'=>$r['public_id'],'devicePublicId'=>$r['device_public_id'],'modelPackagePublicId'=>$r['package_public_id'],
      'contextDriftAnalysisPublicId'=>$r['analysis_public_id'],'selectedProfilePublicId'=>$r['profile_public_id'],'fallbackCalibrationPublicId'=>$r['fallback_public_id'],
      'matchScore'=>(float)$r['match_score'],'decision'=>$r['decision'],'contextFingerprint'=>$r['context_fingerprint'],'selectionHash'=>$r['selection_hash'],
      'evidence'=>json_decode((string)$r['reasons_json'],true)?:[],'createdAt'=>$r['created_at']];
}


function glasses_vision_calibration_profile_verify(PDO $pdo,int $org,string $publicId): array
{
    $p=glasses_vision_calibration_profile_row($pdo,$org,$publicId);
    $material=[
      'schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'profileKey'=>$p['profileKey'],
      'calibration'=>['publicId'=>$p['calibrationPublicId'],'sourceHash'=>$p['calibrationSourceHash'],'platform'=>$p['platform'],'frameWidth'=>$p['frame']['width'],'frameHeight'=>$p['frame']['height'],'pixelFormat'=>$p['frame']['pixelFormat']],
      'model'=>$p['modelPackagePublicId']!==null?['publicId'=>$p['modelPackagePublicId'],'artifactSha256'=>$p['modelPackageSha256']]:null,
      'locationId'=>$p['locationId'],'stationPublicId'=>$p['stationPublicId'],'priority'=>$p['priority'],'context'=>$p['context'],'guardrails'=>$p['guardrails'],
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($material));
    return ['passed'=>hash_equals((string)$p['profileHash'],$hash),'profileHash'=>$p['profileHash'],'recomputedProfileHash'=>$hash];
}

function glasses_vision_calibration_selection_verify(PDO $pdo,int $org,string $publicId): array
{
    $s=glasses_vision_calibration_selection_row($pdo,$org,$publicId);
    $hash=hash('sha256',glasses_vision_training_release_json($s['evidence']));
    $passed=hash_equals((string)$s['selectionHash'],$hash);
    if($s['selectedProfilePublicId']!==null)$passed=$passed&&glasses_vision_calibration_profile_verify($pdo,$org,(string)$s['selectedProfilePublicId'])['passed'];
    if($s['fallbackCalibrationPublicId']!==null){
        $cal=glasses_vision_calibration_profile_calibration($pdo,$org,(string)$s['fallbackCalibrationPublicId']);
        $passed=$passed&&hash_equals((string)($s['evidence']['fallbackCalibrationSourceHash']??''),(string)$cal['source_hash']);
    }
    return ['passed'=>$passed,'selectionHash'=>$s['selectionHash'],'recomputedSelectionHash'=>$hash];
}

function glasses_vision_calibration_profile_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_calibration_profile_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'profiles'=>[],'selections'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_calibration_profiles WHERE organization_id=? ORDER BY status='active' DESC,priority DESC,id DESC");$q->execute([$org]);
    $profiles=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$profiles[]=glasses_vision_calibration_profile_row($pdo,$org,(string)$p);
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_calibration_selections WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $selections=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$selections[]=glasses_vision_calibration_selection_row($pdo,$org,(string)$p);
    return ['ready'=>true,'schema'=>GLASSES_VISION_CALIBRATION_PROFILE_SCHEMA,'profiles'=>$profiles,'selections'=>$selections];
}
