<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}

require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/private-storage.php';
require_once __DIR__.'/../includes/glasses-vision-training-release.php';

// Schedule this CLI command on the host. Each call advances bounded, private cursors.
$options=getopt('',['limit:','release-limit:']);
$limit=max(1,min(5000,(int)($options['limit']??500)));
$releaseLimit=max(1,min(500,(int)($options['release-limit']??100)));
$lock=null;
try{
    $pdo=app_pdo();
    if(!glasses_vision_training_media_ready($pdo)||!glasses_vision_training_release_ready($pdo))throw new RuntimeException('Training-media migrations are required.');
    $statePath=app_private_storage_root().'/vision-retention-cursor.json';
    if(is_link($statePath))throw new RuntimeException('Retention cursor cannot be linked.');
    $lock=fopen($statePath,'c+b');
    if(!$lock)throw new RuntimeException('Retention cursor could not be opened.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);$lock=null;echo json_encode(['skipped'=>'cleanup_already_running'])."\n";exit(0);}
    @chmod($statePath,0600);
    $state=json_decode(stream_get_contents($lock),true)?:[];
    $media=glasses_vision_training_media_expire_batch($pdo,null,null,$limit,(int)($state['mediaAfterId']??0));
    $releases=glasses_vision_training_release_expire_batch($pdo,$releaseLimit,(int)($state['releaseAfterId']??0));
    $next=json_encode(['mediaAfterId'=>$media['nextAfterId'],'releaseAfterId'=>$releases['nextAfterId']],JSON_THROW_ON_ERROR)."\n";
    rewind($lock);
    if(!ftruncate($lock,0)||fwrite($lock,$next)!==strlen($next)||!fflush($lock))throw new RuntimeException('Retention cursor could not be saved.');
    echo json_encode(['media'=>$media,'releases'=>$releases],JSON_THROW_ON_ERROR)."\n";
    $exitCode=($media['failed']+$releases['failed'])>0?1:0;
}catch(Throwable $e){fwrite(STDERR,'Training-media cleanup failed: '.$e->getMessage()."\n");$exitCode=1;}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
exit($exitCode);
