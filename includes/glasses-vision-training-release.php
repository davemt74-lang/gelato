<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-annotation-qa.php';

const GLASSES_VISION_TRAINING_RELEASE_SCHEMA='gelato.vision_training_release.v1';

function glasses_vision_training_release_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_training_releases'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_annotation_qa_ready($pdo);
}

function glasses_vision_training_release_storage_root(): string
{
    return dirname(__DIR__).'/storage/vision-training-releases';
}

function glasses_vision_training_release_profiles(): array
{
    return [
        'yolo11n_640'=>['model'=>'yolo11n.pt','imageSize'=>640,'epochs'=>100,'batch'=>16,'seed'=>74,'task'=>'detect','format'=>'yolo_detection'],
        'yolo11s_640'=>['model'=>'yolo11s.pt','imageSize'=>640,'epochs'=>120,'batch'=>16,'seed'=>74,'task'=>'detect','format'=>'yolo_detection'],
        'yolo11n_960'=>['model'=>'yolo11n.pt','imageSize'=>960,'epochs'=>120,'batch'=>8,'seed'=>74,'task'=>'detect','format'=>'yolo_detection'],
    ];
}

function glasses_vision_training_release_profile(string $name): array
{
    $profiles=glasses_vision_training_release_profiles();
    if(!isset($profiles[$name]))throw new InvalidArgumentException('Unsupported Vision training profile.');
    return $profiles[$name];
}

function glasses_vision_training_release_canonicalize(mixed $value): mixed
{
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('glasses_vision_training_release_canonicalize',$value);
    ksort($value,SORT_STRING);
    foreach($value as $k=>$v)$value[$k]=glasses_vision_training_release_canonicalize($v);
    return $value;
}

function glasses_vision_training_release_json(array $value): string
{
    return json_encode(glasses_vision_training_release_canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
}

function glasses_vision_training_release_hash(array $manifest): string
{
    unset($manifest['releaseHash']);
    return hash('sha256',glasses_vision_training_release_json($manifest));
}

function glasses_vision_training_release_ext(string $mime): string
{
    return match(strtolower($mime)){
        'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
        default=>throw new InvalidArgumentException('Training release contains unsupported media type.'),
    };
}

function glasses_vision_training_release_yolo_lines(array $annotations,array $classIndex): string
{
    $lines=[];
    foreach($annotations as $annotation){
        if(!is_array($annotation))throw new InvalidArgumentException('Training release annotation is invalid.');
        $label=trim((string)($annotation['label']??''));
        if($label===''||!array_key_exists($label,$classIndex))throw new InvalidArgumentException('Training release annotation label is not in the deterministic class map.');
        $box=glasses_vision_annotation_qa_box(is_array($annotation['bbox']??null)?$annotation['bbox']:[]);
        if(!$box['valid'])throw new InvalidArgumentException('Training release contains an invalid annotation box.');
        $cx=$box['x']+$box['width']/2;$cy=$box['y']+$box['height']/2;
        $lines[]=sprintf('%d %.8F %.8F %.8F %.8F',(int)$classIndex[$label],$cx,$cy,$box['width'],$box['height']);
    }
    return $lines?implode("\n",$lines)."\n":'';
}

function glasses_vision_training_release_source_path(string $relative): string
{
    $root=realpath(glasses_vision_training_media_storage_root());
    if($root===false)throw new RuntimeException('Private training-media storage is unavailable.');
    $candidate=glasses_vision_training_media_storage_root().'/'.ltrim($relative,'/\\');
    $real=realpath($candidate);
    if($real===false||!is_file($real)||(!str_starts_with($real,$root.DIRECTORY_SEPARATOR)&&$real!==$root))throw new RuntimeException('Training media artifact is missing or outside private storage.');
    return $real;
}

function glasses_vision_training_release_remove_tree(string $path): void
{
    if(!is_dir($path)){if(is_file($path))@unlink($path);return;}
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());}
    @rmdir($path);
}

