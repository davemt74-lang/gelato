<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/public-agent-core.php';

if(($argv[1]??'')==='worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$pdo=app_pdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    echo json_encode(['connection'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
    try{
        $limits=public_agent_limits_config($input['limits']);
        if($input['mode']==='network'){
            public_agent_network_gate($pdo,(int)$input['org'],'203.0.113.100',$limits);$result=['ok'=>true];
        }else $result=['ok'=>true,'lease'=>public_agent_provider_reserve($pdo,(int)$input['org'],(int)$input['units'],$limits)];
    }catch(PublicAgentLimitException $error){$result=['ok'=>false,'reason'=>$error->reason,'retryAfter'=>$error->retryAfter];}
    catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);}
    echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}

$checks=0;
function hpac_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function hpac_value(PDO $pdo,string $sql,array $args=[]):mixed{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
$pdo=app_pdo();$pdo->exec('SET SESSION innodb_lock_wait_timeout=12');$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$units=public_agent_payload_units(['input'=>'Concurrent public assistant question','max_output_tokens'=>500]);
$defaults=public_agent_limits_config();
$cases=[
    ['network','network',['network_requests_per_10_minutes'=>2]],
    ['provider','concurrency',['organization_concurrent_requests'=>2]],
    ['provider','organization_requests',['organization_concurrent_requests'=>32,'organization_requests_per_10_minutes'=>2]],
    ['provider','organization_token_units',['organization_concurrent_requests'=>32,'organization_token_units_per_day'=>2*$units]],
];
foreach($cases as [$mode,$reason,$overrides]){
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','UTC')")->execute(['Public assistant concurrency '.$reason.' '.bin2hex(random_bytes(6))]);$org=(int)$pdo->lastInsertId();
    $workers=[];
    try{
        $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM organizations WHERE id=? FOR UPDATE');$q->execute([$org]);$q->fetchColumn();
        for($i=0;$i<3;$i++){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not launch quota worker.');
            $workers[]=['process'=>$process,'pipes'=>$pipes];$index=count($workers)-1;
            fwrite($pipes[0],json_encode(['org'=>$org,'units'=>$units,'mode'=>$mode,'limits'=>array_replace($defaults,$overrides)],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
            $ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$workers[$index]['connection']=(int)$ready['connection'];
        }
        // Observe all independent MariaDB transactions waiting on the same real
        // tenant row before releasing them. Sequential calls cannot satisfy this.
        $wait=$pdo->prepare("SELECT DISTINCT r.trx_mysql_thread_id FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE r.trx_state='LOCK WAIT' AND b.trx_mysql_thread_id=?");
        $deadline=microtime(true)+8;$allWaiting=false;
        do{
            $wait->execute([$connection]);$waiting=array_map('intval',$wait->fetchAll(PDO::FETCH_COLUMN));
            $allWaiting=count(array_intersect(array_column($workers,'connection'),$waiting))===3;
            if($allWaiting)break;usleep(200000);
        }while(microtime(true)<$deadline);
        hpac_check($allWaiting,$reason.': three distinct workers must be observed in MariaDB lock wait.');
        $pdo->commit();$results=[];
        foreach($workers as &$worker){
            $out=stream_get_contents($worker['pipes'][1]);$error=stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);$exit=proc_close($worker['process']);$worker['process']=null;
            hpac_check($exit===0,$reason.': concurrent admission must finish without a database error: '.$error);
            $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        }unset($worker);
        $allowed=array_values(array_filter($results,static fn(array $r):bool=>$r['ok']===true));
        $denied=array_values(array_filter($results,static fn(array $r):bool=>$r['ok']===false));
        hpac_check(count($allowed)===2&&count($denied)===1,$reason.': exactly two requests fit the shared allowance.');
        hpac_check($denied[0]['reason']===$reason&&$denied[0]['retryAfter']>0,$reason.': the losing worker receives a retryable quota denial.');
        if($mode==='network'){
            hpac_check((int)hpac_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='network'",[$org])===2,'Concurrent network admissions do not lose an increment.');
            hpac_check((int)hpac_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===0,'Intake alone does not reserve provider slots.');
        }else{
            hpac_check((int)hpac_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_requests'",[$org])===2,$reason.': the denied worker consumes no provider request.');
            hpac_check((int)hpac_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_token_units'",[$org])===2*$units,$reason.': the denied worker consumes no token allocation.');
            hpac_check((int)hpac_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===2,$reason.': no third provider slot exists.');
            foreach($allowed as $result)public_agent_provider_release($pdo,$org,$result['lease']);
        }
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($workers as $worker){
            if(is_resource($worker['process']))proc_terminate($worker['process']);
            foreach($worker['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);
            if(is_resource($worker['process']))proc_close($worker['process']);
        }
        $pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$org]);
    }
}
echo "Public assistant concurrent admission: {$checks} checks passed.\n";
