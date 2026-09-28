<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-dataset-intelligence.php';

const GLASSES_VISION_MEDIA_MAX_BYTES=12582912;
const GLASSES_VISION_MEDIA_NEAR_DUPLICATE_DISTANCE=6;

function glasses_vision_training_media_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_training_media','glasses_vision_training_media_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_dataset_intelligence_ready($pdo);
}

function glasses_vision_training_media_storage_root(): string
{
    return dirname(__DIR__).'/storage/vision-training-media';
}

function glasses_vision_training_media_validate_hash(?string $hash): ?string
{
    if($hash===null||trim($hash)==='')return null;
    $hash=strtolower(trim($hash));
    if(!preg_match('/^[0-9a-f]{16}$/',$hash))throw new InvalidArgumentException('Perceptual hash must be a 64-bit hexadecimal dHash.');
    return $hash;
}

function glasses_vision_training_media_hamming(string $a,string $b): int
{
    $a=strtolower($a);$b=strtolower($b);
    if(!preg_match('/^[0-9a-f]{16}$/',$a)||!preg_match('/^[0-9a-f]{16}$/',$b))return 64;
    $bits=0;
    for($i=0;$i<16;$i++){
        $xor=hexdec($a[$i])^hexdec($b[$i]);
        $bits+=match($xor){0=>0,1,2,4,8=>1,3,5,6,9,10,12=>2,7,11,13,14=>3,15=>4,default=>0};
    }
    return $bits;
}

