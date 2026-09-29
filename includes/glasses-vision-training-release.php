<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-annotation-qa.php';

function glasses_vision_training_release_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_training_releases'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_annotation_qa_ready($pdo);
}

function glasses_vision_training_release_profiles(): array
{
    return [
        'yolo_detection_v1'=>[
            'key'=>'yolo_detection_v1',
            'label'=>'YOLO Detection · Governed V1',
            'format'=>'yolo_detection',
            'imageSize'=>640,
            'seed'=>74,
            'deterministic'=>true,
            'notes'=>'Consumes the frozen dataset and its applied governed train/val/test split without resplitting.',
        ],
    ];
}

function glasses_vision_training_release_storage_root(): string
{
    return dirname(__DIR__).'/storage/vision-training-releases';
}

function glasses_vision_training_release_safe_name(string $value): string
{
    $value=preg_replace('/[^A-Za-z0-9._-]+/','-',trim($value))??'';
    $value=trim($value,'-.');
    return $value!==''?$value:'item';
}

function glasses_vision_training_release_remove_tree(string $path): void
{
    if(!is_dir($path))return;
    $items=scandir($path);
    if($items===false)return;
    foreach($items as $item){
        if($item==='.'||$item==='..')continue;
        $child=$path.'/'.$item;
        if(is_dir($child))glasses_vision_training_release_remove_tree($child);else @unlink($child);
    }
    @rmdir($path);
}

function glasses_vision_training_release_yaml_quote(string $value): string
{
    return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
}

function glasses_vision_training_release_float(float $value): string
{
    return number_format(max(0.0,min(1.0,$value)),6,'.','');
}

function glasses_vision_training_release_label_lines(array $annotations,array $classIndex): string
{
    $lines=[];
    foreach($annotations as $annotation){
        if(!is_array($annotation))continue;
        $label=trim((string)($annotation['label']??''));
        if($label===''||glasses_vision_balance_label_is_negative($label))continue;
        if(!array_key_exists($label,$classIndex))throw new InvalidArgumentException('Release blocked: annotation label is not present in the governed class map: '.$label);
        $box=glasses_vision_annotation_qa_box(is_array($annotation['bbox']??null)?$annotation['bbox']:[]);
        if(!$box['valid'])throw new InvalidArgumentException('Release blocked: invalid normalized annotation box.');
        $cx=$box['x']+($box['width']/2);$cy=$box['y']+($box['height']/2);
        $lines[]=$classIndex[$label].' '.glasses_vision_training_release_float($cx).' '.glasses_vision_training_release_float($cy).' '.glasses_vision_training_release_float($box['width']).' '.glasses_vision_training_release_float($box['height']);
    }
    sort($lines,SORT_STRING);
    return $lines?implode("\n",$lines)."\n":'';
}

function glasses_vision_training_release_core_hash(array $members): string
{
    ksort($members,SORT_STRING);
    $canonical='';
    foreach($members as $path=>$sha)$canonical.=$path."\0".$sha."\n";
    return hash('sha256',$canonical);
}

function glasses_vision_training_release_applied_plan(PDO $pdo,int $org,int $datasetId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_dataset_split_plans WHERE organization_id=? AND dataset_id=? AND status='applied' ORDER BY applied_at DESC,id DESC LIMIT 1");
    $q->execute([$org,$datasetId]);$plan=$q->fetch();
    if(!$plan)throw new InvalidArgumentException('Training release blocked: no applied governed split plan was found.');
    return $plan;
}

function glasses_vision_training_release_dataset(PDO $pdo,int $org,string $datasetPublic): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$datasetPublic]);$dataset=$q->fetch();
    if(!$dataset)throw new InvalidArgumentException('Vision dataset was not found.');
    if((string)$dataset['status']!=='frozen')throw new InvalidArgumentException('Training release blocked: freeze the governed dataset first.');
    if(!preg_match('/^[0-9a-f]{64}$/',(string)$dataset['dataset_hash']))throw new InvalidArgumentException('Training release blocked: frozen dataset hash is missing.');
    return $dataset;
}