function glasses_vision_training_release_zip(string $sourceDir,string $zipPath): void
{
    if(!class_exists('ZipArchive'))throw new RuntimeException('PHP Zip extension is required to build training releases.');
    $entries=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir,FilesystemIterator::SKIP_DOTS));
    foreach($it as $item)if($item->isFile())$entries[]=str_replace('\\','/',substr($item->getPathname(),strlen(rtrim($sourceDir,'/\\'))+1));
    sort($entries,SORT_STRING);
    $zip=new ZipArchive();
    if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Training release ZIP could not be created.');
    foreach($entries as $rel){
        if(!$zip->addFile($sourceDir.'/'.$rel,$rel)){ $zip->close(); throw new RuntimeException('Training release file could not be added to ZIP.'); }
        if(method_exists($zip,'setMtimeName'))$zip->setMtimeName($rel,946684800);
    }
    if(!$zip->close())throw new RuntimeException('Training release ZIP could not be finalized.');
}

function glasses_vision_training_release_dataset(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision dataset was not found.');
    if($row['status']!=='frozen')throw new InvalidArgumentException('Training release requires a frozen curated dataset.');
    if(empty($row['dataset_hash']))throw new InvalidArgumentException('Frozen dataset is missing its immutable hash.');
    return $row;
}

