<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-vision-media-retention.php';

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
      di.split_name,di.source_hash dataset_source_hash,s.id sample_id,s.public_id sample_public_id,s.canonical_label,s.source_type,s.annotation_json sample_annotation_json,
      m.id media_id,m.public_id media_public_id,m.storage_relative_path,m.mime_type,m.sha256,m.status,m.retention_until,m.annotation_json media_annotation_json,m.capture_group,m.build_session_id,b.public_id build_public_id,
      ce.decision curation_decision,ce.reason curation_reason
      FROM glasses_vision_dataset_items di
      JOIN glasses_vision_training_samples s ON s.id=di.sample_id AND s.organization_id=di.organization_id
      JOIN glasses_vision_training_media m ON m.sample_id=s.id AND m.organization_id=s.organization_id AND ".glasses_vision_media_retained_sql('m')."
      LEFT JOIN glasses_build_sessions b ON b.id=m.build_session_id AND b.organization_id=m.organization_id
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

function glasses_vision_training_release_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT r.*,d.public_id dataset_public_id FROM glasses_vision_training_releases r JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id WHERE r.organization_id=? AND r.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Training release was not found.');return $row;
}

function glasses_vision_training_release_list(PDO $pdo,int $org,int $limit=50): array
{
    $limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_releases WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);return array_map(fn($id)=>glasses_vision_training_release_public(glasses_vision_training_release_row($pdo,$org,(string)$id)),$q->fetchAll(PDO::FETCH_COLUMN));
}

/** An older ZIP never extends the availability of the private images it contains. */
function glasses_vision_training_release_require_sources(PDO $pdo,int $org,array $release,bool $forUpdate=false): void
{
    if($forUpdate&&!$pdo->inTransaction())throw new RuntimeException('Training source locks require an active transaction.');
    $manifest=json_decode((string)($release['manifest_json']??''),true);
    $items=is_array($manifest)?($manifest['items']??null):null;
    if(!is_array($items)||!$items)throw new GlassesVisionMediaUnavailable('Training release media is no longer available.');
    $expected=[];
    foreach($items as $item){
        $public=is_array($item)?(string)($item['mediaPublicId']??''):'';
        $sha=is_array($item)?(string)($item['imageSha256']??''):'';
        if($public===''||strlen($public)>80||!preg_match('/^[a-f0-9]{64}$/D',$sha)||isset($expected[$public]))
            throw new GlassesVisionMediaUnavailable('Training release media references are unavailable.');
        $expected[$public]=$sha;
    }
    // Keep one global acquisition order even when releases span several chunks.
    ksort($expected,SORT_STRING);
    foreach(array_chunk(array_keys($expected),200) as $ids){
        $q=$pdo->prepare("SELECT public_id,sha256 FROM glasses_vision_training_media WHERE organization_id=? AND public_id IN (".implode(',',array_fill(0,count($ids),'?')).") AND ".glasses_vision_media_retained_sql().($forUpdate?' ORDER BY public_id FOR UPDATE':''));
        $q->execute(array_merge([$org],$ids));$found=$q->fetchAll();
        if(count($found)!==count($ids))throw new GlassesVisionMediaUnavailable('Training release contains media that is no longer available.');
        foreach($found as $row)if(!hash_equals($expected[(string)$row['public_id']],(string)$row['sha256']))
            throw new GlassesVisionMediaUnavailable('Training release media has changed.');
    }
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
            glasses_vision_media_require_retained($row);
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
                'canonicalLabel'=>$row['canonical_label'],'annotationLabels'=>array_values(array_map(static fn($a)=>trim((string)($a['label']??'')),$annotations)),'sourceType'=>$row['source_type'],'datasetSourceHash'=>$row['dataset_source_hash'],
                'captureGroup'=>$row['capture_group'],'buildSessionPublicId'=>$row['build_public_id'],'curationDecision'=>$row['curation_decision']?:'include','curationReason'=>$row['curation_reason'],
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
        glasses_vision_training_release_require_sources($pdo,$org,['manifest_json'=>$manifestBody]);

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
            // Building and hashing the archive can outlast a source's deadline.
            glasses_vision_training_release_require_sources($pdo,$org,['manifest_json'=>$manifestBody]);
            $pdo->prepare("INSERT INTO glasses_vision_training_releases
              (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by)
              VALUES (?,?,?,?,'built',?,?,?,?,?,?,?,?)")
              ->execute([$org,$public,(int)$dataset['id'],(int)$plan['id'],$profileName,json_encode($profile,JSON_UNESCAPED_SLASHES),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$releaseHash,$relative,$artifactHash,$bytes,$actor]);
        }catch(Throwable $e){@unlink($target);throw $e;}
        return glasses_vision_training_release_public(glasses_vision_training_release_row($pdo,$org,$public));
    }finally{glasses_vision_training_release_remove_tree($tmp);}
}

