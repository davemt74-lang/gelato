<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('FM_VP3_OPERATIONS_TEST')!=='1')
    throw new RuntimeException('Set FM_VP3_OPERATIONS_TEST=1 only against an isolated migrated test database.');

ob_start();
require __DIR__.'/hardening-native-pdo-fixture.php';
$accounts=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
require_once __DIR__.'/../includes/food-monster-vp3-operations.php';
require_once __DIR__.'/../includes/food-monster-llm.php';
require_once __DIR__.'/../includes/kds-core.php';

function fmv3_assert(bool $pass,string $why): void
{
    if(!$pass)throw new RuntimeException('VP3 OPERATIONS FAIL: '.$why);
    $GLOBALS['fmv3_assertions']=1+($GLOBALS['fmv3_assertions']??0);
}

function fmv3_insert(PDO $pdo,string $sql,array $args): int
{
    $pdo->prepare($sql)->execute($args);
    return (int)$pdo->lastInsertId();
}

function fmv3_http(string $endpoint,?array $body=null,?string $token=null,bool $tls=true): array
{
    $headers=['Connection: close','X-Forwarded-Proto: https'];
    if($tls)$headers[]='X-Test-Web-Tls: on';
    if($token!==null)$headers[]='Authorization: Bearer '.$token;
    $options=['method'=>$body===null?'GET':'POST','timeout'=>5,'ignore_errors'=>true];
    if($body!==null){
        $headers[]='Content-Type: application/json';
        $options['content']=json_encode($body,JSON_THROW_ON_ERROR);
    }
    $options['header']=implode("\r\n",$headers);
    $raw=file_get_contents($endpoint,false,stream_context_create(['http'=>$options]));
    if(!is_string($raw))throw new RuntimeException('Fixture HTTP request failed.');
    $status=(int)explode(' ',$http_response_header[0])[1];
    return [$status,json_decode($raw,true,512,JSON_THROW_ON_ERROR)];
}

$org=$accounts['organizations']['home'];
$foreign=$accounts['organizations']['other'];
$uid=$accounts['users']['owner']['id'];
$owner=['id'=>$uid,'organization_id'=>$org,'is_owner_role'=>1,'permissions'=>['*']];
$location=fmv3_insert($pdo,"INSERT INTO locations (organization_id,name,timezone) VALUES (?,'North Kitchen','America/New_York')",[$org]);
$otherLocation=fmv3_insert($pdo,"INSERT INTO locations (organization_id,name) VALUES (?,'South Kitchen')",[$org]);
$foreignLocation=fmv3_insert($pdo,"INSERT INTO locations (organization_id,name) VALUES (?,'Foreign Kitchen')",[$foreign]);
$pdo->prepare("UPDATE organizations SET timezone='Pacific/Honolulu' WHERE id=?")->execute([$org]);
$slug='vp3-'.bin2hex(random_bytes(5));
$section=fmv3_insert($pdo,"INSERT INTO menu_sections (organization_id,name,slug,status) VALUES (?,'Pizza',?,'active')",[$org,$slug]);
$item=fmv3_insert($pdo,"INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Pizza',?,1)",[$org,$section,$slug]);
$price=fmv3_insert($pdo,"INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount) VALUES (?,'Regular','REG',12.00)",[$item]);
$clock=pos_clock($pdo,$org,$location);
$day=$clock->format('Y-m-d');
pos_settings_save($pdo,$org,$location,['makePrimary'=>true],$uid);
$paid=pos_create_check($pdo,$org,$location,['serviceMode'=>'pickup'],$uid);
pos_add_item($pdo,$org,$paid['publicId'],$price,2,'',$uid);
pos_record_tender($pdo,$org,$paid['publicId'],['tenderType'=>'cash','amount'=>24,'tipAmount'=>2],$uid);
$pdo->prepare("INSERT INTO sales_periods (organization_id,location_id,location_key,source_provider,period_start,period_end,
    gross_sales,net_sales,tickets) VALUES (?,?,?,'csv',?,?,900,800,50)")->execute([$org,$location,'id:'.$location,$day,$day]);