function glasses_vision_training_release_applied_plan(PDO $pdo,int $org,int $datasetId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_dataset_split_plans WHERE organization_id=? AND dataset_id=? AND status='applied' ORDER BY applied_at DESC,id DESC LIMIT 1");
    $q->execute([$org,$datasetId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Training release requires an applied governed split plan.');
    return $row;
}

function glasses_vision_training_release_rows(PDO $pdo,int $org,int $datasetId): array
{
    $q=$pdo->prepare("SELECT
      di.split_name,s.id sample_id,s.public_id sample_public_id,s.canonical_label,s.source_type,s.source_reference,s.annotation_json sample_annotation_json,
      m.id media_id,m.public_id media_public_id,m.storage_relative_path,m.mime_type,m.sha256,m.annotation_json media_annotation_json,m.capture_group,m.build_session_id,
      ce.decision curation_decision,ce.reason curation_reason
      FROM glasses_vision_dataset_items di
      JOIN glasses_vision_training_samples s ON s.id=di.sample_id AND s.organization_id=di.organization_id
      JOIN glasses_vision_training_media m ON m.sample_id=s.id AND m.organization_id=s.organization_id AND m.status='active'
      LEFT JOIN glasses_vision_dataset_curation_events ce ON ce.id=(
        SELECT MAX(c2.id) FROM glasses_vision_dataset_curation_events c2
        WHERE c2.organization_id=di.organization_id AND c2.dataset_id=di.dataset_id AND c2.sample_id=di.sample_id
      )
      WHERE di.organization_id=? AND di.dataset_id=?
      ORDER BY FIELD(di.split_name,'train','val','test'),s.public_id,m.public_id");
    $q->execute([$org,$datasetId]);return $q->fetchAll();
}

function glasses_vision_training_release_public(array $row): array
{
    $manifest=json_decode((string)$row['manifest_json'],true)?:[];
    return [
        'publicId'=>$row['public_id'],'datasetPublicId'=>$row['dataset_public_id']??($manifest['dataset']['publicId']??null),
        'status'=>$row['status'],'trainingProfile'=>$row['training_profile'],'releaseHash'=>$row['release_hash'],
        'artifactSha256'=>$row['artifact_sha256'],'artifactBytes'=>(int)$row['artifact_bytes'],'createdAt'=>$row['created_at'],
        'manifest'=>$manifest,
    ];
}

function glasses_vision_training_release_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT r.*,d.public_id dataset_public_id FROM glasses_vision_training_releases r JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id WHERE r.organization_id=? AND r.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Training release was not found.');return $row;
}

function glasses_vision_training_release_list(PDO $pdo,int $org,int $limit=50): array
{
    $limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_releases WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);return array_map(fn($id)=>glasses_vision_training_release_public(glasses_vision_training_release_row($pdo,$org,(string)$id)),$q->fetchAll(PDO::FETCH_COLUMN));
}

function glasses_vision_training_release_build(PDO $pdo,int $org,string $datasetPublic,string $profileName,int $actor): array
{
    if(!glasses_vision_training_release_ready($pdo))throw new RuntimeException('Vision Lab V6 training-release migration is not installed.');
    $dataset=glasses_vision_training_release_dataset($pdo,$org,$datasetPublic);
    $plan=glasses_vision_training_release_applied_plan($pdo,$org,(int)$dataset['id']);
    $profile=glasses_vision_training_release_profile($profileName);
    $rows=glasses_vision_training_release_rows($pdo,$org,(int)$dataset['id']);
    if(!$rows)throw new InvalidArgumentException('Frozen dataset has no active governed training media.');

    $splitCounts=['train'=>0,'val'=>0,'test'=>0];$labels=[];
    foreach($rows as $row){
        if(!isset($splitCounts[$row['split_name']]))throw new InvalidArgumentException('Training release contains an unsupported split.');
        $annotations=json_decode((string)($row['media_annotation_json']?:$row['sample_annotation_json']?:'[]'),true);
        if(!is_array($annotations))throw new InvalidArgumentException('Training release contains invalid annotation JSON.');
        $canonical=trim((string)($row['canonical_label']??''));
        if(!$annotations&&!glasses_vision_balance_label_is_negative($canonical))throw new InvalidArgumentException('Training release blocked: a positive sample has no media annotation.');
        foreach($annotations as $ann){
            $label=trim((string)($ann['label']??''));
            if($label==='')throw new InvalidArgumentException('Training release blocked: an annotation label is empty.');
            $labels[$label]=true;
        }
        $splitCounts[$row['split_name']]++;
    }
    if(min($splitCounts)<1)throw new InvalidArgumentException('Training release requires non-empty train, val, and test splits.');
    $classes=array_keys($labels);sort($classes,SORT_STRING);
    if(!$classes)throw new InvalidArgumentException('Training release requires at least one positive class.');
    $classIndex=[];foreach($classes as $i=>$label)$classIndex[$label]=$i;

    $tmp=sys_get_temp_dir().'/gelato-vision-release-'.bin2hex(random_bytes(8));
    if(!mkdir($tmp,0700,true)&&!is_dir($tmp))throw new RuntimeException('Training release workspace could not be created.');
    try{
        foreach(['train','val','test'] as $split){mkdir($tmp.'/images/'.$split,0700,true);mkdir($tmp.'/labels/'.$split,0700,true);}
        $files=[];$items=[];$index=0;
        foreach($rows as $row){
            $index++;$split=(string)$row['split_name'];$ext=glasses_vision_training_release_ext((string)$row['mime_type']);
            $base='image-'.str_pad((string)$index,6,'0',STR_PAD_LEFT);
            $imageRel='images/'.$split.'/'.$base.'.'.$ext;$labelRel='labels/'.$split.'/'.$base.'.txt';
            $source=glasses_vision_training_release_source_path((string)$row['storage_relative_path']);
            $actual=hash_file('sha256',$source);
            if(!hash_equals((string)$row['sha256'],$actual))throw new RuntimeException('Training media SHA-256 verification failed.');
            if(!copy($source,$tmp.'/'.$imageRel))throw new RuntimeException('Training media could not be copied into release workspace.');
            $annotations=json_decode((string)($row['media_annotation_json']?:$row['sample_annotation_json']?:'[]'),true)?:[];
            $labelBody=glasses_vision_training_release_yolo_lines($annotations,$classIndex);
            if(file_put_contents($tmp.'/'.$labelRel,$labelBody,LOCK_EX)===false)throw new RuntimeException('YOLO label file could not be written.');
            $imageHash=hash_file('sha256',$tmp.'/'.$imageRel);$labelHash=hash_file('sha256',$tmp.'/'.$labelRel);
            $files[]=['path'=>$imageRel,'sha256'=>$imageHash,'bytes'=>filesize($tmp.'/'.$imageRel),'type'=>'image'];
            $files[]=['path'=>$labelRel,'sha256'=>$labelHash,'bytes'=>filesize($tmp.'/'.$labelRel),'type'=>'label'];
            $items[]=[
                'samplePublicId'=>$row['sample_public_id'],'mediaPublicId'=>$row['media_public_id'],'split'=>$split,
                'imagePath'=>$imageRel,'imageSha256'=>$imageHash,'labelPath'=>$labelRel,'labelSha256'=>$labelHash,
                'canonicalLabel'=>$row['canonical_label'],'sourceType'=>$row['source_type'],'sourceReference'=>$row['source_reference'],
                'captureGroup'=>$row['capture_group'],'curationDecision'=>$row['curation_decision']?:'include','curationReason'=>$row['curation_reason'],
            ];
        }

        $yaml=["path: .","train: images/train","val: images/val","test: images/test","names:"];
        foreach($classes as $i=>$label)$yaml[]='  '.$i.': '.json_encode($label,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $yamlBody=implode("\n",$yaml)."\n";file_put_contents($tmp.'/dataset.yaml',$yamlBody,LOCK_EX);
        $files[]=['path'=>'dataset.yaml','sha256'=>hash_file('sha256',$tmp.'/dataset.yaml'),'bytes'=>filesize($tmp.'/dataset.yaml'),'type'=>'config'];
        usort($files,static fn($a,$b)=>strcmp($a['path'],$b['path']));

        $manifest=[
            'schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,
            'format'=>'yolo_detection',
            'dataset'=>['publicId'=>$dataset['public_id'],'name'=>$dataset['name'],'versionLabel'=>$dataset['version_label'],'datasetHash'=>$dataset['dataset_hash']],
            'splitPlan'=>['publicId'=>$plan['public_id'],'planHash'=>$plan['plan_hash'],'policy'=>json_decode((string)$plan['policy_json'],true)?:[],'seed'=>(int)$plan['seed']],
            'trainingProfile'=>['name'=>$profileName,'config'=>$profile],
            'classes'=>$classes,'splitCounts'=>$splitCounts,'itemCount'=>count($items),'items'=>$items,'files'=>$files,
            'privacy'=>['absolutePathsIncluded'=>false,'privateStoragePathsIncluded'=>false],
        ];
        $releaseHash=glasses_vision_training_release_hash($manifest);$manifest['releaseHash']=$releaseHash;
        $manifestBody=json_encode(glasses_vision_training_release_canonicalize($manifest),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n";
        file_put_contents($tmp.'/provenance-manifest.json',$manifestBody,LOCK_EX);

        $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_training_releases WHERE organization_id=? AND release_hash=? LIMIT 1");
        $existing->execute([$org,$releaseHash]);$existingPublic=$existing->fetchColumn();
        if($existingPublic!==false)return glasses_vision_training_release_public(glasses_vision_training_release_row($pdo,$org,(string)$existingPublic));

        $public=glasses_public_id('vision-release');
        $root=glasses_vision_training_release_storage_root().'/'.$org;
        if(!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Private training-release storage could not be created.');
        $relative=$org.'/'.$public.'.zip';$target=glasses_vision_training_release_storage_root().'/'.$relative;
        glasses_vision_training_release_zip($tmp,$target);
        $artifactHash=hash_file('sha256',$target);$bytes=filesize($target);
        try{
            $pdo->prepare("INSERT INTO glasses_vision_training_releases
              (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by)
              VALUES (?,?,?,?,'built',?,?,?,?,?,?,?,?,?)")
              ->execute([$org,$public,(int)$dataset['id'],(int)$plan['id'],$profileName,json_encode($profile,JSON_UNESCAPED_SLASHES),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$releaseHash,$relative,$artifactHash,$bytes,$actor]);
        }catch(Throwable $e){@unlink($target);throw $e;}
        return glasses_vision_training_release_public(glasses_vision_training_release_row($pdo,$org,$public));
    }finally{glasses_vision_training_release_remove_tree($tmp);}
}

function glasses_vision_training_release_artifact(PDO $pdo,int $org,string $publicId): array
{
    $row=glasses_vision_training_release_row($pdo,$org,$publicId);
    $root=realpath(glasses_vision_training_release_storage_root());
    if($root===false)throw new RuntimeException('Training release storage is unavailable.');
    $path=realpath(glasses_vision_training_release_storage_root().'/'.ltrim((string)$row['artifact_relative_path'],'/\\'));
    if($path===false||!is_file($path)||!str_starts_with($path,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Training release artifact is missing.');
    if(!hash_equals((string)$row['artifact_sha256'],hash_file('sha256',$path)))throw new RuntimeException('Training release artifact failed integrity verification.');
    return ['path'=>$path,'filename'=>'gelato-vision-'.$row['release_hash'].'.zip','sha256'=>$row['artifact_sha256'],'bytes'=>(int)$row['artifact_bytes']];
}

function glasses_vision_training_release_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_training_release_ready($pdo))return ['ready'=>false,'profiles'=>glasses_vision_training_release_profiles(),'releases'=>[]];
    return ['ready'=>true,'schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'profiles'=>glasses_vision_training_release_profiles(),'releases'=>glasses_vision_training_release_list($pdo,$org)];
}