function glasses_vision_training_release_artifact(PDO $pdo,int $org,string $publicId): array
{
    $row=glasses_vision_training_release_row($pdo,$org,$publicId);
    glasses_vision_training_release_require_sources($pdo,$org,$row);
    $root=realpath(glasses_vision_training_release_storage_root());
    if($root===false)throw new RuntimeException('Training release storage is unavailable.');
    $path=realpath(glasses_vision_training_release_storage_root().'/'.ltrim((string)$row['artifact_relative_path'],'/\\'));
    if($path===false||!is_file($path)||!str_starts_with($path,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Training release artifact is missing.');
    if(!hash_equals((string)$row['artifact_sha256'],hash_file('sha256',$path)))throw new RuntimeException('Training release artifact failed integrity verification.');
    glasses_vision_training_release_require_sources($pdo,$org,$row);
    return ['path'=>$path,'filename'=>'gelato-vision-'.$row['release_hash'].'.zip','sha256'=>$row['artifact_sha256'],'bytes'=>(int)$row['artifact_bytes']];
}

/** Bounded scan removes derived ZIP bytes; immutable manifests remain audit evidence. */
function glasses_vision_training_release_expire_batch(PDO $pdo,int $limit=100,int $afterId=0): array
{
    $limit=max(1,min(500,$limit));$afterId=max(0,$afterId);
    $q=$pdo->prepare("SELECT id FROM glasses_vision_training_releases WHERE id>? AND status<>'expired' ORDER BY id LIMIT ".$limit);
    $q->execute([$afterId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$expired=0;$failed=0;
    foreach($ids as $id){
        $afterId=(int)$id;
        try{
            $didExpire=glasses_vision_media_cleanup_transaction($pdo,function()use($pdo,$id):bool{
                $q=$pdo->prepare('SELECT * FROM glasses_vision_training_releases WHERE id=? FOR UPDATE');$q->execute([(int)$id]);$row=$q->fetch();
                if(!$row||$row['status']==='expired')return false;
                $org=(int)$row['organization_id'];
                $manifest=json_decode((string)$row['manifest_json'],true);
                // Malformed evidence is unavailable to download, but never permission to erase a file.
                if(!is_array($manifest)||!is_array($manifest['items']??null)||!$manifest['items'])throw new RuntimeException('Training release retention evidence is invalid.');
                foreach($manifest['items'] as $item)if(!is_array($item)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',(string)($item['mediaPublicId']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($item['imageSha256']??'')))throw new RuntimeException('Training release retention evidence is invalid.');
                try{glasses_vision_training_release_require_sources($pdo,$org,$row);return false;}
                catch(GlassesVisionMediaUnavailable $e){}
                $public=(string)$row['public_id'];
                if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$public))throw new RuntimeException('Training release file identity is invalid.');
                $root=glasses_vision_training_release_storage_root();
                $dir=glasses_vision_media_storage_resolve($root,(string)$org);
                $relative=(string)$row['artifact_relative_path'];
                if(!preg_match('~^'.preg_quote((string)$org,'~').'/'.preg_quote($public,'~').'(?:-qualified-[a-f0-9]{16})?\.zip$~D',$relative))throw new RuntimeException('Training release artifact path is invalid.');
                $qualified=glob($dir.'/'.$public.'-qualified-*.zip');
                if($qualified===false)throw new RuntimeException('Training release directory could not be inspected.');
                $paths=array_unique(array_merge([$root.'/'.$relative],$qualified,[$dir.'/'.$public.'.zip']));
                $deleted=0;
                foreach($paths as $path){
                    $path=glasses_vision_media_storage_resolve($root,substr($path,strlen($root)+1));
                    if(file_exists($path)){
                        if(!is_file($path)||!@unlink($path))throw new RuntimeException('Training release bytes could not be deleted.');
                        $deleted++;
                    }
                }
                $pdo->prepare("UPDATE glasses_vision_training_releases SET status='expired' WHERE organization_id=? AND id=?")->execute([$org,(int)$id]);
                app_audit($pdo,$org,null,'glasses.training_release_expired','vision_training_release',$public,null,['reason'=>'source_media_unavailable','deletedFiles'=>$deleted]);
                return true;
            });
            if($didExpire)$expired++;
        }catch(Throwable $e){
            $failed++;
            // Leave the row retryable; deadline checks already deny serving its bytes.
            $q=$pdo->prepare('SELECT organization_id,public_id FROM glasses_vision_training_releases WHERE id=?');$q->execute([(int)$id]);$row=$q->fetch();
            if($row)app_audit($pdo,(int)$row['organization_id'],null,'glasses.training_release_cleanup_failed','vision_training_release',(string)$row['public_id'],null,['retryable'=>true]);
        }
    }
    return ['expired'=>$expired,'failed'=>$failed,'scanned'=>count($ids),'nextAfterId'=>count($ids)<$limit?0:$afterId];
}

function glasses_vision_training_release_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_training_release_ready($pdo))return ['ready'=>false,'profiles'=>glasses_vision_training_release_profiles(),'releases'=>[]];
    return ['ready'=>true,'schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'profiles'=>glasses_vision_training_release_profiles(),'releases'=>glasses_vision_training_release_list($pdo,$org)];
}