$ticket=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Private Free Text','notes'=>'Hidden prep note'],$uid);
$old=$clock->modify('-25 minutes')->format('Y-m-d H:i:s.u');
$pdo->prepare('UPDATE pos_checks SET opened_at=? WHERE organization_id=? AND id=?')->execute([$old,$org,$ticket['id']]);
$pdo->prepare("INSERT INTO service_tables (organization_id,location_id,public_id,name,active_check_id) VALUES (?,?,?,'Table 7',?)")
    ->execute([$org,$location,$slug,$ticket['id']]);
pos_add_item($pdo,$org,$ticket['publicId'],$price,1,'Hidden kitchen instruction',$uid);

$pickup=pos_create_check($pdo,$org,$location,['serviceMode'=>'pickup','tableName'=>'Private Customer Name'],$uid);
pos_add_item($pdo,$org,$pickup['publicId'],$price,1,'',$uid);
$station=kds_station_save($pdo,$org,$location,['name'=>'Oven','targetSeconds'=>60],$uid);
kds_route_save($pdo,$org,$location,$item,$station['public_id'],$uid);
kds_send_check($pdo,$org,$pickup['publicId'],$uid,false);
$pdo->prepare('UPDATE kds_order_items SET fired_at=DATE_SUB(NOW(6),INTERVAL 2 MINUTE) WHERE organization_id=? AND check_id=?')
    ->execute([$org,$pickup['id']]);
$customer=fmv3_insert($pdo,"INSERT INTO crm_customers (organization_id,public_id,display_name,email,phone)
    VALUES (?,?,'Private Customer Name','private@example.test','5555550100')",[$org,$slug]);
$orderId='pickup-'.$slug;
$promise=$clock->setTimezone(new DateTimeZone('America/New_York'))->modify('-25 minutes');
$pdo->prepare("INSERT INTO online_orders (organization_id,location_id,customer_id,user_id,pos_check_id,public_id,idempotency_key,
    requested_ready_at,customer_note) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
        $org,$location,$customer,$uid,$pickup['id'],$orderId,$slug,$promise->format('Y-m-d H:i:s.u'),'Hidden online note',
    ]);
pos_create_check($pdo,$org,$otherLocation,['serviceMode'=>'pickup','tableName'=>'Other Location Ticket'],$uid);

$agentName=food_monster_agent_name();
fmv3_assert(food_monster_agent_name(['food_monster'=>['agent_name'=>' Cinder  Kitchen ']])==='Cinder Kitchen','configured identity normalized');
fmv3_assert(str_starts_with(fmllm_instructions(),'You are '.$agentName.','),'private runtime uses the same identity');
$scopes=['menu','orders.read','tickets.open.read','sales.daily.read'];
try{
    fm_kb2_create($pdo,$owner,'Unscoped',$scopes);
    fmv3_assert(false,'operational grant must require location');
}catch(InvalidArgumentException){fmv3_assert(true,'location required on issue');}
try{
    fm_kb2_create($pdo,$owner,'Cross tenant',$scopes,$foreignLocation);
    fmv3_assert(false,'foreign location must be rejected');
}catch(DomainException){fmv3_assert(true,'foreign location rejected on issue');}
$issued=fm_kb2_create($pdo,$owner,'User pairing label',$scopes,$location);
$legacy=fm_kb2_create($pdo,$owner,'HomeServer',['recipe']);

