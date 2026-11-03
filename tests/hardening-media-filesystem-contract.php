<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}

// The worker runs an exact staged copy of the application includes. Database
// credentials arrive only through stdin, never a world-readable fixture file.
if(($argv[1]??'')==='worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $GLOBALS['hmfs_config']=['database'=>$input['database'],'security'=>['session_name'=>'gelato_media_filesystem'],
        'storage'=>['private_root'=>dirname(__DIR__).'/private']];
    require_once __DIR__.'/hardening-media-retention-fixture.php';
    $pdo=app_pdo();
    $status=(string)@file_get_contents('/proc/self/status');
    if(!preg_match('/^Uid:\s+\d+\s+(\d+)/m',$status,$m))throw new RuntimeException('Linux effective UID evidence is required.');
    $out=['uid'=>(int)$m[1]];
    if(isset($input['probe'])){
        $out['probe']=['directoryReadable'=>is_readable($input['probe']),
            'directorySearchable'=>is_executable($input['probe']),
            'childExists'=>file_exists($input['probeChild'])];
    }
    try{
        if(!empty($input['openBasedir']))ini_set('open_basedir',(string)$input['openBasedir']);
        $out['result']=match($input['action']){
            'seed'=>hdm_seed($pdo),
            'media_cleanup'=>glasses_vision_training_media_expire_batch($pdo,(int)$input['org'],null,500,0),
            'release_cleanup'=>glasses_vision_training_release_expire_batch($pdo,1,(int)$input['releaseId']-1),
            'resolve'=>glasses_vision_media_storage_resolve((string)$input['root'],(string)$input['relative']),
            default=>throw new InvalidArgumentException('Unknown filesystem fixture action.'),
        };
        $out['ok']=true;
    }catch(Throwable $error){$out['ok']=false;$out['error']=$error->getMessage();}
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}

require_once __DIR__.'/../includes/bootstrap.php';
$checks=0;
function hmfs_check(bool $ok,string $message): void
{
    global $checks;if(!$ok)throw new RuntimeException($message);$checks++;
}
function hmfs_row(PDO $pdo,string $sql,array $args): array
{
    $q=$pdo->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('The filesystem fixture row is missing.');return $row;
}
function hmfs_worker(string $stage,array $database,array $input,bool $unprivileged=true): array
{
    $command=$unprivileged?['sudo','-n','-u','nobody','--',PHP_BINARY]:[PHP_BINARY];
    array_push($command,$stage.'/tests/hardening-media-filesystem-contract.php','worker');
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$stage);
    if(!is_resource($process))throw new RuntimeException('Cannot start the filesystem worker.');
    fwrite($pipes[0],json_encode($input+['database'=>$database],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    hmfs_check(proc_close($process)===0,'Filesystem worker must complete without runtime errors: '.$errors);
    $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    if($unprivileged)hmfs_check($result['uid']!==0,'Permission regression executes as a non-root process.');
    clearstatcache();
    return $result;
}
function hmfs_copy_php(string $source,string $target): void
{
    if(!mkdir($target,0755,true)&&!is_dir($target))throw new RuntimeException('Cannot stage application includes.');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile()||$file->getExtension()!=='php')continue;
        $relative=substr($file->getPathname(),strlen($source)+1);$path=$target.'/'.$relative;
        if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);
        if(!copy($file->getPathname(),$path)||!hash_equals(hash_file('sha256',$file->getPathname()),hash_file('sha256',$path)))
            throw new RuntimeException('Staged runtime does not match the tested source.');
        chmod($path,0644);
    }
}
function hmfs_directories(string $root,int $mode): void
{
    if(!is_dir($root))return;
    chmod($root,$mode);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $file)if($file->isDir()&&!$file->isLink())chmod($file->getPathname(),$mode);
}
function hmfs_remove_tree(string $root): void
{
    if(!is_dir($root))return;hmfs_directories($root,0777);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){
        if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());
    }
    rmdir($root);
}

$pdo=app_pdo();$database=app_config()['database'];
hmfs_check(PHP_OS_FAMILY==='Linux','Filesystem permission regression requires Linux and sudo to nobody.');
hmfs_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!(bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
    'Filesystem cleanup uses the real MariaDB schema and native PDO prepares.');
