<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../includes/secret-key.php';
if (!function_exists('proc_open')) throw new RuntimeException('proc_open is required for the key concurrency regression.');
$fixture=sys_get_temp_dir().'/gelato-key-race-'.bin2hex(random_bytes(8));
mkdir($fixture,0700,true);
$worker=$fixture.'/worker.php';
$helper=var_export(realpath(__DIR__.'/../includes/secret-key.php'),true);
file_put_contents($worker,"<?php\nrequire ".$helper.";\nwhile(!is_file(\$argv[2])) usleep(1000);\necho hash('sha256',app_secret_key_from_file(\$argv[1]));\n");
$processes=[];
function sk_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function sk_remove(string $path): void {
    if(is_dir($path)&&!is_link($path)){foreach(new FilesystemIterator($path) as $entry)sk_remove($entry->getPathname());rmdir($path);}
    elseif(file_exists($path)||is_link($path))unlink($path);
}
try {
    for($i=0;$i<12;$i++){
        $pipes=[];$process=proc_open([PHP_BINARY,$worker,$fixture.'/keys',$fixture.'/go'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start key concurrency worker.');
        fclose($pipes[0]);$processes[]=[$process,$pipes];
    }
    touch($fixture.'/go');$hashes=[];
    foreach($processes as [$process,$pipes]){
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        sk_assert(proc_close($process)===0,'Key worker failed: '.$err);$hashes[]=trim($out);
    }
    $processes=[];
    sk_assert(count(array_unique($hashes))===1&&strlen($hashes[0])===64,'Every concurrent caller must receive the same key.');
    $path=$fixture.'/keys/application.key';$persisted=file_get_contents($path);
    sk_assert(hash('sha256',app_secret_key_from_file($fixture.'/keys'))===$hashes[0],'Subsequent processes must receive the persisted key.');
    sk_assert(file_get_contents($path)===$persisted,'An existing valid key must remain unchanged.');
    sk_assert((fileperms($path)&0777)===0600,'Encryption key must be private.');
    file_put_contents($path,'invalid-existing-key');$failed=false;
    try{app_secret_key_from_file($fixture.'/keys');}catch(RuntimeException){$failed=true;}
    sk_assert($failed&&file_get_contents($path)==='invalid-existing-key','Invalid existing keys must fail without silent rotation.');
    echo "secret key concurrent initialization passed\n";
} finally {
    foreach($processes as [$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_close($process);}
    sk_remove($fixture);
}