function glasses_vision_training_media_event(PDO $pdo,int $org,int $mediaId,string $eventType,?int $actor,array $evidence=[]): void
{
    $pdo->prepare("INSERT INTO glasses_vision_training_media_events
      (organization_id,media_id,event_type,evidence_json,actor_user_id)
      VALUES (?,?,?,?,?)")->execute([$org,$mediaId,$eventType,json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
}

function glasses_vision_training_media_annotation_check(array $annotations): array
{
    $normalized=[];$flags=[];
    foreach($annotations as $index=>$annotation){
        if(!is_array($annotation))throw new InvalidArgumentException('Training annotation must be an object.');
        $label=mb_substr(trim((string)($annotation['label']??'')),0,190);
        $bbox=$annotation['bbox']??null;
        if($label===''||!is_array($bbox))throw new InvalidArgumentException('Training annotation requires a label and normalized bounding box.');
        $x=(float)($bbox['x']??-1);$y=(float)($bbox['y']??-1);$w=(float)($bbox['width']??0);$h=(float)($bbox['height']??0);
        if($x<0||$y<0||$w<=0||$h<=0||$x+$w>1.00001||$y+$h>1.00001)throw new InvalidArgumentException('Training annotation bounding boxes must be normalized inside the image.');
        $area=$w*$h;
        if($area<0.001)$flags[]='tiny_box';
        if($area>0.90)$flags[]='oversized_box';
        $normalized[]=['label'=>$label,'bbox'=>['x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h]];
    }
    return ['annotations'=>$normalized,'flags'=>array_values(array_unique($flags))];
}

function glasses_vision_training_media_quality(array $input,int $width,int $height,array $annotationFlags): array
{
    $brightness=isset($input['brightnessMean'])?(float)$input['brightnessMean']:null;
    $contrast=isset($input['contrastMean'])?(float)$input['contrastMean']:null;
    $blur=isset($input['blurScore'])?(float)$input['blurScore']:null;
    $flags=$annotationFlags;
    $exposure='unknown';

    if($width<320||$height<240)$flags[]='low_resolution';
    if($brightness!==null){
        if($brightness<0.12){$flags[]='underexposed';$exposure='underexposed';}
        elseif($brightness>0.90){$flags[]='overexposed';$exposure='overexposed';}
        else $exposure='normal';
    }
    if($contrast!==null&&$contrast<0.08)$flags[]='low_contrast';
    if($blur!==null&&$blur<18)$flags[]='blurred';
    $detectorConfidence=isset($input['detectorConfidence'])&&$input['detectorConfidence']!==null?(float)$input['detectorConfidence']:null;
    if($detectorConfidence!==null&&$detectorConfidence>=0.98&&!empty($input['annotations']))$flags[]='too_easy';

    $flags=array_values(array_unique($flags));
    $state=$flags?'warning':'good';
    if(in_array('low_resolution',$flags,true)||in_array('underexposed',$flags,true)||in_array('overexposed',$flags,true)||in_array('blurred',$flags,true))$state='poor';
    return ['state'=>$state,'flags'=>$flags,'exposure'=>$exposure];
}

function glasses_vision_training_media_resolve_device(PDO $pdo,int $org,?string $devicePublic): ?array
{
    if($devicePublic===null||trim($devicePublic)==='')return null;
    $q=$pdo->prepare("SELECT * FROM glasses_devices WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$devicePublic]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Glasses device was not found.');
    return $row;
}

function glasses_vision_training_media_resolve_build(PDO $pdo,int $org,?string $buildPublic): ?array
{
    if($buildPublic===null||trim($buildPublic)==='')return null;
    $q=$pdo->prepare("SELECT * FROM glasses_build_sessions WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$buildPublic]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Build session was not found.');
    return $row;
}

function glasses_vision_training_media_resolve_mission(PDO $pdo,int $org,?string $missionPublic): ?array
{
    if($missionPublic===null||trim($missionPublic)==='')return null;
    $q=$pdo->prepare("SELECT * FROM glasses_vision_training_missions WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$missionPublic]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision training mission was not found.');
    return $row;
}

function glasses_vision_training_media_current_operator(PDO $pdo,int $org,?int $deviceId,string $capturedAt): ?int
{
    if($deviceId===null)return null;
    $q=$pdo->prepare("SELECT user_id FROM glasses_user_device_assignments
      WHERE organization_id=? AND device_id=? AND assigned_at<=?
        AND (released_at IS NULL OR released_at>?)
      ORDER BY assigned_at DESC,id DESC LIMIT 1");
    $q->execute([$org,$deviceId,$capturedAt,$capturedAt]);
    $id=$q->fetchColumn();
    return $id===false?null:(int)$id;
}

function glasses_vision_training_media_store(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_training_media_ready($pdo))throw new RuntimeException('Vision Lab V5 migration is not installed.');
    if((string)($input['consentBasis']??'')!=='training_media_opt_in')throw new InvalidArgumentException('Explicit training-media opt-in is required.');

    $raw=(string)($input['imageBase64']??'');
    if(str_contains($raw,','))$raw=substr($raw,strpos($raw,',')+1);
    if($raw==='')throw new InvalidArgumentException('Training image is required.');
    $bytes=base64_decode($raw,true);
    if($bytes===false)throw new InvalidArgumentException('Training image encoding is invalid.');
    $size=strlen($bytes);
    if($size<100||$size>GLASSES_VISION_MEDIA_MAX_BYTES)throw new InvalidArgumentException('Training image size is outside the governed limit.');

    $info=@getimagesizefromstring($bytes);
    if(!is_array($info)||empty($info[0])||empty($info[1])||empty($info['mime']))throw new InvalidArgumentException('Training media must be a valid image.');
    $mime=strtolower((string)$info['mime']);
    $ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>throw new InvalidArgumentException('Training media type is not supported.')};
    $width=(int)$info[0];$height=(int)$info[1];
    if($width<64||$height<64||$width>8192||$height>8192)throw new InvalidArgumentException('Training image dimensions are outside the governed limit.');

    $annotations=is_array($input['annotations']??null)?$input['annotations']:[];
    $annotationCheck=glasses_vision_training_media_annotation_check($annotations);
    $quality=glasses_vision_training_media_quality($input,$width,$height,$annotationCheck['flags']);
    $sha=hash('sha256',$bytes);
    $pHash=glasses_vision_training_media_validate_hash(isset($input['perceptualHash'])?(string)$input['perceptualHash']:null);
    $capturedAt=trim((string)($input['capturedAt']??''))?:gmdate('Y-m-d H:i:s');
    $captureGroup=mb_substr(trim((string)($input['captureGroup']??'')),0,120)?:null;
    $burstIndex=isset($input['burstIndex'])?max(0,(int)$input['burstIndex']):null;

    $device=glasses_vision_training_media_resolve_device($pdo,$org,!empty($input['devicePublicId'])?(string)$input['devicePublicId']:null);
    $build=glasses_vision_training_media_resolve_build($pdo,$org,!empty($input['buildSessionPublicId'])?(string)$input['buildSessionPublicId']:null);
    if($build&&$device&&(int)$build['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Build session does not belong to the selected glasses device.');
    if(!$device&&$build){
        $q=$pdo->prepare("SELECT * FROM glasses_devices WHERE organization_id=? AND id=? LIMIT 1");$q->execute([$org,(int)$build['device_id']]);$device=$q->fetch()?:null;
    }
    $mission=glasses_vision_training_media_resolve_mission($pdo,$org,!empty($input['missionPublicId'])?(string)$input['missionPublicId']:null);
    $operator=glasses_vision_training_media_current_operator($pdo,$org,$device?(int)$device['id']:null,$capturedAt);

    $sample=null;
    if(!empty($input['samplePublicId'])){
        $q=$pdo->prepare("SELECT * FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=? LIMIT 1");$q->execute([$org,(string)$input['samplePublicId']]);$sample=$q->fetch();
        if(!$sample)throw new InvalidArgumentException('Vision training sample was not found.');
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$input,$actor,$bytes,$size,$mime,$ext,$width,$height,$annotations,$annotationCheck,$quality,$sha,$pHash,$capturedAt,$captureGroup,$burstIndex,$device,$build,$mission,$operator,$sample):array{
        $public=glasses_public_id('vision-media');

        if(!$sample){
            $samplePublic=glasses_public_id('vision-sample');
            $canonical=null;
            if(count($annotationCheck['annotations'])===1)$canonical=(string)$annotationCheck['annotations'][0]['label'];
            $pdo->prepare("INSERT INTO glasses_vision_training_samples
              (organization_id,public_id,mission_id,observation_id,source_type,source_reference,wearer_assignment_id,operator_user_id,review_status,canonical_label,annotation_json,provenance_json,created_by,created_at)
              VALUES (?,?,?,NULL,'governed_training_media',?,NULL,?,'pending',?,?,?,?,?,?)")
              ->execute([
                  $org,$samplePublic,$mission?(int)$mission['id']:null,$public,$operator,$canonical,
                  json_encode($annotationCheck['annotations'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                  json_encode(['schema'=>'gelato.vision_training_media_sample.v1','mediaPublicId'=>$public,'capturedAt'=>$capturedAt],JSON_UNESCAPED_SLASHES),
                  $actor,$capturedAt,
              ]);
            $sampleId=(int)$pdo->lastInsertId();
        }else{
            $samplePublic=(string)$sample['public_id'];$sampleId=(int)$sample['id'];
        }

        $root=glasses_vision_training_media_storage_root();
        $dir=$root.'/'.(int)$org.'/'.substr($sha,0,2);
        if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Private training-media storage could not be created.');
        $relative=(int)$org.'/'.substr($sha,0,2).'/'.$public.'.'.$ext;
        $path=$root.'/'.$relative;
        if(file_put_contents($path,$bytes,LOCK_EX)!==$size)throw new RuntimeException('Training media could not be written to private storage.');

        $retentionDays=max(1,min(3650,(int)($input['retentionDays']??365)));
        $retentionUntil=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$retentionDays.' days')->format('Y-m-d H:i:s.u');
        $camera=is_array($input['camera']??null)?$input['camera']:[];
        $pose=is_array($input['pose']??null)?$input['pose']:[];
        $meta=is_array($input['metadata']??null)?$input['metadata']:[];
        if(isset($input['detectorConfidence'])&&$input['detectorConfidence']!==null)$meta['detectorConfidence']=(float)$input['detectorConfidence'];
        $meta['featureProvenance']=['perceptualHash'=>'browser_dhash_v1','brightness'=>'browser_luma_v1','contrast'=>'browser_luma_stddev_v1','blur'=>'browser_laplacian_variance_v1'];

        try{
            $pdo->prepare("INSERT INTO glasses_vision_training_media
              (organization_id,public_id,sample_id,mission_id,device_id,operator_user_id,build_session_id,capture_group,burst_index,source_type,storage_relative_path,mime_type,byte_size,width,height,sha256,perceptual_hash,perceptual_hash_source,brightness_mean,contrast_mean,blur_score,exposure_state,camera_facing,camera_device_key,camera_width,camera_height,camera_fps,camera_pitch,camera_yaw,camera_roll,distance_bucket,occlusion_bucket,quality_state,quality_flags_json,annotation_json,metadata_json,consent_basis,retention_until,status,created_by,created_at)
              VALUES (?,?,?,?,?,?,?,?,?,'browser_training_capture',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?,?)")
              ->execute([
                  $org,$public,$sampleId,$mission?(int)$mission['id']:null,$device?(int)$device['id']:null,$operator,$build?(int)$build['id']:null,$captureGroup,$burstIndex,
                  $relative,$mime,$size,$width,$height,$sha,$pHash,$pHash!==null?'browser_dhash_v1':null,
                  isset($input['brightnessMean'])?(float)$input['brightnessMean']:null,isset($input['contrastMean'])?(float)$input['contrastMean']:null,isset($input['blurScore'])?(float)$input['blurScore']:null,$quality['exposure'],
                  mb_substr(trim((string)($camera['facing']??'')),0,24)?:null,mb_substr(trim((string)($camera['deviceKey']??'')),0,190)?:null,
                  isset($camera['width'])?(int)$camera['width']:null,isset($camera['height'])?(int)$camera['height']:null,isset($camera['fps'])?(float)$camera['fps']:null,
                  isset($pose['pitch'])?(float)$pose['pitch']:null,isset($pose['yaw'])?(float)$pose['yaw']:null,isset($pose['roll'])?(float)$pose['roll']:null,
                  mb_substr(trim((string)($input['distanceBucket']??'')),0,24)?:null,mb_substr(trim((string)($input['occlusionBucket']??'')),0,24)?:null,
                  $quality['state'],json_encode($quality['flags']),json_encode($annotationCheck['annotations'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                  'training_media_opt_in',$retentionUntil,$actor,$capturedAt,
              ]);
            $mediaId=(int)$pdo->lastInsertId();
            glasses_vision_training_media_event($pdo,$org,$mediaId,'uploaded',$actor,['sha256'=>$sha,'samplePublicId'=>$samplePublic,'qualityState'=>$quality['state'],'retentionUntil'=>$retentionUntil]);
        }catch(Throwable $e){
            @unlink($path);
            throw $e;
        }

        $exactQ=$pdo->prepare("SELECT public_id FROM glasses_vision_training_media WHERE organization_id=? AND sha256=? AND id<>? AND status='active' ORDER BY id DESC LIMIT 20");
        $exactQ->execute([$org,$sha,$mediaId]);$exact=$exactQ->fetchAll(PDO::FETCH_COLUMN);

        $near=[];
        if($pHash!==null){
            $pq=$pdo->prepare("SELECT public_id,perceptual_hash FROM glasses_vision_training_media WHERE organization_id=? AND id<>? AND perceptual_hash IS NOT NULL AND status='active' ORDER BY id DESC LIMIT 500");
            $pq->execute([$org,$mediaId]);
            foreach($pq->fetchAll() as $row){
                $distance=glasses_vision_training_media_hamming($pHash,(string)$row['perceptual_hash']);
                if($distance<=GLASSES_VISION_MEDIA_NEAR_DUPLICATE_DISTANCE)$near[]=['publicId'=>$row['public_id'],'distance'=>$distance];
            }
            usort($near,static fn($a,$b)=>$a['distance']<=>$b['distance']);
            $near=array_slice($near,0,20);
        }

        return [
            'publicId'=>$public,'samplePublicId'=>$samplePublic,'mimeType'=>$mime,'byteSize'=>$size,'width'=>$width,'height'=>$height,
            'sha256'=>$sha,'perceptualHash'=>$pHash,'qualityState'=>$quality['state'],'qualityFlags'=>$quality['flags'],
            'exactDuplicates'=>$exact,'nearDuplicates'=>$near,'retentionUntil'=>$retentionUntil,'status'=>'active',
        ];
    });
}

function glasses_vision_training_media_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT m.*,s.public_id sample_public_id,tm.public_id mission_public_id,d.public_id device_public_id,d.display_name device_name,u.display_name operator_name,b.public_id build_public_id
      FROM glasses_vision_training_media m
      LEFT JOIN glasses_vision_training_samples s ON s.id=m.sample_id
      LEFT JOIN glasses_vision_training_missions tm ON tm.id=m.mission_id
      LEFT JOIN glasses_devices d ON d.id=m.device_id
      LEFT JOIN users u ON u.id=m.operator_user_id
      LEFT JOIN glasses_build_sessions b ON b.id=m.build_session_id
      WHERE m.organization_id=? AND m.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision training media was not found.');
    return $row;
}

function glasses_vision_training_media_public(array $row): array
{
    return [
        'publicId'=>$row['public_id'],'samplePublicId'=>$row['sample_public_id'],'missionPublicId'=>$row['mission_public_id'],
        'devicePublicId'=>$row['device_public_id'],'deviceName'=>$row['device_name'],'operatorName'=>$row['operator_name'],'buildPublicId'=>$row['build_public_id'],
        'captureGroup'=>$row['capture_group'],'burstIndex'=>$row['burst_index']!==null?(int)$row['burst_index']:null,
        'mimeType'=>$row['mime_type'],'byteSize'=>(int)$row['byte_size'],'width'=>(int)$row['width'],'height'=>(int)$row['height'],
        'sha256'=>$row['sha256'],'perceptualHash'=>$row['perceptual_hash'],'perceptualHashSource'=>$row['perceptual_hash_source'],
        'brightnessMean'=>$row['brightness_mean']!==null?(float)$row['brightness_mean']:null,'contrastMean'=>$row['contrast_mean']!==null?(float)$row['contrast_mean']:null,
        'blurScore'=>$row['blur_score']!==null?(float)$row['blur_score']:null,'exposureState'=>$row['exposure_state'],
        'qualityState'=>$row['quality_state'],'qualityFlags'=>json_decode((string)($row['quality_flags_json']??'[]'),true)?:[],
        'camera'=>['facing'=>$row['camera_facing'],'deviceKey'=>$row['camera_device_key'],'width'=>$row['camera_width']!==null?(int)$row['camera_width']:null,'height'=>$row['camera_height']!==null?(int)$row['camera_height']:null,'fps'=>$row['camera_fps']!==null?(float)$row['camera_fps']:null],
        'pose'=>['pitch'=>$row['camera_pitch']!==null?(float)$row['camera_pitch']:null,'yaw'=>$row['camera_yaw']!==null?(float)$row['camera_yaw']:null,'roll'=>$row['camera_roll']!==null?(float)$row['camera_roll']:null],
        'distanceBucket'=>$row['distance_bucket'],'occlusionBucket'=>$row['occlusion_bucket'],'consentBasis'=>$row['consent_basis'],
        'retentionUntil'=>$row['retention_until'],'status'=>$row['status'],'createdAt'=>$row['created_at'],'deletedAt'=>$row['deleted_at'],
    ];
}

function glasses_vision_training_media_list(PDO $pdo,int $org,int $limit=250): array
{
    $limit=max(1,min(1000,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_media WHERE organization_id=? ORDER BY status='active' DESC,id DESC LIMIT ".$limit);
    $q->execute([$org]);
    return array_map(fn($id)=>glasses_vision_training_media_public(glasses_vision_training_media_row($pdo,$org,(string)$id,false)),$q->fetchAll(PDO::FETCH_COLUMN));
}

function glasses_vision_training_media_delete(PDO $pdo,int $org,string $publicId,string $reason,?int $actor): array
{
    $reason=mb_substr(trim($reason),0,500);
    if($reason==='')throw new InvalidArgumentException('Training-media deletion requires a reason.');

    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$reason,$actor):array{
        $row=glasses_vision_training_media_row($pdo,$org,$publicId,true);
        if((string)$row['status']==='deleted')return ['publicId'=>$publicId,'status'=>'deleted'];
        $path=glasses_vision_training_media_storage_root().'/'.(string)$row['storage_relative_path'];
        if(is_file($path)&&!@unlink($path))throw new RuntimeException('Private training-media bytes could not be deleted.');
        $pdo->prepare("UPDATE glasses_vision_training_media SET status='deleted',deleted_by=?,deleted_at=NOW(6),delete_reason=? WHERE organization_id=? AND id=?")
          ->execute([$actor,$reason,$org,(int)$row['id']]);
        glasses_vision_training_media_event($pdo,$org,(int)$row['id'],'deleted',$actor,['reason'=>$reason]);
        return ['publicId'=>$publicId,'status'=>'deleted'];
    });
}

function glasses_vision_training_media_expire(PDO $pdo,int $org,?int $actor=null,int $limit=500): array
{
    $limit=max(1,min(5000,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_media WHERE organization_id=? AND status='active' AND retention_until IS NOT NULL AND retention_until<=NOW(6) ORDER BY retention_until,id LIMIT ".$limit);
    $q->execute([$org]);$count=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $publicId){
        glasses_vision_training_media_delete($pdo,$org,(string)$publicId,'retention_expired',$actor);$count++;
    }
    return ['expired'=>$count];
}

function glasses_vision_training_media_dataset_quality(PDO $pdo,int $org,?string $datasetPublic=null): array
{
    $datasetId=glasses_vision_dataset_intelligence_dataset_id($pdo,$org,$datasetPublic);
    $join=$datasetId===null?'':" JOIN glasses_vision_dataset_items di ON di.organization_id=m.organization_id AND di.sample_id=m.sample_id AND di.dataset_id=? ";
    $args=$datasetId===null?[$org]:[$datasetId,$org];
    $q=$pdo->prepare("SELECT m.*".($datasetId!==null?",di.split_name":"")." FROM glasses_vision_training_media m {$join} WHERE m.organization_id=? AND m.status='active' ORDER BY m.id DESC LIMIT 1000");
    $q->execute($args);$rows=$q->fetchAll();

    $exact=[];$shaGroups=[];$captureSplits=[];$poor=0;$warning=0;$tooEasy=0;$poseCount=0;$cameraKeys=[];$distance=[];$occlusion=[];$brightnessBuckets=['dark'=>0,'normal'=>0,'bright'=>0];$timeOfDay=['overnight'=>0,'morning'=>0,'afternoon'=>0,'evening'=>0];$poseCoverage=[];$qualityByMedia=[];
    foreach($rows as $row){
        $shaGroups[$row['sha256']][]=$row['public_id'];
        $flags=json_decode((string)($row['quality_flags_json']??'[]'),true)?:[];$qualityByMedia[(string)$row['public_id']]=$flags;
        if((string)$row['quality_state']==='poor')$poor++;
        elseif((string)$row['quality_state']==='warning')$warning++;
        if(in_array('too_easy',$flags,true))$tooEasy++;
        if($row['camera_pitch']!==null||$row['camera_yaw']!==null||$row['camera_roll']!==null){
            $poseCount++;
            $pitch=$row['camera_pitch']!==null?(int)(round(((float)$row['camera_pitch'])/15)*15):0;
            $yaw=$row['camera_yaw']!==null?(int)(round(((float)$row['camera_yaw'])/30)*30):0;
            $roll=$row['camera_roll']!==null?(int)(round(((float)$row['camera_roll'])/15)*15):0;
            $poseKey='p'.$pitch.'_y'.$yaw.'_r'.$roll;$poseCoverage[$poseKey]=($poseCoverage[$poseKey]??0)+1;
        }
        if($row['camera_device_key'])$cameraKeys[(string)$row['camera_device_key']]=true;
        if($row['distance_bucket'])$distance[(string)$row['distance_bucket']]=($distance[(string)$row['distance_bucket']]??0)+1;
        if($row['occlusion_bucket'])$occlusion[(string)$row['occlusion_bucket']]=($occlusion[(string)$row['occlusion_bucket']]??0)+1;
        if($row['brightness_mean']!==null){$b=(float)$row['brightness_mean'];$brightnessBuckets[$b<.25?'dark':($b>.75?'bright':'normal')]++;}
        $hour=(int)substr((string)$row['created_at'],11,2);$bucket=$hour<6?'overnight':($hour<12?'morning':($hour<17?'afternoon':'evening'));$timeOfDay[$bucket]++;
        if($datasetId!==null&&$row['capture_group'])$captureSplits[(string)$row['capture_group']][(string)$row['split_name']]=true;
    }
    foreach($shaGroups as $sha=>$ids)if(count($ids)>1)$exact[]=['sha256'=>$sha,'count'=>count($ids),'mediaPublicIds'=>$ids];

    $near=[];$withHash=array_values(array_filter($rows,static fn($r)=>!empty($r['perceptual_hash'])));
    $cap=min(500,count($withHash));
    for($i=0;$i<$cap;$i++)for($j=$i+1;$j<$cap;$j++){
        $distanceBits=glasses_vision_training_media_hamming((string)$withHash[$i]['perceptual_hash'],(string)$withHash[$j]['perceptual_hash']);
        if($distanceBits<=GLASSES_VISION_MEDIA_NEAR_DUPLICATE_DISTANCE)$near[]=['a'=>$withHash[$i]['public_id'],'b'=>$withHash[$j]['public_id'],'distance'=>$distanceBits];
        if(count($near)>=200)break 2;
    }
    $captureLeakage=[];
    foreach($captureSplits as $group=>$splits)if(count($splits)>1)$captureLeakage[]=['captureGroup'=>$group,'splits'=>array_keys($splits)];
    $recommendations=[];
    foreach($exact as $cluster){$ids=$cluster['mediaPublicIds'];foreach(array_slice($ids,1) as $id)$recommendations[]=['mediaPublicId'=>$id,'action'=>'exclude','reason'=>'exact_duplicate','priority'=>100];}
    foreach($near as $pair)$recommendations[]=['mediaPublicId'=>$pair['b'],'action'=>'review_or_exclude','reason'=>'near_duplicate','priority'=>75,'similarTo'=>$pair['a'],'distance'=>$pair['distance']];
    foreach($rows as $row){
        $flags=$qualityByMedia[(string)$row['public_id']]??[];
        if((string)$row['quality_state']==='poor')$recommendations[]=['mediaPublicId'=>$row['public_id'],'action'=>'recapture_or_exclude','reason'=>'poor_visual_quality','priority'=>95,'flags'=>$flags];
        elseif(in_array('too_easy',$flags,true))$recommendations[]=['mediaPublicId'=>$row['public_id'],'action'=>'deprioritize','reason'=>'too_easy','priority'=>40,'flags'=>$flags];
    }
    usort($recommendations,static fn($a,$b)=>($b['priority']<=>$a['priority']));

    return [
        'mediaCount'=>count($rows),'poorCount'=>$poor,'warningCount'=>$warning,'tooEasyCount'=>$tooEasy,
        'exactDuplicateClusters'=>$exact,'nearDuplicatePairs'=>$near,'captureGroupLeakage'=>$captureLeakage,
        'cameraDeviceDiversity'=>count($cameraKeys),'poseInstrumentedCount'=>$poseCount,'poseCoverage'=>$poseCoverage,'distanceCoverage'=>$distance,'occlusionCoverage'=>$occlusion,'brightnessCoverage'=>$brightnessBuckets,'timeOfDayCoverage'=>$timeOfDay,
        'exclusionRecommendations'=>$recommendations,
        'releaseBlockers'=>[
            'exactDuplicates'=>count($exact),
            'captureGroupLeakage'=>count($captureLeakage),
            'poorMedia'=>$poor,
        ],
        'featureProvenance'=>[
            'sha256'=>'server_verified',
            'dimensions'=>'server_verified',
            'mimeType'=>'server_verified',
            'perceptualHash'=>'browser_dhash_v1',
            'brightness'=>'browser_luma_v1',
            'contrast'=>'browser_luma_stddev_v1',
            'blur'=>'browser_laplacian_variance_v1',
        ],
    ];
}

function glasses_vision_training_media_export_manifest(PDO $pdo,int $org,?string $datasetPublic=null): array
{
    $datasetId=glasses_vision_dataset_intelligence_dataset_id($pdo,$org,$datasetPublic);
    $join=$datasetId===null?'':" JOIN glasses_vision_dataset_items di ON di.organization_id=m.organization_id AND di.sample_id=m.sample_id AND di.dataset_id=? ";
    $args=$datasetId===null?[$org]:[$datasetId,$org];
    $q=$pdo->prepare("SELECT m.public_id,m.sample_id,m.sha256,m.perceptual_hash,m.perceptual_hash_source,m.mime_type,m.byte_size,m.width,m.height,m.capture_group,m.burst_index,m.quality_state,m.quality_flags_json,m.consent_basis,m.retention_until,m.created_at".($datasetId!==null?",di.split_name":"")."
      FROM glasses_vision_training_media m {$join}
      WHERE m.organization_id=? AND m.status='active' ORDER BY m.public_id");
    $q->execute($args);$items=[];
    foreach($q->fetchAll() as $row)$items[]=[
        'publicId'=>$row['public_id'],'sha256'=>$row['sha256'],'perceptualHash'=>$row['perceptual_hash'],'perceptualHashSource'=>$row['perceptual_hash_source'],
        'mimeType'=>$row['mime_type'],'byteSize'=>(int)$row['byte_size'],'width'=>(int)$row['width'],'height'=>(int)$row['height'],
        'captureGroup'=>$row['capture_group'],'burstIndex'=>$row['burst_index']!==null?(int)$row['burst_index']:null,
        'qualityState'=>$row['quality_state'],'qualityFlags'=>json_decode((string)$row['quality_flags_json'],true)?:[],
        'consentBasis'=>$row['consent_basis'],'retentionUntil'=>$row['retention_until'],'createdAt'=>$row['created_at'],
        'split'=>$datasetId!==null?$row['split_name']:null,
    ];
    return ['schema'=>'gelato.vision_training_media_export.v1','datasetPublicId'=>$datasetPublic,'generatedAt'=>gmdate('c'),'mediaCount'=>count($items),'items'=>$items,'privateBytesExcluded'=>true];
}

function glasses_vision_training_media_freeze_guard(PDO $pdo,int $org,string $datasetPublic): void
{
    $quality=glasses_vision_training_media_dataset_quality($pdo,$org,$datasetPublic);
    $blockers=$quality['releaseBlockers']??[];
    if((int)($blockers['captureGroupLeakage']??0)>0)throw new InvalidArgumentException('Dataset freeze blocked: capture-group media leakage must be resolved.');
    if((int)($blockers['exactDuplicates']??0)>0)throw new InvalidArgumentException('Dataset freeze blocked: exact duplicate training media must be removed or excluded.');
    if((int)($blockers['poorMedia']??0)>0)throw new InvalidArgumentException('Dataset freeze blocked: poor-quality training media must be removed or recaptured.');
}

function glasses_vision_training_media_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_training_media_ready($pdo))return ['ready'=>false,'media'=>[],'quality'=>null];
    return ['ready'=>true,'media'=>glasses_vision_training_media_list($pdo,$org,250),'quality'=>glasses_vision_training_media_dataset_quality($pdo,$org,null)];
}