$stage=sys_get_temp_dir().'/gelato-media-filesystem-'.bin2hex(random_bytes(10));
if(!mkdir($stage,0755,true))throw new RuntimeException('Cannot create isolated filesystem fixture.');
$restore=[];
try{
    hmfs_copy_php(dirname(__DIR__).'/includes',$stage.'/includes');
    mkdir($stage.'/tests',0755);mkdir($stage.'/storage',0777);chmod($stage.'/storage',0777);
    foreach(['hardening-media-filesystem-contract.php','hardening-media-retention-fixture.php'] as $file){
        copy(__DIR__.'/'.$file,$stage.'/tests/'.$file);chmod($stage.'/tests/'.$file,0644);
    }
    file_put_contents($stage.'/config.php',"<?php return \$GLOBALS['hmfs_config'];\n");chmod($stage.'/config.php',0644);
    $seed=hmfs_worker($stage,$database,['action'=>'seed'],false);
    hmfs_check($seed['ok'],'The real media and release fixture is staged successfully.');
    $fixture=$seed['result'];$org=(int)$fixture['org'];
    hmfs_directories($stage.'/storage',0777);
    $media=hmfs_row($pdo,'SELECT * FROM glasses_vision_training_media WHERE organization_id=? AND public_id=?',[$org,$fixture['media'][3]['publicId']]);
    $mediaRoot=$stage.'/storage/vision-training-media';$mediaPath=$mediaRoot.'/'.$media['storage_relative_path'];
    $mediaDir=dirname($mediaPath);$bytes=file_get_contents($mediaPath);
    hmfs_check(is_string($bytes)&&strlen($bytes)>0,'The expiry fixture has real bytes before cleanup.');

    foreach([
        ['path'=>$mediaDir,'mode'=>0444,'label'=>'readable image directory without search permission'],
        ['path'=>$mediaDir,'mode'=>0111,'label'=>'searchable image directory without read permission'],
        ['path'=>$mediaRoot,'mode'=>0444,'label'=>'readable storage root without search permission'],
        ['path'=>$stage.'/storage','mode'=>0444,'label'=>'storage root hidden by an inaccessible ancestor'],
    ] as $case){
        $restore[$case['path']]=0777;chmod($case['path'],$case['mode']);
        $result=hmfs_worker($stage,$database,['action'=>'media_cleanup','org'=>$org,
            'probe'=>$case['path'],'probeChild'=>$mediaPath]);
        hmfs_check($result['ok']&&$result['result']['expired']===0&&$result['result']['failed']===1,
            'Cleanup must report retryable failure for '.$case['label'].'.');
        if($case['mode']===0444)hmfs_check($result['probe']['directoryReadable']&&!$result['probe']['directorySearchable']&&!$result['probe']['childExists'],
            'The unprivileged process demonstrates false child absence behind '.$case['label'].'.');
        $current=hmfs_row($pdo,'SELECT status,deleted_at FROM glasses_vision_training_media WHERE id=?',[(int)$media['id']]);
        hmfs_check($current['status']==='active'&&$current['deleted_at']===null,'Inaccessible bytes are never reported deleted.');
        chmod($case['path'],0777);unset($restore[$case['path']]);
        hmfs_check(file_get_contents($mediaPath)===$bytes,'The failed cleanup leaves actual image bytes intact.');
    }
    $event=hmfs_row($pdo,"SELECT COUNT(*) n FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='deleted'",[$org,(int)$media['id']]);
    hmfs_check((int)$event['n']===0,'No failure records a successful deletion event.');

    // A genuinely absent root cannot be assumed absent through a blocked parent.
    $blocked=$stage.'/blocked';mkdir($blocked,0755);chmod($blocked,0444);$restore[$blocked]=0755;
    $resolved=hmfs_worker($stage,$database,['action'=>'resolve','root'=>$blocked.'/missing-root','relative'=>'1/image.png']);
    hmfs_check(!$resolved['ok'],'Missing-root resolution fails closed when its parent cannot be searched.');
    chmod($blocked,0755);unset($restore[$blocked]);
    $resolved=hmfs_worker($stage,$database,['action'=>'resolve','root'=>$blocked.'/missing-root','relative'=>'1/image.png']);
    hmfs_check($resolved['ok']&&$resolved['result']===$blocked.'/missing-root/1/image.png'&&!file_exists($resolved['result']),
        'Absence is accepted only after the existing ancestor is readable and searchable.');
    $fresh=hmfs_row($pdo,'SELECT storage_relative_path FROM glasses_vision_training_media WHERE organization_id=? AND public_id=?',[$org,$fixture['media'][0]['publicId']]);
    $resolved=hmfs_worker($stage,$database,['action'=>'resolve','root'=>$mediaRoot,'relative'=>$fresh['storage_relative_path'],'openBasedir'=>$mediaRoot]);
    hmfs_check($resolved['ok'],'An existing accessible storage root works within PHP open_basedir.');

    $retry=hmfs_worker($stage,$database,['action'=>'media_cleanup','org'=>$org]);
    hmfs_check($retry['ok']&&$retry['result']['expired']===1&&$retry['result']['failed']===0&&!file_exists($mediaPath),
        'Retry after access repair removes the actual bytes exactly once.');
    $retry=hmfs_worker($stage,$database,['action'=>'media_cleanup','org'=>$org]);
    hmfs_check($retry['ok']&&$retry['result']['expired']===0&&$retry['result']['failed']===0,'Successful media cleanup remains idempotent.');
    $missing=hmfs_row($pdo,'SELECT * FROM glasses_vision_training_media WHERE organization_id=? AND public_id=?',[$org,$fixture['media'][1]['publicId']]);
    unlink($mediaRoot.'/'.$missing['storage_relative_path']);
    $pdo->prepare('UPDATE glasses_vision_training_media SET retention_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE id=?')->execute([(int)$missing['id']]);
    $cleanup=hmfs_worker($stage,$database,['action'=>'media_cleanup','org'=>$org]);
    hmfs_check($cleanup['ok']&&$cleanup['result']['expired']===1&&$cleanup['result']['failed']===0,'Proven missing image bytes can be cleaned without a false filesystem failure.');

    $release=hmfs_row($pdo,'SELECT * FROM glasses_vision_training_releases WHERE organization_id=? AND public_id=?',[$org,$fixture['release']['publicId']]);
    $releaseRoot=$stage.'/storage/vision-training-releases';$releaseDir=$releaseRoot.'/'.$org;
    $releasePath=$releaseRoot.'/'.$release['artifact_relative_path'];$releaseBytes=file_get_contents($releasePath);
    $qualified=$releaseDir.'/'.$release['public_id'].'-qualified-0123456789abcdef.zip';copy($releasePath,$qualified);
    $unrelated=$releaseDir.'/unrelated.zip';file_put_contents($unrelated,'Unrelated retained artifact');
    foreach([$releaseDir,$stage.'/storage'] as $blockedPath){
        $restore[$blockedPath]=0777;chmod($blockedPath,0444);
        $cleanup=hmfs_worker($stage,$database,['action'=>'release_cleanup','releaseId'=>(int)$release['id'],
            'probe'=>$blockedPath,'probeChild'=>$releasePath]);
        hmfs_check($cleanup['ok']&&$cleanup['result']['expired']===0&&$cleanup['result']['failed']===1,
            'Inaccessible release bytes remain retryable, including a hidden release root.');
        hmfs_check($cleanup['probe']['directoryReadable']&&!$cleanup['probe']['directorySearchable']&&!$cleanup['probe']['childExists'],
            'Release failure uses the real readable-but-unsearchable filesystem state.');
        $current=hmfs_row($pdo,'SELECT status,manifest_json FROM glasses_vision_training_releases WHERE id=?',[(int)$release['id']]);
        hmfs_check($current['status']==='built'&&$current['manifest_json']===$release['manifest_json'],'An inaccessible artifact is never marked expired or stripped of historical evidence.');
        chmod($blockedPath,0777);unset($restore[$blockedPath]);
        hmfs_check(file_get_contents($releasePath)===$releaseBytes&&file_get_contents($qualified)===$releaseBytes,'Failed release cleanup retains both original and qualified bytes.');
    }
    $cleanup=hmfs_worker($stage,$database,['action'=>'release_cleanup','releaseId'=>(int)$release['id']]);
    hmfs_check($cleanup['ok']&&$cleanup['result']['expired']===1&&$cleanup['result']['failed']===0&&!file_exists($releasePath)&&!file_exists($qualified),
        'Repaired release cleanup removes original and qualified archives.');
    hmfs_check(file_get_contents($unrelated)==='Unrelated retained artifact','Release cleanup does not delete another artifact.');
    $current=hmfs_row($pdo,'SELECT status,manifest_json FROM glasses_vision_training_releases WHERE id=?',[(int)$release['id']]);
    hmfs_check($current['status']==='expired'&&$current['manifest_json']===$release['manifest_json'],'Successful release cleanup retains immutable manifest evidence.');
    echo "Media filesystem hardening: PASS ($checks checks; nobody-worker permission failures, proven absence, open_basedir, actual deletion and retryable media/release cleanup)\n";
}finally{
    foreach($restore as $path=>$mode)@chmod($path,$mode);
    hmfs_remove_tree($stage);
}
