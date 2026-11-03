<?php
declare(strict_types=1);
// This helper is an explicit disposable-database test fixture, never an HTTP API.
if(PHP_SAPI!=='cli'||getenv('GELATO_PUBLIC_AGENT_FIXTURE')!=='1'){http_response_code(404);exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/public-agent-core.php';

function hpaf_rows(PDO $pdo,string $sql,array $args=[]):array{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();}
function hpaf_save(string $stage,array $state):void{
    file_put_contents($stage.'/state.json',json_encode($state,JSON_THROW_ON_ERROR));chmod($stage.'/state.json',0600);
}
function hpaf_config(string $stage,array $limits):void{
    $config=app_config();$config['public_agent_limits']=$limits;
    $config['security']['cookie_secure']=false;$config['security']['session_name']='hpa_http_'.substr(hash('sha256',$stage),0,12);
    file_put_contents($stage.'/site/config.php',"<?php\nreturn ".var_export($config,true).";\n");chmod($stage.'/site/config.php',0600);
}
function hpaf_restore_row(PDO $pdo,string $table,array $row):void{
    $columns=array_keys($row);$names=implode(',',array_map(static fn(string $name):string=>'`'.$name.'`',$columns));
    $pdo->prepare('INSERT INTO `'.$table.'` ('.$names.') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
}
function hpaf_cleanup(PDO $pdo,array $state):void{
    $org=(int)$state['org'];
    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM knowledge_documents WHERE organization_id=? AND public_id=?')->execute([$org,$state['document']]);
        foreach(['public_agent_usage_buckets','public_agent_request_leases'] as $table){
            $pdo->prepare('DELETE FROM '.$table.' WHERE organization_id=?')->execute([$org]);
            foreach($state[$table] as $row)hpaf_restore_row($pdo,$table,$row);
        }
        $pdo->prepare("DELETE FROM llm_api_credentials WHERE organization_id=? AND provider IN ('anthropic','openai')")->execute([$org]);
        foreach($state['credentials'] as $row)hpaf_restore_row($pdo,'llm_api_credentials',$row);
        // Restore the existing settings row in place; no other fixture changes it.
        if($state['settings']){
            $row=$state['settings'];$id=(int)$row['id'];unset($row['id']);
            $sets=implode(',',array_map(static fn(string $name):string=>'`'.$name.'`=?',array_keys($row)));
            $pdo->prepare('UPDATE public_agent_settings SET '.$sets.' WHERE id=?')->execute([...array_values($row),$id]);
        }else $pdo->prepare('DELETE FROM public_agent_settings WHERE organization_id=?')->execute([$org]);
        if($state['created_org'])$pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$org]);
        $pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([(int)$state['other']]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([(int)$state['actor']]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

$mode=$argv[1]??'';$stage=$argv[2]??'';
if($stage===''||!is_dir($stage)||is_link($stage))throw new RuntimeException('A private existing stage directory is required.');
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,512,JSON_THROW_ON_ERROR);$pdo=app_pdo();
if($mode==='seed'){
    if(is_file($stage.'/state.json'))throw new RuntimeException('The fixture stage must be new.');
    $port=(int)($input['providerPort']??0);if($port<1024||$port>65535)throw new RuntimeException('A localhost provider port is required.');
    foreach(['/site','/site/includes','/site/api','/site/storage','/site/storage/keys','/sessions'] as $directory)if(!is_dir($stage.$directory))mkdir($stage.$directory,0700,true);
    $files=['api/public-agent.php','includes/bootstrap.php','includes/auth-session.php','includes/secret-key.php','includes/public-agent-core.php','includes/public-agent-limits.php'];
    foreach($files as $file){
        if(!copy(RESTAURANT_APP_ROOT.'/'.$file,$stage.'/site/'.$file)||hash_file('sha256',RESTAURANT_APP_ROOT.'/'.$file)!==hash_file('sha256',$stage.'/site/'.$file))throw new RuntimeException('Production source staging failed.');
    }
    file_put_contents($stage.'/site/storage/keys/application.key',base64_encode(app_secret_key()));chmod($stage.'/site/storage/keys/application.key',0600);
    hpaf_config($stage,public_agent_limits_config());
    $router= <<<'PHP'
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/api/public-agent.php'){http_response_code(404);exit('Not found.');}
require __DIR__.'/site/includes/bootstrap.php';
require __DIR__.'/site/includes/public-agent-core.php';
public_agent_serve(static function(string $url,array $headers,array $payload,int $timeout):array{
    if(!in_array($url,['https://api.anthropic.com/v1/messages','https://api.openai.com/v1/responses'],true))throw new RuntimeException('Unexpected production provider target.');
    return public_agent_http_json('http://127.0.0.1:STUB_PORT/response',$headers,$payload,$timeout);
});
PHP;
    file_put_contents($stage.'/router.php',str_replace('STUB_PORT',(string)$port,$router));chmod($stage.'/router.php',0600);
    $saved=public_agent_settings($pdo);$created=$saved===null;
    if($created){$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','UTC')")->execute(['Public assistant HTTP '.bin2hex(random_bytes(5))]);$org=(int)$pdo->lastInsertId();}
    else{$org=(int)$saved['organization_id'];unset($saved['organization_name']);}
    $suffix=bin2hex(random_bytes(8));$question='quotaquery'.$suffix;
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','UTC')")->execute(['Other public assistant HTTP '.$suffix]);$other=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture','Public','Assistant','Public Assistant Fixture','active')")->execute(['hpa-http-'.$suffix.'@example.test']);$actor=(int)$pdo->lastInsertId();
    $state=['org'=>$org,'other'=>$other,'actor'=>$actor,'created_org'=>$created,'settings'=>$saved,'document'=>'hpa-http-'.$suffix,'question'=>$question,
        'credentials'=>hpaf_rows($pdo,"SELECT * FROM llm_api_credentials WHERE organization_id=? AND provider IN ('anthropic','openai')",[$org]),
        'public_agent_usage_buckets'=>hpaf_rows($pdo,'SELECT * FROM public_agent_usage_buckets WHERE organization_id=?',[$org]),
        'public_agent_request_leases'=>hpaf_rows($pdo,'SELECT * FROM public_agent_request_leases WHERE organization_id=?',[$org]),
    ];hpaf_save($stage,$state);
    try{
        $pdo->prepare("INSERT INTO public_agent_settings(organization_id,enabled,provider,model,system_prompt) VALUES (?,1,'anthropic','fixture-model','Use public knowledge only.') ON DUPLICATE KEY UPDATE enabled=1,provider='anthropic',model='fixture-model',system_prompt='Use public knowledge only.'")->execute([$org]);
        $key=app_encrypt_secret('fake-local-provider-key');
        foreach(['anthropic','openai'] as $provider)$pdo->prepare("INSERT INTO llm_api_credentials(organization_id,provider,display_name,encrypted_key,nonce,encryption_method,key_last_four,status,updated_by) VALUES (?,?,'Local fixture',?,?,?,'-key','configured',?) ON DUPLICATE KEY UPDATE encrypted_key=VALUES(encrypted_key),nonce=VALUES(nonce),encryption_method=VALUES(encryption_method),status='configured'")->execute([$org,$provider,$key['ciphertext'],$key['nonce'],$key['method'],$actor]);
        $content=$question.' is our fixture pizza. It is made with tomatoes and cheese.';
        $pdo->prepare("INSERT INTO knowledge_documents(organization_id,public_id,title,content,content_sha256,status,agent_enabled) VALUES (?,?,?,?,?,'published',1)")->execute([$org,$state['document'],'Fixture '.$question,$content,hash('sha256',$content)]);$doc=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO knowledge_document_chunks(organization_id,document_id,chunk_index,content,content_sha256,token_estimate) VALUES (?,?,0,?,?,30)')->execute([$org,$doc,$content,hash('sha256',$content)]);
        foreach(['public_agent_request_leases','public_agent_usage_buckets'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE organization_id=?')->execute([$org]);
    }catch(Throwable $error){hpaf_cleanup($pdo,$state);unlink($stage.'/state.json');throw $error;}
    echo json_encode(['org'=>$org,'other'=>$other,'question'=>$question,'sourceFiles'=>$files],JSON_THROW_ON_ERROR);exit;
}
$state=json_decode((string)file_get_contents($stage.'/state.json'),true,512,JSON_THROW_ON_ERROR);$org=(int)$state['org'];
if($mode==='reset'){
    foreach(['public_agent_request_leases','public_agent_usage_buckets'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE organization_id=?')->execute([$org]);
    $limits=public_agent_limits_config($input['limits']??[]);hpaf_config($stage,$limits);
    $provider=($input['provider']??'anthropic')==='openai'?'openai':'anthropic';
    $pdo->prepare('UPDATE public_agent_settings SET enabled=?,provider=?,model=?,system_prompt=? WHERE organization_id=?')->execute([($input['enabled']??true)?1:0,$provider,'fixture-model',$input['systemPrompt']??'Use public knowledge only.',$org]);
    $pdo->prepare("UPDATE llm_api_credentials SET status=? WHERE organization_id=? AND provider IN ('anthropic','openai')")->execute([($input['credential']??true)?'configured':'missing',$org]);
    $pdo->prepare('UPDATE knowledge_documents SET agent_enabled=? WHERE organization_id=? AND public_id=?')->execute([($input['knowledge']??true)?1:0,$org,$state['document']]);
    $content=$input['context']??($state['question'].' is our fixture pizza. It is made with tomatoes and cheese.');
    $pdo->prepare('UPDATE knowledge_document_chunks c JOIN knowledge_documents d ON d.id=c.document_id SET c.content=?,c.content_sha256=? WHERE d.organization_id=? AND d.public_id=?')->execute([$content,hash('sha256',$content),$org,$state['document']]);
    echo '{"ok":true}';exit;
}
if($mode==='snapshot'){
    $buckets=hpaf_rows($pdo,'SELECT bucket_type,bucket_hash,used_units FROM public_agent_usage_buckets WHERE organization_id=? ORDER BY bucket_type,bucket_hash',[$org]);$usage=[];
    foreach($buckets as $bucket)$usage[$bucket['bucket_type']]=($usage[$bucket['bucket_type']]??0)+(int)$bucket['used_units'];
    $leases=hpaf_rows($pdo,'SELECT lease_id,reserved_token_units FROM public_agent_request_leases WHERE organization_id=?',[$org]);
    $other=hpaf_rows($pdo,'SELECT * FROM public_agent_usage_buckets WHERE organization_id=?',[(int)$state['other']]);
    echo json_encode(['usage'=>$usage,'buckets'=>$buckets,'leases'=>$leases,'otherBuckets'=>$other],JSON_THROW_ON_ERROR);exit;
}
if($mode==='database-unavailable'){
    $config=require $stage.'/site/config.php';
    $config['database']['dsn']='mysql:host=127.0.0.1;port=1;dbname=unavailable_public_agent_fixture;charset=utf8mb4';
    file_put_contents($stage.'/site/config.php',"<?php\nreturn ".var_export($config,true).";\n");chmod($stage.'/site/config.php',0600);
    echo '{"ok":true}';exit;
}
if($mode==='cleanup'){hpaf_cleanup($pdo,$state);unlink($stage.'/state.json');echo '{"ok":true}';exit;}
throw new RuntimeException('Unknown fixture operation.');