$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$server)throw new RuntimeException('Unable to allocate a local fixture port.');
$address=stream_socket_get_name($server,false);
fclose($server);
$router=tempnam(sys_get_temp_dir(),'fmvp3-router-');
$log=tempnam(sys_get_temp_dir(),'fmvp3-log-');
file_put_contents($router,'<?php
if (($_SERVER["REQUEST_URI"] ?? "") !== "/api/food-monster-vp3.php") {http_response_code(404); return;}
if (($_SERVER["HTTP_X_TEST_WEB_TLS"] ?? "") === "on") $_SERVER["HTTPS"]="on";
require '.var_export(RESTAURANT_APP_ROOT.'/api/food-monster-vp3.php',true).';');
$process=proc_open([PHP_BINARY,'-S',$address,$router],
    [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,RESTAURANT_APP_ROOT);
try{
    if(!is_resource($process))throw new RuntimeException('Could not start VP3 HTTP fixture.');
    fclose($pipes[0]);
    $probe=false;
    for($i=0;$i<50&&!$probe;$i++){
        $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
        if(!$probe)usleep(50_000);
    }
    if(!$probe)throw new RuntimeException('VP3 HTTP fixture did not start.');
    fclose($probe);
    $endpoint='http://'.$address.'/api/food-monster-vp3.php';
    fmv3_assert(fmv3_http($endpoint,null,null,false)[0]===403,'forwarded proto alone cannot authorize discovery');
    [$status,$discovery]=fmv3_http($endpoint);
    fmv3_assert($status===200&&!isset($discovery['agentName'])&&!isset($discovery['locations'])
        &&!array_diff(FM_VP3_OPERATION_SCOPES,$discovery['capabilities']),'discovery exposes protocol capabilities only');
    [$status,$exchange]=fmv3_http($endpoint,['action'=>'exchange','code'=>$issued['pairingCode']]);
    fmv3_assert($status===200&&$exchange['agentName']===$agentName&&$exchange['agentName']!=='User pairing label',
        'authenticated pairing returns configured private agent identity');
    fmv3_assert($exchange['locations']===[['id'=>$location,'name'=>'North Kitchen','timezone'=>'Pacific/Honolulu']]
        &&$exchange['connectionId']===$issued['id'],'exchange binds the issued connection and one location');
    fmv3_assert(fmv3_http($endpoint,['action'=>'exchange','code'=>$issued['pairingCode']])[0]===403,'exchange code can be used once');
    $token=$exchange['accessToken'];
    [$status,$oldExchange]=fmv3_http($endpoint,['action'=>'exchange','code'=>$legacy['pairingCode']]);
    fmv3_assert($status===200&&$oldExchange['scopes']===['recipe']&&$oldExchange['locations']===[],'legacy knowledge grant preserved');
    fmv3_assert(fmv3_http($endpoint,['action'=>'orders.read','locationId'=>$location],$oldExchange['accessToken'])[0]===403,
        'legacy knowledge token cannot read operational data');
    fmv3_assert(fmv3_http($endpoint,['action'=>'orders.read','locationId'=>$otherLocation],$token)[0]===403,'another tenant location excluded');
    fmv3_assert(fmv3_http($endpoint,['action'=>'orders.read','locationId'=>$foreignLocation],$token)[0]===403,'foreign tenant excluded');
    fmv3_assert(fmv3_http($endpoint,['action'=>'orders.read','locationId'=>$location,'limit'=>51],$token)[0]===422,'unbounded request rejected');
    fmv3_assert(fmv3_http($endpoint,['action'=>'sales.daily.read','locationId'=>$location,'businessDate'=>'2026-02-31'],$token)[0]===422,
        'impossible date rejected');
    $operations=[];
    foreach(FM_VP3_OPERATION_SCOPES as $capability){
        [$status,$wire]=fmv3_http($endpoint,['action'=>$capability,'locationId'=>$location],$token);
        fmv3_assert($status===200&&$wire['schema']==='food-monster-vp3-operations-v1'
            &&$wire['capability']===$capability&&$wire['instanceId']===$exchange['instanceId']
            &&$wire['connectionId']===$exchange['connectionId']&&$wire['agentName']===$agentName
            &&$wire['location']===$exchange['locations'][0]&&str_ends_with($wire['asOf'],'Z')
            &&str_starts_with($wire['source']['path'],'/'),'authenticated wire envelope');
        $operations[$capability]=$wire;
    }
    $orders=$operations['orders.read']['data']['orders'];
    fmv3_assert(count($orders)===1&&$orders[0]['orderId']===$orderId&&$orders[0]['checkId']===$pickup['publicId']
        &&$orders[0]['pastPromise']&&$orders[0]['paymentStatus']==='due'
        &&$orders[0]['requestedReadyAt']===$promise->format(DATE_ATOM),'active pickup and local promise resolved');
    $tickets=$operations['tickets.open.read']['data']['tickets'];
    fmv3_assert(count($tickets)===2&&$tickets[0]['checkId']===$ticket['publicId']&&$tickets[0]['tableName']==='Table 7'
        &&$tickets[0]['ageSeconds']>=1500&&$tickets[0]['kitchen']['status']==='not_sent'&&$tickets[0]['kitchen']['unsent']===1,
        'oldest open POS ticket, canonical table and unsent state');
    fmv3_assert($tickets[1]['checkId']===$orders[0]['checkId']&&$tickets[1]['tableName']===null
        &&$tickets[1]['kitchen']['queued']===1&&$tickets[1]['kitchen']['late'],'pickup links to actual POS and KDS');
    [$status,$limited]=fmv3_http($endpoint,['action'=>'tickets.open.read','locationId'=>$location,'limit'=>1],$token);
    fmv3_assert($status===200&&$limited['data']['truncated']&&count($limited['data']['tickets'])===1,'bounded ticket snapshot');
    $sales=$operations['sales.daily.read']['data'];
    fmv3_assert($sales['available']&&$sales['businessDate']===$day&&$sales['provider']==='gelato_pos'
        &&$sales['currency']==='USD'&&$sales['totals']['tickets']===1&&$sales['totals']['grossSales']==='24.00'
        &&$sales['totals']['netSales']==='24.00'&&$sales['totals']['tips']==='2.00'
        &&str_ends_with($sales['dataUpdatedAt'],'Z'),'daily sales uses primary posted source and excludes duplicate import');
    [$status,$missing]=fmv3_http($endpoint,['action'=>'sales.daily.read','locationId'=>$location,'businessDate'=>'1900-01-01'],$token);
    fmv3_assert($status===200&&$missing['data']['available']===false&&$missing['data']['totals']===null,'absent sales are unknown');
    $snapshot=json_encode($operations,JSON_THROW_ON_ERROR);
    foreach(['Private Customer Name','private@example.test','5555550100','Hidden prep note','Hidden kitchen instruction','Hidden online note','Private Free Text','Other Location Ticket',$token] as $private)
        fmv3_assert(!str_contains($snapshot,$private),'safe projection excludes private or unrelated record');
    $pdo->prepare("UPDATE locations SET status='inactive' WHERE id=?")->execute([$location]);
    fmv3_assert(fmv3_http($endpoint,['action'=>'tickets.open.read','locationId'=>$location],$token)[0]===403,'inactive approved location fails closed');
    fmv3_assert(fmv3_http($endpoint,['action'=>'revoke'],$token)[0]===200,'Cloud may revoke only its own grant');
    fmv3_assert(fmv3_http($endpoint,['action'=>'orders.read','locationId'=>$location],$token)[0]===403,'revoked credential denied');
    $output=getenv('FM_VP3_OPERATIONS_FIXTURE_OUTPUT');
    if(is_string($output)&&$output!==''){
        $exchange['accessToken']='fm2_'.str_repeat('A',43);
        file_put_contents($output,json_encode(compact('discovery','exchange','operations'),JSON_THROW_ON_ERROR));
    }
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    unlink($router);unlink($log);
}
echo 'FM-VP3 Gelato operations HTTP contract PASS ('.$GLOBALS['fmv3_assertions']." assertions)\n";