function glasses_vision_training_release_rows(PDO $pdo,int $org,int $datasetId,int $planId): array
{
    $q=$pdo->prepare("SELECT
      s.id sample_id,s.public_id sample_public_id,s.canonical_label,s.annotation_json sample_annotation_json,
      di.split_name,a.split_name governed_split,a.group_key,
      m.id media_id,m.public_id media_public_id,m.storage_relative_path,m.mime_type,m.sha256,m.annotation_json media_annotation_json,m.quality_state
      FROM glasses_vision_dataset_items di
      JOIN glasses_vision_training_samples s ON s.id=di.sample_id AND s.organization_id=di.organization_id
      JOIN glasses_vision_dataset_split_assignments a ON a.organization_id=di.organization_id AND a.sample_id=s.id AND a.split_plan_id=?
      LEFT JOIN glasses_vision_training_media m ON m.organization_id=s.organization_id AND m.sample_id=s.id AND m.status='active'
      WHERE di.organization_id=? AND di.dataset_id=?
      ORDER BY s.public_id,m.public_id");
    $q->execute([$planId,$org,$datasetId]);$rows=$q->fetchAll();
    if(!$rows)throw new InvalidArgumentException('Training release blocked: dataset has no governed samples.');
    return $rows;
}

function glasses_vision_training_release_build(PDO $pdo,int $org,string $datasetPublic,string $profileKey,int $actor): array
{
    if(!glasses_vision_training_release_ready($pdo))throw new InvalidArgumentException('Training release schema is not ready. Run Upgrade first.');
    $profiles=glasses_vision_training_release_profiles();
    if(!isset($profiles[$profileKey]))throw new InvalidArgumentException('Unsupported training profile.');
    $profile=$profiles[$profileKey];
    $dataset=glasses_vision_training_release_dataset($pdo,$org,$datasetPublic);
    $plan=glasses_vision_training_release_applied_plan($pdo,$org,(int)$dataset['id']);
    $rows=glasses_vision_training_release_rows($pdo,$org,(int)$dataset['id'],(int)$plan['id']);

    $sampleMedia=[];$classes=[];$splitCounts=['train'=>0,'val'=>0,'test'=>0];
    foreach($rows as $row){
        $split=(string)$row['split_name'];$governed=(string)$row['governed_split'];
        if(!isset($splitCounts[$split])||$split!==$governed)throw new InvalidArgumentException('Training release blocked: stored dataset split does not match the applied governed split.');
        $sample=(string)$row['sample_public_id'];
        if(!isset($sampleMedia[$sample]))$sampleMedia[$sample]=[];
        if($row['media_id']!==null)$sampleMedia[$sample][]=$row;
        $annotations=json_decode((string)($row['media_annotation_json']??$row['sample_annotation_json']??'[]'),true);
        if(!is_array($annotations))$annotations=[];
        foreach($annotations as $annotation){
            if(!is_array($annotation))continue;
            $label=trim((string)($annotation['label']??''));
            if($label!==''&&!glasses_vision_balance_label_is_negative($label))$classes[$label]=true;
        }
    }
    foreach($sampleMedia as $sample=>$media)if(!$media)throw new InvalidArgumentException('Training release blocked: every governed sample must have active private training media. Missing media for '.$sample.'.');
    if(!$classes)throw new InvalidArgumentException('Training release blocked: no positive object classes are present.');
    $classNames=array_keys($classes);sort($classNames,SORT_STRING);$classIndex=array_flip($classNames);

    $root=glasses_vision_training_release_storage_root();
    if(!is_dir($root)&&!@mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Private training-release storage could not be created.');
    $work=$root.'/.build-'.hash('sha256',$datasetPublic.'|'.$profileKey.'|'.(string)$dataset['dataset_hash']);
    glasses_vision_training_release_remove_tree($work);
    if(!@mkdir($work,0770,true)&&!is_dir($work))throw new RuntimeException('Training release workspace could not be created.');

    $members=[];$provenanceSamples=[];$mediaRoot=glasses_vision_training_media_storage_root();
    try{
        foreach(['train','val','test'] as $split){
            @mkdir($work.'/images/'.$split,0770,true);@mkdir($work.'/labels/'.$split,0770,true);
        }

        foreach($sampleMedia as $samplePublic=>$mediaRows){
            foreach($mediaRows as $row){
                $split=(string)$row['split_name'];
                $source=$mediaRoot.'/'.(string)$row['storage_relative_path'];
                if(!is_file($source))throw new InvalidArgumentException('Training release blocked: governed media bytes are missing for '.$row['media_public_id'].'.');
                $actual=hash_file('sha256',$source);
                if(!hash_equals((string)$row['sha256'],$actual))throw new InvalidArgumentException('Training release blocked: media SHA-256 verification failed for '.$row['media_public_id'].'.');
                $ext=match(strtolower((string)$row['mime_type'])){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>throw new InvalidArgumentException('Training release blocked: unsupported governed image type.')};
                $stem=glasses_vision_training_release_safe_name($samplePublic).'--'.glasses_vision_training_release_safe_name((string)$row['media_public_id']);
                $imageRel='images/'.$split.'/'.$stem.'.'.$ext;$labelRel='labels/'.$split.'/'.$stem.'.txt';
                if(!copy($source,$work.'/'.$imageRel))throw new RuntimeException('Training release image copy failed.');

                $annotations=json_decode((string)($row['media_annotation_json']??'[]'),true);
                if(!is_array($annotations)||(!$annotations&&!glasses_vision_balance_label_is_negative((string)$row['canonical_label']))){
                    $annotations=json_decode((string)($row['sample_annotation_json']??'[]'),true);
                    if(!is_array($annotations))$annotations=[];
                }
                $labelBytes=glasses_vision_training_release_label_lines($annotations,$classIndex);
                if(file_put_contents($work.'/'.$labelRel,$labelBytes,LOCK_EX)===false)throw new RuntimeException('Training release label write failed.');
                $members[$imageRel]=$actual;$members[$labelRel]=hash('sha256',$labelBytes);$splitCounts[$split]++;
                $provenanceSamples[]=[
                    'samplePublicId'=>$samplePublic,'mediaPublicId'=>(string)$row['media_public_id'],'split'=>$split,'groupKey'=>(string)$row['group_key'],
                    'image'=>$imageRel,'label'=>$labelRel,'imageSha256'=>$actual,'labelSha256'=>$members[$labelRel],
                ];
            }
        }
        foreach($splitCounts as $split=>$count)if($count<1)throw new InvalidArgumentException('Training release blocked: governed '.$split.' split has no media.');

        $yaml="path: .\ntrain: images/train\nval: images/val\ntest: images/test\nnames:\n";
        foreach($classNames as $i=>$name)$yaml.="  ".$i.": ".glasses_vision_training_release_yaml_quote($name)."\n";
        file_put_contents($work.'/data.yaml',$yaml,LOCK_EX);$members['data.yaml']=hash('sha256',$yaml);

        usort($provenanceSamples,static fn($a,$b)=>strcmp($a['image'],$b['image']));
        $provenance=[
            'schema'=>'gelato.vision_training_release_provenance.v1',
            'dataset'=>['publicId'=>$datasetPublic,'name'=>(string)$dataset['name'],'versionLabel'=>(string)$dataset['version_label'],'datasetHash'=>(string)$dataset['dataset_hash']],
            'splitPlan'=>['publicId'=>(string)$plan['public_id'],'planHash'=>(string)$plan['plan_hash'],'seed'=>(int)$plan['seed'],'policy'=>json_decode((string)$plan['policy_json'],true)?:[]],
            'trainingProfile'=>$profile,'classes'=>$classNames,'splitMediaCounts'=>$splitCounts,'samples'=>$provenanceSamples,
            'privatePathsExcluded'=>true,
        ];
        $provBytes=json_encode($provenance,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        file_put_contents($work.'/provenance.json',$provBytes,LOCK_EX);$members['provenance.json']=hash('sha256',$provBytes);

        $packageHash=glasses_vision_training_release_core_hash($members);
        $public='vision-release-'.substr($packageHash,0,32);
        ksort($members,SORT_STRING);
        $releaseManifest=['schema'=>'gelato.vision_training_release.v1','publicId'=>$public,'packageHash'=>$packageHash,'profileKey'=>$profileKey,'datasetPublicId'=>$datasetPublic,'datasetHash'=>(string)$dataset['dataset_hash'],'splitPlanPublicId'=>(string)$plan['public_id'],'splitPlanHash'=>(string)$plan['plan_hash'],'classes'=>$classNames,'splitMediaCounts'=>$splitCounts,'members'=>$members,'hashContract'=>'sha256(sorted package-relative path + NUL + member sha256 + LF)'];
        $manifestBytes=json_encode($releaseManifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        file_put_contents($work.'/release-manifest.json',$manifestBytes,LOCK_EX);
        $manifestSha=hash('sha256',$manifestBytes);

        $checkMembers=$members;$checkMembers['release-manifest.json']=$manifestSha;ksort($checkMembers,SORT_STRING);
        $checkBytes='';foreach($checkMembers as $path=>$sha)$checkBytes.=$sha.'  '.$path."\n";
        file_put_contents($work.'/checksums.sha256',$checkBytes,LOCK_EX);

        $zipName=$public.'.zip';$finalRelative=(int)$org.'/'.$zipName;$orgDir=$root.'/'.(int)$org;
        if(!is_dir($orgDir)&&!@mkdir($orgDir,0770,true)&&!is_dir($orgDir))throw new RuntimeException('Training release destination could not be created.');
        $zipTmp=$orgDir.'/.'.$zipName.'.tmp';@unlink($zipTmp);
        if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP support is required to build training releases.');
        $zip=new ZipArchive();
        if($zip->open($zipTmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Training release ZIP could not be created.');
        $zipMembers=array_keys($checkMembers);$zipMembers[]='checksums.sha256';sort($zipMembers,SORT_STRING);
        foreach($zipMembers as $path){
            if(!$zip->addFile($work.'/'.$path,$path)){ $zip->close(); throw new RuntimeException('Training release ZIP member could not be added.'); }
            if(method_exists($zip,'setMtimeName'))$zip->setMtimeName($path,315532800);
        }
        if(!$zip->close())throw new RuntimeException('Training release ZIP could not be finalized.');
        $final=$root.'/'.$finalRelative;
        if(is_file($final))@unlink($final);
        if(!@rename($zipTmp,$final))throw new RuntimeException('Training release ZIP could not be published.');
        $bytes=filesize($final);if($bytes===false)throw new RuntimeException('Training release size could not be read.');

        $existing=$pdo->prepare("SELECT public_id,manifest_json,package_bytes,created_at FROM glasses_vision_training_releases WHERE organization_id=? AND package_hash=? LIMIT 1");
        $existing->execute([$org,$packageHash]);$found=$existing->fetch();
        if(!$found){
            $pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,profile_key,package_hash,manifest_json,package_relative_path,package_bytes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
              ->execute([$org,$public,(int)$dataset['id'],(int)$plan['id'],$profileKey,$packageHash,json_encode($releaseManifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$finalRelative,(int)$bytes,$actor]);
        }
        return ['publicId'=>$public,'packageHash'=>$packageHash,'packageBytes'=>(int)$bytes,'profile'=>$profile,'manifest'=>$releaseManifest,'reused'=>(bool)$found];
    } finally {
        glasses_vision_training_release_remove_tree($work);
    }
}

function glasses_vision_training_release_list(PDO $pdo,int $org,int $limit=50): array
{
    if(!glasses_vision_training_release_ready($pdo))return [];
    $limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT r.public_id,r.profile_key,r.package_hash,r.package_bytes,r.created_at,d.public_id dataset_public_id,d.name dataset_name,d.version_label
      FROM glasses_vision_training_releases r JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id
      WHERE r.organization_id=? ORDER BY r.id DESC LIMIT ".$limit);
    $q->execute([$org]);
    return array_map(static fn($r)=>[
        'publicId'=>$r['public_id'],'profileKey'=>$r['profile_key'],'packageHash'=>$r['package_hash'],'packageBytes'=>(int)$r['package_bytes'],'createdAt'=>$r['created_at'],
        'datasetPublicId'=>$r['dataset_public_id'],'datasetName'=>$r['dataset_name'],'datasetVersion'=>$r['version_label'],
    ],$q->fetchAll());
}

function glasses_vision_training_release_download_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT public_id,package_hash,package_relative_path,package_bytes FROM glasses_vision_training_releases WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Training release was not found.');
    return $row;
}

function glasses_vision_training_release_catalog(PDO $pdo,int $org): array
{
    return ['ready'=>glasses_vision_training_release_ready($pdo),'schema'=>'gelato.vision_training_release.v1','profiles'=>array_values(glasses_vision_training_release_profiles()),'releases'=>glasses_vision_training_release_list($pdo,$org)];
}
